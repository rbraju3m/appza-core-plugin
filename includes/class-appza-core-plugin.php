<?php
/**
 * Orchestrator. Wires the i18n loader and the REST routes registered under
 * appza/v1. The admin shell (chunk 4) hooks in here next.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use AppzaCore\Plugin\Admin\AdminController;
use AppzaCore\Plugin\Admin\AdminMenu;
use AppzaCore\Plugin\Admin\AssetLoader;
use AppzaCore\Plugin\Repository\RefreshTokenRepository;
use AppzaCore\Plugin\Rest\AuthMiddleware;
use AppzaCore\Plugin\Rest\AuthRoutes;
use AppzaCore\Plugin\Rest\FluentCommunityChatController;
use AppzaCore\Plugin\Rest\FluentCommunityFeedPermissionsFilter;
use AppzaCore\Plugin\Rest\FluentCommunityFeedUpdateEnricher;
use AppzaCore\Plugin\Rest\FluentCommunityFeedEnricher;
use AppzaCore\Plugin\Rest\PreviewCacheInvalidator;
use AppzaCore\Plugin\Rest\FluentCommunityMediaCap;
use AppzaCore\Plugin\Rest\FluentCommunityMediaGalleryEnricher;
use AppzaCore\Plugin\Rest\FluentCommunityMemberUpdateCompleter;
use AppzaCore\Plugin\Rest\FluentCommunityModerationReportsEnricher;
use AppzaCore\Plugin\Rest\FluentCommunitySpaceConfigEnricher;
use AppzaCore\Plugin\Rest\FluentCommunityComposerEnricher;
use AppzaCore\Plugin\Rest\RestRoutes;
use AppzaCore\Plugin\Schema\SchemaManager;

class Appza_Core_Plugin {

	protected $loader;

	public function __construct() {
		$this->loader = new Appza_Core_Loader();
		$this->set_locale();
		$this->register_auth_middleware();
		$this->define_rest_hooks();
		$this->define_admin_hooks();
		$this->define_maintenance_hooks();
	}

	protected function register_auth_middleware() {
		( new AuthMiddleware() )->register();
	}

	protected function define_maintenance_hooks() {
		$this->loader->add_action( 'plugins_loaded', $this, 'maybe_upgrade_schema' );
		$this->loader->add_action( Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS, $this, 'purge_refresh_tokens' );
		$this->loader->add_action( Appza_Core_Activator::CRON_SWEEP_CHAT_MEDIA, $this, 'sweep_chat_media' );
	}

	/**
	 * Re-runs SchemaManager::install() if the stored DB version doesn't
	 * match the current code, and ensures the daily refresh-token purge
	 * cron is scheduled. dbDelta is idempotent for existing tables and
	 * CREATEs missing ones — so customers picking up a new plug-in
	 * version (where activation hooks DON'T re-fire on update) still get
	 * the schema brought current on first page load.
	 */
	public function maybe_upgrade_schema() {
		if ( SchemaManager::DB_VERSION !== (string) get_option( 'appza_core_db_version', '0' ) ) {
			SchemaManager::install();
		}
		if ( ! wp_next_scheduled( Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS );
		}
		// Scheduled here as well as on activation: activation hooks do not
		// re-fire on update, so a site already running the plug-in would
		// otherwise never pick the sweep up.
		if ( ! wp_next_scheduled( Appza_Core_Activator::CRON_SWEEP_CHAT_MEDIA ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Appza_Core_Activator::CRON_SWEEP_CHAT_MEDIA );
		}
	}

	public function purge_refresh_tokens() {
		( new RefreshTokenRepository() )->purge_expired();
	}

	public function sweep_chat_media() {
		( new FluentCommunityChatController() )->sweep_abandoned_media();
	}

	protected function set_locale() {
		$i18n = new Appza_Core_I18n();
		$this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
	}

	protected function define_rest_hooks() {
		$rest = new RestRoutes();
		$auth = new AuthRoutes();
		// Post-dispatch filter attaches a `permissions` bag to feed rows
		// returned by fcom-mobile — the mobile 3-dot menu reads it to
		// decide which entries to show per viewer. Lives here rather
		// than editing the fcom-mobile plugin so third-party sources
		// stay untouched.
		$fcFeedPerms = new FluentCommunityFeedPermissionsFilter();
		// Lifts FC's max_media_per_post cap (default 4) so posts with
		// more images survive an edit round-trip. See class docblock
		// for the exact code path in FC that drops the extras.
		$fcMediaCap = new FluentCommunityMediaCap();
		// Enriches every mobile-side feed update payload by appending
		// `?media_key=<key>` to each media_images URL. Without this,
		// FC's fragile URL-match-plus-is_active-only fallback drops
		// images that a prior failed edit left inactive. See class
		// docblock for the failure chain.
		$fcFeedUpdateEnricher = new FluentCommunityFeedUpdateEnricher();
		// Attaches per-space `appza_config` blob onto fcom-mobile's
		// /spaces/by/slug response. Read from wp_appza_space_config.
		// Powers per-space renderer overrides (switcher / subtabs /
		// cover / topics) without editing the fcom-mobile plug-in.
		$fcSpaceConfig = new FluentCommunitySpaceConfigEnricher();
		// Reshapes fcom-mobile's /media-gallery response for the MEDIA
		// sub-tab: flattens each item to leaf keys, and restores the
		// has_more / cursor / has_audio envelope keys that fcom-mobile
		// drops from FC Pro's response. See class docblock.
		$fcMediaGallery = new FluentCommunityMediaGalleryEnricher();
		// Normalizes feed responses on BOTH the list (/feeds) and the
		// single post (/feeds/by/slug): fills the video_url the post card
		// binds for FluentPlayer uploads, synthesizes a preview for audio
		// posts, and returns the single post as a list of one.
		$fcFeed = new FluentCommunityFeedEnricher();
		// Drops a viewer's cached preview-proxy reads as soon as that
		// viewer writes through fcom-mobile. Not just freshness: the
		// reaction controls send a toggle whose DIRECTION the client
		// reads off the row it holds, so a stale row makes the next tap
		// send the opposite of what was intended.
		$previewCache = new PreviewCacheInvalidator();
		// Puts the create-post composer's PER-VIEWER verdicts back on the
		// space-picker response: whether this viewer may schedule a post
		// and send the announcement email (global, and per space), and
		// whether a given space takes attachments. fcom-mobile's lean
		// transform computes and then drops all three. See class docblock.
		$fcComposer = new FluentCommunityComposerEnricher();
		// Gives each moderation report row the three strings the review
		// modal draws (design 30) — the headline, the reason's label and
		// a relative time — so the shells bind values rather than
		// reproducing FluentCommunity's own branch in two places.
		$fcModerationReports = new FluentCommunityModerationReportsEnricher();
		// Fills the profile fields our five-field edit form does not carry
		// — headline, is_verified, status, username — from the profile as
		// it stands, because FluentCommunity's updateProfile() rebuilds
		// meta from the request and an omitted field is ERASED rather than
		// left alone. See the class docblock for the exact code paths.
		$fcMemberUpdate = new FluentCommunityMemberUpdateCompleter();
		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $auth, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $fcFeedPerms, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcSpaceConfig, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcComposer, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcMediaGallery, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcFeed, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcModerationReports, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcMemberUpdate, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $previewCache, 'register_hooks' );
		// Filter needs to be up whenever FC processes a feed write —
		// which happens through rest_api_init for the mobile endpoint,
		// AND through admin-ajax for the FC portal. plugins_loaded is
		// the earliest common ancestor.
		$this->loader->add_action( 'plugins_loaded', $fcMediaCap, 'register_hooks' );
		// rest_pre_dispatch fires per-request, so registering on
		// rest_api_init (which fires once, right before dispatch) is
		// the right seam.
		$this->loader->add_action( 'rest_api_init', $fcFeedUpdateEnricher, 'register_hooks' );
	}

	protected function define_admin_hooks() {
		$menu       = new AdminMenu();
		$controller = new AdminController();
		$assets     = new AssetLoader();

		$this->loader->add_action( 'admin_menu', $menu, 'register_menu' );
		$this->loader->add_action( 'admin_notices', $controller, 'maybe_render_notice' );
		$this->loader->add_action( 'admin_post_' . AdminController::PULL_ACTION, $controller, 'handle_pull' );
		$this->loader->add_action( 'admin_enqueue_scripts', $assets, 'enqueue' );
		$this->loader->add_filter( 'script_loader_tag', $assets, 'add_module_type', 10, 2 );
	}

	public function run() {
		$this->loader->run();
	}

	public function get_loader() {
		return $this->loader;
	}
}