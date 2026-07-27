<?php
/**
 * Hooks `rest_post_dispatch` to attach a `permissions` bag to every FluentCommunity
 * feed row returned by fcom-mobile's REST endpoints.
 *
 * The bag drives the mobile 3-dot menu's per-item visibility (Edit, Delete,
 * Pin, Report, Disable/Enable Comments). Computed server-side using
 * FluentCommunity's own capability helpers so results match what the web
 * portal would show for the same viewer.
 *
 * Runs post-dispatch so we don't own the fcom-mobile endpoint definitions —
 * we just inspect + patch the outgoing response. Fires for both external
 * mobile calls to `/wp-json/fcom-mobile/*` and internal `rest_do_request`
 * calls from PreviewProxyController (WP-admin simulator path).
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
	];

	public function register_hooks() {
		add_filter( 'rest_post_dispatch', [ $this, 'attach_permissions' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response $response
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public function attach_permissions( $response, $server, $request ) {
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
