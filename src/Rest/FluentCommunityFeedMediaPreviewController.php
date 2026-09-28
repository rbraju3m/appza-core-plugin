<?php
/**
 * POST /wp-json/fcom-mobile/api/v1/feeds/remove-preview
 *
 * FC's own DELETE feeds/{id}/media-preview is unreachable from WP REST. Guarded by FC's
 * canDeleteFeed, exactly as its deleteMediaPreview does. No undo.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedMediaPreviewController {

	const FEED_MODEL = '\\FluentCommunity\\App\\Models\\Feed';
	const USER_MODEL = '\\FluentCommunity\\App\\Models\\User';

	public function permission_check() {
		return PortalGuard::member();
	}

	public function destroy( \WP_REST_Request $request ) {
		if ( ! class_exists( self::FEED_MODEL ) || ! class_exists( self::USER_MODEL ) ) {
			return new \WP_Error(
				'appza_fc_missing',
				__( 'FluentCommunity is not available.', 'appza-core' ),
				array( 'status' => 501 )
			);
		}

		$feed_id = (int) $request->get_param( 'feed_id' );
		if ( $feed_id <= 0 ) {
			return new \WP_Error(
				'appza_bad_request',
				__( 'A feed_id is required.', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		$feed = call_user_func( array( self::FEED_MODEL, 'find' ), $feed_id );
		if ( ! $feed ) {
			return new \WP_Error(
				'appza_not_found',
				__( 'Post not found.', 'appza-core' ),
				array( 'status' => 404 )
			);
		}

		$user = call_user_func( array( self::USER_MODEL, 'find' ), get_current_user_id() );
		if ( ! $user ) {
			return new \WP_Error(
				'appza_forbidden',
				__( 'You are not allowed to do that.', 'appza-core' ),
				array( 'status' => 403 )
			);
		}

		// `true` makes it throw on refusal instead of returning false.
		try {
			$user->canDeleteFeed( $feed, true );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'appza_forbidden',
				$e->getMessage() ? $e->getMessage() : __( 'You are not allowed to do that.', 'appza-core' ),
				array( 'status' => 403 )
			);
		}

		$meta = (array) $feed->meta;
		if ( empty( $meta['media_preview'] ) ) {
			// Success, not 404: only a race or stale card gets here.
			return array(
				'response' => array(
					'status'  => 200,
					'message' => __( 'This post has no preview image.', 'appza-core' ),
				),
				'feed_id'  => $feed_id,
			);
		}

		$meta['media_preview'] = null;
		$feed->meta            = $meta;
		$feed->save();

		return array(
			'response' => array(
				'status'  => 200,
				'message' => __( 'Media preview image has been removed successfully.', 'appza-core' ),
			),
			'feed_id'  => $feed_id,
		);
	}
}
