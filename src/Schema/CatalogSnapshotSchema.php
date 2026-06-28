<?php
/**
 * DDL for wp_appza_catalog_snapshot per appza-implementation-plan.md § 4.12.3
 * (DC#13 Q5 — cached Core catalog subset, one row per Template).
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CatalogSnapshotSchema {

	const TABLE = 'appza_catalog_snapshot';

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
			template_slug VARCHAR(190) NOT NULL,
			catalog_snapshot_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
			core_api_version VARCHAR(40) NOT NULL DEFAULT '',
			snapshot_blob LONGTEXT NOT NULL,
			fetched_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED NULL DEFAULT NULL,
			updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_template_slug (template_slug),
			KEY idx_fetched_at (fetched_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}