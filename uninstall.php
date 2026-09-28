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
);

foreach ( $options as $option ) {
	delete_option( $option );
}