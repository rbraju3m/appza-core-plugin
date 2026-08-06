<?php
/**
 * CRUD wrapper for wp_appza_space_config. Consumed by the admin
 * editor + the REST controller that persists edits. Enricher reads
 * this directly via wpdb for the hot path — no additional caching
 * layer at this scale (<= dozen spaces per site).
 */

namespace AppzaCore\Plugin\Repository;

use AppzaCore\Plugin\Schema\SpaceConfigSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SpaceConfigRepository {

	/**
	 * @return array<int, array{space_slug:string, config:array<string,mixed>, updated_at:string}>
	 */
	public function all() {
		global $wpdb;
		$table = SpaceConfigSchema::table_name();
		$rows  = $wpdb->get_results( "SELECT space_slug, config_json, updated_at FROM {$table} ORDER BY space_slug ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$decoded  = json_decode( (string) ( $r['config_json'] ?? '' ), true );
			$out[]    = array(
				'space_slug' => (string) $r['space_slug'],
				'config'     => is_array( $decoded ) ? $decoded : array(),
				'updated_at' => (string) $r['updated_at'],
			);
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null Config array (may be empty), or null if no row.
	 */
	public function get( string $slug ) {
		global $wpdb;
		$table = SpaceConfigSchema::table_name();
		$json  = $wpdb->get_var(
			$wpdb->prepare( "SELECT config_json FROM {$table} WHERE space_slug = %s LIMIT 1", $slug )
		);
		if ( ! is_string( $json ) ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Insert or update the row for $slug with the given config array.
	 * Empty $config removes the row so the enricher returns the "no
	 * overrides" empty object cleanly.
	 */
	public function upsert( string $slug, array $config ): bool {
		global $wpdb;
		$table = SpaceConfigSchema::table_name();
		if ( empty( $config ) ) {
			return false !== $wpdb->delete( $table, array( 'space_slug' => $slug ), array( '%s' ) );
		}
		$json = wp_json_encode( $config );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE space_slug = %s LIMIT 1", $slug )
		);
		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				array( 'config_json' => $json ),
				array( 'space_slug' => $slug ),
				array( '%s' ),
				array( '%s' )
			);
		}
		return false !== $wpdb->insert(
			$table,
			array( 'space_slug' => $slug, 'config_json' => $json ),
			array( '%s', '%s' )
		);
	}

	/**
	 * List every FluentCommunity space (slug + title) so the admin
	 * editor can present a picker. Reads wp_fcom_spaces directly to
	 * avoid pulling FC's whole model layer for one query.
	 *
	 * @return array<int, array{slug:string, title:string}>
	 */
	public function list_spaces() {
		global $wpdb;
		$table = $wpdb->prefix . 'fcom_spaces';
		$rows  = $wpdb->get_results( "SELECT slug, title FROM {$table} WHERE status = 'published' ORDER BY title ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'slug'  => (string) ( $r['slug'] ?? '' ),
				'title' => (string) ( $r['title'] ?? '' ),
			);
		}
		return $out;
	}
}
