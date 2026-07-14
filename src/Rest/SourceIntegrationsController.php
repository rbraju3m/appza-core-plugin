<?php
/**
 * GET /wp-json/appza/v1/source-integrations
 *
 * Powers the admin onboarding wizard "Select App" step. Pulls the active
 * Source Integrations from the Core and merges local WP install state so
 * the UI can render toggle + disabled correctly:
 *
 *   [
 *     { slug, name, addon_plugin_slug, addon_min_version, description,
 *       is_installed, is_disable }
 *   ]
 *
 * - is_installed: the APPZA add-on plug-in for this Source is active in
 *   this WP install (checked via `{slug}/{slug}.php` convention).
 * - is_disable:   !is_installed — the wizard shows the toggle greyed out
 *   with an "install the add-on first" affordance handled client-side.
 *
 * Public-read v1 — no auth. Prod license-key handshake is a separate DC.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SourceIntegrationsController {

	protected $core;

	public function __construct( CoreClient $core = null ) {
		$this->core = $core ?: new CoreClient();
	}

	public function handle( \WP_REST_Request $request ) {
		$envelope = $this->core->fetch_source_integrations();
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// WP stores active plugins as `<folder>/<file>.php`. Folder and main
		// file basename don't have to match (e.g. `tutor-lms-api/tutor-lms-mobile.php`).
		// Extract both halves once so the per-row check is cheap.
		$active_pairs = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || false === strpos( $plugin_file, '/' ) ) {
				continue;
			}
			$folder            = strtok( $plugin_file, '/' );
			$file_basename     = basename( $plugin_file, '.php' );
			$active_pairs[]    = array( $folder, $file_basename );
		}

		$rows = array();
		foreach ( $envelope['source_integrations'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) || empty( $row['addon_plugin_slug'] ) ) {
				continue;
			}
			$addon = (string) $row['addon_plugin_slug'];
			// Matches when the Core's addon_plugin_slug equals either the
			// folder name or the main file basename of any active plug-in.
			$is_installed = false;
			foreach ( $active_pairs as $pair ) {
				if ( $pair[0] === $addon || $pair[1] === $addon ) {
					$is_installed = true;
					break;
				}
			}
			$rows[]       = array(
				'slug'              => (string) $row['slug'],
				'name'              => (string) $row['name'],
				'addon_plugin_slug' => $addon,
				'addon_min_version' => isset( $row['addon_min_version'] ) ? (string) $row['addon_min_version'] : '',
				'description'       => isset( $row['description'] ) ? (string) $row['description'] : '',
				'image_url'         => isset( $row['image_url'] ) ? (string) $row['image_url'] : null,
				'is_installed'      => $is_installed,
				'is_disable'        => ! $is_installed,
			);
		}

		return rest_ensure_response( array(
			'core_api_version'    => isset( $envelope['core_api_version'] ) ? (string) $envelope['core_api_version'] : '',
			'source_integrations' => $rows,
		) );
	}
}
