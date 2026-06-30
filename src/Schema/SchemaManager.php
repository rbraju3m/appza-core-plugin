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
	// Bumped to 3 with the wp_appza_refresh_tokens addition (Phase 1B.6 chunk 2).
	const DB_VERSION = '3';

	public static function install() {
		CatalogSnapshotSchema::install();
		CatalogMetaSchema::install();
		CustomizationSchema::install();
		RefreshTokenSchema::install();
		update_option( 'appza_core_db_version', self::DB_VERSION, false );
	}

	public static function drop_all() {
		CatalogSnapshotSchema::drop();
		CatalogMetaSchema::drop();
		CustomizationSchema::drop();
		RefreshTokenSchema::drop();
	}
}