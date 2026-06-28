<?php
/**
 * Runs on plug-in activation.
 *
 * Chunk 1 scaffold: seeds option markers only. The `wp_appza_catalog_snapshot`
 * table DDL lands in chunk 2 (Phase 1B.2) — at that point this class delegates
 * to a Schema class in `src/`.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Appza_Core_Activator {

	public static function activate() {
		add_option( 'appza_core_db_version', '0', '', false );
		add_option( 'appza_core_last_pulled_at', '', '', false );
	}
}