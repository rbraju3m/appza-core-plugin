<?php
/**
 * HS256 access/guest tokens. Refresh tokens are opaque strings (RefreshTokenRepository).
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JwtService {

	public function issue_access_token( int $user_id ): string {
		$now = time();
		return $this->encode( array(
			'iss'  => JwtConfig::issuer(),
			'aud'  => JwtConfig::audience(),
			'sub'  => $user_id,
			'iat'  => $now,
			'nbf'  => $now,
			'exp'  => $now + JwtConfig::ACCESS_TTL,
			'type' => JwtConfig::TOKEN_TYPE_ACCESS,
		) );
	}

	public function issue_guest_token(): string {
		$now = time();
		return $this->encode( array(
			'iss'  => JwtConfig::issuer(),
			'aud'  => JwtConfig::audience(),
			'sub'  => 0,
			'iat'  => $now,
			'nbf'  => $now,
			'exp'  => $now + JwtConfig::ACCESS_TTL,
			'type' => JwtConfig::TOKEN_TYPE_GUEST,
		) );
	}

	/** @return array|\WP_Error */
	public function verify( string $jwt ) {
		if ( '' === $jwt ) {
			return new \WP_Error( 'appza_jwt_empty', 'Empty JWT.', array( 'status' => 401 ) );
		}

		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'appza_jwt_malformed', 'JWT could not be decoded.', array( 'status' => 401 ) );
		}

		list( $header_b64, $payload_b64, $sig_b64 ) = $parts;

		$header_json  = self::base64url_decode( $header_b64 );
		$payload_json = self::base64url_decode( $payload_b64 );
		$sig          = self::base64url_decode( $sig_b64 );

		if ( false === $header_json || false === $payload_json || false === $sig ) {
			return new \WP_Error( 'appza_jwt_malformed', 'JWT could not be decoded.', array( 'status' => 401 ) );
		}

		$header = json_decode( $header_json, true );
		$claims = json_decode( $payload_json, true );

		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new \WP_Error( 'appza_jwt_malformed', 'JWT could not be decoded.', array( 'status' => 401 ) );
		}

		// Locked algorithm: rejects `none` and algorithm-confusion attacks.
		if ( ( isset( $header['alg'] ) ? $header['alg'] : '' ) !== JwtConfig::ALGORITHM ) {
			return new \WP_Error( 'appza_jwt_bad_signature', 'JWT signature is invalid.', array( 'status' => 401 ) );
		}

		$expected_sig = hash_hmac( 'sha256', $header_b64 . '.' . $payload_b64, JwtConfig::secret(), true );
		if ( ! hash_equals( $expected_sig, $sig ) ) {
			return new \WP_Error( 'appza_jwt_bad_signature', 'JWT signature is invalid.', array( 'status' => 401 ) );
		}

		$now = time();

		if ( isset( $claims['nbf'] ) && (int) $claims['nbf'] > $now ) {
			return new \WP_Error( 'appza_jwt_not_yet_valid', 'JWT is not yet valid.', array( 'status' => 401 ) );
		}
		if ( ! isset( $claims['nbf'] ) && isset( $claims['iat'] ) && (int) $claims['iat'] > $now ) {
			return new \WP_Error( 'appza_jwt_not_yet_valid', 'JWT is not yet valid.', array( 'status' => 401 ) );
		}
		if ( isset( $claims['exp'] ) && (int) $claims['exp'] <= $now ) {
			return new \WP_Error( 'appza_jwt_expired', 'JWT has expired.', array( 'status' => 401 ) );
		}

		if ( ( isset( $claims['iss'] ) ? $claims['iss'] : null ) !== JwtConfig::issuer() ) {
			return new \WP_Error( 'appza_jwt_bad_issuer', 'JWT issuer mismatch.', array( 'status' => 401 ) );
		}
		if ( ( isset( $claims['aud'] ) ? $claims['aud'] : null ) !== JwtConfig::audience() ) {
			return new \WP_Error( 'appza_jwt_bad_audience', 'JWT audience mismatch.', array( 'status' => 401 ) );
		}
		if ( ! in_array( isset( $claims['type'] ) ? $claims['type'] : null, array( JwtConfig::TOKEN_TYPE_ACCESS, JwtConfig::TOKEN_TYPE_GUEST ), true ) ) {
			return new \WP_Error( 'appza_jwt_bad_type', 'JWT type is not access or guest.', array( 'status' => 401 ) );
		}

		return $claims;
	}

	private function encode( array $payload ): string {
		$header        = array( 'typ' => 'JWT', 'alg' => JwtConfig::ALGORITHM );
		$header_b64    = self::base64url_encode( wp_json_encode( $header ) );
		$payload_b64   = self::base64url_encode( wp_json_encode( $payload ) );
		$signing_input = $header_b64 . '.' . $payload_b64;
		$sig           = hash_hmac( 'sha256', $signing_input, JwtConfig::secret(), true );
		return $signing_input . '.' . self::base64url_encode( $sig );
	}

	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function base64url_decode( string $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ), true );
	}
}
