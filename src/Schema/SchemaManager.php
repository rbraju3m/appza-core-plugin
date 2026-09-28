<?php
namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SchemaManager {

	// Bump when any schema changes; maybe_upgrade_schema() re-runs install() on mismatch.
	const DB_VERSION = '5';

	public static function install() {
		CatalogSnapshotSchema::install();
		CatalogMetaSchema::install();
		CustomizationSchema::install();
		RefreshTokenSchema::install();
		SpaceConfigSchema::install();
		update_option( 'appza_core_db_version', self::DB_VERSION, false );
	}

	public static function drop_all() {
		CatalogSnapshotSchema::drop();
		CatalogMetaSchema::drop();
		CustomizationSchema::drop();
		RefreshTokenSchema::drop();
		SpaceConfigSchema::drop();
	}
}