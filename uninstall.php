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
	'appza_core_site_template',
	'appza_core_tutorial_video_url',
	'appza_core_demo_app',
	'appza_core_app_identity', // P1c; the icon and splash stay in the media library.
);

foreach ( $options as $option ) {
	delete_option( $option );
}

delete_metadata( 'user', 0, 'appza_core_tour_seen', '', true );
delete_metadata( 'user', 0, 'appza_core_intro_hidden', '', true );

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE 'appza\\_preview\\_gen\\_%'
	OR option_name LIKE '\\_transient\\_appza\\_preview\\_%'
	OR option_name LIKE '\\_transient\\_timeout\\_appza\\_preview\\_%'"
);
