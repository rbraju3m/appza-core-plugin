<?php
namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\CustomizationSchema;
use AppzaCore\Plugin\Services\ScreenLayout;

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
	 * Returns {scope: {target_key: {column: value}}}. Decoded as objects so an empty {}
	 * doesn't come back as [].
	 */
	public function all_as_tree() {
		$rows = $this->all();
		$tree = array();
		foreach ( $rows as $row ) {
			$scope  = (string) $row['scope'];
			$column = (string) $row['target_column'];
			$key    = $this->target_key_for( $row );
			$value  = json_decode( (string) $row['override_value'], false );
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

		// prepare() turns null into '' rather than SQL NULL, so null columns need IS NULL.
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

	/**
	 * Saves a builder layout only if the row is still at base_version (null: no row yet).
	 *
	 * @return array{ok: bool, version: ?int}
	 */
	public function save_layout( $scope, $target_slug, $template_slug, $column, $value, $base_version ) {
		global $wpdb;
		$table    = CustomizationSchema::table_name();
		$json     = wp_json_encode( $value );
		$user_id  = get_current_user_id() ?: null;
		$existing = $this->find( $scope, $target_slug, $template_slug, $column );

		if ( $existing ) {
			if ( null === $base_version || (int) $existing['version'] !== (int) $base_version ) {
				return array( 'ok' => false, 'version' => (int) $existing['version'] );
			}
			$next    = (int) $existing['version'] + 1;
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET override_value = %s, version = %d, updated_by = %d WHERE id = %d AND version = %d",
					$json,
					$next,
					(int) $user_id,
					(int) $existing['id'],
					(int) $base_version
				)
			);
			if ( 1 !== (int) $changed ) {
				$now = $this->find( $scope, $target_slug, $template_slug, $column );
				return array( 'ok' => false, 'version' => $now ? (int) $now['version'] : null );
			}
			$this->meta->bump_customizations_version();
			return array( 'ok' => true, 'version' => $next );
		}

		if ( null !== $base_version ) {
			return array( 'ok' => false, 'version' => null );
		}
		// The unique key catches a racing insert: the composite is never NULL here.
		$inserted = $wpdb->insert(
			$table,
			array(
				'scope'                 => $scope,
				'target_slug'           => $target_slug,
				'target_slug_composite' => $template_slug,
				'target_column'         => $column,
				'override_value'        => $json,
				'version'               => 1,
				'created_by'            => $user_id,
				'updated_by'            => $user_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		if ( ! $inserted ) {
			$now = $this->find( $scope, $target_slug, $template_slug, $column );
			return array( 'ok' => false, 'version' => $now ? (int) $now['version'] : null );
		}
		$this->meta->bump_customizations_version();
		return array( 'ok' => true, 'version' => 1 );
	}

	public function delete_layout( $scope, $target_slug, $template_slug, $column ) {
		$row = $this->find( $scope, $target_slug, $template_slug, $column );
		return $row ? $this->delete( (int) $row['id'] ) : 0;
	}

	protected function target_key_for( array $row ) {
		$scope = (string) $row['scope'];
		if ( 'global' === $scope ) {
			return '';
		}
		// A screen's builder layout is per template (D110); screen_tokens stay keyed by screen.
		if ( 'template_screen' === $scope
			&& in_array( (string) $row['target_column'], ScreenLayout::LAYOUT_COLUMNS, true )
			&& ! empty( $row['target_slug_composite'] )
		) {
			return $row['target_slug_composite'] . ':' . $row['target_slug'];
		}
		if ( 'template_screen_placement' === $scope || 'appzet_primitive' === $scope ) {
			return (string) ( $row['target_slug_composite'] ?? '' );
		}
		return (string) ( $row['target_slug'] ?? '' );
	}
}
