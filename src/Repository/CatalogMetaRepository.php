<?php
/**
 * Get / bump for wp_appza_catalog_meta. Singleton row at id=1.
 *
 * Bootstrap endpoint reads the row to include version markers in its envelope.
 * Pull-from-Core action bumps catalog_snapshot_version after a successful pull.
 * Customizations admin (Phase 1B.5+) bumps customizations_version on save.
 */

namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\CatalogMetaSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CatalogMetaRepository {

	const ROW_ID = 1;

	public function get() {
		global $wpdb;
		$table = CatalogMetaSchema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", self::ROW_ID ), ARRAY_A );

		if ( ! $row ) {
			return array(
				'customizations_version'   => 0,
				'catalog_snapshot_version' => 0,
				'schema_version'           => APPZA_CORE_CONTRACTS_VERSION,
				'last_synced_at'           => null,
			);
		}

		$row['customizations_version']   = (int) $row['customizations_version'];
		$row['catalog_snapshot_version'] = (int) $row['catalog_snapshot_version'];

		return $row;
	}

	public function bump_catalog_snapshot_version() {
		return $this->update_one( array(
			'catalog_snapshot_version' => $this->get()['catalog_snapshot_version'] + 1,
			'last_synced_at'           => current_time( 'mysql', true ),
		) );
	}

	public function bump_customizations_version() {
		return $this->update_one( array(
			'customizations_version' => $this->get()['customizations_version'] + 1,
		) );
	}

	public function set_schema_version( $version ) {
		return $this->update_one( array( 'schema_version' => (string) $version ) );
	}

	protected function update_one( array $data ) {
		global $wpdb;
		$table   = CatalogMetaSchema::table_name();
		$formats = array_map( array( $this, 'format_for_column' ), array_keys( $data ) );

		$updated = $wpdb->update( $table, $data, array( 'id' => self::ROW_ID ), $formats, array( '%d' ) );

		if ( false === $updated || 0 === $updated ) {
			$wpdb->insert(
				$table,
				array_merge( array( 'id' => self::ROW_ID ), $data ),
				array_merge( array( '%d' ), $formats )
			);
		}

		return $this->get();
	}

	protected function format_for_column( $column ) {
		switch ( $column ) {
			case 'customizations_version':
			case 'catalog_snapshot_version':
				return '%d';
			default:
				return '%s';
		}
	}
}