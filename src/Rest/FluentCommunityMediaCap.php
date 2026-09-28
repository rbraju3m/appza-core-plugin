<?php
/**
 * FC's processFeedMetaData() slices media to max_media_per_post (default 4) on update and
 * deactivates the rest, so posts with more images lose them on the first mobile edit.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMediaCap {

	/** Only raises the site's setting, never lowers it. */
	const CAP = 20;

	public function register_hooks() {
		add_filter( 'fluent_community/max_media_per_post', [ $this, 'ensure_cap' ], 20 );
	}

	public function ensure_cap( $current ) {
		$currentInt = (int) $current;
		return $currentInt < self::CAP ? self::CAP : $currentInt;
	}
}
