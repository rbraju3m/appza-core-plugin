<?php
/**
 * The All Members screen — the directory, and the one row above it.
 *
 *   GET /wp-json/fcom-mobile/api/v1/members/directory
 *   GET /wp-json/fcom-mobile/api/v1/members/directory/summary
 *
 * Registered under the fcom-mobile namespace from our plugin, like the
 * leaderboard, chat and move-space-options routes. fcom-mobile and
 * FluentCommunity are third-party and are never edited.
 *
 * ---------------------------------------------------------------------
 * Why not fcom-mobile's own `/members`
 * ---------------------------------------------------------------------
 *
 * It asks the right FluentCommunity endpoint — `GET /members`, the one the
 * portal's All Members page asks — and then keeps only `members.data`.
 * Two things the screen needs go with the rest:
 *
 *   1. `members.total`. The title is "Members (N)", and the portal's N is
 *      the total of the CURRENT query, so it moves with the tab and the
 *      search. There is nothing else to count it from.
 *   2. `current_user_follows`. FluentCommunity Pro adds the viewer's follow
 *      levels to that response as a top-level map, and it is the only
 *      place a directory row can learn whether the viewer follows it.
 *
 * So these routes ask FluentCommunity's endpoint in-process and keep both.
 * Asking the endpoint rather than querying XProfile ourselves keeps every
 * rule it applies — the "who can view all members" privacy setting, the
 * status filter only a moderator may use, `whereHas('user')`, and anything
 * hooked on `members_query_ref`.
 *
 * ---------------------------------------------------------------------
 * The menu verdicts are decided here, in the request
 * ---------------------------------------------------------------------
 *
 * Every row carries the answers its ⋮ sheet needs as plain booleans,
 * because a menu row's `visible_when` is a truthiness test with no
 * negation (P159). Each one mirrors the server rule that would refuse the
 * write, so a row that draws is a write that succeeds:
 *
 *   can_follow / can_unfollow   FollowController::follow|unfollow — not
 *                               yourself, module on, signed in. AND the
 *                               member must HAVE a username: fcom-mobile
 *                               resolves `member_id` to one and gives up
 *                               without it, and most members on a site
 *                               migrated from elsewhere have none.
 *   can_set_active|pending|blocked
 *                               MembersController::patchMember — never
 *                               the viewer themself, never the status the
 *                               member already has, and never a
 *                               non-active status on a community admin
 *                               ("Sorry, you cannot change the status of
 *                               another admin").
 *
 * Deciding them INSIDE this callback is deliberate. When the preview proxy
 * asks, it sets the viewer only for the length of the sub-request, and any
 * FluentCommunity helper that reads the current user afterwards answers
 * for a guest (P158). In here, the viewer is still the viewer.
 *
 * ---------------------------------------------------------------------
 * Who sees the status tabs
 * ---------------------------------------------------------------------
 *
 * The portal draws Active / Pending / Blocked for `community_admin` only,
 * and "Deactivated (self)" only when Privacy → "can deactivate account"
 * is on. FluentCommunity's endpoint is looser — it honours `status` for
 * any moderator — so the status param is passed through only for a viewer
 * the portal would show the tabs to. A moderator who is not an admin gets
 * active members, as they do in the portal.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMembersDirectoryController {

	/** The portal's four tabs, by the value FluentCommunity's `status` param takes. */
	private const STATUSES = array( 'active', 'pending', 'blocked', 'deactivated' );

	/** FluentCommunity's own whitelist — anything else becomes last_activity there. */
	private const SORT_FIELDS = array( 'last_activity', 'display_name', 'created_at' );

	/** What the portal asks for. */
	private const DEFAULT_PER_PAGE = 24;

	/**
	 * The portal fires a search only when the term is empty or at least
	 * this long. Applied here rather than in two shells.
	 */
	private const MIN_SEARCH_LENGTH = 3;

	/**
	 * Public read. FluentCommunity's own "who can view all members"
	 * setting decides, and it defaults to everybody.
	 */
	public function permission_check() {
		return PortalGuard::reader();
	}

	/* ---------------------------------------------------------------
	 * GET /members/directory
	 * ------------------------------------------------------------- */

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

		// "Joined 2d" — the catalog's words, our arithmetic. A child binds
		// one text and there is no prefix binding, so the line is composed
		// here; `%s` is replaced rather than handed to sprintf.
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

	/* ---------------------------------------------------------------
	 * GET /members/directory/summary
	 * ------------------------------------------------------------- */

	/**
	 * One row: the count for the title, and the viewer facts the tab strip
	 * is gated on. A list of one, because the preview proxy resolves every
	 * DataSource to an array of items.
	 *
	 * Takes the same params as the directory, so the count is the count of
	 * what the list is showing. Asks for a page of one — the total is on
	 * the paginator whatever the page size.
	 */
	public function summary( \WP_REST_Request $request ) {
		$viewer = $this->viewer_facts();

		// The status strip asks with this flag and gets NO row unless the
		// viewer is one the portal shows the tabs to, so the strip's
		// `hide_when_empty` removes it for everybody else. Answering
		// "no row" rather than a row of false permissions matters: an
		// entry gate fails OPEN on a missing bag, so a row-less answer is
		// the only one a member can never see a tab through.
		if ( $request->get_param( 'for_status_tabs' ) && ! $viewer['is_admin'] ) {
			return $this->ok( array() );
		}

		$answer = $this->ask_fluent_community( $request, $viewer, 1 );
		$total  = ( null !== $answer && isset( $answer['members']['total'] ) )
			? (int) $answer['members']['total']
			: 0;

		// The words belong to the catalog. `%d` is replaced rather than
		// handed to sprintf, so a format string from a request can only
		// ever produce text.
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
					// The strip's entries gate on this bag through
					// `requires_permission`.
					'permissions'              => array(
						'view_member_statuses'     => $viewer['is_admin'],
						'view_deactivated_members' => $viewer['is_admin'] && $viewer['can_deactivate_account'],
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * FluentCommunity
	 * ------------------------------------------------------------- */

	/**
	 * FluentCommunity's `GET /members`, asked in-process. One question per
	 * request (P134).
	 *
	 * @return array<string, mixed>|null  null when FluentCommunity refuses
	 *                                    (privacy) or is absent.
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

		// Empty or unknown leaves FluentCommunity's per-column default in
		// charge — last activity and joining date newest first, name A–Z.
		$sort_dir = strtoupper( sanitize_key( (string) $request->get_param( 'sort_dir' ) ) );
		if ( in_array( $sort_dir, array( 'ASC', 'DESC' ), true ) ) {
			$params['sort_dir'] = $sort_dir;
		}

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

		// FluentCommunity's paginator reads `per_page` straight off
		// $_REQUEST, bypassing the request object — without this it caps
		// every page at 15. Restored before returning.
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

		// FluentCommunity writes a self-deactivated account as an EMPTY
		// status, and its own API calls that "deactivated".
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'active';
		if ( '' === $status ) {
			$status = 'deactivated';
		}

		$is_self = $viewer['id'] > 0 && $user_id === $viewer['id'];

		// Levels arrive from $wpdb as strings: "0" blocked, "1" following,
		// "2" following with notifications.
		$level = array_key_exists( $user_id, $follows ) ? (int) $follows[ $user_id ] : null;

		$may_follow   = $viewer['followers_module'] && $viewer['id'] > 0 && ! $is_self && '' !== $username;
		$can_follow   = $may_follow && null === $level;
		$can_unfollow = $may_follow && null !== $level && $level >= 1;

		$can_manage      = $viewer['is_admin'] && ! $is_self;
		$target_is_admin = $can_manage && $this->is_community_admin( $user_id );

		$can_set_active  = $can_manage && 'active' !== $status;
		$can_set_pending = $can_manage && 'pending' !== $status && ! $target_is_admin;
		$can_set_blocked = $can_manage && 'blocked' !== $status && ! $target_is_admin;

		$badge = $this->first_badge( $meta, $badges );

		return array(
			'user_id'         => $user_id,
			'display_name'    => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
			'username'        => $username,
			// '@name', joined here as the follow rows do — a child binds one
			// text, and a member with no username gets no stray '@'.
			'username_at'     => '' !== $username ? '@' . $username : '',
			'avatar'          => isset( $row['avatar'] ) ? (string) $row['avatar'] : '',
			'is_verified'     => ! empty( $row['is_verified'] ),
			'total_points'    => isset( $row['total_points'] ) ? (int) $row['total_points'] : 0,
			'permalink'       => isset( $row['permalink'] ) ? (string) $row['permalink'] : '',
			// Present only when FluentCommunity lets this viewer see them;
			// the proxy turns created_at into "2d".
			'created_at'      => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			// Empty when FluentCommunity withholds the date, so the line's
			// gate drops it rather than drawing a bare "Joined".
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

			// No ⋮ at all when every row would be hidden — design 6: "on
			// your own profile will not get the 3 dots".
			'has_menu'        => $can_follow || $can_unfollow || $can_set_active || $can_set_pending || $can_set_blocked,
		);
	}

	/**
	 * Who is asking, read once per request while the viewer is still set.
	 *
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

		// The portal's gate for the tabs and the status menu:
		// `appVars.permissions.community_admin`.
		$user = $facts['id'] > 0 ? call_user_func( array( $helper, 'getCurrentUser' ) ) : null;
		if ( $user && method_exists( $user, 'hasCommunityAdminAccess' ) ) {
			$facts['is_admin'] = (bool) $user->hasCommunityAdminAccess();
		}

		if ( class_exists( $utility ) ) {
			// The same expression PortalHandler publishes as
			// `features.can_deactivate_account`.
			$facts['can_deactivate_account'] = 'yes' === call_user_func( array( $utility, 'getPrivacySetting' ), 'can_deactive_account' );
		}

		if ( method_exists( $helper, 'isFeatureEnabled' ) ) {
			$facts['followers_module'] = (bool) call_user_func( array( $helper, 'isFeatureEnabled' ), 'followers_module' );
		}

		return $facts;
	}

	/** The guard patchMember applies to the TARGET. */
	protected function is_community_admin( $user_id ) {
		$model = '\\FluentCommunity\\App\\Models\\User';
		if ( ! class_exists( $model ) ) {
			return false;
		}
		$user = call_user_func( array( $model, 'find' ), (int) $user_id );
		return $user && method_exists( $user, 'isCommunityAdmin' ) && (bool) $user->isCommunityAdmin();
	}

	/**
	 * FluentCommunity Pro's badge definitions, keyed by slug.
	 *
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
	 * The member's first badge that is still defined. The appended `badge`
	 * attribute on XProfile is a filter nothing hooks, so it is always null;
	 * the portal reads `meta.badge_slug` through `user_badges`, and so does
	 * this.
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

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/**
	 * "2d", "3w"-style age, in exactly the steps the preview proxy's
	 * relative_time() uses for every other date in the app — a member card
	 * must not say "1mo" where the profile byline says "30d".
	 */
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

	/** Models, paginators and stdClass all come back from FluentCommunity. */
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
