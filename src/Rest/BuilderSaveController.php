<?php
/**
 * The builder's one Save (Friendly v2 P3): every unsaved layout and customization in one request,
 * written in one DB transaction. Nothing is written unless all of it can be.
 *
 *   POST /builder/save
 *   {
 *     layouts:        [{template, screen|null, part, items, base_version} | {…, remove: true}],
 *     customizations: [{scope, target_slug, target_slug_composite?, target_column, override_value} | {…, remove: true}]
 *   }
 *
 * Every item is checked first (422, naming each bad one); then the writes run in a transaction, and a
 * layout changed since it was loaded (`base_version`) rolls all of them back (409, naming it).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CustomizationRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BuilderSaveController {

	protected $repo;
	protected $layouts;
	protected $customizations;

	public function __construct( CustomizationRepository $repo = null, ScreenLayoutController $layouts = null, CustomizationsController $customizations = null ) {
		$this->repo           = $repo ?: new CustomizationRepository();
		$this->layouts        = $layouts ?: new ScreenLayoutController( $this->repo );
		$this->customizations = $customizations ?: new CustomizationsController( $this->repo );
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function save( \WP_REST_Request $request ) {
		$layouts        = $request->get_param( 'layouts' );
		$customizations = $request->get_param( 'customizations' );
		$layouts        = null === $layouts ? array() : $layouts;
		$customizations = null === $customizations ? array() : $customizations;
		if ( ! is_array( $layouts ) || ! is_array( $customizations ) ) {
			return $this->invalid( array( 'body' => 'layouts and customizations must be lists' ) );
		}

		list( $layout_ops, $errors ) = $this->check_layouts( array_values( $layouts ) );
		list( $custom_ops, $more )   = $this->check_customizations( array_values( $customizations ) );
		$errors                      = array_merge( $errors, $more );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		$versions  = array();
		$conflicts = array();
		foreach ( $layout_ops as $op ) {
			$result = $op['remove'] ? $this->remove_layout( $op ) : $this->write_layout( $op );
			if ( ! $result['ok'] ) {
				$conflicts[ $op['name'] ] = 'changed in another tab';
				continue;
			}
			$versions[] = array(
				'template' => $op['target']['template'],
				'screen'   => $op['target']['screen_slug'],
				'part'     => $op['target']['part'],
				'version'  => $result['version'],
			);
		}
		if ( $conflicts ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error(
				'appza_builder_conflict',
				__( 'Changed in another tab. Reload to see the latest. Nothing was saved.', 'appza-core' ),
				array( 'status' => 409, 'errors' => $conflicts )
			);
		}

		foreach ( $custom_ops as $op ) {
			if ( $op['remove'] ) {
				$row = $this->repo->find( $op['scope'], $op['target_slug'], $op['composite'], $op['column'] );
				if ( $row ) {
					$this->repo->delete( (int) $row['id'] );
				}
			} else {
				$this->repo->upsert( $op['scope'], $op['target_slug'], $op['composite'], $op['column'], $op['value'] );
			}
			if ( '' !== $wpdb->last_error ) {
				$failed = $op['name'];
				break;
			}
		}
		if ( isset( $failed ) || '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error(
				'appza_builder_failed',
				__( 'The changes could not be saved. Nothing was saved.', 'appza-core' ),
				array( 'status' => 500, 'errors' => array( $failed ?? 'database' => 'could not be written' ) )
			);
		}

		$wpdb->query( 'COMMIT' );
		return rest_ensure_response( array(
			'layouts' => $versions,
			'saved'   => count( $layout_ops ) + count( $custom_ops ),
		) );
	}

	/** @return array{0: array, 1: array<string,string>} */
	protected function check_layouts( array $list ) {
		$ops    = array();
		$errors = array();
		foreach ( $list as $i => $raw ) {
			$name = "layouts[{$i}]";
			if ( ! is_array( $raw ) ) {
				$errors[ $name ] = 'must be an object';
				continue;
			}
			$screen = isset( $raw['screen'] ) && '' !== $raw['screen'] ? (string) $raw['screen'] : null;
			$target = $this->layouts->resolve_target( $raw['template'] ?? '', $screen, $raw['part'] ?? '' );
			if ( is_wp_error( $target ) ) {
				$errors[ $name ] = $target->get_error_message();
				continue;
			}
			$name = $this->layout_name( $target );
			$base = $raw['base_version'] ?? null;
			if ( null !== $base && ! is_int( $base ) && ! ctype_digit( (string) $base ) ) {
				$errors[ $name ] = 'base_version must be a number or null';
				continue;
			}
			$op = array(
				'name'   => $name,
				'target' => $target,
				'base'   => null === $base ? null : (int) $base,
				'remove' => ! empty( $raw['remove'] ),
				'items'  => array(),
			);
			if ( ! $op['remove'] ) {
				list( $items, $item_errors ) = $target['layout']->validate( $target['part'], $raw['items'] ?? null, $target['screen'] );
				if ( $item_errors ) {
					$errors[ $name ] = implode( '; ', array_map(
						static function ( $k, $v ) {
							return "{$k}: {$v}";
						},
						array_keys( $item_errors ),
						array_values( $item_errors )
					) );
					continue;
				}
				$op['items'] = $items;
			}
			$ops[] = $op;
		}
		return array( $ops, $errors );
	}

	/** @return array{0: array, 1: array<string,string>} */
	protected function check_customizations( array $list ) {
		$ops    = array();
		$errors = array();
		foreach ( $list as $i => $raw ) {
			$name = "customizations[{$i}]";
			if ( ! is_array( $raw ) ) {
				$errors[ $name ] = 'must be an object';
				continue;
			}
			$scope  = (string) ( $raw['scope'] ?? '' );
			$column = (string) ( $raw['target_column'] ?? '' );
			$target = $this->customizations->validate_target( $scope, $raw['target_slug'] ?? null, $raw['target_slug_composite'] ?? null, $column );
			if ( is_wp_error( $target ) ) {
				$errors[ $name ] = $target->get_error_message();
				continue;
			}
			$remove = ! empty( $raw['remove'] );
			if ( ! $remove && ! array_key_exists( 'override_value', $raw ) ) {
				$errors[ $name ] = 'override_value is required';
				continue;
			}
			$ops[] = array(
				'name'        => $name,
				'scope'       => $scope,
				'target_slug' => $target[0],
				'composite'   => $target[1],
				'column'      => $column,
				'remove'      => $remove,
				'value'       => $remove ? null : $raw['override_value'],
			);
		}
		return array( $ops, $errors );
	}

	protected function write_layout( array $op ) {
		$t    = $op['target'];
		$core = $t['layout']->core_items( $t['part'], $t['screen'] );
		return $this->repo->save_layout(
			$t['scope'],
			$t['target_slug'],
			$t['template'],
			$t['column'],
			array( 'items' => $op['items'], 'core_hash' => $t['layout']->core_hash( $core ) ),
			$op['base']
		);
	}

	/** Back to Core's list, if nobody saved it since it was loaded. */
	protected function remove_layout( array $op ) {
		$t   = $op['target'];
		$row = $this->repo->find( $t['scope'], $t['target_slug'], $t['template'], $t['column'] );
		if ( ! $row ) {
			return array( 'ok' => null === $op['base'], 'version' => null );
		}
		if ( null === $op['base'] || (int) $row['version'] !== $op['base'] ) {
			return array( 'ok' => false, 'version' => (int) $row['version'] );
		}
		$this->repo->delete( (int) $row['id'] );
		return array( 'ok' => true, 'version' => null );
	}

	protected function layout_name( array $t ) {
		return null === $t['screen_slug'] ? $t['part'] : "{$t['screen_slug']} {$t['part']}";
	}

	protected function invalid( array $errors ) {
		return new \WP_Error(
			'appza_builder_invalid',
			__( 'Some changes are not valid. Nothing was saved.', 'appza-core' ),
			array( 'status' => 422, 'errors' => $errors )
		);
	}
}
