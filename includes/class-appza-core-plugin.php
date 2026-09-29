<?php
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
use AppzaCore\Plugin\Rest\FluentCommunityMemberUsernameShim;
use AppzaCore\Plugin\Rest\FluentCommunityCoursesController;
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
		if ( ! APPZA_CORE_ENABLE_JWT_AUTH ) {
			return;
		}
		( new AuthMiddleware() )->register();
	}

	protected function define_maintenance_hooks() {
		$this->loader->add_action( 'plugins_loaded', $this, 'maybe_upgrade_schema' );
		$this->loader->add_action( Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS, $this, 'purge_refresh_tokens' );
		$this->loader->add_action( Appza_Core_Activator::CRON_SWEEP_CHAT_MEDIA, $this, 'sweep_chat_media' );
	}

	// Activation hooks don't re-fire on update, so schema and crons are brought current here.
	public function maybe_upgrade_schema() {
		if ( SchemaManager::DB_VERSION !== (string) get_option( 'appza_core_db_version', '0' ) ) {
			SchemaManager::install();
		}
		if ( ! wp_next_scheduled( Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Appza_Core_Activator::CRON_PURGE_REFRESH_TOKENS );
		}
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
		$fcFeedPerms = new FluentCommunityFeedPermissionsFilter();
		$fcMediaCap = new FluentCommunityMediaCap();
		$fcFeedUpdateEnricher = new FluentCommunityFeedUpdateEnricher();
		$fcSpaceConfig = new FluentCommunitySpaceConfigEnricher();
		$fcMediaGallery = new FluentCommunityMediaGalleryEnricher();
		$fcFeed = new FluentCommunityFeedEnricher();
		$previewCache = new PreviewCacheInvalidator();
		$fcComposer = new FluentCommunityComposerEnricher();
		$fcModerationReports = new FluentCommunityModerationReportsEnricher();
		$fcMemberUpdate = new FluentCommunityMemberUpdateCompleter();
		$fcMemberUsername = new FluentCommunityMemberUsernameShim();
		$fcCourses = new FluentCommunityCoursesController();
		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );
		if ( APPZA_CORE_ENABLE_JWT_AUTH ) {
			$this->loader->add_action( 'rest_api_init', $auth, 'register_routes' );
		}
		$this->loader->add_action( 'rest_api_init', $fcFeedPerms, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcSpaceConfig, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcComposer, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcMediaGallery, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcFeed, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcModerationReports, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcMemberUpdate, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcMemberUsername, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcCourses, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', new \AppzaCore\Plugin\Rest\FluentCommunityChatController(), 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $previewCache, 'register_hooks' );
		// plugins_loaded, not rest_api_init: FC also writes feeds through admin-ajax.
		$this->loader->add_action( 'plugins_loaded', $fcMediaCap, 'register_hooks' );
		$this->loader->add_action( 'rest_api_init', $fcFeedUpdateEnricher, 'register_hooks' );
	}

	protected function define_admin_hooks() {
		$menu       = new AdminMenu();
		$controller = new AdminController();
		$assets     = new AssetLoader();

		$this->loader->add_action( 'admin_menu', $menu, 'register_menu' );
		$this->loader->add_action( 'admin_notices', $controller, 'maybe_render_notice' );
		$this->loader->add_action( 'admin_post_' . AdminController::PULL_ACTION, $controller, 'handle_pull' );
		$this->loader->add_action( 'admin_post_' . AdminController::URL_ACTION, $controller, 'handle_save_url' );
		$this->loader->add_action( 'admin_post_' . AdminController::TEST_ACTION, $controller, 'handle_test_url' );
		$this->loader->add_action( 'admin_post_' . AdminController::TOKEN_ACTION, $controller, 'handle_save_build_token' );
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