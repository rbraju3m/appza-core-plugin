<?php
/**
 * Hooks `rest_request_after_callbacks` to attach a `permissions` bag to every
 * FluentCommunity feed row returned by fcom-mobile's REST endpoints.
 *
 * The bag drives the mobile 3-dot menu's per-item visibility (Edit, Delete,
 * Pin, Report, Disable/Enable Comments). Computed server-side using
 * FluentCommunity's own capability helpers so results match what the web
 * portal would show for the same viewer.
 *
 * Runs inside dispatch() (not post_dispatch) so the filter fires for the
 * internal `rest_do_request` calls made by PreviewProxyController for the
 * WP-admin simulator — `rest_post_dispatch` only fires for the outer
 * serve_request and would miss sub-requests entirely. Also covers direct
 * mobile-app calls to `/wp-json/fcom-mobile/*` (dispatch runs the same
 * filter on any route).
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedPermissionsFilter {

	/**
	 * fcom-mobile routes whose response payload contains one or more feed
	 * rows we should enrich. Read routes return an `items` array or the
	 * single feed row directly; write routes (create/patch/update) return
	 * a single row under `items`.
	 */
	const ENRICHABLE_ROUTES = [
		'/fcom-mobile/api/v1/feeds',
		'/fcom-mobile/api/v1/feeds/create',
		'/fcom-mobile/api/v1/feeds/patch',
		'/fcom-mobile/api/v1/feeds/update',
		'/fcom-mobile/api/v1/feeds/by/slug',
		// Saved Posts (2026-08-19). The same rows as /feeds, filtered to
		// the viewer's bookmarks — and the 3-dot sheet on that screen is
		// gated entirely on the bag computed below. Without this line
		// every saved post offers every menu item to everybody, which is
		// exactly the difference Noor's design 4 turns on.
		'/fcom-mobile/api/v1/feeds/bookmarks',
	];

	public function register_hooks() {
		// rest_request_after_callbacks fires inside WP_REST_Server::dispatch()
		// after the route callback returns — so we catch both the outer
		// serve_request() dispatch AND any rest_do_request() sub-calls (which
		// only ever go through dispatch, never through serve_request).
		add_filter( 'rest_request_after_callbacks', [ $this, 'attach_permissions' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 * @return mixed
	 */
	public function attach_permissions( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( ! in_array( $route, self::ENRICHABLE_ROUTES, true ) ) {
			return $response;
		}
		// FluentCommunity must be active — otherwise the Space/User classes
		// we lean on for the capability check won't exist and this filter is
		// a silent no-op.
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if ( ! isset( $data['items'] ) ) {
			return $response;
		}
		$items = $data['items'];

		// An EMPTY list is a list, and must stay one.
		//
		// Without this, `looks_like_row_list([])` says false (there is no
		// first key to inspect), the single-row branch below runs, and a
		// zero-row response comes back as
		// `items: {permissions: {...}}` — one object where there were no
		// rows. Everything downstream then counts one row: hide_when_empty
		// never fires, the empty-state verdict never publishes, and the
		// screen paints a ghost card instead of "No matches".
		//
		// Caught on /feeds/bookmarks, where an empty result is an ordinary
		// state (search for something you have not saved). /feeds has the
		// same shape and the same latent bug; it simply never returns zero
		// rows on a community with any content in it.
		if ( is_array( $items ) && empty( $items ) ) {
			return $response;
		}

		$currentUser = null;
		$currentUserResolved = false;
		$spaceCache = [];

		// items may be an array of rows (list) OR a single row (create/patch/update).
		if ( is_array( $items ) && $this->looks_like_row_list( $items ) ) {
			foreach ( $items as $i => $row ) {
				if ( is_array( $row ) ) {
					$items[ $i ] = $this->add_permissions_bag( $row, $currentUser, $currentUserResolved, $spaceCache );
				}
			}
		} elseif ( is_array( $items ) ) {
			$items = $this->add_permissions_bag( $items, $currentUser, $currentUserResolved, $spaceCache );
		}

		$data['items'] = $items;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Heuristic — is $items a list of feed rows (numeric keys, first entry
	 * has feed-shape) rather than a single feed row (associative keys)?
	 * fcom-mobile's list endpoint returns a plain numeric-indexed array;
	 * single-row endpoints return an associative array with `id`/`user_id`.
	 */
	protected function looks_like_row_list( array $items ) {
		if ( empty( $items ) ) {
			return false;
		}
		$firstKey = array_key_first( $items );
		return is_int( $firstKey );
	}

	/**
	 * Compute + attach the permissions bag on a single feed row.
	 *
	 * @param array                                  $feed
	 * @param \FluentCommunity\App\Models\User|null  $currentUser         Resolved lazily, reused across rows.
	 * @param bool                                   $currentUserResolved
	 * @param array<int, \FluentCommunity\App\Models\Space|null> $spaceCache
	 */
	protected function add_permissions_bag(
		array $feed,
		&$currentUser,
		&$currentUserResolved,
		array &$spaceCache
	) {
		if ( ! $currentUserResolved ) {
			$currentUser = \FluentCommunity\App\Services\Helper::getCurrentUser() ?: null;
			$currentUserResolved = true;
		}

		$isAuthor = false;
		$canModerate = false;

		if ( $currentUser ) {
			$isAuthor = isset( $feed['user_id'] ) && (int) $feed['user_id'] === (int) $currentUser->ID;

			$spaceId = isset( $feed['space_id'] ) ? (int) $feed['space_id'] : 0;
			if ( $spaceId > 0 ) {
				if ( ! array_key_exists( $spaceId, $spaceCache ) ) {
					$spaceCache[ $spaceId ] = \FluentCommunity\App\Models\Space::find( $spaceId ) ?: null;
				}
				$space = $spaceCache[ $spaceId ];
				if ( $space ) {
					$isMod   = $currentUser->hasPermissionOrInCurrentSpace( 'community_moderator', $space );
					$isAdmin = $currentUser->hasPermissionOrInCurrentSpace( 'community_admin', $space );
					$canModerate = $isMod || $isAdmin;
				}
			}
		}

		// Author-only for edit / delete / comments-toggle — even a space
		// moderator or admin cannot mutate a post they didn't write. Pin
		// (top + sidebar) stays moderator-scoped since those are curation
		// actions on the space itself, not on the post's content. Report
		// is available to any logged-in non-author.
		$feed['permissions'] = [
			'is_author'            => $isAuthor,
			'can_edit'             => $isAuthor,
			'can_delete'           => $isAuthor,
			'can_moderate'         => $canModerate,
			'can_pin'              => $canModerate,
			'can_report'           => $currentUser && ! $isAuthor,
			'can_disable_comments' => $isAuthor,
		];

		return $feed;
	}
}
