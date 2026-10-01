<?php
/**
 * The builder's layouts (D110), one row per resource:
 *   /screen-layout/<template>/<screen>/<body|appbar>
 *   /chrome-layout/<template>/<appbar|nav|drawer|drawer-menu>
 * GET shows Core's list, the site's and the palette; PUT replaces the site's; DELETE resets to Core.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Repository\CustomizationRepository;
use AppzaCore\Plugin\Services\ScreenLayout;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class ScreenLayoutController {

	protected $repo;
	protected $snapshots;

	public function __construct( CustomizationRepository $repo = null, CatalogSnapshotRepository $snapshots = null ) {
		$this->repo      = $repo ?: new CustomizationRepository();
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function show( \WP_REST_Request $request ) {
		$target = $this->resolve( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$row   = $this->row( $target );
		$saved = $row ? json_decode( (string) $row['override_value'], true ) : null;
		$core  = $target['layout']->core_items( $target['part'], $target['screen'] );
		$hash  = $target['layout']->core_hash( $core );

		return rest_ensure_response( array(
			'template'  => $target['template'],
			'screen'    => $target['screen_slug'],
			'part'      => $target['part'],
			'core'      => $core,
			'site'      => is_array( $saved ) ? ( $saved['items'] ?? array() ) : null,
			'version'   => $row ? (int) $row['version'] : null,
			'palette'   => 'drawer-menu' === $target['part'] ? array() : $target['layout']->palette( $target['part'], $target['screen'] ),
			'core_hash' => $hash,
			// Core changed this list since the site saved it: the builder asks, it never merges.
			'drift'     => is_array( $saved ) && ( $saved['core_hash'] ?? null ) !== $hash,
		) );
	}

	public function save( \WP_REST_Request $request ) {
		$target = $this->resolve( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$base = $request->get_param( 'base_version' );
		if ( null !== $base && ! is_int( $base ) && ! ctype_digit( (string) $base ) ) {
			return new \WP_Error( 'appza_layout_invalid', __( 'base_version must be a number or null', 'appza-core' ), array( 'status' => 422, 'errors' => array( 'base_version' => 'must be a number or null' ) ) );
		}

		list( $items, $errors ) = $target['layout']->validate( $target['part'], $request->get_param( 'items' ), $target['screen'] );
		if ( $errors ) {
			return new \WP_Error( 'appza_layout_invalid', __( 'The layout was not saved.', 'appza-core' ), array( 'status' => 422, 'errors' => $errors ) );
		}

		$core   = $target['layout']->core_items( $target['part'], $target['screen'] );
		$result = $this->repo->save_layout(
			$target['scope'],
			$target['target_slug'],
			$target['template'],
			$target['column'],
			array( 'items' => $items, 'core_hash' => $target['layout']->core_hash( $core ) ),
			null === $base ? null : (int) $base
		);
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'appza_layout_conflict', __( 'Changed in another tab. Reload to see the latest.', 'appza-core' ), array( 'status' => 409, 'version' => $result['version'] ) );
		}
		return rest_ensure_response( array( 'items' => $items, 'version' => $result['version'] ) );
	}

	public function reset( \WP_REST_Request $request ) {
		$target = $this->resolve( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$deleted = $this->repo->delete_layout( $target['scope'], $target['target_slug'], $target['template'], $target['column'] );
		return rest_ensure_response( array( 'deleted' => $deleted > 0 ) );
	}

	protected function row( array $t ) {
		return $this->repo->find( $t['scope'], $t['target_slug'], $t['template'], $t['column'] );
	}

	protected function resolve( \WP_REST_Request $request ) {
		$template    = (string) $request->get_param( 'template' );
		$screen_slug = $request->get_param( 'screen' );
		$part        = (string) $request->get_param( 'part' );
		$is_screen   = null !== $screen_slug;
		$parts       = $is_screen ? ScreenLayout::SCREEN_PARTS : ScreenLayout::CHROME_PARTS;

		if ( ! isset( $parts[ $part ] ) ) {
			return new \WP_Error( 'appza_layout_not_found', __( 'Unknown layout part.', 'appza-core' ), array( 'status' => 404 ) );
		}
		$row = $this->snapshots->find_by_template_slug( $template );
		if ( ! $row || ! is_array( $row['snapshot_blob'] ) ) {
			return new \WP_Error( 'appza_layout_not_found', __( 'That template has not been synced.', 'appza-core' ), array( 'status' => 404 ) );
		}
		$layout = new ScreenLayout( $row['snapshot_blob'] );
		$screen = null;
		if ( $is_screen ) {
			$screen = $layout->screen( (string) $screen_slug );
			if ( ! $screen ) {
				return new \WP_Error( 'appza_layout_not_found', __( 'That screen is not in the template.', 'appza-core' ), array( 'status' => 404 ) );
			}
		}
		return array(
			'template'    => $template,
			'screen_slug' => $is_screen ? (string) $screen_slug : null,
			'screen'      => $screen,
			'part'        => $part,
			'layout'      => $layout,
			'scope'       => $is_screen ? 'template_screen' : 'template',
			'target_slug' => $is_screen ? (string) $screen_slug : $template,
			'column'      => $parts[ $part ],
		);
	}
}
