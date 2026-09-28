<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/members/directory[/summary]
 *
 * Asks FluentCommunity's GET /members in-process (keeping all its privacy rules) because
 * fcom-mobile's /members drops members.total and current_user_follows.
 * Menu verdicts are computed inside the callback: the preview proxy sets the viewer only
 * for the sub-request, so FC helpers called later would answer for a guest.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMembersDirectoryController {

	private const STATUSES = array( 'active', 'pending', 'blocked', 'deactivated' );

	/** FluentCommunity's own whitelist — anything else becomes last_activity there. */
	private const SORT_FIELDS = array( 'last_activity', 'display_name', 'created_at' );

	private const DEFAULT_PER_PAGE = 24;

	/** The portal searches only when the term is empty or at least this long. */
	private const MIN_SEARCH_LENGTH = 3;

	public function permission_check() {
		return PortalGuard::reader();
	}

	public function directory( \WP_REST_Request $request ) {
		$viewer = $this->viewer_facts();
		$answer = $this->ask_fluent_community( $request, $viewer, null );
		if ( null === $answer ) {
			return $this->ok( array() );
		}

		$rows    = isset( $answer['members']['data'] ) && is_array( $answer['members']['data'] )
			? $answer['members']['data']
			: array();
		$follows = isset( $answer['current_user_follows'] ) && is_array( $answer['current_user_follows'] )
			? $answer['current_user_follows']
			: array();
		$badges  = $this->badge_definitions();

		// `%s` is str_replace'd, not sprintf'd: the format comes from the request.
		$joined_format = (string) $request->get_param( 'joined_format' );
		if ( '' === $joined_format ) {
			$joined_format = __( 'Joined %s', 'appza-core' );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$row = $this->to_array( $row );
			if ( empty( $row['user_id'] ) ) {
				continue;
			}
			$items[] = $this->directory_row( $row, $viewer, $follows, $badges, $joined_format );
		}

		return $this->ok( $items );
	}

	/**
	 * One row: the title count plus the viewer facts the tab strip is gated on.
	 */
	public function summary( \WP_REST_Request $request ) {
		$viewer = $this->viewer_facts();

		// No row (not a row of false permissions) for non-admins: entry gates fail OPEN on a missing bag.
		if ( $request->get_param( 'for_status_tabs' ) && ! $viewer['is_admin'] ) {
			return $this->ok( array() );
		}

		$answer = $this->ask_fluent_community( $request, $viewer, 1 );
		$total  = ( null !== $answer && isset( $answer['members']['total'] ) )
			? (int) $answer['members']['total']
			: 0;

		// `%d` is str_replace'd, not sprintf'd, so a request-supplied format can only produce text.
		$format = (string) $request->get_param( 'title_format' );
		if ( '' === $format ) {
			$format = __( 'Members (%d)', 'appza-core' );
		}

		return $this->ok(
			array(
				array(
					'total'                    => $total,
					'title'                    => str_replace( '%d', number_format_i18n( $total ), $format ),
					'permission_failed'        => null === $answer,
					'viewer_is_admin'          => $viewer['is_admin'],
					'followers_module_enabled' => $viewer['followers_module'],
					'permissions'              => array(
						'view_member_statuses'     => $viewer['is_admin'],
						'view_deactivated_members' => $viewer['is_admin'] && $viewer['can_deactivate_account'],
					),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>|null  null when FluentCommunity refuses (privacy) or is absent.
	 */
	protected function ask_fluent_community( \WP_REST_Request $request, array $viewer, $per_page_override ) {
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return null;
		}

		$params = array(
			'per_page' => null !== $per_page_override
				? (int) $per_page_override
				: $this->clamp( (int) $request->get_param( 'per_page' ), self::DEFAULT_PER_PAGE, 1, 100 ),
			'page'     => max( 1, (int) $request->get_param( 'page' ) ),
		);

		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		if ( '' !== $search && mb_strlen( $search ) >= self::MIN_SEARCH_LENGTH ) {
			$params['search'] = $search;
		}

		$sort_by = sanitize_key( (string) $request->get_param( 'sort_by' ) );
		if ( in_array( $sort_by, self::SORT_FIELDS, true ) ) {
			$params['sort_by'] = $sort_by;
		}

		$sort_dir = strtoupper( sanitize_key( (string) $request->get_param( 'sort_dir' ) ) );
		if ( in_array( $sort_dir, array( 'ASC', 'DESC' ), true ) ) {
			$params['sort_dir'] = $sort_dir;
		}

		// FC honours `status` for any moderator; the portal shows the tabs to community admins only.
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( in_array( $status, self::STATUSES, true ) && $viewer['is_admin'] ) {
			if ( 'deactivated' !== $status || $viewer['can_deactivate_account'] ) {
				$params['status'] = $status;
			}
		}

		$fc = new \WP_REST_Request( 'GET', '/fluent-community/v2/members' );
		foreach ( $params as $key => $value ) {
			$fc->set_param( $key, $value );
		}
		$fc->set_query_params( $params );

		// FC's paginator reads per_page straight off $_REQUEST (else caps at 15). Restored below.
		$prev_request = $_REQUEST;
		$prev_get     = $_GET;
		foreach ( $params as $key => $value ) {
			$_REQUEST[ $key ] = (string) $value;
			$_GET[ $key ]     = (string) $value;
		}

		$response = rest_do_request( $fc );

		$_REQUEST = $prev_request;
		$_GET     = $prev_get;

		if ( $response->is_error() ) {
			return null;
		}

		$data = $this->to_array( $response->get_data() );
		if ( ! empty( $data['permission_failed'] ) || ! isset( $data['members'] ) ) {
			return null;
		}
		$data['members'] = $this->to_array( $data['members'] );

		return $data;
	}

	/**
	 * Menu verdicts mirror the server rule that would refuse the write
	 * (FollowController::follow|unfollow, MembersController::patchMember).
	 *
	 * @param array<string, mixed>     $row
	 * @param array<string, mixed>     $viewer
	 * @param array<int|string, mixed> $follows  followed_id => level
	 * @param array<string, mixed>     $badges   slug => definition
	 * @return array<string, mixed>
	 */
	protected function directory_row( array $row, array $viewer, array $follows, array $badges, $joined_format = 'Joined %s' ) {
		$user_id  = (int) $row['user_id'];
		$username = isset( $row['username'] ) ? (string) $row['username'] : '';
		$meta     = isset( $row['meta'] ) ? $this->to_array( $row['meta'] ) : array();

		// FC stores a self-deactivated account as an empty status.
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'active';
		if ( '' === $status ) {
			$status = 'deactivated';
		}

		$is_self = $viewer['id'] > 0 && $user_id === $viewer['id'];

		// "0" blocked, "1" following, "2" following with notifications.
		$level = array_key_exists( $user_id, $follows ) ? (int) $follows[ $user_id ] : null;

		// fcom-mobile resolves member_id to a username and gives up without one.
		$may_follow   = $viewer['followers_module'] && $viewer['id'] > 0 && ! $is_self && '' !== $username;
		$can_follow   = $may_follow && null === $level;
		$can_unfollow = $may_follow && null !== $level && $level >= 1;

		$can_manage      = $viewer['is_admin'] && ! $is_self;
		$target_is_admin = $can_manage && $this->is_community_admin( $user_id );

		// patchMember refuses a non-active status on another community admin.
		$can_set_active  = $can_manage && 'active' !== $status;
		$can_set_pending = $can_manage && 'pending' !== $status && ! $target_is_admin;
		$can_set_blocked = $can_manage && 'blocked' !== $status && ! $target_is_admin;

		$badge = $this->first_badge( $meta, $badges );

		return array(
			'user_id'         => $user_id,
			'display_name'    => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
			'username'        => $username,
			'username_at'     => '' !== $username ? '@' . $username : '',
			'avatar'          => isset( $row['avatar'] ) ? (string) $row['avatar'] : '',
			'is_verified'     => ! empty( $row['is_verified'] ),
			'total_points'    => isset( $row['total_points'] ) ? (int) $row['total_points'] : 0,
			'permalink'       => isset( $row['permalink'] ) ? (string) $row['permalink'] : '',
			'created_at'      => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'joined_label'    => ! empty( $row['created_at'] )
				? str_replace( '%s', $this->compact_age( (string) $row['created_at'] ), (string) $joined_format )
				: '',
			'last_activity'   => isset( $row['last_activity'] ) ? (string) $row['last_activity'] : '',
			'headline'        => isset( $meta['headline'] ) ? (string) $meta['headline'] : '',
			'badge_title'     => $badge['title'],
			'badge_emoji'     => $badge['emoji'],
			'badge_logo'      => $badge['logo'],

			'status'          => $status,
			'is_active'       => 'active' === $status,
			'is_pending'      => 'pending' === $status,
			'is_blocked'      => 'blocked' === $status,
			'is_deactivated'  => 'deactivated' === $status,

			'is_self'         => $is_self,
			'follow_level'    => $level,
			'is_following'    => null !== $level && $level >= 1,
			'can_follow'      => $can_follow,
			'can_unfollow'    => $can_unfollow,

			'target_is_admin' => $target_is_admin,
			'can_set_active'  => $can_set_active,
			'can_set_pending' => $can_set_pending,
			'can_set_blocked' => $can_set_blocked,

			'has_menu'        => $can_follow || $can_unfollow || $can_set_active || $can_set_pending || $can_set_blocked,
		);
	}

	/**
	 * @return array{id:int, is_admin:bool, can_deactivate_account:bool, followers_module:bool}
	 */
	protected function viewer_facts() {
		$helper  = '\\FluentCommunity\\App\\Services\\Helper';
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';

		$facts = array(
			'id'                     => (int) get_current_user_id(),
			'is_admin'               => false,
			'can_deactivate_account' => false,
			'followers_module'       => false,
		);

		if ( ! class_exists( $helper ) ) {
			return $facts;
		}

		$user = $facts['id'] > 0 ? call_user_func( array( $helper, 'getCurrentUser' ) ) : null;
		if ( $user && method_exists( $user, 'hasCommunityAdminAccess' ) ) {
			$facts['is_admin'] = (bool) $user->hasCommunityAdminAccess();
		}

		if ( class_exists( $utility ) ) {
			// `can_deactive_account` (sic) is FluentCommunity's real key.
			$facts['can_deactivate_account'] = 'yes' === call_user_func( array( $utility, 'getPrivacySetting' ), 'can_deactive_account' );
		}

		if ( method_exists( $helper, 'isFeatureEnabled' ) ) {
			$facts['followers_module'] = (bool) call_user_func( array( $helper, 'isFeatureEnabled' ), 'followers_module' );
		}

		return $facts;
	}

	protected function is_community_admin( $user_id ) {
		$model = '\\FluentCommunity\\App\\Models\\User';
		if ( ! class_exists( $model ) ) {
			return false;
		}
		$user = call_user_func( array( $model, 'find' ), (int) $user_id );
		return $user && method_exists( $user, 'isCommunityAdmin' ) && (bool) $user->isCommunityAdmin();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function badge_definitions() {
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';
		if ( ! class_exists( $utility ) ) {
			return array();
		}
		$badges = call_user_func( array( $utility, 'getOption' ), 'user_badges', array() );
		return is_array( $badges ) ? $badges : array();
	}

	/**
	 * XProfile's appended `badge` attribute is always null; read meta.badge_slug like the portal.
	 *
	 * @return array{title:string, emoji:string, logo:string}
	 */
	protected function first_badge( array $meta, array $badges ) {
		$none  = array( 'title' => '', 'emoji' => '', 'logo' => '' );
		$slugs = isset( $meta['badge_slug'] ) ? (array) $meta['badge_slug'] : array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || ! isset( $badges[ $slug ] ) ) {
				continue;
			}
			$badge  = $this->to_array( $badges[ $slug ] );
			$config = isset( $badge['config'] ) ? $this->to_array( $badge['config'] ) : array();
			return array(
				'title' => isset( $badge['title'] ) ? (string) $badge['title'] : '',
				'emoji' => isset( $config['emoji'] ) ? (string) $config['emoji'] : '',
				'logo'  => isset( $config['logo'] ) ? (string) $config['logo'] : '',
			);
		}

		return $none;
	}

	/** Same steps as the preview proxy's relative_time(), so ages agree across the app. */
	protected function compact_age( $datetime ) {
		try {
			$then = new \DateTime( $datetime, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $datetime;
		}
		$diff = time() - $then->getTimestamp();
		if ( $diff < 0 ) {
			return $datetime;
		}
		if ( $diff < 60 ) {
			return $diff . 's';
		}
		if ( $diff < 3600 ) {
			return (int) floor( $diff / 60 ) . 'm';
		}
		if ( $diff < 86400 ) {
			return (int) floor( $diff / 3600 ) . 'h';
		}
		if ( $diff < 2592000 ) {
			return (int) floor( $diff / 86400 ) . 'd';
		}
		if ( $diff < 31536000 ) {
			return (int) floor( $diff / 2592000 ) . 'mo';
		}
		return (int) floor( $diff / 31536000 ) . 'y';
	}

	protected function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'toArray' ) ) {
				return (array) $value->toArray();
			}
			return json_decode( wp_json_encode( $value ), true ) ?: array();
		}
		return array();
	}

	protected function clamp( $value, $fallback, $min, $max ) {
		if ( $value <= 0 ) {
			return $fallback;
		}
		return max( $min, min( $max, $value ) );
	}

	protected function ok( array $items ) {
		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => 'Success' ),
				'items'    => array_values( $items ),
			)
		);
	}
}
