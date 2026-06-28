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

	const DB_VERSION = '1';

	public static function install() {
		CatalogSnapshotSchema::install();
		CatalogMetaSchema::install();
		update_option( 'appza_core_db_version', self::DB_VERSION, false );
	}

	public static function drop_all() {
		CatalogSnapshotSchema::drop();
		CatalogMetaSchema::drop();
	}
}