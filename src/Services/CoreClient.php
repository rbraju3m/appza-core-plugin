<?php
namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CoreClient {

	/** Returns array( 'not_modified' => true ) when $etag still matches. */
	public function fetch_snapshot( $template_slug, $etag = '' ) {
		$url = $this->url( 'catalog/snapshot?template=' . rawurlencode( $template_slug ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = $this->get( $url, '' !== $etag ? array( 'If-None-Match' => $etag ) : array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 304 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return array( 'not_modified' => true );
		}

		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['etag'] = (string) wp_remote_retrieve_header( $response, 'etag' );

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

	/** $slugs: null = no filter; an empty array = no integrations picked, returns nothing. */
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
		$response = $this->get( $url );
		return is_wp_error( $response ) ? $response : $this->decode( $response );
	}

	protected function get( $url, array $headers = array() ) {
		$response = wp_remote_get( $url, array(
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 10 * MB_IN_BYTES,
			'headers'             => array_merge( array(
				'Accept'     => 'application/json',
				'User-Agent' => 'APPZA-Core-Plugin/' . APPZA_CORE_VERSION,
			), $headers ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status && 304 !== $status ) {
			return new \WP_Error(
				'appza_core_http_error',
				sprintf( __( 'Core returned HTTP %d for %s', 'appza-core' ), $status, $url ),
				array( 'status' => $status )
			);
		}

		return $response;
	}

	protected function decode( $response ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'appza_core_parse_error', __( 'Core response was not valid JSON', 'appza-core' ) );
		}

		return $data;
	}
}