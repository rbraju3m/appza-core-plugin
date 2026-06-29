<?php
/**
 * REST CRUD for wp_appza_customizations.
 *
 * Endpoints (all under appza/v1):
 *   POST   /customizations               — upsert one row (composite-key matched)
 *   DELETE /customizations/(?P<id>\d+)   — remove one row by primary key
 *
 * Auth: every route requires `manage_options` + a valid X-WP-Nonce header
 * (the React app gets the nonce + URLs localized via window.appzaCoreConfig).
 * Same gate as the legacy admin-post pull form — overrides are a customer-
 * privilege operation.
 *
 * GET-ing the current overrides is intentionally NOT a separate endpoint;
 * the bootstrap envelope already carries the full nested tree, so the
 * React app reads them from there and refetches the envelope after a
 * mutate. Avoids a parallel source of truth.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CustomizationRepository;
use AppzaCore\Plugin\Schema\CustomizationSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class CustomizationsController {

	protected $repo;

	public function __construct( CustomizationRepository $repo = null ) {
		$this->repo = $repo ?: new CustomizationRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function list( \WP_REST_Request $request ) {
		$rows = $this->repo->all();
		$normalized = array();
		foreach ( $rows as $row ) {
			$value = json_decode( (string) $row['override_value'], false );
			if ( is_array( $value ) && empty( $value ) ) {
				$value = new \stdClass();
			}
			$normalized[] = array(
				'id'                    => (int) $row['id'],
				'scope'                 => $row['scope'],
				'target_slug'           => $row['target_slug'],
				'target_slug_composite' => $row['target_slug_composite'],
				'target_column'         => $row['target_column'],
				'override_value'        => $value,
				'version'               => (int) $row['version'],
				'updated_at'            => $row['updated_at'],
			);
		}
		return rest_ensure_response( $normalized );
	}

	public function upsert( \WP_REST_Request $request ) {
		$scope                 = (string) $request->get_param( 'scope' );
		$target_slug           = $request->get_param( 'target_slug' );
		$target_slug_composite = $request->get_param( 'target_slug_composite' );
		$target_column         = (string) $request->get_param( 'target_column' );
		$override_value        = $request->get_param( 'override_value' );

		if ( ! in_array( $scope, CustomizationSchema::SCOPES, true ) ) {
			return new \WP_Error(
				'appza_customizations_invalid_scope',
				sprintf( __( 'Unknown scope: %s', 'appza-core' ), $scope ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $target_column ) {
			return new \WP_Error(
				'appza_customizations_missing_column',
				__( 'target_column is required', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		// scope=global allows null target_slug; every other scope requires one.
		if ( 'global' !== $scope && ( ! is_string( $target_slug ) || '' === $target_slug ) ) {
			return new \WP_Error(
				'appza_customizations_missing_target',
				sprintf( __( 'target_slug is required for scope %s', 'appza-core' ), $scope ),
				array( 'status' => 400 )
			);
		}

		// Compose normalised values for the repo (NULL not "" for empty slugs).
		$target_slug           = ( is_string( $target_slug ) && '' !== $target_slug ) ? $target_slug : null;
		$target_slug_composite = ( is_string( $target_slug_composite ) && '' !== $target_slug_composite ) ? $target_slug_composite : null;

		$id = $this->repo->upsert( $scope, $target_slug, $target_slug_composite, $target_column, $override_value );

		return rest_ensure_response( array(
			'id'                    => $id,
			'scope'                 => $scope,
			'target_slug'           => $target_slug,
			'target_slug_composite' => $target_slug_composite,
			'target_column'         => $target_column,
			'override_value'        => $this->normalize_response_value( $override_value ),
		) );
	}

	public function delete( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'appza_customizations_invalid_id', __( 'Invalid id', 'appza-core' ), array( 'status' => 400 ) );
		}
		$deleted = $this->repo->delete( $id );
		if ( 0 === $deleted ) {
			return new \WP_Error( 'appza_customizations_not_found', __( 'Row not found', 'appza-core' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	protected function normalize_response_value( $value ) {
		if ( is_array( $value ) && empty( $value ) ) {
			return new \stdClass();
		}
		return $value;
	}
}