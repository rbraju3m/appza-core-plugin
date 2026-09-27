<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/feeds/move-space-options?feed_id=N
 *
 * The destination list behind the edit screen's "Move to a different
 * space" checkbox.
 *
 * Registered under the fcom-mobile namespace, like our users-search,
 * chat, documents, invitations and leaderboard routes — WordPress does
 * not gate register_rest_route by which plugin owns a namespace, and the
 * fcom-mobile plugin itself is third-party and stays unmodified.
 *
 * ⚠ This is NOT the create-post picker with a filter on it, and the
 * difference is the whole point. `/spaces/create-post-information-lean`
 * answers with the spaces the VIEWER may post in
 * (`appVars.auth.spaces`), which is what FluentCommunity's own edit
 * modal offers — and its own API then refuses half of them:
 *
 *   FeedsController::update, line 573
 *     if (!Helper::isUserInSpace($existingFeed->user_id, $newSpaceId))
 *         → "The author is not a member of the selected space"
 *
 * So the list is the POST AUTHOR's active memberships, not the viewer's.
 * The viewer's own half of the rule is applied too — `community_admin`
 * on the destination, from the very next check in the same method — so
 * every row this route emits is a move the server will actually accept.
 *
 * Response shape is deliberately identical to the composer picker's:
 *
 *   items: [ { spaces: [ { group_name, spaces: [ … ] } ], viewer: {…} } ]
 *
 * because the renderer's `fetchPostSpacePayload` already parses that,
 * and a second normaliser for one extra caller is a second thing to
 * drift (P100).
 *
 * The post's CURRENT space is left out. FluentCommunity's own select
 * renders it disabled; a sheet row that cannot be tapped says less than
 * a list that only offers real destinations, and the chip already shows
 * where the post is now.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMoveSpaceOptionsController {

	public function permission_check() {
		return PortalGuard::member();
	}

	public function handle( \WP_REST_Request $request ) {
		$empty = array(
			'response' => array( 'status' => 200, 'message' => 'Success' ),
			'items'    => array(
				array(
					'spaces'             => array(),
					'viewer'             => array( 'is_moderator' => false ),
					'can_move_to_profile' => false,
					'profile_label'      => __( "On author's profile", 'appza-core' ),
				),
			),
		);

		if ( ! class_exists( '\\FluentCommunity\\App\\Models\\Feed' ) ) {
			return rest_ensure_response( $empty );
		}

		$feed_id = (int) $request->get_param( 'feed_id' );
		if ( $feed_id <= 0 ) {
			return rest_ensure_response( $empty );
		}

		$feed = \FluentCommunity\App\Models\Feed::find( $feed_id );
		if ( ! $feed ) {
			return rest_ensure_response( $empty );
		}

		$viewer = \FluentCommunity\App\Services\Helper::getCurrentUser();
		if ( ! $viewer ) {
			return rest_ensure_response( $empty );
		}

		$current_space_id = (int) $feed->space_id;
		$author_id        = (int) $feed->user_id;

		// The author's ACTIVE memberships — FluentCommunity's own
		// `Helper::isUserInSpace` is exactly this query, so building the
		// list from it means the offer and the acceptance cannot
		// disagree.
		global $wpdb;
		$space_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$wpdb->prefix}fcom_space_user WHERE user_id = %d AND status = 'active'",
				$author_id
			)
		);
		$space_ids = array_values(
			array_filter(
				array_map( 'intval', $space_ids ?: array() ),
				function ( $id ) use ( $current_space_id ) {
					return $id > 0 && $id !== $current_space_id;
				}
			)
		);

		$is_moderator = (bool) \FluentCommunity\App\Services\Helper::isModerator()
			|| (bool) \FluentCommunity\App\Services\Helper::isSiteAdmin();

		$groups = $space_ids ? $this->build_groups( $space_ids, $viewer, $is_moderator ) : array();

		// "On my profile" / "On author's profile" — FluentCommunity's own
		// two strings, chosen the same way its edit modal chooses them.
		// The row is offered only when the post is currently IN a space
		// (there is nowhere to move it from otherwise — FC disables the
		// option on `!feed.space_id`) and when the site allows posts that
		// belong to no space at all.
		$global_posts = class_exists( '\\FluentCommunity\\App\\Services\\Helper' )
			&& (bool) call_user_func( array( '\\FluentCommunity\\App\\Services\\Helper', 'hasGlobalPost' ) );

		$wrapper = array(
			'spaces'              => $groups,
			'viewer'              => array(
				'is_authenticated' => true,
				'is_moderator'     => $is_moderator,
			),
			'can_move_to_profile' => $current_space_id > 0 && $global_posts,
			'profile_label'       => $author_id === (int) $viewer->ID
				? __( 'On my profile', 'appza-core' )
				: __( "On author's profile", 'appza-core' ),
		);

		return rest_ensure_response(
			array(
				'response' => array( 'status' => 200, 'message' => 'Success' ),
				'items'    => array( $wrapper ),
			)
		);
	}

	/**
	 * The author's spaces, grouped the way the portal groups them, with
	 * every row the composer picker's normaliser expects.
	 *
	 * @param array<int, int> $space_ids
	 * @param object          $viewer
	 * @param bool            $is_moderator
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_groups( array $space_ids, $viewer, $is_moderator ) {
		$spaces = \FluentCommunity\App\Models\Space::whereIn( 'id', $space_ids )
			->where( 'status', 'published' )
			->get();

		if ( method_exists( '\\FluentCommunity\\App\\Models\\BaseSpace', 'preloadMemberships' ) ) {
			\FluentCommunity\App\Models\BaseSpace::preloadMemberships( $spaces, $viewer->ID );
		}

		$rows       = array();
		$parent_ids = array();

		foreach ( $spaces as $space ) {
			// The viewer's half of FluentCommunity's rule. Its own edit
			// modal skips this and lets the API say no; we would rather
			// not draw the row.
			if ( ! $viewer->hasPermissionOrInCurrentSpace( 'community_admin', $space ) ) {
				continue;
			}

			$settings   = (array) $space->settings;
			$permissions = (array) $viewer->getSpacePermissions( $space );
			$parent_id  = (int) $space->parent_id;
			if ( $parent_id > 0 ) {
				$parent_ids[] = $parent_id;
			}

			$rows[] = array(
				'parent_id'   => $parent_id,
				'row'         => array(
					'id'          => (string) $space->id,
					'title'       => (string) $space->title,
					'slug'        => (string) $space->slug,
					'privacy'     => (string) ( $space->privacy ?: 'public' ),
					'logo'        => (string) ( $space->logo ?: '' ),
					'settings'    => array( 'emoji' => (string) ( $settings['emoji'] ?? '' ) ),
					// Every row here is a destination the server will
					// accept, so none of them is dimmed. `can_create_post`
					// is what the sheet reads for that.
					'permissions' => array(
						'can_create_post'      => true,
						'community_moderator'  => ! empty( $permissions['community_moderator'] ),
						'can_view_documents'   => ! empty( $permissions['can_view_documents'] ),
						'can_upload_documents' => ! empty( $permissions['can_upload_documents'] ),
					),
					'topics'         => $this->space_topics( $space, $is_moderator ),
					'topic_required' => 'yes' === ( $settings['topic_required'] ?? 'no' ),
				),
			);
		}

		$group_titles = $this->group_titles( array_values( array_unique( $parent_ids ) ) );

		$grouped = array();
		foreach ( $rows as $entry ) {
			$name = $group_titles[ $entry['parent_id'] ] ?? __( 'Others', 'appza-core' );
			if ( ! isset( $grouped[ $name ] ) ) {
				$grouped[ $name ] = array();
			}
			$grouped[ $name ][] = $entry['row'];
		}

		$out = array();
		foreach ( $grouped as $name => $group_rows ) {
			$out[] = array(
				'group_name' => (string) $name,
				'spaces'     => $group_rows,
			);
		}

		return $out;
	}

	/**
	 * @param array<int, int> $parent_ids
	 * @return array<int, string>  id => title
	 */
	protected function group_titles( array $parent_ids ) {
		// SpaceGroup, NOT BaseSpace. BaseSpace carries
		// `protected static $type = 'community'` and a global scope
		// built from it, so `BaseSpace::whereIn('id', …)` silently
		// matches no space GROUP at all — every row lands under
		// "Others" and the grouping looks like it was never wired.
		if ( ! $parent_ids || ! class_exists( '\\FluentCommunity\\App\\Models\\SpaceGroup' ) ) {
			return array();
		}

		$titles = array();
		$groups = \FluentCommunity\App\Models\SpaceGroup::whereIn( 'id', $parent_ids )->get();
		foreach ( $groups as $group ) {
			$titles[ (int) $group->id ] = (string) $group->title;
		}

		return $titles;
	}

	/**
	 * The chips the composer would draw for this space, dropping the
	 * admin-only ones for anyone who is not a moderator — the same rule
	 * FluentCommunityComposerEnricher applies to the create-post picker.
	 *
	 * @param object $space
	 * @param bool   $is_moderator
	 * @return array<int, array<string, string>>
	 */
	protected function space_topics( $space, $is_moderator ) {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return array();
		}

		$topics = call_user_func(
			array( '\\FluentCommunity\\App\\Functions\\Utility', 'getTopicsBySpaceId' ),
			$space->id
		);

		$out = array();
		foreach ( (array) $topics as $topic ) {
			$topic = (array) $topic;
			if ( ! $is_moderator && 'yes' === ( $topic['admin_only'] ?? 'no' ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) ( $topic['id'] ?? '' ),
				'title' => (string) ( $topic['title'] ?? '' ),
				'slug'  => (string) ( $topic['slug'] ?? '' ),
			);
		}

		return $out;
	}
}
