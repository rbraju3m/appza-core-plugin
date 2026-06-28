<?php
/**
 * Orchestrator. Wires the i18n loader (chunk 1) and is the single seam where
 * REST routes (chunk 3) + the admin shell (chunk 4) get registered as those
 * land.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_Plugin {

	protected $loader;

	public function __construct() {
		$this->loader = new Appza_Core_Loader();
		$this->set_locale();
	}

	protected function set_locale() {
		$i18n = new Appza_Core_I18n();
		$this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
	}

	public function run() {
		$this->loader->run();
	}

	public function get_loader() {
		return $this->loader;
	}
}