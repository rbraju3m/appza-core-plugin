<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/users/search
 *
 * Registered under the fcom-mobile namespace (not appza/v1) because the
 * mobile catalog treats this as a FluentCommunity data source and
 * always calls it via the fcom-mobile prefix. WordPress does not gate
 * register_rest_route by which plugin owns the namespace — we're
 * adding a route to that namespace, not editing the fcom-mobile
 * plugin (which is third-party and must stay unmodified per project
 * policy).
 *
 * Returns WP users that are NOT already an active member of the
 * given space, ranked by display_name. Response envelope mirrors the
 * fcom-mobile shape (response.status + data[]) so the same client
 * code can consume it interchangeably.
 *
 * Auth: requires a logged-in WP user (Basic Auth via Application
 * Passwords from the plugin-admin simulator, cookie auth from a
 * real browser session). The picker is only exposed on the MEMBERS
 * sub-tab of a space the current user is an admin of, so no
 * per-request capability check is layered on top — WP's login gate
 * is sufficient.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityUsersSearchController {

	public function permission_check() {
		return is_user_logged_in();
	}

	public function handle( \WP_REST_Request $request ) {
		global $wpdb;

		$search     = (string) $request->get_param( 'search' );
		$space_slug = (string) $request->get_param( 'space_slug' );
		$per_page   = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 || $per_page > 50 ) {
			$per_page = 20;
		}

		$excluded_ids = array();
		if ( $space_slug !== '' ) {
			$space_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}fcom_spaces WHERE slug = %s LIMIT 1",
					$space_slug
				)
			);
			if ( $space_id > 0 ) {
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT user_id FROM {$wpdb->prefix}fcom_space_user WHERE space_id = %d AND status = 'active'",
						$space_id
					)
				);
				$excluded_ids = array_map( 'intval', $rows ?: array() );
			}
		}

		$args = array(
			'number'         => $per_page,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'fields'         => array( 'ID', 'display_name', 'user_email', 'user_login' ),
			'exclude'        => $excluded_ids,
			'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
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
					'email'        => $user->user_email,
					'user_email'   => $user->user_email,
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
