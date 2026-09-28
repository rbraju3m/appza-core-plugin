<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/users/search
 *
 * Users not already active in the space, for the add-member picker.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityUsersSearchController {

	public function permission_check() {
		return PortalGuard::member();
	}

	public function handle( \WP_REST_Request $request ) {
		global $wpdb;

		$search     = (string) $request->get_param( 'search' );
		$space_slug = (string) $request->get_param( 'space_slug' );
		$per_page   = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 || $per_page > 50 ) {
			$per_page = 20;
		}

		// FC's own search (SpaceController::getOtherUsers): a space the viewer may add members to.
		$space = '' !== $space_slug ? \FluentCommunity\App\Models\Space::where( 'slug', $space_slug )->first() : null;
		$viewer = \FluentCommunity\App\Services\Helper::getCurrentUser();
		if ( ! $space || ! $viewer || ! $space->verifyUserPermisson( $viewer, 'can_add_member', false ) ) {
			return new \WP_Error( 'appza_users_search_forbidden', __( 'You are not allowed to add members to this space.', 'appza-core' ), array( 'status' => 403 ) );
		}
		$show_email = $viewer->isCommunityModerator() && current_user_can( 'list_users' );

		$rows         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}fcom_space_user WHERE space_id = %d AND status = 'active'",
				(int) $space->id
			)
		);
		$excluded_ids = array_map( 'intval', $rows ?: array() );

		$args = array(
			'number'         => $per_page,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'fields'         => array( 'ID', 'display_name', 'user_email', 'user_login' ),
			'exclude'        => $excluded_ids,
			'search_columns' => $show_email
				? array( 'user_login', 'user_email', 'display_name', 'user_nicename' )
				: array( 'user_login', 'display_name', 'user_nicename' ),
		);
		if ( $search !== '' ) {
			$args['search'] = '*' . str_replace( '*', '', $search ) . '*';
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();

		$xprofile = array();
		if ( ! empty( $users ) ) {
			$user_ids = array_map( static fn( $u ) => (int) $u->ID, $users );
			$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, display_name, avatar, username, total_points
					 FROM {$wpdb->prefix}fcom_xprofile WHERE user_id IN ($placeholders)",
					$user_ids
				)
			);
			foreach ( $rows ?: array() as $row ) {
				$xprofile[ (int) $row->user_id ] = $row;
			}
		}

		$items = array();
		foreach ( $users as $user ) {
			$uid  = (int) $user->ID;
			$prof = $xprofile[ $uid ] ?? null;
			$avatar = $prof && $prof->avatar ? $prof->avatar : get_avatar_url( $uid, array( 'size' => 96 ) );
			$items[] = array(
				'id'       => $uid,
				'user_id'  => $uid,
				'xprofile' => array(
					'display_name' => $prof && $prof->display_name ? $prof->display_name : $user->display_name,
					'email'        => $show_email ? $user->user_email : '',
					'user_email'   => $show_email ? $user->user_email : '',
					'user_nicename'=> $user->user_login,
					'username'     => $prof->username ?? $user->user_login,
					'avatar'       => $avatar,
					'total_points' => $prof ? (int) $prof->total_points : 0,
				),
			);
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $items,
			)
		);
	}
}
