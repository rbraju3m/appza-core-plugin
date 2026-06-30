<?php
/**
 * Runs on plug-in activation. Seeds option markers and installs the plug-in's
 * tables via the SchemaManager.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use AppzaCore\Plugin\Schema\SchemaManager;

class Appza_Core_Activator {

	const CRON_PURGE_REFRESH_TOKENS = 'appza_core_purge_refresh_tokens';

	public static function activate() {
		add_option( 'appza_core_db_version', '0', '', false );
		add_option( 'appza_core_last_pulled_at', '', '', false );

		SchemaManager::install();

		if ( ! wp_next_scheduled( self::CRON_PURGE_REFRESH_TOKENS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE_REFRESH_TOKENS );
		}
	}
}