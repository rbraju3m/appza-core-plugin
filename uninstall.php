<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'appza_catalog_snapshot',
	$wpdb->prefix . 'appza_catalog_meta',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'appza_core_db_version',
	'appza_core_last_pulled_at',
	'appza_core_snapshot_etags',
	'appza_core_build_token',
	'appza_build_requests_log',
);

foreach ( $options as $option ) {
	delete_option( $option );
}