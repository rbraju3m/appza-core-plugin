<?php
namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\CatalogSnapshotSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CatalogSnapshotRepository {

	public function find_by_template_slug( $template_slug ) {
		global $wpdb;
		$table = CatalogSnapshotSchema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE template_slug = %s LIMIT 1", $template_slug ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['snapshot_blob'] = json_decode( $row['snapshot_blob'], true );

		return $row;
	}

	public function all() {
		global $wpdb;
		$table = CatalogSnapshotSchema::table_name();
		$rows  = $wpdb->get_results( "SELECT id, template_slug, catalog_snapshot_version, core_api_version, fetched_at, updated_at FROM {$table} ORDER BY template_slug ASC", ARRAY_A );

		return $rows ?: array();
	}

	public function upsert( $template_slug, array $snapshot, $catalog_snapshot_version, $core_api_version ) {
		global $wpdb;
		$table   = CatalogSnapshotSchema::table_name();
		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id() ?: null;
		$blob    = wp_json_encode( $snapshot );

		$existing = $this->find_by_template_slug( $template_slug );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'catalog_snapshot_version' => (int) $catalog_snapshot_version,
					'core_api_version'         => (string) $core_api_version,
					'snapshot_blob'            => $blob,
					'fetched_at'               => $now,
					'updated_at'               => $now,
					'updated_by'               => $user_id,
				),
				array( 'template_slug' => $template_slug ),
				array( '%d', '%s', '%s', '%s', '%s', '%d' ),
				array( '%s' )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			array(
				'template_slug'            => $template_slug,
				'catalog_snapshot_version' => (int) $catalog_snapshot_version,
				'core_api_version'         => (string) $core_api_version,
				'snapshot_blob'            => $blob,
				'fetched_at'               => $now,
				'created_at'               => $now,
				'updated_at'               => $now,
				'created_by'               => $user_id,
				'updated_by'               => $user_id,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	const ETAGS_OPTION = 'appza_core_snapshot_etags';

	/** Empty unless the row it describes is still here. */
	public function etag( $template_slug ) {
		global $wpdb;
		$etags = get_option( self::ETAGS_OPTION, array() );
		if ( empty( $etags[ $template_slug ] ) ) {
			return '';
		}
		$table  = CatalogSnapshotSchema::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE template_slug = %s LIMIT 1", $template_slug ) );

		return $exists ? (string) $etags[ $template_slug ] : '';
	}

	public function remember_etag( $template_slug, $etag ) {
		$etags = get_option( self::ETAGS_OPTION, array() );
		if ( '' === (string) $etag ) {
			unset( $etags[ $template_slug ] );
		} else {
			$etags[ $template_slug ] = (string) $etag;
		}
		update_option( self::ETAGS_OPTION, $etags, false );
	}

	public function delete_by_template_slug( $template_slug ) {
		global $wpdb;
		$this->remember_etag( $template_slug, '' );
		$table = CatalogSnapshotSchema::table_name();
		return (int) $wpdb->delete( $table, array( 'template_slug' => $template_slug ), array( '%s' ) );
	}
}