<?php
/**
 * Adds per-viewer composer verdicts to fcom-mobile's space-picker responses.
 * The lean route computes then DROPS these permission keys, so they are re-derived here;
 * they cannot live in the bootstrap envelope, which is cached per template, not per viewer.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityComposerEnricher {

	const ROUTES = array(
		'/fcom-mobile/api/v1/spaces/create-post-information-lean',
		'/fcom-mobile/api/v1/spaces/create-post-information',
	);

	public function register_hooks() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'attach_composer_context' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 */
	public function attach_composer_context( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		if ( ! in_array( (string) $request->get_route(), self::ROUTES, true ) ) {
			return $response;
		}
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! array_key_exists( 'items', $data ) ) {
			return $response;
		}

		$user = \FluentCommunity\App\Services\Helper::getCurrentUser();

		// items is [ {spaces: [...]} ]. A viewer with no spaces gets items: [] upstream; synthesise the
		// wrapper so the viewer verdict (schedule, announcement, "On my profile") still arrives.
		$items = is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		if ( ! $items ) {
			$items = array( array( 'spaces' => array() ) );
		}

		foreach ( $items as $i => $wrapper ) {
			$wrapper = (array) $wrapper;
			if ( isset( $wrapper['spaces'] ) && is_array( $wrapper['spaces'] ) ) {
				$wrapper['spaces'] = $this->enrich_groups( $wrapper['spaces'], $user );
			}
			$wrapper['viewer'] = $this->viewer_context( $user );
			$items[ $i ]       = $wrapper;
		}

		$data['items'] = $items;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * @param array<int, mixed> $groups
	 * @param object|false      $user
	 * @return array<int, mixed>
	 */
	protected function enrich_groups( array $groups, $user ) {
		$slugs = array();
		foreach ( $groups as $group ) {
			$group = (array) $group;
			foreach ( (array) ( $group['spaces'] ?? array() ) as $space ) {
				$space = (array) $space;
				if ( ! empty( $space['slug'] ) ) {
					$slugs[] = (string) $space['slug'];
				}
			}
		}

		$models = $this->load_spaces( array_values( array_unique( $slugs ) ), $user );

		$is_moderator = (bool) call_user_func( array( '\\FluentCommunity\\App\\Services\\Helper', 'isModerator' ) )
			|| (bool) call_user_func( array( '\\FluentCommunity\\App\\Services\\Helper', 'isSiteAdmin' ) );

		foreach ( $groups as $gi => $group ) {
			$group = (array) $group;
			foreach ( (array) ( $group['spaces'] ?? array() ) as $si => $space ) {
				$space                  = (array) $space;
				$slug                   = (string) ( $space['slug'] ?? '' );
				$model                  = $models[ $slug ] ?? null;
				$space['permissions']   = $this->space_permissions(
					isset( $space['permissions'] ) ? (array) $space['permissions'] : array(),
					$model,
					$user
				);
				$space['topics']        = $this->space_topics( $model, $is_moderator );
				$space['topic_required'] = $this->topic_required( $model );
				$group['spaces'][ $si ] = $space;
			}
			$groups[ $gi ] = $group;
		}

		return $groups;
	}

	/**
	 * Cheap per row: FC's getTopicsBySpaceId() filters one cached all-topics query in memory.
	 *
	 * @param object|null $space
	 * @return array<int, array<string, mixed>>
	 */
	protected function space_topics( $space, $is_moderator ) {
		if ( ! $space || ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
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

	/**
	 * @param object|null $space
	 */
	protected function topic_required( $space ) {
		if ( ! $space ) {
			return false;
		}
		$settings = (array) $space->settings;

		return 'yes' === ( $settings['topic_required'] ?? 'no' );
	}

	/**
	 * Preloads memberships so getSpacePermissions() does not query per row.
	 *
	 * @param array<int, string> $slugs
	 * @param object|false       $user
	 * @return array<string, object>  slug => space model
	 */
	protected function load_spaces( array $slugs, $user ) {
		if ( ! $slugs || ! $user || ! class_exists( '\\FluentCommunity\\App\\Models\\BaseSpace' ) ) {
			return array();
		}

		$spaces = \FluentCommunity\App\Models\BaseSpace::whereIn( 'slug', $slugs )->get();

		if ( method_exists( '\\FluentCommunity\\App\\Models\\BaseSpace', 'preloadMemberships' ) ) {
			\FluentCommunity\App\Models\BaseSpace::preloadMemberships( $spaces, $user->ID );
		}

		$bySlug = array();
		foreach ( $spaces as $space ) {
			$bySlug[ (string) $space->slug ] = $space;
		}

		return $bySlug;
	}

	/**
	 * `can_create_post` is kept as it arrived so it cannot disagree with the picker's own gating.
	 *
	 * @param array<string, mixed> $existing
	 * @param object|null          $space
	 * @param object|false         $user
	 * @return array<string, mixed>
	 */
	protected function space_permissions( array $existing, $space, $user ) {
		$existing['can_create_post'] = ! empty( $existing['can_create_post'] );

		$defaults = array(
			'community_moderator'  => false,
			'can_view_documents'   => false,
			'can_upload_documents' => false,
		);

		if ( ! $space || ! $user ) {
			return array_merge( $defaults, $existing );
		}

		$permissions = (array) $user->getSpacePermissions( $space );

		$resolved = array(
			'community_moderator'  => ! empty( $permissions['community_moderator'] ),
			'can_view_documents'   => ! empty( $permissions['can_view_documents'] ),
			'can_upload_documents' => ! empty( $permissions['can_upload_documents'] ),
		);

		return array_merge( $defaults, $existing, $resolved );
	}

	/**
	 * @param object|false $user
	 * @return array<string, mixed>
	 */
	protected function viewer_context( $user ) {
		$helper = '\\FluentCommunity\\App\\Services\\Helper';

		$is_site_admin = (bool) call_user_func( array( $helper, 'isSiteAdmin' ) );
		$is_moderator  = (bool) call_user_func( array( $helper, 'isModerator' ) );

		return array(
			'is_authenticated'    => (bool) $user,
			'is_site_admin'       => $is_site_admin,
			'is_moderator'        => $is_moderator || $is_site_admin,
			'can_upload_video'    => $this->can_upload_media( $is_site_admin, $is_moderator ),
			'can_upload_audio'    => $this->can_upload_media( $is_site_admin, $is_moderator )
				&& $this->player_setting( 'enable_audio' ) === 'yes',
		);
	}

	// Mirrors FluentPlayer's MediaController@uploadVideo gate.
	protected function can_upload_media( $is_site_admin, $is_moderator ) {
		$bootstrap = '\\FluentCommunity\\Modules\\Integrations\\FluentPlayer\\Bootstrap';
		if ( ! class_exists( $bootstrap ) ) {
			return false;
		}
		if ( 'active' !== call_user_func( array( $bootstrap, 'getPluginStatus' ) ) ) {
			return false;
		}
		if ( 'yes' !== $this->player_setting( 'video_upload' ) ) {
			return false;
		}

		$role = $this->player_setting( 'video_upload_role' );
		$role = $role ? $role : 'admin';

		if ( 'everyone' === $role ) {
			return true;
		}
		if ( 'admin_moderator' === $role ) {
			return $is_moderator || $is_site_admin;
		}

		return (bool) $is_site_admin;
	}

	/**
	 * @return string
	 */
	protected function player_setting( $key ) {
		$bootstrap = '\\FluentCommunity\\Modules\\Integrations\\FluentPlayer\\Bootstrap';
		if ( ! class_exists( $bootstrap ) ) {
			return '';
		}
		$settings = (array) call_user_func( array( $bootstrap, 'getSettings' ) );

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}
}
