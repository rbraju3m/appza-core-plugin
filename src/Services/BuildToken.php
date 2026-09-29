<?php
/**
 * The token Core wants on /builds: the APPZA_CORE_BUILD_TOKEN constant (wp-config) wins,
 * then the appza_core_build_token option. Never echoed back to the page.
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BuildToken {

	const OPTION = 'appza_core_build_token';

	public static function source() {
		if ( defined( 'APPZA_CORE_BUILD_TOKEN' ) ) {
			return 'constant';
		}
		return '' !== self::get() ? 'option' : 'none';
	}

	public static function get() {
		if ( defined( 'APPZA_CORE_BUILD_TOKEN' ) ) {
			return (string) APPZA_CORE_BUILD_TOKEN;
		}
		return (string) get_option( self::OPTION, '' );
	}
}
