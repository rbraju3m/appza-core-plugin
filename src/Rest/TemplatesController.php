<?php
/**
 * GET /wp-json/appza/v1/templates — Core templates for the site's active Source Integrations.
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
