<?php
/**
 * Registers the APPZA Core top-level admin menu + renders the dashboard.
 *
 * v1 surface: a single dashboard page that shows version markers, the per-
 * Template snapshot list, and a "Pull from Core" button. Settings + a
 * customizations editor + a sweep audit log land in later Phase 1B slices.
 */

namespace AppzaCore\Plugin\Admin;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AdminMenu {

	const SLUG       = 'appza-core';
	const CAPABILITY = 'manage_options';

	public function register_menu() {
		add_menu_page(
			__( 'APPZA Core', 'appza-core' ),
			__( 'APPZA Core', 'appza-core' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-smartphone',
			58
		);
	}

	public function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'appza-core' ) );
		}

		$meta      = ( new CatalogMetaRepository() )->get();
		$snapshots = ( new CatalogSnapshotRepository() )->all();

		$default_template = isset( $_GET['template'] ) ? sanitize_title( wp_unslash( $_GET['template'] ) ) : 'fc-default';

		$pull_action_url = admin_url( 'admin-post.php' );
		$pull_nonce      = wp_create_nonce( AdminController::PULL_ACTION );
		$pull_action     = AdminController::PULL_ACTION;

		$bootstrap_endpoint = rest_url( APPZA_CORE_REST_NAMESPACE . '/bootstrap' );
		$core_api_url       = APPZA_CORE_API_URL;

		require APPZA_CORE_PLUGIN_DIR . 'admin/views/dashboard.php';
	}
}