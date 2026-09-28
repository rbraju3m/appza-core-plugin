<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/feeds/move-space-options?feed_id=N
 *
 * Lists the POST AUTHOR's spaces, not the viewer's: FC's FeedsController::update refuses a
 * move when the author is not a member of the destination. Same shape as the composer picker.
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

		// Same query as FC's Helper::isUserInSpace, so the offer matches what update accepts.
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

		// FC offers "move to profile" only when the post is in a space and global posts are on.
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
	 * @param array<int, int> $space_ids
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
			// FC's update also requires community_admin on the destination.
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
		// SpaceGroup, not BaseSpace: BaseSpace's global type scope silently matches no group.
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

	/** @return array<int, array<string, string>> */
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
