<?php
/**
 * Drops a viewer's cached preview-proxy reads the moment that viewer
 * writes something through fcom-mobile.
 *
 * Why this is not merely a freshness nicety: the reaction controls send a
 * TOGGLE, and the client decides which direction to send from the row it
 * is currently holding. A stale row therefore does not just look wrong —
 * tapping the heart on a post the cache still calls "liked" sends an
 * un-like, and the like the user meant to add is silently removed
 * instead. That is a real data loss, reproduced on the real bundle on
 * 2026-08-20 (see bugs_fixed.md).
 *
 * Invalidation is per-viewer and O(1): `PreviewProxyController` carries a
 * generation counter inside every cache key, so incrementing one option
 * makes that viewer's whole cached set unreachable at once. Nothing is
 * enumerated or deleted, which means this behaves identically whether
 * transients live in the options table or in an external object cache.
 * The orphans are already TTL-bounded and expire unread.
 *
 * Deliberately coarse: ANY successful write invalidates ALL of that
 * viewer's cached sources, not just the one the write touched. Working
 * out which DataSources a given fcom-mobile write can affect means a map
 * that has to be maintained in step with the catalog, and the failure
 * mode of getting it wrong is exactly the bug this fixes. The cost of
 * being coarse is a few extra upstream fetches for one user who just
 * demonstrated they are active.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewCacheInvalidator {

	/** Requests that cannot change anything, so cannot invalidate anything. */
	const SAFE_METHODS = array( 'GET', 'HEAD', 'OPTIONS' );

	/** Only writes to FluentCommunity's mobile namespace concern us. */
	const NAMESPACE_PREFIX = '/fcom-mobile/';

	public function register_hooks() {
		// rest_request_after_callbacks, like the sibling enrichers, so it
		// also fires for the internal rest_do_request the simulator's
		// preview proxy makes. Late priority: let every enricher finish
		// before we read the status.
		add_filter( 'rest_request_after_callbacks', array( $this, 'maybe_invalidate' ), 99, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 * @return mixed The response, untouched.
	 */
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
		// A rejected write changed nothing, so the cache is still right.
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
