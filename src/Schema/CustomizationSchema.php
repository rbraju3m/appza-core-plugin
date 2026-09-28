<?php
/**
 * One row per (scope, target, column) override. For `appzet_primitive`,
 * target_slug_composite is "<appzet.slug>#children[<i>]" and target_column is "layout".
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CustomizationSchema {

	const TABLE = 'appza_customizations';

	const SCOPES = array(
		'appzet',
		'template',
		'template_screen',
		'template_screen_placement',
		'data_source',
		'action',
		'appzet_primitive',
		'global',
	);

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		$table     = self::table_name();
		$charset   = $wpdb->get_charset_collate();
		$scope_sql = "'" . implode( "','", array_map( 'esc_sql', self::SCOPES ) ) . "'";

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope ENUM({$scope_sql}) NOT NULL,
			target_slug VARCHAR(190) NULL DEFAULT NULL,
			target_slug_composite VARCHAR(380) NULL DEFAULT NULL,
			target_column VARCHAR(190) NOT NULL,
			override_value LONGTEXT NOT NULL,
			version BIGINT UNSIGNED NOT NULL DEFAULT 1,
			migration_flagged_at DATETIME NULL DEFAULT NULL,
			migration_error TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED NULL DEFAULT NULL,
			updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_scope_target (scope, target_slug, target_slug_composite, target_column),
			KEY idx_scope (scope),
			KEY idx_migration_flagged (migration_flagged_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta often leaves an existing ENUM stale when values are added.
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN scope ENUM({$scope_sql}) NOT NULL" );
	}

	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
