<?php
// Makes the saved core_hash of one test layout stale, as a Core change would. Run by builder.sh.
global $wpdb;
$table = $wpdb->prefix . 'appza_customizations';
echo (int) $wpdb->query( $wpdb->prepare(
	"UPDATE {$table} SET override_value = REPLACE(override_value, %s, %s) WHERE scope = 'template_screen' AND target_slug = 'home' AND target_slug_composite = %s AND target_column = 'placements'",
	'"core_hash":"',
	'"core_hash":"stale',
	getenv( 'A14_LAYOUT_TEMPLATE' )
) ) . "\n";
