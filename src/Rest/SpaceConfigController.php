<?php
namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\SpaceConfigRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SpaceConfigController {

	protected $repo;

	public function __construct( ?SpaceConfigRepository $repo = null ) {
		$this->repo = $repo ?: new SpaceConfigRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function list_all() {
		return rest_ensure_response(
			array(
				'spaces'  => $this->repo->list_spaces(),
				'configs' => $this->repo->all(),
			)
		);
	}

	public function get_one( \WP_REST_Request $request ) {
		$slug   = (string) $request->get_param( 'slug' );
		$config = $this->repo->get( $slug );
		return rest_ensure_response(
			array(
				'space_slug' => $slug,
				'config'     => $config ?? new \stdClass(),
			)
		);
	}

	public function save( \WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'appza_bad_body', 'Body must be a JSON object.', array( 'status' => 400 ) );
		}
		$config = isset( $body['config'] ) && is_array( $body['config'] ) ? $body['config'] : $body;
		$config = $this->sanitize_config( $config );
		$ok     = $this->repo->upsert( $slug, $config );
		if ( ! $ok ) {
			return new \WP_Error( 'appza_db_write_failed', 'Failed to save config.', array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'ok'         => true,
				'space_slug' => $slug,
				'config'     => $config ?: new \stdClass(),
			)
		);
	}

	protected function sanitize_config( array $in ): array {
		$out = array();
		foreach ( array( 'switcher', 'topics', 'cover' ) as $key ) {
			if ( isset( $in[ $key ] ) && is_array( $in[ $key ] ) ) {
				$out[ $key ] = $this->clean_object( $in[ $key ] );
			}
		}
		if ( isset( $in['subtabs'] ) && is_array( $in['subtabs'] ) ) {
			$rows = array();
			foreach ( $in['subtabs'] as $row ) {
				if ( ! is_array( $row ) ) continue;
				$clean = array();
				if ( isset( $row['value'] ) && is_string( $row['value'] ) )         $clean['value']  = sanitize_key( $row['value'] );
				if ( isset( $row['label'] ) && is_string( $row['label'] ) )         $clean['label']  = sanitize_text_field( $row['label'] );
				if ( isset( $row['hidden'] ) )                                      $clean['hidden'] = (bool) $row['hidden'];
				if ( isset( $row['icon'] ) && is_string( $row['icon'] ) )           $clean['icon']   = sanitize_text_field( $row['icon'] );
				if ( isset( $row['target_screen'] ) && is_string( $row['target_screen'] ) ) {
					$clean['target_screen'] = sanitize_title( $row['target_screen'] );
				}
				if ( isset( $clean['value'] ) && $clean['value'] !== '' ) {
					$rows[] = $clean;
				}
			}
			if ( $rows ) {
				$out['subtabs'] = $rows;
			}
		}
		return $out;
	}

	protected function clean_object( array $in ): array {
		$out = array();
		foreach ( $in as $k => $v ) {
			$key = is_string( $k ) ? sanitize_key( $k ) : $k;
			if ( is_array( $v ) ) {
				$out[ $key ] = $this->clean_object( $v );
			} elseif ( is_string( $v ) ) {
				$out[ $key ] = sanitize_text_field( $v );
			} elseif ( is_bool( $v ) || is_numeric( $v ) ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}
}
