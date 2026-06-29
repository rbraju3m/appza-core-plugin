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

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BootstrapController {

	protected $snapshots;
	protected $meta;

	public function __construct( CatalogSnapshotRepository $snapshots = null, CatalogMetaRepository $meta = null ) {
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
		$this->meta      = $meta ?: new CatalogMetaRepository();
	}

	public function handle( \WP_REST_Request $request ) {
		$template_slug = (string) $request->get_param( 'template' );

		if ( '' === $template_slug ) {
			return new \WP_Error( 'appza_bootstrap_missing_template', __( 'template query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		$meta     = $this->meta->get();

		$catalog = $snapshot && is_array( $snapshot['snapshot_blob'] )
			? $snapshot['snapshot_blob']
			: $this->empty_catalog( $template_slug );

		$response = array(
			'schema_version'           => APPZA_CORE_CONTRACTS_VERSION,
			'catalog_snapshot_version' => $snapshot ? (int) $snapshot['catalog_snapshot_version'] : 0,
			'customizations_version'   => (int) $meta['customizations_version'],
			'catalog'                  => $catalog,
			// Always a JSON object (possibly empty {}), never an array — the
			// runtime customizations read is a scope -> target -> column ->
			// override map. PHP's empty array() serializes to [] so we cast
			// to stdClass to force object encoding. Phase 1B.5+ replaces
			// this empty stub with the real wp_appza_customizations read.
			'customizations'           => new \stdClass(),
			'runtime_config'           => $this->runtime_config( $catalog ),
		);

		return rest_ensure_response( $response );
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
		);
	}
}