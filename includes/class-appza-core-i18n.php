<?php
/**
 * Loads the plug-in's text domain for translations.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_I18n {

	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'appza-core',
			false,
			dirname( APPZA_CORE_PLUGIN_BASENAME ) . '/languages/'
		);
	}
}