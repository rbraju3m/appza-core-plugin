<?php
/**
 * Runs on plug-in deactivation. Intentionally minimal — uninstall.php handles
 * destructive teardown (drop tables, delete options). Deactivation should be
 * non-destructive so a customer can re-activate without data loss.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_Deactivator {

	public static function deactivate() {
		// no-op
	}
}