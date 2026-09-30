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

	public function request_build( array $body ) {
		return $this->build_call( 'POST', 'builds', $body );
	}

	public function build_status( $id ) {
		return $this->build_call( 'GET', 'builds/' . rawurlencode( $id ) );
	}

	public function build_list( array $ids ) {
		return $this->build_call( 'GET', 'builds?ids=' . rawurlencode( implode( ',', $ids ) ) );
	}

	/** Core's own error message comes back as the WP_Error message, its status as data.status. */
	protected function build_call( $method, $path, ?array $body = null ) {
		$url = $this->url( $path );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( '' === BuildToken::get() ) {
			return new \WP_Error( 'appza_build_no_token', __( 'No build token. Add it on APPZA Core → Sync.', 'appza-core' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_request( $url, array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . BuildToken::get(),
				'User-Agent'    => 'APPZA-Core-Plugin/' . APPZA_CORE_VERSION,
			),
			'body'        => null === $body ? null : wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
			return $data;
		}

		$message = is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] )
			? implode( ' ', array_map( 'strval', array_merge( ...array_values( $data['errors'] ) ) ) )
			: ( is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : ( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '' ) );
		if ( 401 === $status ) {
			$message = __( 'Core refused the build token. Check it on APPZA Core → Sync.', 'appza-core' );
		}

		return new \WP_Error(
			'appza_build_core_error',
			'' !== $message ? $message : sprintf( __( 'Core returned HTTP %d', 'appza-core' ), $status ),
			array( 'status' => in_array( $status, array( 400, 409, 422, 429 ), true ) ? $status : 502 )
		);
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