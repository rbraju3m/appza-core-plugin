<?php
/**
 * Orchestrator. Wires the i18n loader and the REST routes registered under
 * appza/v1. The admin shell (chunk 4) hooks in here next.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use AppzaCore\Plugin\Rest\RestRoutes;

class Appza_Core_Plugin {

	protected $loader;

	public function __construct() {
		$this->loader = new Appza_Core_Loader();
		$this->set_locale();
		$this->define_rest_hooks();
	}

	protected function set_locale() {
		$i18n = new Appza_Core_I18n();
		$this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
	}

	protected function define_rest_hooks() {
		$rest = new RestRoutes();
		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );
	}

	public function run() {
		$this->loader->run();
	}

	public function get_loader() {
		return $this->loader;
	}
}