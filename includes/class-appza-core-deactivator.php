<?php
/** Non-destructive by design; uninstall.php drops data. */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS );
		wp_clear_scheduled_hook( Appza_Core_Activator::CRON_SWEEP_CHAT_MEDIA );
	}
}