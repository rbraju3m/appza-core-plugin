<?php
/**
 * POST /wp-json/fcom-mobile/api/v1/feeds/remove-preview
 *
 * "Remove Preview" from the post 3-dot menu (Noor's design 22).
 *
 * FluentCommunity has this as `DELETE feeds/{id}/media-preview`, but
 * that route lives in FC's OWN namespace behind its own router and is
 * not reachable from ours, and fcom-mobile ships nothing equivalent. So
 * the route is added to the fcom-mobile namespace from here — the same
 * P68 reasoning as /users/search and /spaces/invitations: WordPress does
 * not gate register_rest_route by which plugin owns a namespace, and
 * fcom-mobile is third-party and must stay unmodified.
 *
 * POST rather than DELETE, matching every other fcom-mobile mutation the
 * catalog wires (add-member, remove-member, invitations/delete), so the
 * renderer's HTTP action needs no special case for the verb.
 *
 * The guard is FluentCommunity's own. `FeedsController::deleteMediaPreview`
 * calls `User::canDeleteFeed($feed, true)` — whoever may delete the post
 * may strip its preview — and that same call is made here rather than
 * reasoned about, so the app can never offer a row the API will refuse.
 * The effect is two lines against the same model; calling FC's controller
 * directly would mean constructing its framework Request object.
 *
 * ⚠ There is no undo. FC nulls the meta key and the preview is gone.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedMediaPreviewController {

	const FEED_MODEL = '\\FluentCommunity\\App\\Models\\Feed';
	const USER_MODEL = '\\FluentCommunity\\App\\Models\\User';

	public function permission_check() {
		return is_user_logged_in();
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

		// FluentCommunity's own rule, called rather than replicated. The
		// `true` argument is what makes it THROW on refusal instead of
		// returning false — the same call deleteMediaPreview() makes.
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
			// Already gone. Reported as success rather than 404: the menu
			// row is gated on the preview existing, so arriving here means
			// two taps raced or a stale card was tapped, and neither is an
			// error the person can act on.
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
