<?php
/**
 * GET /wp-json/appza/v1/source-integrations
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SourceIntegrationsController {

	protected $core;

	public function __construct( CoreClient $core = null ) {
		$this->core = $core ?: new CoreClient();
	}

	public function handle( \WP_REST_Request $request ) {
		$envelope = $this->core->fetch_source_integrations();
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Folder and main file can differ (tutor-lms-api/tutor-lms-mobile.php), so match either.
		$active_pairs = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || false === strpos( $plugin_file, '/' ) ) {
				continue;
			}
			$folder            = strtok( $plugin_file, '/' );
			$file_basename     = basename( $plugin_file, '.php' );
			$active_pairs[]    = array( $folder, $file_basename );
		}

		$rows = array();
		foreach ( $envelope['source_integrations'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) || empty( $row['addon_plugin_slug'] ) ) {
				continue;
			}
			$addon = (string) $row['addon_plugin_slug'];
			$is_installed = false;
			foreach ( $active_pairs as $pair ) {
				if ( $pair[0] === $addon || $pair[1] === $addon ) {
					$is_installed = true;
					break;
				}
			}
			$rows[]       = array(
				'slug'              => (string) $row['slug'],
				'name'              => (string) $row['name'],
				'addon_plugin_slug' => $addon,
				'addon_min_version' => isset( $row['addon_min_version'] ) ? (string) $row['addon_min_version'] : '',
				'description'       => isset( $row['description'] ) ? (string) $row['description'] : '',
				'image_url'         => isset( $row['image_url'] ) ? (string) $row['image_url'] : null,
				'is_installed'      => $is_installed,
				'is_disable'        => ! $is_installed,
			);
		}

		$video = self::embed_url( isset( $envelope['tutorial_video_url'] ) ? $envelope['tutorial_video_url'] : null );
		if ( get_option( self::VIDEO_OPTION, '' ) !== $video ) {
			update_option( self::VIDEO_OPTION, $video, false );
		}

		$demo = self::demo_links( isset( $envelope['demo_app'] ) ? $envelope['demo_app'] : null );
		if ( get_option( self::DEMO_OPTION, array() ) !== $demo ) {
			update_option( self::DEMO_OPTION, $demo, false );
		}

		return rest_ensure_response( array(
			'core_api_version'    => isset( $envelope['core_api_version'] ) ? (string) $envelope['core_api_version'] : '',
			'source_integrations' => $rows,
			'tutorial_video_url'  => '' === $video ? null : $video,
			'demo_app'            => $demo,
		) );
	}

	const VIDEO_OPTION = 'appza_core_tutorial_video_url';
	const DEMO_OPTION  = 'appza_core_demo_app';

	/** The demo app's store pages Core last gave (each null when unset). */
	public static function demo_app() {
		return self::demo_links( get_option( self::DEMO_OPTION, array() ) );
	}

	// Store pages only: they become QR codes in wp-admin.
	protected static function demo_links( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$play  = isset( $raw['play_store_url'] ) && is_string( $raw['play_store_url'] ) ? $raw['play_store_url'] : '';
		$apple = isset( $raw['app_store_url'] ) && is_string( $raw['app_store_url'] ) ? $raw['app_store_url'] : '';
		return array(
			'play_store_url' => preg_match( '#^https://play\.google\.com/store/apps/details\?id=[A-Za-z0-9._]+$#', $play ) ? $play : null,
			'app_store_url'  => preg_match( '#^https://apps\.apple\.com/[A-Za-z0-9/_-]*id\d+$#', $apple ) ? $apple : null,
		);
	}

	/** The last video address Core gave, for the builder's first paint. */
	public static function tutorial_video_url() {
		$url = self::embed_url( get_option( self::VIDEO_OPTION, '' ) );
		return '' === $url ? null : $url;
	}

	// It goes into an iframe in wp-admin: only the two players' embed addresses pass, whatever Core sends.
	protected static function embed_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$ok = preg_match( '#^https://www\.youtube\.com/embed/[A-Za-z0-9_-]{6,20}$#', $url )
			|| preg_match( '#^https://player\.vimeo\.com/video/\d+(\?h=[0-9a-f]+)?$#', $url );
		return $ok ? $url : '';
	}
}
