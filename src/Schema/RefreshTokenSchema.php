<?php
/**
 * DDL for wp_appza_refresh_tokens per DC#13 Q4.
 *
 * Refresh tokens are opaque random strings (NOT JWTs) — the plaintext is
 * returned to the client at issuance and never stored. The DB only keeps a
 * SHA-256 hash so a database leak can't be replayed as a valid token.
 *
 * 5 cols v1 — lean by design. Rotation = insert new + delete old; logout =
 * delete by hash. No `revoked_at` audit trail v1 (additive per P25 if audit
 * becomes a real need). No `device_id` v1 — multi-device flows just hold
 * one refresh token per device on the device.
 */

namespace AppzaCore\Plugin\Schema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RefreshTokenSchema {

	const TABLE = 'appza_refresh_tokens';

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
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_token_hash (token_hash),
			KEY idx_user_id (user_id),
			KEY idx_expires_at (expires_at)
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