<?php
/**
 * GET /wp-json/appza/v1/templates
 *
 * Powers the plug-in admin's "Template" dropdown. Reads the site's
 * activated Source Integrations from the local option
 * (`appza_core_active_source_integrations`) and asks the Core for the
 * Templates tied to those Sources.
 *
 * Empty active set → returns an empty templates array (matches the
 * onboarding-not-done UX; the dropdown will show a "pick an app first"
 * hint client-side).
 *
 * Response shape mirrors the Core's — `{ core_api_version, templates[] }`.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class TemplatesController {

	protected $core;

	public function __construct( CoreClient $core = null ) {
		$this->core = $core ?: new CoreClient();
	}

	public function handle( \WP_REST_Request $request ) {
		$active = get_option( ActiveSourceIntegrationsController::OPTION_KEY, array() );
		$slugs  = is_array( $active ) ? array_values( array_filter( array_map( 'strval', $active ) ) ) : array();

		$envelope = $this->core->fetch_templates( $slugs );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		return rest_ensure_response( array(
			'core_api_version' => isset( $envelope['core_api_version'] ) ? (string) $envelope['core_api_version'] : '',
			'templates'        => $envelope['templates'],
		) );
	}
}
