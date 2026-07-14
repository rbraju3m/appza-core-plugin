<?php
/**
 * GET  /wp-json/appza/v1/active-source-integrations
 *   → { slugs: string[] }  — the Source Integrations the site owner has
 *     activated via the onboarding wizard "Select App" step. Empty array
 *     on first install so the wizard auto-opens.
 *
 * POST /wp-json/appza/v1/active-source-integrations
 *   Body: { slugs: string[] } — full replacement of the active set. The
 *   list is validated against the Core's authoritative Source Integrations
 *   endpoint; unknown slugs are dropped silently rather than 400ing so a
 *   stale UI can't wedge itself.
 *
 * Storage: WP option `appza_core_active_source_integrations` (array of
 * slugs). Kept out of the wp_appza_* table space since this is per-site
 * config, not catalog data.
 *
 * Mutating writes require the standard REST cookie nonce (X-WP-Nonce)
 * and `manage_options` capability — same pattern as CustomizationsController.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class ActiveSourceIntegrationsController {

	const OPTION_KEY = 'appza_core_active_source_integrations';

	protected $core;

	public function __construct( CoreClient $core = null ) {
		$this->core = $core ?: new CoreClient();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function get( \WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'slugs' => $this->read_slugs(),
		) );
	}

	public function put( \WP_REST_Request $request ) {
		$incoming = $request->get_param( 'slugs' );
		if ( ! is_array( $incoming ) ) {
			return new \WP_Error(
				'appza_active_source_bad_shape',
				__( 'slugs must be an array of strings', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		$incoming = array_values( array_unique( array_filter( array_map( 'sanitize_title', $incoming ) ) ) );

		$envelope = $this->core->fetch_source_integrations();
		if ( ! is_wp_error( $envelope ) ) {
			$known = array();
			foreach ( $envelope['source_integrations'] as $row ) {
				if ( ! empty( $row['slug'] ) ) {
					$known[] = (string) $row['slug'];
				}
			}
			$incoming = array_values( array_intersect( $incoming, $known ) );
		}

		update_option( self::OPTION_KEY, $incoming, false );

		return rest_ensure_response( array( 'slugs' => $incoming ) );
	}

	protected function read_slugs() {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $raw ) ) );
	}
}
