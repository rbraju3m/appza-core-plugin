<?php
namespace AppzaCore\Plugin\Admin;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Services\CoreUrl;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Rest\BuildRequestsController;
use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AdminMenu {

	const SLUG              = 'appza-core';
	const SLUG_SYNC         = 'appza-core-sync';
	const SLUG_SPACE_CONFIG = 'appza-core-space-config';
	const SLUG_BUILDS       = 'appza-core-builds';
	const CAPABILITY        = 'manage_options';

	// The v1 plug-in's menu icon.
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjIiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgdmlld0JveD0iMCAwIDIwIDE4IiB3aWR0aD0iMjAiIGhlaWdodD0iMTgiPgoJPHRpdGxlPk5ldyBQcm9qZWN0PC90aXRsZT4KCTxkZWZzPgoJCTxpbWFnZSB3aWR0aD0iMjgiIGhlaWdodD0iMjgiIGlkPSJpbWcxIiBocmVmPSJkYXRhOmltYWdlL3BuZztiYXNlNjQsaVZCT1J3MEtHZ29BQUFBTlNVaEVVZ0FBQUJ3QUFBQWNDQVlBQUFCeURkK1VBQUFBQ1hCSVdYTUFBQXNUQUFBTEV3RUFtcHdZQUFBQUFYTlNSMElBcnM0YzZRQUFBQVJuUVUxQkFBQ3hqd3Y4WVFVQUFBSHpTVVJCVkhnQnZaWTlMQU5oSE1hZjkwZ3d0QXhNeE5kbW9MVmdxZFdBUkF4V01UTXdtRmd0Rm9rd2kwUWlNUWpCWUJNbUZsK0RUVFRScmNOaDBZU2U5K25ieTEybDk5N2J0TDNmZFBmMm4vZnAvL2wvNUFRa3psWnpMeHJGSGh3azVXc2JhbzNBQ1g2Y0ZiSHkvU1lLWWczaXZpNUNwZGo0ZFlZdEtiWVZnUmdLR2cxaXo1SVBNNGlPcElWb2FXdXNLTHlwRllqMUFCMUQzdGxuR3NnK0Fia1BveXZNQlB1bnBSbUxRT2Q0Y016TEFYQ3pHaW9zbk8wV1J4c1JseG5OdjhBSVpubzRwZzBKejVDV1phNVZkcGtiNEYwK2Y2WFZPZi9NNkpxeW1iUkxxeE5Md09OTzRIWGhHYm9Yc1g0VS9BL1A2VUJUY2JJWWN6d1JlSlZabDlLcWNtS0VOWHM5OTk1ajNkQlJtN0dndllaVU5oYXNZL3Vnc2kvZTQ1Mzd4NlJxUWRZb0tSc2hzZWpWcVFyMGduRlpqOWxMcnd0SnpwWTFmVlkyc241ODcwcnBaOVJZY0dTOVZPeGhGN2piVUNJbHJOVklrUC9jaFJseGs1U0R0aHVpNzFKL2RrRmpRU3BvR3IyZzN6cC9WL3BoUXhuYUdTN0lnWGZwVEtrMTV0b1hLNjYxMUNZcVFiL2FLTUl1RGVOaURwZzhVcytzOWY1QVlLZytROWJ0ZGlQNGQxck92Y25WeGdWdmdObnladjM2cG1SekpJcENjdjZ5ajBySXJUUGRZRnhodDU1VktWaERhS21OQ0tIZ0E2TEN3YWtsUDA0WEVFMldOdkxPc3NYUGIzNFJRK1JQVUI5czJTbFgxS0RXSDJKZG5wa0ducFJHQUFBQUFFbEZUa1N1UW1DQyIvPgoJPC9kZWZzPgoJPHN0eWxlPgoJPC9zdHlsZT4KCTx1c2UgaWQ9ImFhcHBpZnktbWVudS1pY29uIiBocmVmPSIjaW1nMSIgdHJhbnNmb3JtPSJtYXRyaXgoLjY0MywwLDAsLjY0MywxLDApIi8+Cjwvc3ZnPg==';

	public function register_menu() {
		add_menu_page(
			__( 'APPZA Core', 'appza-core' ),
			__( 'APPZA Core', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_app' ),
			self::MENU_ICON,
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

		add_submenu_page(
			self::SLUG,
			__( 'APPZA Core — Build History', 'appza-core' ),
			__( 'Build History', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG_BUILDS,
			array( $this, 'render_builds' )
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

	public function render_builds() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'appza-core' ) );
		}

		$log = get_option( BuildRequestsController::OPTION_KEY, array() );
		$log = is_array( $log ) ? array_reverse( $log ) : array();

		$core  = array();
		$error = '';
		if ( $log ) {
			$result = ( new CoreClient() )->build_list( array_column( $log, 'id' ) );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				foreach ( $result as $build ) {
					$core[ $build['id'] ] = $build;
				}
			}
		}

		require APPZA_CORE_PLUGIN_DIR . 'admin/views/build-history.php';
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
