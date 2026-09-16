<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/members/add-to-options?user_id=N
 *
 * The rows behind a member profile's "Add to Space or Course" sheet
 * (doc state 49): every space and every course the member is NOT already
 * in, in two lists.
 *
 * Registered under the fcom-mobile namespace, like our move-space,
 * chat, documents and leaderboard routes. fcom-mobile wraps both WRITES
 * (`spaces/add-member`, `courses/student/add`) and the memberships read,
 * but not the list the portal builds its picker from.
 *
 * What the portal does, and what this mirrors (AddUserForm in app.js):
 *
 *   list        GET admin/all_space_courses  — AdminPolicy, isSiteAdmin()
 *   memberships GET profile/{username}/memberships — active pivots
 *   exclude     SpaceCourseSelector.isMemberOfSpace
 *
 * One route rather than the shell stitching two reads together, because
 * the exclusion is a rule and rules live on the server, where both shells
 * get the same answer.
 *
 * The list is EMPTY for anyone who is not a site admin, exactly as
 * FluentCommunity's own AdminPolicy refuses the list to them. The row
 * that opens this sheet is gated on the same verdict (`can_add_to_space`
 * on fc-member-profile), so an empty answer is a race, not a UI state.
 *
 * Courses are left out when the course module is off: FluentCommunity
 * does not even register `admin/courses/*` then, so every course row
 * would be a write that 404s.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityAddToSpaceOptionsController {

	public function permission_check() {
		return is_user_logged_in();
	}

	public function handle( \WP_REST_Request $request ) {
		$wrapper = array(
			'spaces'  => array(),
			'courses' => array(),
		);

		$helper = '\\FluentCommunity\\App\\Services\\Helper';
		if ( ! class_exists( '\\FluentCommunity\\App\\Models\\BaseSpace' ) || ! class_exists( $helper ) ) {
			return $this->respond( $wrapper );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 || ! call_user_func( array( $helper, 'isSiteAdmin' ) ) ) {
			return $this->respond( $wrapper );
		}

		global $wpdb;
		$member_of = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT space_id FROM {$wpdb->prefix}fcom_space_user WHERE user_id = %d AND status = 'active'",
					$user_id
				)
			)
		);

		$types = array( 'community' );
		if ( call_user_func( array( $helper, 'isFeatureEnabled' ), 'course_module' ) ) {
			$types[] = 'course';
		}

		// The portal's own query — withoutGlobalScopes, both types, by
		// serial — so the order here is the order an admin sees there.
		$rows = \FluentCommunity\App\Models\BaseSpace::query()
			->withoutGlobalScopes()
			->whereIn( 'type', $types )
			->orderBy( 'serial', 'ASC' )
			->get();

		foreach ( $rows as $space ) {
			if ( in_array( (int) $space->id, $member_of, true ) ) {
				continue;
			}
			$row = array(
				'id'    => (string) $space->id,
				'slug'  => (string) $space->slug,
				'title' => (string) $space->title,
				'type'  => (string) $space->type,
			);
			if ( 'course' === $space->type ) {
				$wrapper['courses'][] = $row;
			} else {
				$wrapper['spaces'][] = $row;
			}
		}

		return $this->respond( $wrapper );
	}

	/**
	 * @param array<string, mixed> $wrapper
	 */
	protected function respond( array $wrapper ) {
		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => 'Success' ),
				'items'    => array( $wrapper ),
			)
		);
	}
}
