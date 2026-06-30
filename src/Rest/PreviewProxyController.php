<?php
/**
 * GET /wp-json/appza/v1/preview/data?ds=<slug>&params=<json>
 *
 * Backs the renderer's `useDataSource()` hook. Routes a catalog DataSource
 * slug to the matching WP REST endpoint that lives on this WP install
 * (typically a fcom-mobile / appza-builder controller). Caches per-session
 * via WP transients (5 min TTL) so the simulator's many AppZets each
 * fetching from the same source don't hammer the upstream.
 *
 * Same proxy serves both the plugin-admin simulator AND the Capacitor
 * mobile app, so the renderer never branches on context.
 *
 * Fallback: when the upstream returns 4xx/5xx OR the catalog row's endpoint
 * is missing (dev environment with broken legacy plugins), a small fixture
 * dataset keyed by data source slug is served so the preview still renders
 * realistic content.
 *
 * No auth at v1 (matches the bootstrap endpoint posture — admin-only access
 * is enforced by the React simulator's WP-admin context, and customer
 * mobile-app reads will gain JWT in Phase 1B.6).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewProxyController {

	const CACHE_TTL = 300;

	protected $snapshots;

	public function __construct( CatalogSnapshotRepository $snapshots = null ) {
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
	}

	public function handle( \WP_REST_Request $request ) {
		$ds_slug      = sanitize_title( (string) $request->get_param( 'ds' ) );
		$params_json  = (string) $request->get_param( 'params' );
		$template_slug = sanitize_title( (string) $request->get_param( 'template' ) ) ?: 'fluent-community-default';

		if ( '' === $ds_slug ) {
			return new \WP_Error( 'appza_preview_missing_ds', __( 'ds query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		$cache_key = 'appza_preview_' . md5( $template_slug . '|' . $ds_slug . '|' . $params_json );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
		$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json ) : null;

		if ( ! is_array( $payload ) ) {
			$payload = $this->fixture_for( $ds_slug );
		}

		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return rest_ensure_response( $payload );
	}

	protected function resolve_endpoint( $template_slug, $ds_slug ) {
		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		if ( ! $snapshot || ! is_array( $snapshot['snapshot_blob'] ) ) {
			return null;
		}
		$data_sources = isset( $snapshot['snapshot_blob']['data_sources'] )
			? (array) $snapshot['snapshot_blob']['data_sources']
			: array();
		foreach ( $data_sources as $ds ) {
			if ( isset( $ds['slug'] ) && (string) $ds['slug'] === $ds_slug ) {
				return isset( $ds['endpoint'] ) ? (string) $ds['endpoint'] : null;
			}
		}
		return null;
	}

	protected function fetch_upstream( $endpoint, $params_json ) {
		$url = home_url( $endpoint );
		$params = array();
		if ( '' !== $params_json ) {
			$decoded = json_decode( $params_json, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			}
		}
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'APPZA-Preview-Proxy',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Some upstreams wrap items: try common shapes.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $decoded['data'];
		}
		if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
			return $decoded['items'];
		}
		return $decoded;
	}

	protected function fixture_for( $ds_slug ) {
		switch ( $ds_slug ) {
			case 'fc-feed':
				return $this->fixture_fc_feed();
			default:
				return array();
		}
	}

	protected function fixture_fc_feed() {
		return array(
			array(
				'id'      => 1,
				'title'   => 'The Benefits of Reading',
				'content' => 'Reading every day helps improve knowledge, vocabulary, and focus. It also reduces stress and encourages creativity. Even a few minutes of reading daily can have a positive impact on personal growth and learning.',
				'mood_label' => '· Say Hello',
				'created_at' => '1m',
				'reactions_summary' => '45 likes',
				'comments_summary'  => '4 comments',
				'author'  => array(
					'display_name' => 'John Deo',
					'avatar_url'   => null,
				),
			),
			array(
				'id'      => 2,
				'title'   => 'Tips for Better Sleep',
				'content' => 'Stick to a schedule, dim the lights an hour before bed, and keep your phone out of arms reach. Small habits compound fast.',
				'mood_label' => '· Tip',
				'created_at' => '1h',
				'reactions_summary' => '12 likes',
				'comments_summary'  => '2 comments',
				'author'  => array(
					'display_name' => 'Bill Gets',
					'avatar_url'   => null,
				),
			),
			array(
				'id'      => 3,
				'title'   => 'Welcome to the Community',
				'content' => 'New here? Introduce yourself in the welcome thread — say where you are joining from and what you are working on.',
				'mood_label' => '· Pinned',
				'created_at' => '3h',
				'reactions_summary' => '89 likes',
				'comments_summary'  => '12 comments',
				'author'  => array(
					'display_name' => 'Community Bot',
					'avatar_url'   => null,
				),
			),
		);
	}
}
