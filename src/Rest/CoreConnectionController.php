<?php
/**
 * GET/POST /wp-json/appza/v1/core-connection — the wizard's "Connect" step (Friendly v2 P2, owner: keep the
 * address field). GET checks the saved address; POST saves a new one (same rules as the Sync page) and checks it.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;
use AppzaCore\Plugin\Services\CoreUrl;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CoreConnectionController {

	protected $client;

	public function __construct( CoreClient $client = null ) {
		$this->client = $client;
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function get( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->status() );
	}

	public function put( \WP_REST_Request $request ) {
		if ( 'constant' === CoreUrl::source() ) {
			return new \WP_Error(
				'appza_core_url_locked',
				__( 'This site\'s APPZA address is set in wp-config.php and cannot be changed here.', 'appza-core' ),
				array( 'status' => 409 )
			);
		}
		$url = CoreUrl::validate( sanitize_text_field( (string) $request->get_param( 'url' ) ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_Error( $url->get_error_code(), $url->get_error_message(), array( 'status' => 400 ) );
		}
		update_option( CoreUrl::OPTION, $url, false );
		return rest_ensure_response( $this->status() );
	}

	protected function status() {
		$client = $this->client ?: new CoreClient();
		$result = $client->fetch_source_integrations();
		return array(
			'url'       => CoreUrl::raw(),
			'locked'    => 'constant' === CoreUrl::source(),
			'connected' => ! is_wp_error( $result ),
			'message'   => is_wp_error( $result ) ? $result->get_error_message() : '',
		);
	}
}
