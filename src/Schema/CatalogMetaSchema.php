<?php
/**
 * DDL for wp_appza_catalog_meta per appza-implementation-plan.md § 4.14.
 * Single-row version registry consumed by the bootstrap endpoint.
 *
 * Bumped on:
 *   - customer customizations edit       → customizations_version
 *   - WP plug-in catalog refresh         → catalog_snapshot_version
 *   - Lazycoders ship (contracts change) → schema_version
 *
 * `id = 1` is a hardcoded singleton row; the table is constrained but trivially
 * extensible to multi-row if a per-tenant break-out is ever needed.
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CatalogMetaSchema {

	const TABLE = 'appza_catalog_meta';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customizations_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
			catalog_snapshot_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
			schema_version VARCHAR(40) NOT NULL DEFAULT '',
			last_synced_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$wpdb->query( "INSERT IGNORE INTO {$table} (id, schema_version) VALUES (1, '" . esc_sql( APPZA_CORE_CONTRACTS_VERSION ) . "')" );
	}

	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}