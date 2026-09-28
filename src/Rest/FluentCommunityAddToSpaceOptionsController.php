<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/members/add-to-options?user_id=N
 *
 * Spaces and courses the member is NOT in, mirroring the portal's AddUserForm.
 * Empty for non-site-admins, as FC's AdminPolicy refuses the list to them. Courses are
 * omitted when the course module is off (FC doesn't register those write routes then).
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityAddToSpaceOptionsController {

	public function permission_check() {
		return PortalGuard::member();
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

		// The portal's own query, so the order matches what an admin sees there.
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

	/** @param array<string, mixed> $wrapper */
	protected function respond( array $wrapper ) {
		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => 'Success' ),
				'items'    => array( $wrapper ),
			)
		);
	}
}
