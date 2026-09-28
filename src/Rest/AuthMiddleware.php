<?php
/**
 * Sets the WP current user from a Bearer JWT. Lenient: a bad token leaves the request
 * anonymous and the route's permission_callback decides.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\JwtConfig;
use AppzaCore\Plugin\Services\JwtService;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AuthMiddleware {

	private $jwt;

	public function __construct( ?JwtService $jwt = null ) {
		$this->jwt = $jwt ?: new JwtService();
	}

	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'maybe_authenticate' ), 30 );
	}

	/**
	 * @param int|false $user_id
	 * @return int|false
	 */
	public function maybe_authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( self::request_targets_auth_namespace() ) {
			return $user_id;
		}

		$bearer = self::extract_bearer_from_globals();
		if ( '' === $bearer ) {
			return $user_id;
		}

		$claims = $this->jwt->verify( $bearer );
		if ( is_wp_error( $claims ) ) {
			return $user_id;
		}

		$type = $claims['type'] ?? '';
		if ( JwtConfig::TOKEN_TYPE_ACCESS !== $type ) {
			// Guest tokens never become a WP user.
			return $user_id;
		}

		$sub = (int) ( $claims['sub'] ?? 0 );
		return $sub > 0 ? $sub : $user_id;
	}

	/** Checks every place Apache/PHP-FPM may have put the Authorization header. */
	public static function extract_bearer_from_globals(): string {
		$candidates = array();
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_AUTHORIZATION'];
		}
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$candidates[] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $k => $v ) {
					if ( 0 === strcasecmp( $k, 'Authorization' ) ) {
						$candidates[] = (string) $v;
						break;
					}
				}
			}
		}

		foreach ( $candidates as $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( 0 === stripos( $value, 'bearer ' ) ) {
				return trim( substr( $value, 7 ) );
			}
		}
		return '';
	}

	private static function request_targets_auth_namespace(): bool {
		$uri        = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$rest_route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
		$needle     = '/' . APPZA_CORE_AUTH_REST_NAMESPACE . '/';
		if ( '' !== $rest_route && false !== strpos( $rest_route, $needle ) ) {
			return true;
		}
		return false !== strpos( $uri, $needle );
	}
}
