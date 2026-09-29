<?php
namespace AppzaCore\Plugin\Admin;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Services\CoreUrl;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AdminMenu {

	const SLUG              = 'appza-core';
	const SLUG_SYNC         = 'appza-core-sync';
	const SLUG_SPACE_CONFIG = 'appza-core-space-config';
	const CAPABILITY        = 'manage_options';

	public function register_menu() {
		add_menu_page(
			__( 'APPZA Core', 'appza-core' ),
			__( 'APPZA Core', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_app' ),
			'dashicons-smartphone',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'APPZA Core — App', 'appza-core' ),
			__( 'App', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'APPZA Core — Sync', 'appza-core' ),
			__( 'Sync', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG_SYNC,
			array( $this, 'render_sync' )
		);

		// Space Config page deliberately unregistered; render_space_config stays for its REST/enricher use.
	}

	public function render_app() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'appza-core' ) );
		}

		require APPZA_CORE_PLUGIN_DIR . 'admin/views/app.php';
	}

	public function render_sync() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'appza-core' ) );
		}

		$meta      = ( new CatalogMetaRepository() )->get();
		$snapshots = ( new CatalogSnapshotRepository() )->all();

		$default_template = isset( $_GET['template'] ) ? sanitize_title( wp_unslash( $_GET['template'] ) ) : 'fluent-community-default';

		$pull_action_url = admin_url( 'admin-post.php' );
		$pull_nonce      = wp_create_nonce( AdminController::PULL_ACTION );
		$pull_action     = AdminController::PULL_ACTION;

		$bootstrap_endpoint = rest_url( APPZA_CORE_REST_NAMESPACE . '/bootstrap' );
		$core_api_url       = CoreUrl::raw();
		$core_url_source    = CoreUrl::source();
		$core_url           = CoreUrl::get();
		$core_url_error     = is_wp_error( $core_url ) ? $core_url->get_error_message() : '';
		$last_pulled_from   = (string) get_option( 'appza_core_last_pulled_from', '' );
		$url_action         = AdminController::URL_ACTION;
		$url_nonce          = wp_create_nonce( AdminController::URL_ACTION );
		$test_action        = AdminController::TEST_ACTION;
		$test_nonce         = wp_create_nonce( AdminController::TEST_ACTION );
		$token_action       = AdminController::TOKEN_ACTION;
		$token_nonce        = wp_create_nonce( AdminController::TOKEN_ACTION );
		$build_token_source = \AppzaCore\Plugin\Services\BuildToken::source();

		require APPZA_CORE_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function render_space_config() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'appza-core' ) );
		}

		$repo    = new \AppzaCore\Plugin\Repository\SpaceConfigRepository();
		$spaces  = $repo->list_spaces();
		$configs = array();
		foreach ( $repo->all() as $row ) {
			$configs[ $row['space_slug'] ] = $row['config'];
		}
		$rest_root = rest_url( APPZA_CORE_REST_NAMESPACE . '/space-config' );
		$nonce     = wp_create_nonce( 'wp_rest' );

		require APPZA_CORE_PLUGIN_DIR . 'admin/views/space-config.php';
	}
}
