<?php
/**
 * CRUD for wp_appza_refresh_tokens (DC#13 Q4).
 *
 * Plaintext refresh tokens leave this class exactly twice: once at
 * issue() and once at rotate(), both as the return value to the caller.
 * The DB stores SHA-256 hashes only — `find_user_id()` and `revoke()`
 * hash the input client-side before any DB lookup.
 *
 * Token format: 64 bytes of randomness, URL-safe base64 → ~86 chars. That
 * gives 512 bits of entropy, well above the brute-force horizon for any
 * realistic attacker, and lets us round-trip safely through HTTP headers
 * and JSON without escaping.
 */

namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\RefreshTokenSchema;
use AppzaCore\Plugin\Services\JwtConfig;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RefreshTokenRepository {

	const TOKEN_BYTES = 64;

	/**
	 * Generates a fresh refresh token, persists its hash + expiry, returns
	 * the plaintext + expiry to the caller. Caller hands plaintext to
	 * client; only the hash stays in DB.
	 *
	 * @param int $user_id Owning WP user.
	 * @return array{token: string, expires_at: int} Plaintext token + unix expiry.
	 */
	public function issue( int $user_id ): array {
		global $wpdb;

		$token      = self::generate_token();
		$hash       = self::hash_token( $token );
		$expires_at = time() + JwtConfig::REFRESH_TTL;

		$wpdb->insert(
			RefreshTokenSchema::table_name(),
			array(
				'user_id'    => $user_id,
				'token_hash' => $hash,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires_at ),
			),
			array( '%d', '%s', '%s' )
		);

		return array(
			'token'      => $token,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Looks up the owning user for a refresh token. Returns null if the
	 * hash isn't stored OR the row has expired.
	 */
	public function find_user_id( string $token ): ?int {
		global $wpdb;
		$hash  = self::hash_token( $token );
		$table = RefreshTokenSchema::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, expires_at FROM {$table} WHERE token_hash = %s LIMIT 1",
				$hash
			)
		);

		if ( ! $row ) {
			return null;
		}
		if ( strtotime( $row->expires_at . ' UTC' ) <= time() ) {
			return null;
		}
		return (int) $row->user_id;
	}

	/**
	 * Atomically swaps a refresh token: deletes the old hash, issues a new
	 * one to the same user. Returns the new plaintext + expiry, or null if
	 * the old token didn't resolve (expired / revoked / never existed).
	 *
	 * @return array{token: string, expires_at: int}|null
	 */
	public function rotate( string $old_token ): ?array {
		$user_id = $this->find_user_id( $old_token );
		if ( null === $user_id ) {
			return null;
		}
		$this->revoke( $old_token );
		return $this->issue( $user_id );
	}

	/** Deletes the refresh-token row by hash; no-op if not present. */
	public function revoke( string $token ): void {
		global $wpdb;
		$wpdb->delete(
			RefreshTokenSchema::table_name(),
			array( 'token_hash' => self::hash_token( $token ) ),
			array( '%s' )
		);
	}

	/** Deletes every refresh token for a user (force-logout-everywhere). */
	public function revoke_all_for_user( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			RefreshTokenSchema::table_name(),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);
	}

	/**
	 * Hygiene: removes rows past their expires_at. Safe to call ad hoc; in
	 * production a daily cron will call this.
	 */
	public function purge_expired(): int {
		global $wpdb;
		$table = RefreshTokenSchema::table_name();
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	private static function generate_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( self::TOKEN_BYTES ) ), '+/', '-_' ), '=' );
	}

	private static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}
}
