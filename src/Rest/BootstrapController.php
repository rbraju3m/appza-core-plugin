<?php
/**
 * GET /wp-json/appza/v1/bootstrap?template=<slug>
 *
 * Single endpoint the APPZA mobile app calls on cold boot + foreground
 * revalidation. Returns the DC#13 Q5 envelope per appza-implementation-plan.md
 * § 4.13. Public-read v1 per DC#13 Q4 — no auth required.
 *
 * v1 behaviour: reads the local snapshot for the requested template_slug.
 * Empty-catalog branch when no snapshot row exists (first-install state):
 * envelope returns with catalog_snapshot_version = 0 and empty catalog arrays,
 * so the mobile app can render its onboarding / "site not configured yet"
 * state without erroring.
 *
 * Customizations always empty for v1 (wp_appza_customizations lands in
 * Phase 1B.5+). Auth block in runtime_config is empty until the JWT flow
 * lands (DC#13 Q4 — own phase).
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

		// Features -> "Leaderboards Module" off takes the Leaderboard page
		// out of the catalog entirely rather than leaving it to render an
		// explanation. The hamburger drawer lists every template screen it
		// is handed, so a screen left in place would be a live entry
		// leading to a dead page — which is exactly what FluentCommunity
		// does in its own portal sidebar when the module is off.
		$catalog = $this->apply_feature_gates( $catalog );

		$customizations_tree = $this->customizations->all_as_tree();

		$response = array(
			'schema_version'           => APPZA_CORE_CONTRACTS_VERSION,
			'catalog_snapshot_version' => $snapshot ? (int) $snapshot['catalog_snapshot_version'] : 0,
			'customizations_version'   => (int) $meta['customizations_version'],
			'catalog'                  => $catalog,
			// Real customizations payload — `scope -> target_key -> column ->
			// override_value` nested object. Empty when no overrides exist
			// (still {}, never []). Phase 1B.5a wires the read path; the
			// admin UI to edit these ships in Phase 1B.5b.
			'customizations'           => empty( (array) $customizations_tree )
				? new \stdClass()
				: $customizations_tree,
			'runtime_config'           => $this->runtime_config( $catalog ),
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Restore object-shape for known leaf-bag fields after the repository's
	 * json_decode($blob, true) collapsed `{}` to PHP empty array. Without
	 * this, the round-trip Core -> WP-cache -> bootstrap response converts
	 * object-shaped fields (tokens, screen_tokens) into JSON arrays,
	 * breaking the wire contract documented in DC#09 / DC#10.
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
	 * Drop screens whose feature is switched off in the Source
	 * Integration's own settings.
	 *
	 * The Leaderboard and Saved Posts. The screen is removed rather than
	 * flagged because the drawer is generated from `template_screens`:
	 * anything left in the list is a tappable entry, and a page that
	 * exists only to say the feature is off is worse than no page. The
	 * AppZets and data sources stay in the snapshot — they are harmless
	 * unreferenced rows, and pruning them would mean re-walking the
	 * reachability graph on every bootstrap.
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

	/**
	 * Features -> "Leaderboards Module", plus FluentCommunity Pro
	 * actually being installed. The settings flag survives deactivating
	 * Pro, and every leaderboard route goes through a Pro class.
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
	 * Settings -> Menu Settings -> Profile Dropdown Items -> Bookmarks ->
	 * Edit Link -> "Enable this link".
	 *
	 * This is the switch Noor pointed at for the Saved Posts screen, and
	 * it is the half of that dialog that can be answered here: whether
	 * the link exists at all is a property of the site, not of who is
	 * asking. The other half — Visibility — is per-viewer and is
	 * deliberately NOT resolved in this method; see
	 * fluent_community_settings() below.
	 *
	 * Read off the raw option rather than Helper::getMenuItemsGroup(),
	 * which resolves the whole menu FOR THE CURRENT USER. Bootstrap is
	 * public-read and cached per template, so a per-viewer answer
	 * computed here would be whoever warmed the cache.
	 *
	 * Defaults to enabled: FluentCommunity ships Bookmarks on, and a site
	 * that has never opened Menu Settings has no stored entry at all.
	 */
	protected function bookmarks_enabled() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return false;
		}

		$item = $this->bookmarks_menu_item();

		// No stored entry means the site is running FluentCommunity's
		// defaults, where the link is enabled.
		if ( null === $item ) {
			return true;
		}

		// FluentCommunity's own rule (Helper::isLinkAccessible): absent
		// means yes, anything other than 'yes' means no.
		$enabled = isset( $item['enabled'] ) ? $item['enabled'] : 'yes';
		if ( 'yes' !== $enabled ) {
			return false;
		}

		// A link marked unavailable is one FluentCommunity itself has
		// switched off underneath the admin's choice.
		return ( isset( $item['is_unavailable'] ) ? $item['is_unavailable'] : 'no' ) !== 'yes';
	}

	/**
	 * The stored Bookmarks entry from FluentCommunity's menu option, or
	 * null when the site has never saved Menu Settings.
	 *
	 * The option stores profileDropdownItems as a LIST, and the admin can
	 * reorder it, so the entry is found by slug rather than by position.
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
	 * Edit Link -> Visibility, raw.
	 *
	 * @return string public | logged_in | logged_out_only | members_only,
	 *                or '' when the site has not set one.
	 */
	protected function bookmarks_visibility() {
		$item = $this->bookmarks_menu_item();
		if ( null === $item || ! isset( $item['privacy'] ) || ! is_string( $item['privacy'] ) ) {
			return '';
		}

		return $item['privacy'];
	}

	/**
	 * Edit Link -> "Select Space/Course Memberships", read only when
	 * Visibility is members_only.
	 *
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
	 * Surface the FluentCommunity customization settings the mobile
	 * renderer honors so the app matches whatever the site admin picked
	 * in Settings → Customization: member_list_layout (classic = list,
	 * grid = tile grid) plus, under `composer`, everything the create-post
	 * screen branches on.
	 *
	 * Returns null when FluentCommunity isn't installed so the mobile
	 * bootstrap loader can distinguish "no FC on this site" from
	 * "FC present with default settings".
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
			// Features -> "Leaderboards Module". The screen is already
			// gone from the catalog when this is false; the flag rides
			// along so a shell can explain itself rather than silently
			// missing a page.
			'leaderboard_enabled'            => $this->leaderboard_enabled(),
			// Privacy -> "Who can view leaderboard top members?", raw.
			// Deliberately NOT resolved to a yes/no here: bootstrap is
			// public-read and cached per template, so a per-viewer verdict
			// computed at this point would be whoever warmed the cache.
			// The verdict travels with the leaderboard payload instead,
			// which is fetched per request and per viewer.
			'leaderboard_members_visibility' => $this->leaderboard_visibility(),
			// Menu Settings -> Bookmarks -> Edit Link, split the same way
			// the two leaderboard switches are (D64).
			//
			// `enabled` is a property of the site, so the screen is
			// already gone from the catalog when this is false; the flag
			// rides along so a shell can explain itself rather than
			// silently missing a page.
			//
			// `visibility` is a property of the VIEWER, so it is shipped
			// raw and resolved where the auth state is actually known —
			// the shells, which is also where FluentCommunity resolves it
			// (its portal filters its own sidebar, not its settings).
			// Resolving it here would bake whoever warmed the cache into
			// every later response.
			'bookmarks_enabled'              => $this->bookmarks_enabled(),
			'bookmarks_visibility'           => $this->bookmarks_visibility(),
			'bookmarks_membership_ids'       => $this->bookmarks_membership_ids(),
			// Everything the create-post composer branches on. Grouped
			// rather than flattened because it is one screen's worth of
			// settings read from four different FluentCommunity stores.
			'composer'                       => $this->composer_settings(),
		);
	}

	/**
	 * The create-post composer's feature gates.
	 *
	 * Mirrors FluentCommunity's own `PortalHandler::appVars()['features']`
	 * — the portal composer reads exactly these, so reading anything else
	 * would put the app and the portal out of step. Every value is a
	 * property of the SITE. Per-viewer verdicts (may this user schedule a
	 * post, send an announcement email, upload a video) are deliberately
	 * NOT resolved here: bootstrap is public-read and cached per template,
	 * so an answer computed at this point would be whoever warmed the
	 * cache. Those ride the per-request payload instead, the same split
	 * the leaderboard and bookmarks visibility settings use (D64).
	 *
	 * @return array<string, mixed>
	 */
	protected function composer_settings() {
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';
		$helper  = '\\FluentCommunity\\App\\Services\\Helper';

		// 'disabled' | 'optional' | 'required'. FluentCommunity's
		// postTitlePref() maps 'disabled' to an empty string (it is
		// checked for truthiness at the call site) and runs the
		// `fluent_community/has_post_title` filter — go through it so a
		// site that filters the pref gets the same answer in the app as
		// in its portal, then map the empty string back to a real word.
		$title_pref = call_user_func( array( $utility, 'postTitlePref' ) );
		$title_pref = in_array( $title_pref, array( 'optional', 'required' ), true ) ? $title_pref : 'disabled';

		// The RAW customization setting, deliberately not run through
		// `fluent_community/max_media_per_post`. Our own
		// FluentCommunityMediaCap raises that filter to 20 so an edit
		// cannot truncate a post that already carries more images than
		// the site's limit — a repair valve, not the composer's rule.
		// Reporting the filtered value would let the picker take 20.
		$max_media = (int) call_user_func( array( $utility, 'getCustomizationSetting' ), 'max_media_per_post' );

		$layout = call_user_func( array( $utility, 'getCustomizationSetting' ), 'rich_post_layout' );

		$topics_config = class_exists( $helper )
			? call_user_func( array( $helper, 'getTopicsConfig' ) )
			: array();

		return array(
			'post_title_pref'            => $title_pref,
			// Settings → General → Global Post Status. When off there is
			// no "On my profile" row on the space picker.
			'global_posts_enabled'       => class_exists( $helper )
				? (bool) call_user_func( array( $helper, 'hasGlobalPost' ) )
				: false,
			// Customization → Rich Media Post Layout. `modern` puts media
			// above the author block, `classic` below the body.
			'rich_post_layout'           => in_array( $layout, array( 'classic', 'modern' ), true ) ? $layout : 'classic',
			'max_media_per_post'         => $max_media > 0 ? $max_media : 4,
			// Settings → Email Notification → announcement mail. Whether
			// the checkbox exists at all; whether THIS viewer may tick it
			// is a moderator question answered per request.
			'announcement_email_enabled' => (bool) call_user_func( array( $utility, 'hasEmailAnnouncementEnabled' ) ),
			// FluentCommunity hardcodes has_survey_poll to true; kept as
			// a key so a future switch has somewhere to land.
			'survey_enabled'             => (bool) apply_filters( 'fluent_community/has_survey_poll', true ),
			'video_embed_enabled'        => (bool) apply_filters( 'fluent_community/has_video_embeder', true ),
			'inline_image_upload'        => 'yes' === apply_filters( 'fluent_community/has_inline_image_upload', 'yes' ),
			'topics_enabled'             => (bool) call_user_func( array( $utility, 'getTopics' ) ),
			'max_topics_per_post'        => max( 1, (int) ( $topics_config['max_topics_per_post'] ?? 1 ) ),
			'fluent_player'              => $this->fluent_player_settings(),
		);
	}

	/**
	 * FluentPlayer's half of the composer: the Upload Video tab and the
	 * audio glyph both exist only because of it.
	 *
	 * `video_upload_role` ships RAW for the same reason
	 * `bookmarks_visibility` does — it is a question about the viewer, and
	 * the shells are where the auth state is actually known.
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
	 * @return string everybody | logged_in | admin_only, or '' when
	 *                FluentCommunity is not installed.
	 */
	protected function leaderboard_visibility() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return '';
		}

		$value = \FluentCommunity\App\Functions\Utility::getPrivacySetting( 'leaderboard_members_visibility' );

		return is_string( $value ) ? $value : '';
	}
}