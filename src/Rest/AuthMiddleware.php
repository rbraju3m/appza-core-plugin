<?php
/**
 * Bearer-JWT auth middleware (DC#13 Q4).
 *
 * Hooks `determine_current_user` so a valid Authorization: Bearer <jwt>
 * sets the WP current user for the rest of the request. Add-on plug-ins
 * that read wp_get_current_user() / is_user_logged_in() benefit
 * automatically — no per-Source auth handshake.
 *
 * Posture: lenient. Invalid + expired + malformed tokens leave the user
 * as anonymous (the route's own permission_callback decides whether to
 * reject). The appza/auth/v1 namespace is skipped entirely so the auth
 * endpoints can't reject themselves via this filter.
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
	 * @param int|false $user_id Result of earlier auth filters (cookie / app password).
	 * @return int|false WP user ID if Bearer resolved; otherwise pass-through.
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
			// Guest tokens authenticate the request as "guest browser" but
			// do not promote to a real WP user. The handler can introspect
			// the Bearer directly (see AuthController::me).
			return $user_id;
		}

		$sub = (int) ( $claims['sub'] ?? 0 );
		return $sub > 0 ? $sub : $user_id;
	}

	/**
	 * Reads the Bearer JWT from any place Apache + PHP-FPM may have
	 * stashed the Authorization header. Mirrors
	 * AuthController::extract_bearer_from_request but works without a
	 * WP_REST_Request (this filter fires before REST routing).
	 */
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

	/**
	 * Inspects the current request URI for the auth namespace. Handles
	 * both pretty-permalink (`/wp-json/appza/auth/v1/*`) and
	 * query-form (`?rest_route=/appza/auth/v1/*`) variants.
	 */
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
