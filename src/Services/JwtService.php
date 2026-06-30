<?php
/**
 * Encodes + verifies the access + guest tokens issued by the appza/auth/v1
 * endpoints (DC#13 Q4). Refresh tokens are opaque random strings (NOT JWTs)
 * and live in `wp_appza_refresh_tokens` keyed by SHA-256 hash — see
 * RefreshTokenRepository.
 *
 * Verification returns the decoded claims as an array on success or a
 * WP_Error on failure (expired / signature mismatch / wrong issuer / wrong
 * audience / malformed). Callers translate the WP_Error to a 401 response.
 */

namespace AppzaCore\Plugin\Services;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JwtService {

	/**
	 * Issues an access token for a WP user. `sub` is the user ID.
	 *
	 * @param int $user_id WP user ID.
	 * @return string JWT.
	 */
	public function issue_access_token( int $user_id ): string {
		$now = time();
		$payload = array(
			'iss'  => JwtConfig::issuer(),
			'aud'  => JwtConfig::audience(),
			'sub'  => $user_id,
			'iat'  => $now,
			'nbf'  => $now,
			'exp'  => $now + JwtConfig::ACCESS_TTL,
			'type' => JwtConfig::TOKEN_TYPE_ACCESS,
		);
		return JWT::encode( $payload, JwtConfig::secret(), JwtConfig::ALGORITHM );
	}

	/**
	 * Issues a guest token. `sub` is 0 (no user); `type` is 'guest' so
	 * action/data_source `auth_required: true` gates reject these tokens.
	 *
	 * @return string JWT.
	 */
	public function issue_guest_token(): string {
		$now = time();
		$payload = array(
			'iss'  => JwtConfig::issuer(),
			'aud'  => JwtConfig::audience(),
			'sub'  => 0,
			'iat'  => $now,
			'nbf'  => $now,
			'exp'  => $now + JwtConfig::ACCESS_TTL,
			'type' => JwtConfig::TOKEN_TYPE_GUEST,
		);
		return JWT::encode( $payload, JwtConfig::secret(), JwtConfig::ALGORITHM );
	}

	/**
	 * Verifies a JWT against the current secret + expected issuer/audience.
	 *
	 * @param string $jwt The bearer token.
	 * @return array|\WP_Error Decoded claims as assoc array on success;
	 *                         WP_Error with code 'appza_jwt_*' on failure.
	 */
	public function verify( string $jwt ) {
		if ( '' === $jwt ) {
			return new \WP_Error( 'appza_jwt_empty', 'Empty JWT.', array( 'status' => 401 ) );
		}

		try {
			$decoded = JWT::decode( $jwt, new Key( JwtConfig::secret(), JwtConfig::ALGORITHM ) );
		} catch ( ExpiredException $e ) {
			return new \WP_Error( 'appza_jwt_expired', 'JWT has expired.', array( 'status' => 401 ) );
		} catch ( SignatureInvalidException $e ) {
			return new \WP_Error( 'appza_jwt_bad_signature', 'JWT signature is invalid.', array( 'status' => 401 ) );
		} catch ( BeforeValidException $e ) {
			return new \WP_Error( 'appza_jwt_not_yet_valid', 'JWT is not yet valid.', array( 'status' => 401 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'appza_jwt_malformed', 'JWT could not be decoded.', array( 'status' => 401 ) );
		}

		$claims = (array) $decoded;

		if ( ( $claims['iss'] ?? null ) !== JwtConfig::issuer() ) {
			return new \WP_Error( 'appza_jwt_bad_issuer', 'JWT issuer mismatch.', array( 'status' => 401 ) );
		}
		if ( ( $claims['aud'] ?? null ) !== JwtConfig::audience() ) {
			return new \WP_Error( 'appza_jwt_bad_audience', 'JWT audience mismatch.', array( 'status' => 401 ) );
		}
		if ( ! in_array( $claims['type'] ?? null, array( JwtConfig::TOKEN_TYPE_ACCESS, JwtConfig::TOKEN_TYPE_GUEST ), true ) ) {
			return new \WP_Error( 'appza_jwt_bad_type', 'JWT type is not access or guest.', array( 'status' => 401 ) );
		}

		return $claims;
	}
}