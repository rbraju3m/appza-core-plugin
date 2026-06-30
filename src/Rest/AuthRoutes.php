<?php
/**
 * Registers REST routes under appza/auth/v1 (DC#13 Q4).
 *
 * All five endpoints are public per the spec — login + refresh + guest +
 * logout don't need a pre-existing session, and /me carries its own
 * Bearer token. The Bearer middleware (chunk 4) skips this namespace
 * entirely so the auth routes can stand on their own.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AuthRoutes {

	public function register_routes() {
		$auth = new AuthController();

		register_rest_route(
			APPZA_CORE_AUTH_REST_NAMESPACE,
			'/login',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'username' => array( 'required' => true, 'type' => 'string' ),
					'password' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_AUTH_REST_NAMESPACE,
			'/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth, 'refresh' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'refresh_token' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_AUTH_REST_NAMESPACE,
			'/logout',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth, 'logout' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'refresh_token' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_AUTH_REST_NAMESPACE,
			'/guest',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $auth, 'guest' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			APPZA_CORE_AUTH_REST_NAMESPACE,
			'/me',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $auth, 'me' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
