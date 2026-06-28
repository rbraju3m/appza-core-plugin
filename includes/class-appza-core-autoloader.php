<?php
/**
 * Autoloader for APPZA Core plug-in classes.
 *
 * Handles two class-naming conventions:
 *
 *   1. PSR-4 namespaced classes under `AppzaCore\Plugin\` → loaded from `src/`.
 *      Used by the modern code surface (REST controllers, services, repositories).
 *
 *   2. Legacy underscore class names `Appza_Core_*` → loaded from `includes/`.
 *      Used by the WP-Plugin-Boilerplate-style orchestrator + activation classes.
 *
 * Composer's autoloader is preferred when vendor/autoload.php is present (see main
 * plug-in file). This fallback keeps the plug-in runnable without a composer install.
 */

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