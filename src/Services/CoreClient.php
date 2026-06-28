<?php
/**
 * HTTP client to the APPZA Core SaaS catalog-export endpoint.
 *
 * Endpoint URL is built from the APPZA_CORE_API_URL constant (overridable per
 * install via wp-config.php). The Core-side endpoint is `GET /catalog/snapshot
 * ?template=<slug>` and is expected to return:
 *
 *   {
 *     catalog_snapshot_version: <bigint>,
 *     core_api_version:        "<semver>",
 *     snapshot: { template, superstructures[], appzets[], data_sources[],
 *                 actions[], primitives[], primitive_props[], app_map,
 *                 source_integration }
 *   }
 *
 * The Core-side endpoint is NOT yet implemented (Phase 1B-Core mirror).
 * Until it ships, calls return a WP_Error and the admin dashboard surfaces
 * the failure to the operator without silently faking success.
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CoreClient {

	public function fetch_snapshot( $template_slug ) {
		$url = trailingslashit( APPZA_CORE_API_URL ) . 'catalog/snapshot?template=' . rawurlencode( $template_slug );

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'APPZA-Core-Plugin/' . APPZA_CORE_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error(
				'appza_core_http_error',
				sprintf( __( 'Core returned HTTP %d for %s', 'appza-core' ), $status, $url ),
				array( 'status' => $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'appza_core_parse_error', __( 'Core response was not valid JSON', 'appza-core' ) );
		}

		foreach ( array( 'catalog_snapshot_version', 'core_api_version', 'snapshot' ) as $required ) {
			if ( ! array_key_exists( $required, $data ) ) {
				return new \WP_Error(
					'appza_core_shape_error',
					sprintf( __( 'Core response missing required key: %s', 'appza-core' ), $required )
				);
			}
		}

		return $data;
	}
}