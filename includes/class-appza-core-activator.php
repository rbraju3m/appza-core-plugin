<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

use AppzaCore\Plugin\Schema\SchemaManager;

class Appza_Core_Activator {

	const CRON_PURGE_REFRESH_TOKENS = 'appza_core_purge_refresh_tokens';

	// FC creates the Media row at upload, so an unsent chat image leaves an orphan row and file.
	const CRON_SWEEP_CHAT_MEDIA = 'appza_core_sweep_chat_media';

	public static function activate() {
		add_option( 'appza_core_db_version', '0', '', false );
		add_option( 'appza_core_last_pulled_at', '', '', false );

		SchemaManager::install();

		if ( ! wp_next_scheduled( self::CRON_PURGE_REFRESH_TOKENS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE_REFRESH_TOKENS );
		}

		if ( ! wp_next_scheduled( self::CRON_SWEEP_CHAT_MEDIA ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_SWEEP_CHAT_MEDIA );
		}
	}
}