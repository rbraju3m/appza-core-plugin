<?php
/**
 * Read / upsert for wp_appza_customizations.
 *
 * Bootstrap endpoint calls all_as_tree() to fold the flat table into the
 * nested wire shape the renderer expects (scope -> target_key -> column).
 * Admin override editor (Phase 1B.5b) will call upsert() per form submit.
 */

namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\CustomizationSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CustomizationRepository {

	protected $meta;

	public function __construct( CatalogMetaRepository $meta = null ) {
		$this->meta = $meta ?: new CatalogMetaRepository();
	}

	public function all() {
		global $wpdb;
		$table = CustomizationSchema::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY scope, target_slug, target_column ASC", ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Returns nested {scope: {target_key: {column: value}}}.
	 *
	 * `target_key` is the target_slug for single-target scopes
	 * (appzet / template / etc.), the target_slug_composite for the
	 * placement scope, and the empty string for global scope (no target).
	 *
	 * Every value is stored as JSON in the DB; we decode without $assoc=true
	 * so stored object shapes are preserved (avoids the empty-{}-becomes-[]
	 * trap that bit catalog.template.tokens — see bugs_fixed memory entry
	 * for that family of issues).
	 */
	public function all_as_tree() {
		$rows = $this->all();
		$tree = array();
		foreach ( $rows as $row ) {
			$scope  = (string) $row['scope'];
			$column = (string) $row['target_column'];
			$key    = $this->target_key_for( $row );
			$value  = json_decode( (string) $row['override_value'], false );
			// Normalize empty PHP arrays back to stdClass so they encode
			// as JSON objects (matches the leaf-bag wire contract).
			if ( is_array( $value ) && empty( $value ) ) {
				$value = new \stdClass();
			}
			if ( ! isset( $tree[ $scope ] ) ) {
				$tree[ $scope ] = array();
			}
			if ( ! isset( $tree[ $scope ][ $key ] ) ) {
				$tree[ $scope ][ $key ] = array();
			}
			$tree[ $scope ][ $key ][ $column ] = $value;
		}
		// Cast each leaf bucket to stdClass so empty inner objects survive.
		// (We start with arrays for easy assignment; finalize as objects.)
		foreach ( $tree as $scope => $by_target ) {
			foreach ( $by_target as $target_key => $columns ) {
				$tree[ $scope ][ $target_key ] = (object) $columns;
			}
			$tree[ $scope ] = (object) $tree[ $scope ];
		}
		return $tree;
	}

	public function upsert( $scope, $target_slug, $target_slug_composite, $target_column, $value ) {
		global $wpdb;
		$table     = CustomizationSchema::table_name();
		$json      = wp_json_encode( $value );
		$user_id   = get_current_user_id() ?: null;
		$existing  = $this->find( $scope, $target_slug, $target_slug_composite, $target_column );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'override_value' => $json,
					'version'        => (int) $existing['version'] + 1,
					'updated_by'     => $user_id,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%d' ),
				array( '%d' )
			);
			$this->meta->bump_customizations_version();
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			array(
				'scope'                 => $scope,
				'target_slug'           => $target_slug,
				'target_slug_composite' => $target_slug_composite,
				'target_column'         => $target_column,
				'override_value'        => $json,
				'version'               => 1,
				'created_by'            => $user_id,
				'updated_by'            => $user_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		$this->meta->bump_customizations_version();
		return (int) $wpdb->insert_id;
	}

	public function find( $scope, $target_slug, $target_slug_composite, $target_column ) {
		global $wpdb;
		$table = CustomizationSchema::table_name();

		// wpdb->prepare() coerces PHP null through %s to '' (or 'null'
		// depending on WP version) rather than preserving SQL NULL, so
		// `<=> %s` degenerates to `<=> ''` and never matches real NULL
		// rows. Branch to `IS NULL` when the value is null; use `= %s`
		// otherwise. Same treatment for target_slug (nullable for global
		// scope) and target_slug_composite (nullable for non-placement).
		$where   = array( 'scope = %s' );
		$prepare = array( $scope );

		if ( null === $target_slug ) {
			$where[] = 'target_slug IS NULL';
		} else {
			$where[]   = 'target_slug = %s';
			$prepare[] = $target_slug;
		}

		if ( null === $target_slug_composite ) {
			$where[] = 'target_slug_composite IS NULL';
		} else {
			$where[]   = 'target_slug_composite = %s';
			$prepare[] = $target_slug_composite;
		}

		$where[]   = 'target_column = %s';
		$prepare[] = $target_column;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1',
			$prepare
		);
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	public function delete( $id ) {
		global $wpdb;
		$table   = CustomizationSchema::table_name();
		$deleted = (int) $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
		if ( $deleted > 0 ) {
			$this->meta->bump_customizations_version();
		}
		return $deleted;
	}

	protected function target_key_for( array $row ) {
		$scope = (string) $row['scope'];
		if ( 'global' === $scope ) {
			return '';
		}
		if ( 'template_screen_placement' === $scope ) {
			return (string) ( $row['target_slug_composite'] ?? '' );
		}
		return (string) ( $row['target_slug'] ?? '' );
	}
}
