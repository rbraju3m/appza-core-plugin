<?php
/**
 * POST /wp-json/appza/v1/sync-from-core
 *
 * The React simulator's "Sync" button calls this to trigger a fresh
 * snapshot pull from the Laravel core — same effect as the legacy
 * "Pull from Core" admin-post form on the Sync page, without the
 * navigate-away round trip.
 *
 * Auth: manage_options + X-WP-Nonce (mirrors CustomizationsController).
 * On success returns `{ template_slug, catalog_snapshot_version,
 * core_api_version, pulled_at }` so the client can render a toast.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SyncFromCoreController {

	protected $client;
	protected $snapshots;
	protected $meta;

	public function __construct(
		CoreClient $client = null,
		CatalogSnapshotRepository $snapshots = null,
		CatalogMetaRepository $meta = null
	) {
		$this->client    = $client ?: new CoreClient();
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
		$this->meta      = $meta ?: new CatalogMetaRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function handle( \WP_REST_Request $request ) {
		$template_slug = sanitize_title( (string) $request->get_param( 'template' ) );
		if ( '' === $template_slug ) {
			return new \WP_Error(
				'appza_sync_missing_template',
				__( 'template is required.', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->client->fetch_snapshot( $template_slug );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'appza_sync_upstream_failed',
				sprintf(
					/* translators: 1: template slug, 2: upstream error message */
					__( 'Pull failed for %1$s: %2$s', 'appza-core' ),
					$template_slug,
					$result->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$this->snapshots->upsert(
			$template_slug,
			$result['snapshot'],
			(int) $result['catalog_snapshot_version'],
			(string) $result['core_api_version']
		);

		$this->meta->bump_catalog_snapshot_version();
		$pulled_at = current_time( 'mysql', true );
		update_option( 'appza_core_last_pulled_at', $pulled_at, false );

		return rest_ensure_response(
			array(
				'template_slug'            => $template_slug,
				'catalog_snapshot_version' => (int) $result['catalog_snapshot_version'],
				'core_api_version'         => (string) $result['core_api_version'],
				'pulled_at'                => $pulled_at,
			)
		);
	}
}