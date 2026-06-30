<?php
/**
 * JWT auth configuration knobs (DC#13 Q4). Centralizes the values used by
 * JwtService + the refresh-token repository so the access TTL, refresh TTL,
 * issuer, audience, and secret all read from a single place.
 *
 * Secret defaults to wp_salt('auth') so customers get a working setup with
 * zero configuration. The salt rotates with WP's existing key-rotation tools;
 * rotating it invalidates all outstanding access + guest tokens (refresh
 * tokens stay valid because they're opaque random strings, not JWTs).
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JwtConfig {

	const ALGORITHM    = 'HS256';
	const ACCESS_TTL   = HOUR_IN_SECONDS;          // 1h per DC#13 Q4
	const REFRESH_TTL  = 30 * DAY_IN_SECONDS;      // 30d per DC#13 Q4
	const TOKEN_TYPE_ACCESS = 'access';
	const TOKEN_TYPE_GUEST  = 'guest';

	public static function secret(): string {
		return wp_salt( 'auth' );
	}

	public static function issuer(): string {
		return rtrim( get_site_url(), '/' );
	}

	public static function audience(): string {
		return 'appza-mobile';
	}
}