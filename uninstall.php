<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'appza_catalog_snapshot',
	$wpdb->prefix . 'appza_catalog_meta',
	$wpdb->prefix . 'appza_customizations',
	$wpdb->prefix . 'appza_space_config',
	$wpdb->prefix . 'appza_refresh_tokens',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'appza_core_db_version',
	'appza_core_last_pulled_at',
	'appza_core_last_pulled_from',
	'appza_core_api_url',
	'appza_core_active_source_integrations',
	'appza_core_admin_notice',
	'appza_core_snapshot_etags',
	'appza_core_build_token',
	'appza_build_requests_log',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE 'appza\\_preview\\_gen\\_%'
	OR option_name LIKE '\\_transient\\_appza\\_preview\\_%'
	OR option_name LIKE '\\_transient\\_timeout\\_appza\\_preview\\_%'"
);
