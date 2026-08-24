<?php
/**
 * Attaches the create-post composer's PER-VIEWER verdicts to the
 * fcom-mobile space-picker responses.
 *
 * The site-wide half of the composer's configuration (title pref, media
 * cap, rich-media layout, FluentPlayer switches) rides the bootstrap
 * envelope as `runtime_config.fluent_community.composer`. It is cached
 * per template and public-read, so anything that depends on WHO is
 * asking cannot be answered there — it would be whoever warmed the
 * cache. That is the same split the leaderboard and bookmarks visibility
 * settings already use (D64).
 *
 * This is the other half. Two things are per-viewer:
 *
 *   1. Whether the schedule + "Send announcement email" controls exist
 *      at all. FluentCommunity gates the announcement mail on
 *      `hasPermissionOrInCurrentSpace('community_moderator', $space)`
 *      (FeedsController::create, line ~346) — a GLOBAL moderator, or a
 *      moderator of the space being posted to. Both halves ship: the
 *      global verdict on `viewer`, the per-space one on each row, so the
 *      composer can re-evaluate when the space changes without a
 *      second request.
 *
 *   2. Whether the attachment glyph exists. That is the space's own
 *      Document Library switch plus its "who can upload" setting, which
 *      FluentCommunity resolves into `can_view_documents` /
 *      `can_upload_documents` on the same permissions bag.
 *
 * Why an enricher rather than a route of our own: fcom-mobile's
 * `/spaces/create-post-information-lean` already computes and then
 * DROPS these keys — it copies only `can_create_post` out of a
 * permissions bag that FluentCommunity filled in completely. Editing
 * fcom-mobile is out (third-party source plugin), and a parallel route
 * would fetch the same space list twice. So we re-derive the dropped
 * keys here, from FluentCommunity's own models, on the response the
 * composer is already fetching.
 *
 * Cost: one `whereIn(slug)` query plus FluentCommunity's own membership
 * preload, once per composer open.
 *
 * Hooks on `rest_request_after_callbacks` for the same reason
 * FluentCommunitySpaceConfigEnricher does — it fires for the outer
 * serve_request AND for the internal rest_do_request calls the WP-admin
 * simulator makes through PreviewProxyController.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityComposerEnricher {

	/**
	 * Both variants of the space picker. The composer uses the lean one;
	 * the full one is enriched too so the two never disagree.
	 */
	const ROUTES = array(
		'/fcom-mobile/api/v1/spaces/create-post-information-lean',
		'/fcom-mobile/api/v1/spaces/create-post-information',
	);

	public function register_hooks() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'attach_composer_context' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
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

		// items is [ {spaces: [ {group_name, spaces: []}, … ]} ] — a
		// one-element list whose single member is the wrapper object.
		// Adding a second key to that object is safe: the renderer only
		// unwraps single-key objects that arrive as `items` ITSELF, never
		// elements of an items ARRAY.
		//
		// A viewer with no spaces gets `items: []` upstream. This is not
		// a list of rows — the picker's rows live at items[0].spaces — so
		// the wrapper is synthesised rather than left off. Otherwise the
		// composer would lose its viewer verdict (no schedule control, no
		// announcement checkbox, no "On my profile" row) for exactly the
		// members most likely to be posting to their profile. `spaces`
		// stays the empty list it was, so the picker's own empty state is
		// untouched (P93).
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
	 * The topic chips the composer draws once this space is picked.
	 *
	 * Costs nothing per space: FluentCommunity's `Utility::getTopics()`
	 * is a single cached query for EVERY topic on the site, and
	 * `getTopicsBySpaceId()` filters that in memory. So attaching them to
	 * every row is cheaper than fetching the space's detail after each
	 * selection, which is what the composer would otherwise have to do —
	 * once per space the user tries.
	 *
	 * `admin_only` topics are dropped for anyone who is not a moderator.
	 * FluentCommunity flags them on the topic itself, and a chip a member
	 * cannot post under should not be offered.
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
	 * The space's own "topic required" switch, which decides whether the
	 * composer may post without a chip selected.
	 *
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
	 * One query for every space in the picker, with FluentCommunity's own
	 * membership preload so `getSpacePermissions()` does not fire a
	 * lookup per row.
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
	 * The keys fcom-mobile's lean transform drops, re-derived.
	 *
	 * `can_create_post` is left exactly as it arrived — the lean endpoint
	 * already carries it and re-deriving would risk disagreeing with the
	 * value the picker is already gating rows on.
	 *
	 * @param array<string, mixed> $existing
	 * @param object|null          $space
	 * @param object|false         $user
	 * @return array<string, mixed>
	 */
	protected function space_permissions( array $existing, $space, $user ) {
		$existing['can_create_post'] = ! empty( $existing['can_create_post'] );

		$defaults = array(
			// The announcement checkbox and the schedule control.
			'community_moderator'  => false,
			// The attachment glyph: the space's Document Library switch,
			// then its "who can upload" setting.
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
	 * The verdicts that are about the viewer alone, with no space in
	 * hand — what the composer needs before a space is picked, and what
	 * "On my profile" is governed by.
	 *
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
			// Global community moderator. The composer shows the schedule
			// and announcement controls on this; picking a space the
			// viewer moderates widens it via the row's own
			// `community_moderator`.
			'is_moderator'        => $is_moderator || $is_site_admin,
			'can_upload_video'    => $this->can_upload_media( $is_site_admin, $is_moderator ),
			'can_upload_audio'    => $this->can_upload_media( $is_site_admin, $is_moderator )
				&& $this->player_setting( 'enable_audio' ) === 'yes',
		);
	}

	/**
	 * FluentPlayer's upload gate, read exactly the way its own
	 * MediaController@uploadVideo reads it: the plugin active, the
	 * `video_upload` switch on, then `video_upload_role` — `admin`,
	 * `admin_moderator` or `everyone`. Audio rides the same gate plus
	 * `enable_audio`, which is also how the controller enforces it.
	 */
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
