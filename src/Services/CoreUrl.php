<?php
/**
 * Which Core the plug-in talks to: the APPZA_CORE_API_URL constant
 * (wp-config) wins, then the appza_core_api_url option, else none.
 * https only; plain http is allowed for local hosts.
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CoreUrl {

	const OPTION = 'appza_core_api_url';

	public static function source() {
		if ( defined( 'APPZA_CORE_API_URL' ) ) {
			return 'constant';
		}
		return '' !== (string) get_option( self::OPTION, '' ) ? 'option' : 'none';
	}

	public static function raw() {
		if ( defined( 'APPZA_CORE_API_URL' ) ) {
			return (string) APPZA_CORE_API_URL;
		}
		return (string) get_option( self::OPTION, '' );
	}

	/** @return string|\WP_Error the validated base URL, no trailing slash */
	public static function get() {
		$raw = self::raw();
		if ( '' === trim( $raw ) ) {
			return new \WP_Error( 'appza_core_url_missing', __( 'Core URL not set. Add it on APPZA Core > Sync.', 'appza-core' ) );
		}
		return self::validate( $raw );
	}

	/** @return string|\WP_Error */
	public static function validate( $url ) {
		$url = untrailingslashit( trim( (string) $url ) );
		if ( '' === $url ) {
			return new \WP_Error( 'appza_core_url_missing', __( 'Core URL is empty.', 'appza-core' ) );
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( '' === $host || ! in_array( $scheme, array( 'https', 'http' ), true ) || esc_url_raw( $url ) !== $url ) {
			return new \WP_Error( 'appza_core_url_invalid', __( 'Core URL is not a valid http(s) URL.', 'appza-core' ) );
		}

		if ( 'http' === $scheme && ! self::is_local_host( $host ) ) {
			return new \WP_Error( 'appza_core_url_insecure', __( 'Core URL must use https (http is allowed only for local hosts).', 'appza-core' ) );
		}

		return $url;
	}

	protected static function is_local_host( $host ) {
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return true;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(local|test|localhost)$/', $host );
	}
}
