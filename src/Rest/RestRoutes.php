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
		$bootstrap      = new BootstrapController();
		$customizations = new CustomizationsController();

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

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/customizations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $customizations, 'list' ),
					'permission_callback' => array( $customizations, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $customizations, 'upsert' ),
					'permission_callback' => array( $customizations, 'permission_check' ),
					'args'                => array(
						'scope'                 => array( 'required' => true, 'type' => 'string' ),
						'target_slug'           => array( 'type' => 'string' ),
						'target_slug_composite' => array( 'type' => 'string' ),
						'target_column'         => array( 'required' => true, 'type' => 'string' ),
						'override_value'        => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/customizations/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $customizations, 'delete' ),
				'permission_callback' => array( $customizations, 'permission_check' ),
			)
		);
	}
}