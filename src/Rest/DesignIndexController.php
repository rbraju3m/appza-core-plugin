<?php
/**
 * GET /wp-json/appza/v1/design-index?template=<slug> — the simulator's Design tab index (admin only).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\DesignIndexRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class DesignIndexController {

	protected $design;

	public function __construct( DesignIndexRepository $design = null ) {
		$this->design = $design ?: new DesignIndexRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function handle( \WP_REST_Request $request ) {
		$template_slug = (string) $request->get_param( 'template' );
		$index         = '' === $template_slug ? null : $this->design->find( $template_slug );
		if ( null === $index ) {
			return new \WP_Error(
				'appza_design_index_missing',
				__( 'No design index for this template yet. Sync from Core.', 'appza-core' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $index );
	}
}
