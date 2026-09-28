<?php
/** Fallback when vendor/autoload.php is absent: AppzaCore\Plugin\* from src/, Appza_Core_* from includes/. */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load_psr4' ) );
		spl_autoload_register( array( __CLASS__, 'load_underscore' ) );
	}

	public static function load_psr4( $class ) {
		$prefix   = 'AppzaCore\\Plugin\\';
		$base_dir = APPZA_CORE_PLUGIN_DIR . 'src/';

		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	public static function load_underscore( $class ) {
		if ( strpos( $class, 'Appza_Core_' ) !== 0 ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class ) );
		$file = APPZA_CORE_PLUGIN_DIR . 'includes/class-' . $slug . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}