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

		$isAuthor    = false;
		$canModerate = false;
		$canEdit     = false;
		$canDelete   = false;
		$space       = null;

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

			$canEdit   = $this->can_edit( $currentUser, $feed, $space );
			$canDelete = $this->can_delete( $currentUser, $isAuthor, $space );
		}

		// Every verdict below mirrors FluentCommunity's OWN rule, read out
		// of its code rather than reasoned about — so the app offers a menu
		// row exactly when the portal would, and never one the API will
		// refuse. The three that are NOT simply `is_author`:
		//
		//   edit             Feed::hasEditAccess()  — author OR the
		//                    `edit_any_feed` permission, space-scoped when
		//                    the post lives in a space.
		//   delete           User::canDeleteFeed()  — author OR
		//                    `delete_any_feed`, community OR space scope.
		//   disable comments FeedsController::patchFeed() — author OR
		//                    moderator OR admin. Pin (top + sidebar) is
		//                    moderator-only in the same method, which is
		//                    why it is `can_moderate` and this is not.
		//
		// This used to read `is_author` for all three, which hid Edit and
		// Delete from an admin looking at someone else's post — the exact
		// case Noor's design 22 turns on.
		$feed['permissions'] = [
			'is_author'            => $isAuthor,
			'can_edit'             => $canEdit,
			'can_delete'           => $canDelete,
			'can_moderate'         => $canModerate,
			'can_pin'              => $canModerate,
			'can_report'           => (bool) $currentUser && ! $isAuthor,
			'can_disable_comments' => $isAuthor || $canModerate,
			// "Remove Preview" (design 22). FluentCommunity guards
			// deleteMediaPreview() with canDeleteFeed(), the same rule as
			// deleting the post — so whoever may delete the post may strip
			// its preview. The MENU row is additionally gated on the post
			// actually having one; that is a property of the post, not of
			// the viewer, so it is answered in the catalog's visible_when
			// rather than folded in here.
			'can_remove_preview'   => $canDelete,
		];

		// Each inline COMMENT gets its own bag. Noor's item 24 gives the
		// comment menu a different matrix from the post's, and it is a
		// different matrix in FluentCommunity too:
		//
		//   edit    CommentsController::update() — author OR
		//           `edit_any_comment` in the post's space.
		//   delete  CommentsController::delete() — author OR
		//           `delete_any_comment` in the post's space.
		//   pin     CommentsController::patch()  — moderator or admin
		//           ONLY. Not the author, which is where this differs
		//           most from the post menu.
		//   report  any signed-in reader who did not write it.
		if ( ! empty( $feed['comments'] ) && is_array( $feed['comments'] ) ) {
			foreach ( $feed['comments'] as $i => $comment ) {
				if ( is_array( $comment ) ) {
					$feed['comments'][ $i ]['permissions'] = $this->comment_permissions(
						$comment,
						$currentUser,
						$space,
						$canModerate
					);
				}
			}
		}

		return $feed;
	}

	/**
	 * @param array                                     $comment
	 * @param \FluentCommunity\App\Models\User|null   $user
	 * @param \FluentCommunity\App\Models\Space|null  $space  The POST's space — a comment has none of its own.
	 * @param bool                                      $canModerate
	 * @return array<string, bool>
	 */
	protected function comment_permissions( array $comment, $user, $space, $canModerate ) {
		if ( ! $user ) {
			// Signed out: every verdict false, which collapses the menu
			// to Copy link on its own — the same rule the post menu
			// follows for a guest (design 22's logout case).
			return [
				'is_author'  => false,
				'can_edit'   => false,
				'can_delete' => false,
				'can_pin'    => false,
				'can_report' => false,
			];
		}

		$isAuthor = isset( $comment['user_id'] ) && (int) $comment['user_id'] === (int) $user->ID;

		$mayEdit   = $isAuthor;
		$mayDelete = $isAuthor;
		if ( ! $isAuthor && $space ) {
			$mayEdit   = (bool) $user->can( 'edit_any_comment', $space );
			$mayDelete = (bool) $user->can( 'delete_any_comment', $space );
		}

		return [
			'is_author'  => $isAuthor,
			'can_edit'   => $mayEdit,
			'can_delete' => $mayDelete,
			'can_pin'    => (bool) $canModerate,
			'can_report' => ! $isAuthor,
		];
	}

	/**
	 * Feed::hasEditAccess(), reimplemented against the ROW rather than the
	 * model — the row is what we have, and re-finding every Feed to ask it
	 * would be one query per card.
	 *
	 * @param \FluentCommunity\App\Models\User        $user
	 * @param array                                     $feed
	 * @param \FluentCommunity\App\Models\Space|null  $space
	 */
	protected function can_edit( $user, array $feed, $space ) {
		if ( isset( $feed['user_id'] ) && (int) $feed['user_id'] === (int) $user->ID ) {
			return true;
		}

		// A post with a space asks the space; a profile post asks the
		// community. FluentCommunity branches on `space_id` being set, not
		// on the space resolving — a post pointing at a deleted space is
		// not silently promoted to a community-wide question.
		if ( ! empty( $feed['space_id'] ) ) {
			return $space ? (bool) $user->hasSpacePermission( 'edit_any_feed', $space ) : false;
		}

		return (bool) $user->hasCommunityPermission( 'edit_any_feed' );
	}

	/**
	 * User::canDeleteFeed(). Unlike edit, FluentCommunity checks BOTH
	 * scopes here — a community-level `delete_any_feed` reaches a post in
	 * any space.
	 *
	 * @param \FluentCommunity\App\Models\User        $user
	 * @param bool                                      $isAuthor
	 * @param \FluentCommunity\App\Models\Space|null  $space
	 */
	protected function can_delete( $user, $isAuthor, $space ) {
		if ( $isAuthor ) {
			return true;
		}
		if ( $user->hasCommunityPermission( 'delete_any_feed' ) ) {
			return true;
		}

		return $space ? (bool) $user->hasSpacePermission( 'delete_any_feed', $space ) : false;
	}
}
