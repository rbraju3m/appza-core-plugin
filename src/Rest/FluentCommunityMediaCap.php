<?php
/**
 * Raises FluentCommunity's `max_media_per_post` cap so posts with more
 * than 4 uploaded images survive an edit.
 *
 * Why this is needed: FC's `FeedsHelper::processFeedMetaData()` calls
 *   apply_filters('fluent_community/max_media_per_post', 4)
 * on both create and update. On update it then does:
 *   $allMediaItems = array_slice($uploadedMediaItems, 0, $cap);
 *   ...
 *   Media::where('feed_id', $existingFeed->id)
 *        ->whereNotIn('id', $keptIds)
 *        ->update(['is_active' => 0]);
 * — so if the incoming payload has 7 images but the cap is 4, three of
 * the existing media rows get deactivated. Posts created outside the
 * mobile app (via the FC portal directly, or from a previous cap value)
 * can carry more than 4 images and would silently lose them on the
 * first mobile edit.
 *
 * Lives here rather than editing the fluent-community plugin so
 * third-party sources stay untouched (project rule).
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMediaCap {

	/**
	 * New upper bound. 20 comfortably covers every post currently in the
	 * customer's feed and matches the "many images per post" ceiling
	 * users expect from social feeds; raise further if we see posts hit
	 * this. The floor stays at whatever the site's `max_media_per_post`
	 * customization setting resolves to — we only bump it if it's lower.
	 */
	const CAP = 20;

	public function register_hooks() {
		add_filter( 'fluent_community/max_media_per_post', [ $this, 'ensure_cap' ], 20 );
	}

	public function ensure_cap( $current ) {
		$currentInt = (int) $current;
		return $currentInt < self::CAP ? self::CAP : $currentInt;
	}
}
