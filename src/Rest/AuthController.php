<?php
/**
 * REST handlers under appza/auth/v1 (DC#13 Q4).
 *
 *   POST /login    — username + password → access + refresh
 *   POST /refresh  — refresh_token       → rotated access + refresh
 *   POST /logout   — refresh_token       → 204 (best-effort revoke)
 *   POST /guest    — (no body)           → guest access token (no refresh)
 *   GET  /me       — Bearer required     → current user info
 *
 * /me reads the Authorization header directly via WP_REST_Request rather
 * than relying on the Bearer middleware (chunk 4). The two approaches
 * coexist — middleware additionally calls wp_set_current_user(sub) so
 * add-on plug-ins see a logged-in user on routes outside our namespace.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\RefreshTokenRepository;
use AppzaCore\Plugin\Services\JwtConfig;
use AppzaCore\Plugin\Services\JwtService;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AuthController {

	private $jwt;
	private $refresh_tokens;

	public function __construct( ?JwtService $jwt = null, ?RefreshTokenRepository $refresh_tokens = null ) {
		$this->jwt            = $jwt ?: new JwtService();
		$this->refresh_tokens = $refresh_tokens ?: new RefreshTokenRepository();
	}

	public function login( \WP_REST_Request $request ) {
		$username = (string) $request->get_param( 'username' );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $username || '' === $password ) {
			return new \WP_Error( 'appza_auth_missing_credentials', 'username and password are required.', array( 'status' => 400 ) );
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) ) {
			return new \WP_Error( 'appza_auth_invalid_credentials', 'Invalid credentials.', array( 'status' => 401 ) );
		}

		return self::token_pair_response( $user->ID, $user );
	}

	public function refresh( \WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh_token ) {
			return new \WP_Error( 'appza_auth_missing_refresh_token', 'refresh_token is required.', array( 'status' => 400 ) );
		}

		$rotated = $this->refresh_tokens->rotate( $refresh_token );
		if ( null === $rotated ) {
			return new \WP_Error( 'appza_auth_invalid_refresh_token', 'Refresh token is unknown or expired.', array( 'status' => 401 ) );
		}

		$user_id = $this->refresh_tokens->find_user_id( $rotated['token'] );
		if ( null === $user_id ) {
			// Should be impossible since we just issued, but defense in depth.
			return new \WP_Error( 'appza_auth_refresh_lookup_failed', 'Newly issued refresh token did not resolve.', array( 'status' => 500 ) );
		}

		$access = $this->jwt->issue_access_token( $user_id );
		return new \WP_REST_Response(
			array(
				'access_token'       => $access,
				'refresh_token'      => $rotated['token'],
				'expires_in'         => JwtConfig::ACCESS_TTL,
				'refresh_expires_in' => $rotated['expires_at'] - time(),
			),
			200
		);
	}

	public function logout( \WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		if ( '' !== $refresh_token ) {
			$this->refresh_tokens->revoke( $refresh_token );
		}
		// Always 204 — don't leak whether the token existed.
		return new \WP_REST_Response( null, 204 );
	}

	public function guest( \WP_REST_Request $request ) {
		$access = $this->jwt->issue_guest_token();
		return new \WP_REST_Response(
			array(
				'access_token' => $access,
				'expires_in'   => JwtConfig::ACCESS_TTL,
			),
			200
		);
	}

	public function me( \WP_REST_Request $request ) {
		$jwt = self::extract_bearer_from_request( $request );
		if ( '' === $jwt ) {
			return new \WP_Error( 'appza_auth_missing_bearer', 'Missing Authorization: Bearer header.', array( 'status' => 401 ) );
		}

		$claims = $this->jwt->verify( $jwt );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$type = $claims['type'] ?? '';
		if ( JwtConfig::TOKEN_TYPE_GUEST === $type ) {
			return new \WP_REST_Response(
				array(
					'is_guest' => true,
					'user'     => null,
				),
				200
			);
		}

		$user = get_userdata( (int) ( $claims['sub'] ?? 0 ) );
		if ( ! $user ) {
			return new \WP_Error( 'appza_auth_user_not_found', 'JWT subject is no longer a valid user.', array( 'status' => 401 ) );
		}

		return new \WP_REST_Response(
			array(
				'is_guest' => false,
				'user'     => self::user_summary( $user ),
			),
			200
		);
	}

	/** Issues an access + refresh pair and shapes the response body. */
	private function token_pair_response( int $user_id, \WP_User $user ): \WP_REST_Response {
		$access  = $this->jwt->issue_access_token( $user_id );
		$refresh = $this->refresh_tokens->issue( $user_id );

		return new \WP_REST_Response(
			array(
				'access_token'       => $access,
				'refresh_token'      => $refresh['token'],
				'expires_in'         => JwtConfig::ACCESS_TTL,
				'refresh_expires_in' => $refresh['expires_at'] - time(),
				'user'               => self::user_summary( $user ),
			),
			200
		);
	}

	private static function user_summary( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
		);
	}

	/**
	 * Pulls the JWT out of `Authorization: Bearer <jwt>`, looking in every
	 * reasonable place the value might land. Apache + PHP-FPM strips the
	 * Authorization header by default in some configs; this fallback chain
	 * keeps the plug-in working without forcing customers to edit their
	 * .htaccess.
	 */
	public static function extract_bearer_from_request( \WP_REST_Request $request ): string {
		$candidates = array( (string) $request->get_header( 'authorization' ) );
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
}