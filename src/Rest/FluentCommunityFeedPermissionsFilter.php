<?php
/**
 * Attaches a `permissions` bag (3-dot menu verdicts) to fcom-mobile feed and comment rows,
 * using FluentCommunity's own capability rules so the app matches the portal.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedPermissionsFilter {

	const ENRICHABLE_ROUTES = [
		'/fcom-mobile/api/v1/feeds',
		'/fcom-mobile/api/v1/feeds/create',
		'/fcom-mobile/api/v1/feeds/patch',
		'/fcom-mobile/api/v1/feeds/update',
		'/fcom-mobile/api/v1/feeds/by/slug',
		'/fcom-mobile/api/v1/feeds/bookmarks',
	];

	// Rows are comments, not feeds: no space_id of their own, so they are scoped by the post's space.
	const COMMENT_LIST_ROUTE = '/fcom-mobile/api/v1/feeds/comments';

	public function register_hooks() {
		// Not rest_post_dispatch: that misses rest_do_request() sub-calls from the preview proxy.
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
		$isCommentList = ( self::COMMENT_LIST_ROUTE === $route );
		if ( ! $isCommentList && ! in_array( $route, self::ENRICHABLE_ROUTES, true ) ) {
			return $response;
		}
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

		// An empty list must stay a list; the single-row branch would turn it into one ghost row.
		if ( is_array( $items ) && empty( $items ) ) {
			return $response;
		}

		$currentUser = null;
		$currentUserResolved = false;
		$spaceCache = [];

		if ( $isCommentList ) {
			$data['items'] = $this->attach_comment_list_permissions( $items );
			$response->set_data( $data );

			return $response;
		}

		// items may be a list of rows or a single row (create/patch/update).
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

	protected function looks_like_row_list( array $items ) {
		if ( empty( $items ) ) {
			return false;
		}
		$firstKey = array_key_first( $items );
		return is_int( $firstKey );
	}

	/**
	 * @param array                                  $feed
	 * @param \FluentCommunity\App\Models\User|null  $currentUser
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
		$canMove     = false;
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
			// FC's API rule, not its edit modal's: FeedsController::update requires
			// community_admin to move a post; the portal shows the checkbox to moderators too.
			$canMove = (bool) $currentUser->hasPermissionOrInCurrentSpace( 'community_admin', $space );
		}

		// Pin is moderator-only in FeedsController::patchFeed(); disabling comments also allows the author.
		$feed['permissions'] = [
			'is_author'            => $isAuthor,
			'can_edit'             => $canEdit,
			'can_delete'           => $canDelete,
			'can_moderate'         => $canModerate,
			'can_pin'              => $canModerate,
			'can_report'           => (bool) $currentUser && ! $isAuthor,
			'can_disable_comments' => $isAuthor || $canModerate,
			// FC guards deleteMediaPreview() with canDeleteFeed().
			'can_remove_preview'   => $canDelete,
			'can_move_space'       => $canMove,
			// Not can_moderate: /moderation/reports only answers community-wide moderators.
			'can_review_reports'   => $this->can_review_reports( $currentUser ),
		];

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
	 * All rows belong to one post, so the post's space is looked up once.
	 *
	 * @param  array $items
	 * @return array
	 */
	protected function attach_comment_list_permissions( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$user = \FluentCommunity\App\Services\Helper::getCurrentUser() ?: null;

		$space       = null;
		$canModerate = false;

		if ( $user ) {
			$postId = 0;
			foreach ( $items as $row ) {
				if ( is_array( $row ) && ! empty( $row['post_id'] ) ) {
					$postId = (int) $row['post_id'];
					break;
				}
			}
			if ( $postId > 0 && class_exists( '\FluentCommunity\App\Models\Feed' ) ) {
				$feed = \FluentCommunity\App\Models\Feed::withoutGlobalScopes()->find( $postId );
				if ( $feed && ! empty( $feed->space_id ) ) {
					$space = \FluentCommunity\App\Models\Space::find( (int) $feed->space_id ) ?: null;
				}
			}
			if ( $space ) {
				$canModerate = $user->hasPermissionOrInCurrentSpace( 'community_moderator', $space )
					|| $user->hasPermissionOrInCurrentSpace( 'community_admin', $space );
			}
		}

		foreach ( $items as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[ $i ]['permissions'] = $this->comment_permissions( $row, $user, $space, $canModerate );
		}

		return $items;
	}

	/**
	 * Mirrors FC's CommentsController: edit/delete = author or *_any_comment in the post's space;
	 * pin = moderator/admin only (not the author).
	 *
	 * @param array                                     $comment
	 * @param \FluentCommunity\App\Models\User|null   $user
	 * @param \FluentCommunity\App\Models\Space|null  $space  The POST's space — a comment has none of its own.
	 * @param bool                                      $canModerate
	 * @return array<string, bool>
	 */
	protected function comment_permissions( array $comment, $user, $space, $canModerate ) {
		if ( ! $user ) {
			return [
				'is_author'          => false,
				'can_edit'           => false,
				'can_delete'         => false,
				'can_pin'            => false,
				'can_report'         => false,
				'can_review_reports' => false,
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
			'can_review_reports' => $this->can_review_reports( $user ),
		];
	}

	/**
	 * Same predicate fcom-mobile's /moderation/reports gates on; fails closed.
	 *
	 * @param \FluentCommunity\App\Models\User|null $user
	 */
	protected function can_review_reports( $user ) {
		if ( ! $user || ! method_exists( $user, 'hasCommunityModeratorAccess' ) ) {
			return false;
		}
		return (bool) $user->hasCommunityModeratorAccess();
	}

	/**
	 * Feed::hasEditAccess(), evaluated on the row to avoid a query per card.
	 *
	 * @param \FluentCommunity\App\Models\User        $user
	 * @param array                                     $feed
	 * @param \FluentCommunity\App\Models\Space|null  $space
	 */
	protected function can_edit( $user, array $feed, $space ) {
		if ( isset( $feed['user_id'] ) && (int) $feed['user_id'] === (int) $user->ID ) {
			return true;
		}

		// FC branches on space_id being set, not on the space resolving.
		if ( ! empty( $feed['space_id'] ) ) {
			return $space ? (bool) $user->hasSpacePermission( 'edit_any_feed', $space ) : false;
		}

		return (bool) $user->hasCommunityPermission( 'edit_any_feed' );
	}

	/**
	 * User::canDeleteFeed(): unlike edit, a community-level delete_any_feed reaches any space.
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
