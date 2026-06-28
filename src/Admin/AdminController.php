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

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AdminController {

	const PULL_ACTION  = 'appza_core_pull_snapshot';
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

		$this->set_notice( 'success', sprintf(
			/* translators: 1: template slug, 2: version */
			__( 'Pulled snapshot for %1$s (version %2$d).', 'appza-core' ),
			$template_slug,
			(int) $result['catalog_snapshot_version']
		) );

		$this->redirect_back( $template_slug );
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
}