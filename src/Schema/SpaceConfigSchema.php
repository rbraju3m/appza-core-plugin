<?php
/**
 * Per-space runtime configuration table. One row per FluentCommunity space
 * slug, holding a JSON blob of AppZa overrides (switcher / subtabs / cover /
 * topics). Read at REST-dispatch time by FluentCommunitySpaceConfigEnricher
 * and attached to the space payload so the mobile renderer can apply the
 * overrides at render time.
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SpaceConfigSchema {

	const TABLE = 'appza_space_config';

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
			space_slug VARCHAR(190) NOT NULL,
			config_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_space_slug (space_slug)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
	}
}
