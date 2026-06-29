<?php
/**
 * Schema coordinator. Runs every schema's install() in deterministic order and
 * bumps the appza_core_db_version option so future activations can short-circuit
 * once migrations are introduced.
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SchemaManager {

	// Bumped to 2 with the wp_appza_customizations addition (Phase 1B.5a).
	const DB_VERSION = '2';

	public static function install() {
		CatalogSnapshotSchema::install();
		CatalogMetaSchema::install();
		CustomizationSchema::install();
		update_option( 'appza_core_db_version', self::DB_VERSION, false );
	}

	public static function drop_all() {
		CatalogSnapshotSchema::drop();
		CatalogMetaSchema::drop();
		CustomizationSchema::drop();
	}
}