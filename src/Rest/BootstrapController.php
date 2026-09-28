<?php
/**
 * GET /wp-json/appza/v1/bootstrap?template=<slug> — public-read, cached per template.
 * Never resolve per-viewer settings here: the answer would be whoever warmed the cache.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Repository\CustomizationRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BootstrapController {

	protected $snapshots;
	protected $meta;
	protected $customizations;

	public function __construct(
		CatalogSnapshotRepository $snapshots = null,
		CatalogMetaRepository $meta = null,
		CustomizationRepository $customizations = null
	) {
		$this->snapshots      = $snapshots ?: new CatalogSnapshotRepository();
		$this->meta           = $meta ?: new CatalogMetaRepository();
		$this->customizations = $customizations ?: new CustomizationRepository();
	}

	public function handle( \WP_REST_Request $request ) {
		$template_slug = (string) $request->get_param( 'template' );

		if ( '' === $template_slug ) {
			return new \WP_Error( 'appza_bootstrap_missing_template', __( 'template query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		$meta     = $this->meta->get();

		$catalog = $snapshot && is_array( $snapshot['snapshot_blob'] )
			? $this->normalize_catalog_shape( $snapshot['snapshot_blob'] )
			: $this->empty_catalog( $template_slug );

		$catalog = $this->apply_feature_gates( $catalog );

		$customizations_tree = $this->customizations->all_as_tree();

		$response = array(
			'schema_version'           => APPZA_CORE_CONTRACTS_VERSION,
			'catalog_snapshot_version' => $snapshot ? (int) $snapshot['catalog_snapshot_version'] : 0,
			'customizations_version'   => (int) $meta['customizations_version'],
			'catalog'                  => $catalog,
			// Must serialize as {} , never [].
			'customizations'           => empty( (array) $customizations_tree )
				? new \stdClass()
				: $customizations_tree,
			'runtime_config'           => $this->runtime_config( $catalog ),
		);

		return rest_ensure_response( $response );
	}

	/**
	 * json_decode($blob, true) collapses `{}` to an empty array; restore object
	 * shape so tokens/screen_tokens stay JSON objects on the wire.
	 */
	protected function normalize_catalog_shape( array $catalog ) {
		if ( isset( $catalog['template'] ) && is_array( $catalog['template'] ) ) {
			$catalog['template']['tokens'] = $this->normalize_object( $catalog['template']['tokens'] ?? null );
		}
		if ( ! empty( $catalog['template_screens'] ) && is_array( $catalog['template_screens'] ) ) {
			foreach ( $catalog['template_screens'] as &$screen ) {
				if ( is_array( $screen ) ) {
					$screen['screen_tokens'] = $this->normalize_object( $screen['screen_tokens'] ?? null, true );
				}
			}
			unset( $screen );
		}
		return $catalog;
	}

	protected function normalize_object( $value, $allow_null = false ) {
		if ( null === $value ) {
			return $allow_null ? null : new \stdClass();
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return new \stdClass();
		}
		return $value;
	}

	protected function empty_catalog( $template_slug ) {
		return array(
			'template'           => array( 'slug' => $template_slug ),
			'superstructures'    => array(),
			'appzets'            => array(),
			'data_sources'       => array(),
			'actions'            => array(),
			'primitives'         => array(),
			'primitive_props'    => array(),
			'app_map'            => null,
			'source_integration' => null,
		);
	}

	protected function runtime_config( array $catalog ) {
		$source_integration_slug = null;
		if ( isset( $catalog['source_integration']['slug'] ) ) {
			$source_integration_slug = (string) $catalog['source_integration']['slug'];
		}

		return array(
			'wp_base_url'             => get_site_url(),
			'source_integration_slug' => $source_integration_slug,
			'locale_default'          => get_locale(),
			'auth'                    => null,
			'fluent_community'        => $this->fluent_community_settings(),
		);
	}

	/**
	 * Remove screens whose feature is off: the drawer lists every template
	 * screen, so a screen left in place would be a tappable dead entry.
	 *
	 * @param array<string, mixed> $catalog
	 * @return array<string, mixed>
	 */
	protected function apply_feature_gates( array $catalog ) {
		if ( empty( $catalog['template_screens'] ) || ! is_array( $catalog['template_screens'] ) ) {
			return $catalog;
		}

		$gated = array();
		if ( ! $this->leaderboard_enabled() ) {
			$gated[] = 'leaderboard';
		}
		if ( ! $this->bookmarks_enabled() ) {
			$gated[] = 'bookmarks';
		}
		if ( ! $this->courses_enabled() ) {
			array_push( $gated, 'courses', 'course-detail', 'course-students', 'course-lesson' );
		}

		if ( ! $gated ) {
			return $catalog;
		}

		$catalog['template_screens'] = array_values(
			array_filter(
				$catalog['template_screens'],
				static function ( $screen ) use ( $gated ) {
					$slug = is_array( $screen ) ? ( $screen['app_map_screen_slug'] ?? '' ) : '';
					return ! in_array( $slug, $gated, true );
				}
			)
		);

		return $catalog;
	}

	protected function courses_enabled() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return false;
		}

		return (bool) \FluentCommunity\App\Services\Helper::isFeatureEnabled( 'course_module' );
	}

	/**
	 * The settings flag survives deactivating Pro, and every leaderboard
	 * route goes through a Pro class — so check Pro is present too.
	 */
	protected function leaderboard_enabled() {
		if ( ! class_exists( '\\FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper' ) ) {
			return false;
		}
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return false;
		}

		return (bool) \FluentCommunity\App\Services\Helper::isFeatureEnabled( 'leader_board_module' );
	}

	/**
	 * Menu Settings -> Bookmarks -> "Enable this link". Read from the raw option,
	 * not Helper::getMenuItemsGroup(), which resolves the menu for the current user.
	 */
	protected function bookmarks_enabled() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return false;
		}

		$item = $this->bookmarks_menu_item();

		// Never-saved Menu Settings means FC defaults, where Bookmarks is on.
		if ( null === $item ) {
			return true;
		}

		// FC's rule (Helper::isLinkAccessible): absent means yes.
		$enabled = isset( $item['enabled'] ) ? $item['enabled'] : 'yes';
		if ( 'yes' !== $enabled ) {
			return false;
		}

		return ( isset( $item['is_unavailable'] ) ? $item['is_unavailable'] : 'no' ) !== 'yes';
	}

	/**
	 * profileDropdownItems is a reorderable list, so match by slug, not position.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function bookmarks_menu_item() {
		static $cached = false;
		static $value  = null;

		if ( $cached ) {
			return $value;
		}
		$cached = true;

		$groups = \FluentCommunity\App\Functions\Utility::getOption( 'fluent_community_menu_groups', array() );
		if ( ! is_array( $groups ) || empty( $groups['profileDropdownItems'] ) || ! is_array( $groups['profileDropdownItems'] ) ) {
			return $value;
		}

		foreach ( $groups['profileDropdownItems'] as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && 'bookmarks' === $item['slug'] ) {
				$value = $item;
				break;
			}
		}

		return $value;
	}

	/**
	 * @return string public | logged_in | logged_out_only | members_only, or ''.
	 */
	protected function bookmarks_visibility() {
		$item = $this->bookmarks_menu_item();
		if ( null === $item || ! isset( $item['privacy'] ) || ! is_string( $item['privacy'] ) ) {
			return '';
		}

		return $item['privacy'];
	}

	/**
	 * @return array<int, int>
	 */
	protected function bookmarks_membership_ids() {
		$item = $this->bookmarks_menu_item();
		if ( null === $item || empty( $item['membership_ids'] ) || ! is_array( $item['membership_ids'] ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $item['membership_ids'] ) );
	}

	/**
	 * Null when FluentCommunity is absent, so the app can tell "no FC" from "FC defaults".
	 *
	 * @return array<string, string>|null
	 */
	protected function fluent_community_settings() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return null;
		}

		$layout = \FluentCommunity\App\Functions\Utility::getCustomizationSetting( 'member_list_layout' );
		$layout = in_array( $layout, array( 'classic', 'grid' ), true ) ? $layout : 'classic';

		return array(
			'member_list_layout'             => $layout,
			'leaderboard_enabled'            => $this->leaderboard_enabled(),
			// Per-viewer: shipped raw; the verdict travels with the per-request leaderboard payload.
			'leaderboard_members_visibility' => $this->leaderboard_visibility(),
			'bookmarks_enabled'              => $this->bookmarks_enabled(),
			// Per-viewer: shipped raw and resolved by the shells.
			'bookmarks_visibility'           => $this->bookmarks_visibility(),
			'bookmarks_membership_ids'       => $this->bookmarks_membership_ids(),
			'composer'                       => $this->composer_settings(),
			'feed'                           => $this->feed_settings(),
			'access'                         => $this->access_settings(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function feed_settings() {
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';
		$helper  = '\\FluentCommunity\\App\\Services\\Helper';

		$layout = call_user_func( array( $utility, 'getCustomizationSetting' ), 'default_feed_layout' );
		$layout = in_array( $layout, array( 'timeline', 'list', 'card' ), true ) ? $layout : 'timeline';

		// FC Pro appends `following` only for a logged-in user; drop it so the
		// cache-warmer's session isn't baked in. Shells re-add it from followers_module_enabled.
		$sort_options = array();
		if ( class_exists( $helper ) ) {
			foreach ( (array) call_user_func( array( $helper, 'getPostOrderOptions' ), 'feed' ) as $value => $label ) {
				if ( 'following' === $value ) {
					continue;
				}
				$sort_options[] = array(
					'value' => (string) $value,
					'label' => (string) $label,
				);
			}
		}

		$sort_values  = wp_list_pluck( $sort_options, 'value' );
		$default_sort = call_user_func( array( $utility, 'getCustomizationSetting' ), 'default_feed_sort_by' );
		// Keep '' (no preference) distinct from an explicit 'latest'.
		$default_sort = in_array( $default_sort, $sort_values, true ) ? $default_sort : '';

		$topics_config = class_exists( $helper )
			? (array) call_user_func( array( $helper, 'getTopicsConfig' ) )
			: array();

		return array(
			'default_layout'             => $layout,
			'layout_change_disabled'     => 'yes' === call_user_func( array( $utility, 'getCustomizationSetting' ), 'disable_feed_layout' ),
			'default_sort_by'            => $default_sort,
			'sort_change_disabled'       => 'yes' === call_user_func( array( $utility, 'getCustomizationSetting' ), 'disable_feed_sort_by' ),
			'sort_options'               => $sort_options,
			'followers_module_enabled'   => class_exists( $helper )
				? (bool) call_user_func( array( $helper, 'isFeatureEnabled' ), 'followers_module' )
				: false,
			'content_moderation_enabled' => class_exists( $helper )
				? (bool) call_user_func( array( $helper, 'isFeatureEnabled' ), 'content_moderation' )
				: false,
			'show_topic_on_post_card'    => 'no' !== ( $topics_config['show_on_post_card'] ?? 'yes' ),
		);
	}

	/**
	 * Guest reachability, shipped raw for the shells to resolve against auth state.
	 *
	 * @return array<string, mixed>
	 */
	protected function access_settings() {
		$helper = '\\FluentCommunity\\App\\Services\\Helper';
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';

		$general = class_exists( $helper )
			? (array) call_user_func( array( $helper, 'generalSettings' ) )
			: array();

		// `acess_level` (sic) is FluentCommunity's real key.
		$level = $general['access']['acess_level'] ?? 'public';

		$privacy = array();
		foreach ( array( 'members_page_status', 'profile_page_visibility', 'user_space_visibility', 'leaderboard_members_visibility' ) as $key ) {
			$privacy[ $key ] = class_exists( $utility )
				? (string) call_user_func( array( $utility, 'getPrivacySetting' ), $key )
				: '';
		}

		return array(
			'access_level' => in_array( $level, array( 'public', 'logged_in', 'role_based' ), true ) ? $level : 'public',
			'privacy'      => $privacy,
		);
	}

	/**
	 * Mirrors FC's PortalHandler::appVars()['features'], which the portal composer reads.
	 *
	 * @return array<string, mixed>
	 */
	protected function composer_settings() {
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';
		$helper  = '\\FluentCommunity\\App\\Services\\Helper';

		// postTitlePref() maps 'disabled' to '' and applies the has_post_title filter.
		$title_pref = call_user_func( array( $utility, 'postTitlePref' ) );
		$title_pref = in_array( $title_pref, array( 'optional', 'required' ), true ) ? $title_pref : 'disabled';

		// Raw setting on purpose: FluentCommunityMediaCap raises the
		// max_media_per_post filter to 20 as an edit-repair valve.
		$max_media = (int) call_user_func( array( $utility, 'getCustomizationSetting' ), 'max_media_per_post' );

		$layout = call_user_func( array( $utility, 'getCustomizationSetting' ), 'rich_post_layout' );

		$topics_config = class_exists( $helper )
			? call_user_func( array( $helper, 'getTopicsConfig' ) )
			: array();

		return array(
			'post_title_pref'            => $title_pref,
			'global_posts_enabled'       => class_exists( $helper )
				? (bool) call_user_func( array( $helper, 'hasGlobalPost' ) )
				: false,
			'rich_post_layout'           => in_array( $layout, array( 'classic', 'modern' ), true ) ? $layout : 'classic',
			'max_media_per_post'         => $max_media > 0 ? $max_media : 4,
			'announcement_email_enabled' => (bool) call_user_func( array( $utility, 'hasEmailAnnouncementEnabled' ) ),
			'survey_enabled'             => (bool) apply_filters( 'fluent_community/has_survey_poll', true ),
			'video_embed_enabled'        => (bool) apply_filters( 'fluent_community/has_video_embeder', true ),
			'inline_image_upload'        => 'yes' === apply_filters( 'fluent_community/has_inline_image_upload', 'yes' ),
			'topics_enabled'             => (bool) call_user_func( array( $utility, 'getTopics' ) ),
			'max_topics_per_post'        => max( 1, (int) ( $topics_config['max_topics_per_post'] ?? 1 ) ),
			'fluent_player'              => $this->fluent_player_settings(),
		);
	}

	/**
	 * video_upload_role is per-viewer, so it ships raw.
	 *
	 * @return array<string, mixed>|null  null when the integration is absent.
	 */
	protected function fluent_player_settings() {
		$bootstrap = '\\FluentCommunity\\Modules\\Integrations\\FluentPlayer\\Bootstrap';
		if ( ! class_exists( $bootstrap ) ) {
			return null;
		}

		$status = call_user_func( array( $bootstrap, 'getPluginStatus' ) );
		if ( 'active' !== $status ) {
			return array(
				'status'  => is_string( $status ) ? $status : 'not_installed',
				'enabled' => false,
			);
		}

		$settings = call_user_func( array( $bootstrap, 'getSettings' ) );

		return array(
			'status'               => 'active',
			'enabled'              => 'yes' === ( $settings['enable_fluent_player'] ?? 'no' ),
			'video_upload_enabled' => 'yes' === ( $settings['video_upload'] ?? 'no' ),
			'video_upload_role'    => (string) ( $settings['video_upload_role'] ?? 'admin' ),
			'audio_upload_enabled' => 'yes' === ( $settings['enable_audio'] ?? 'no' ),
			'max_audios_per_post'  => (int) call_user_func( array( $bootstrap, 'maxAudiosPerPost' ) ),
			'play_embedded_videos' => 'yes' === ( $settings['play_embedded_videos'] ?? 'no' ),
		);
	}

	/**
	 * @return string everybody | logged_in | admin_only, or '' without FluentCommunity.
	 */
	protected function leaderboard_visibility() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return '';
		}

		$value = \FluentCommunity\App\Functions\Utility::getPrivacySetting( 'leaderboard_members_visibility' );

		return is_string( $value ) ? $value : '';
	}
}
