<?php
/**
 * Only SHA-256 hashes are stored; the plaintext token leaves this class only from issue()/rotate().
 */

namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\RefreshTokenSchema;
use AppzaCore\Plugin\Services\JwtConfig;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RefreshTokenRepository {

	const TOKEN_BYTES = 64;

	/** @return array{token: string, expires_at: int} */
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

	/** @return array{token: string, expires_at: int}|null */
	public function rotate( string $old_token ): ?array {
		$user_id = $this->find_user_id( $old_token );
		if ( null === $user_id ) {
			return null;
		}
		$this->revoke( $old_token );
		return $this->issue( $user_id );
	}

	public function revoke( string $token ): void {
		global $wpdb;
		$wpdb->delete(
			RefreshTokenSchema::table_name(),
			array( 'token_hash' => self::hash_token( $token ) ),
			array( '%s' )
		);
	}

	public function revoke_all_for_user( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			RefreshTokenSchema::table_name(),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);
	}

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
