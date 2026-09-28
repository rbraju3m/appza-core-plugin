<?php
/**
 * Bumps a viewer's preview-cache generation on any successful fcom-mobile write.
 * Not just freshness: reaction toggles pick their direction from the cached row, so a stale
 * row makes the next tap undo the user's action. Deliberately coarse (all of the viewer's sources).
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewCacheInvalidator {

	const SAFE_METHODS = array( 'GET', 'HEAD', 'OPTIONS' );

	const NAMESPACE_PREFIX = '/fcom-mobile/';

	public function register_hooks() {
		// Late priority so every enricher has finished before the status is read.
		add_filter( 'rest_request_after_callbacks', array( $this, 'maybe_invalidate' ), 99, 3 );
	}

	public function maybe_invalidate( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( in_array( strtoupper( $request->get_method() ), self::SAFE_METHODS, true ) ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), self::NAMESPACE_PREFIX ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) {
			return $response;
		}

		PreviewProxyController::bump_generation(
			PreviewProxyController::auth_bucket_for( $request )
		);

		return $response;
	}
}
