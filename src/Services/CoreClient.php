<?php
/**
 * HTTP client to the APPZA Core SaaS catalog-export endpoint.
 *
 * Base URL comes from CoreUrl (wp-config constant, then the Sync-page
 * setting). The Core-side endpoint is `GET /catalog/snapshot
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
		$url = $this->url( 'catalog/snapshot?template=' . rawurlencode( $template_slug ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$data = $this->get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
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

	/**
	 * Fetches Templates from the Core. When $slugs is a non-empty array,
	 * the Core filters to Templates whose Source Integration slug is in
	 * that list. Pass an empty array explicitly to signal "no integrations
	 * picked → return nothing"; pass null to omit the filter (all active).
	 */
	public function fetch_templates( $slugs = null ) {
		$url = $this->url( 'catalog/templates' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( is_array( $slugs ) ) {
			$url .= '?source_integrations=' . rawurlencode( implode( ',', $slugs ) );
		}

		$data = $this->get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! array_key_exists( 'templates', $data ) || ! is_array( $data['templates'] ) ) {
			return new \WP_Error(
				'appza_core_shape_error',
				__( 'Core response missing templates array', 'appza-core' )
			);
		}

		return $data;
	}

	/**
	 * Fetches the active Source Integrations list from the Core.
	 * Returns the decoded envelope `{ core_api_version, source_integrations[] }`
	 * or a WP_Error on transport/shape failure.
	 */
	public function fetch_source_integrations() {
		$url = $this->url( 'catalog/source-integrations' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$data = $this->get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! array_key_exists( 'source_integrations', $data ) || ! is_array( $data['source_integrations'] ) ) {
			return new \WP_Error(
				'appza_core_shape_error',
				__( 'Core response missing source_integrations array', 'appza-core' )
			);
		}

		return $data;
	}

	protected function url( $path ) {
		$base = CoreUrl::get();
		return is_wp_error( $base ) ? $base : $base . '/' . $path;
	}

	protected function get_json( $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 10 * MB_IN_BYTES,
			'headers'             => array(
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

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'appza_core_parse_error', __( 'Core response was not valid JSON', 'appza-core' ) );
		}

		return $data;
	}
}