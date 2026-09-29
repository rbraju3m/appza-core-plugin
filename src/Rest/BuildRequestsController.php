<?php
/**
 * POST /wp-json/appza/v1/build-requests       — ask Core for a QA APK of this site
 * GET  /wp-json/appza/v1/build-requests/{id}  — its status, and the download link once done
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BuildRequestsController {

	const OPTION_KEY = 'appza_build_requests_log';
	const MAX_KEEP   = 50;

	protected $client;

	public function __construct( ?CoreClient $client = null ) {
		$this->client = $client ?: new CoreClient();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function create( \WP_REST_Request $request ) {
		$si_slug    = sanitize_title( (string) $request->get_param( 'source_integration_slug' ) );
		$tpl_slug   = sanitize_title( (string) $request->get_param( 'template_slug' ) );
		$mode       = (string) $request->get_param( 'base_url_mode' );
		$custom_url = $request->get_param( 'custom_base_url' );

		if ( '' === $si_slug ) {
			return new \WP_Error( 'appza_build_bad_si', __( 'source_integration_slug is required', 'appza-core' ), array( 'status' => 400 ) );
		}
		if ( '' === $tpl_slug ) {
			return new \WP_Error( 'appza_build_bad_template', __( 'template_slug is required', 'appza-core' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $mode, array( 'current', 'custom' ), true ) ) {
			return new \WP_Error( 'appza_build_bad_mode', __( 'base_url_mode must be "current" or "custom"', 'appza-core' ), array( 'status' => 400 ) );
		}

		$site = 'current' === $mode ? (string) get_site_url() : ( is_string( $custom_url ) ? trim( $custom_url ) : '' );
		$site = untrailingslashit( $site );
		if ( ! preg_match( '#^https://[A-Za-z0-9.-]+(:[0-9]+)?$#', $site ) ) {
			return new \WP_Error(
				'appza_build_bad_url',
				'current' === $mode
					? sprintf( __( 'This site is %s. A phone needs an https address it can reach; use a custom URL.', 'appza-core' ), $site )
					: __( 'The custom URL must be an https address with no path, e.g. https://example.com', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		$active = get_option( ActiveSourceIntegrationsController::OPTION_KEY, array() );
		if ( is_array( $active ) && ! in_array( $si_slug, $active, true ) ) {
			return new \WP_Error( 'appza_build_si_inactive', __( 'That Source Integration is not currently activated on this site.', 'appza-core' ), array( 'status' => 409 ) );
		}

		$user   = wp_get_current_user();
		$result = $this->client->request_build( array(
			'site_url'                => $site,
			'template_slug'           => $tpl_slug,
			'source_integration_slug' => $si_slug,
			'requested_by'            => 'wp:' . $user->user_login . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->append_log( array(
			'id'            => (string) $result['id'],
			'site_url'      => $site,
			'template_slug' => $tpl_slug,
			'requested_by'  => get_current_user_id(),
			'requested_at'  => gmdate( 'c' ),
		) );

		return rest_ensure_response( $result );
	}

	public function show( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $id ) ) {
			return new \WP_Error( 'appza_build_bad_id', __( 'Unknown build.', 'appza-core' ), array( 'status' => 404 ) );
		}

		$result = $this->client->build_status( $id );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	protected function append_log( array $row ) {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $row;
		if ( count( $log ) > self::MAX_KEEP ) {
			$log = array_slice( $log, -1 * self::MAX_KEEP );
		}
		update_option( self::OPTION_KEY, $log, false );
	}
}
