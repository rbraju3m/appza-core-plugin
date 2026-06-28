<?php
/**
 * Registers every REST route under the appza/v1 namespace.
 *
 * Routes added here are wired into WP via the `rest_api_init` action by the
 * orchestrator. Chunk 3 ships /bootstrap; /customizations, /auth/* land in
 * later Phase 1B slices.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RestRoutes {

	public function register_routes() {
		$bootstrap = new BootstrapController();

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/bootstrap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $bootstrap, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'template' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}
}