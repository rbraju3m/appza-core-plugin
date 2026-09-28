<?php
/**
 * Handles the admin-post hook for "Pull from Core".
 *
 * Calls CoreClient to fetch a snapshot for the requested template_slug,
 * upserts the result into wp_appza_catalog_snapshot via the repository, and
 * bumps wp_appza_catalog_meta.catalog_snapshot_version on success. Outcomes
 * (success/error) flow back to the dashboard via transient admin notices.
 */

namespace AppzaCore\Plugin\Admin;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Services\CoreClient;
use AppzaCore\Plugin\Services\CoreUrl;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AdminController {

	const PULL_ACTION  = 'appza_core_pull_snapshot';
	const URL_ACTION   = 'appza_core_save_core_url';
	const TEST_ACTION  = 'appza_core_test_core_url';
	const NOTICE_OPTION = 'appza_core_admin_notice';

	protected $client;
	protected $snapshots;
	protected $meta;

	public function __construct( CoreClient $client = null, CatalogSnapshotRepository $snapshots = null, CatalogMetaRepository $meta = null ) {
		$this->client    = $client ?: new CoreClient();
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
		$this->meta      = $meta ?: new CatalogMetaRepository();
	}

	public function handle_pull() {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'appza-core' ) );
		}

		check_admin_referer( self::PULL_ACTION );

		$template_slug = isset( $_POST['template'] ) ? sanitize_title( wp_unslash( $_POST['template'] ) ) : '';
		if ( '' === $template_slug ) {
			$this->set_notice( 'error', __( 'Template slug is required.', 'appza-core' ) );
			$this->redirect_back( '' );
		}

		$result = $this->client->fetch_snapshot( $template_slug );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', sprintf(
				/* translators: 1: template slug, 2: error message */
				__( 'Pull failed for %1$s: %2$s', 'appza-core' ),
				$template_slug,
				$result->get_error_message()
			) );
			$this->redirect_back( $template_slug );
		}

		$this->snapshots->upsert(
			$template_slug,
			$result['snapshot'],
			(int) $result['catalog_snapshot_version'],
			(string) $result['core_api_version']
		);

		$this->meta->bump_catalog_snapshot_version();
		update_option( 'appza_core_last_pulled_at', current_time( 'mysql', true ), false );
		update_option( 'appza_core_last_pulled_from', CoreUrl::get(), false );

		$this->set_notice( 'success', sprintf(
			/* translators: 1: template slug, 2: version */
			__( 'Pulled snapshot for %1$s (version %2$d).', 'appza-core' ),
			$template_slug,
			(int) $result['catalog_snapshot_version']
		) );

		$this->redirect_back( $template_slug );
	}

	public function handle_save_url() {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'appza-core' ) );
		}

		check_admin_referer( self::URL_ACTION );

		if ( 'constant' === CoreUrl::source() ) {
			$this->set_notice( 'error', __( 'Core URL is set in wp-config.php and cannot be changed here.', 'appza-core' ) );
			$this->redirect_to_sync();
		}

		$input = isset( $_POST['core_api_url'] ) ? sanitize_text_field( wp_unslash( $_POST['core_api_url'] ) ) : '';
		$url   = CoreUrl::validate( $input );
		if ( is_wp_error( $url ) ) {
			$this->set_notice( 'error', $url->get_error_message() );
			$this->redirect_to_sync();
		}

		update_option( CoreUrl::OPTION, $url, false );
		$this->set_notice( 'success', sprintf( __( 'Core URL saved: %s', 'appza-core' ), $url ) );
		$this->redirect_to_sync();
	}

	public function handle_test_url() {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'appza-core' ) );
		}

		check_admin_referer( self::TEST_ACTION );

		$result = $this->client->fetch_source_integrations();
		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', sprintf( __( 'Connection failed: %s', 'appza-core' ), $result->get_error_message() ) );
		} else {
			$this->set_notice( 'success', sprintf( __( 'Connected to Core (API %s).', 'appza-core' ), (string) ( $result['core_api_version'] ?? '?' ) ) );
		}
		$this->redirect_to_sync();
	}

	public function maybe_render_notice() {
		$notice = get_option( self::NOTICE_OPTION );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_option( self::NOTICE_OPTION );

		$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	protected function set_notice( $type, $message ) {
		update_option( self::NOTICE_OPTION, array( 'type' => $type, 'message' => $message ), false );
	}

	protected function redirect_back( $template_slug ) {
		$url = add_query_arg(
			array(
				'page'     => AdminMenu::SLUG,
				'template' => $template_slug,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	protected function redirect_to_sync() {
		wp_safe_redirect( add_query_arg( 'page', AdminMenu::SLUG_SYNC, admin_url( 'admin.php' ) ) );
		exit;
	}
}
