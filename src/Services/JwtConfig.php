<?php
/** Rotating wp_salt('auth') invalidates all access/guest tokens (refresh tokens are not JWTs). */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JwtConfig {

	const ALGORITHM    = 'HS256';
	const ACCESS_TTL   = HOUR_IN_SECONDS;
	const REFRESH_TTL  = 30 * DAY_IN_SECONDS;
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