<?php
/**
 * Plugin Name:       APPZA Core 2.0
 * Plugin URI:        https://github.com/rbraju3m/appza-core-plugin
 * Description:       APPZA 2.0 base WordPress plug-in. Serves runtime JSON to the APPZA mobile app via a single bootstrap endpoint, stores the Lazycoders catalog snapshot locally, and hosts customer-side customizations.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Lazycoders
 * Author URI:        https://lazycoders.co
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       appza-core
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'APPZA_CORE_VERSION', '2.0.0' );
define( 'APPZA_CORE_PLUGIN_FILE', __FILE__ );
define( 'APPZA_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APPZA_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'APPZA_CORE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'APPZA_CORE_API_URL' ) ) {
	define( 'APPZA_CORE_API_URL', 'http://localhost/appza-core-2.0/api/v1' );
}

if ( ! defined( 'APPZA_CORE_REST_NAMESPACE' ) ) {
	define( 'APPZA_CORE_REST_NAMESPACE', 'appza/v1' );
}

if ( ! defined( 'APPZA_CORE_CONTRACTS_VERSION' ) ) {
	define( 'APPZA_CORE_CONTRACTS_VERSION', '2.0.0' );
}

$appza_core_composer_autoload = APPZA_CORE_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $appza_core_composer_autoload ) ) {
	require_once $appza_core_composer_autoload;
}

require_once APPZA_CORE_PLUGIN_DIR . 'includes/class-appza-core-autoloader.php';
Appza_Core_Autoloader::register();

require_once APPZA_CORE_PLUGIN_DIR . 'includes/class-appza-core-activator.php';
require_once APPZA_CORE_PLUGIN_DIR . 'includes/class-appza-core-deactivator.php';

register_activation_hook( __FILE__, array( 'Appza_Core_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Appza_Core_Deactivator', 'deactivate' ) );

function appza_core_run() {
	$plugin = new Appza_Core_Plugin();
	$plugin->run();
}
appza_core_run();