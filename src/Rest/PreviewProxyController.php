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
		$mode         = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( ! in_array( $mode, array( 'demo', 'real', 'auto' ), true ) ) {
			$mode = 'auto';
		}

		if ( '' === $ds_slug ) {
			return new \WP_Error( 'appza_preview_missing_ds', __( 'ds query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		$cache_key = 'appza_preview_' . md5( $template_slug . '|' . $ds_slug . '|' . $params_json . '|' . $mode );
		// `nocache=1` bypasses the transient read (still writes fresh result
		// after fetch, so subsequent normal reads benefit). Used by
		// plug-in-admin's Refresh button + window-focus revalidation to
		// pull new upstream posts without waiting the 5-min TTL.
		$nocache = '1' === (string) $request->get_param( 'nocache' );
		if ( ! $nocache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return rest_ensure_response( $cached );
			}
		}

		if ( 'demo' === $mode ) {
			$payload = $this->fixture_for( $ds_slug );
		} elseif ( 'real' === $mode ) {
			$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		} else {
			$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = $this->fixture_for( $ds_slug );
			}
		}

		$payload = $this->normalize_items( $payload );

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

	/**
	 * Normalize each item so text bindings render cleanly.
	 * - Strips HTML from `message_rendered` (rendered arrives as
	 *   `<p>...</p>` from FluentCommunity; text bindings would otherwise
	 *   show the literal tags).
	 * - Converts `created_at` datetime string to a compact relative form
	 *   (`5m`, `2h`, `3d`) so the text bindings don't blow up the header
	 *   row width.
	 *
	 * Applied to both fixture and real payloads at handle() time, so the
	 * fixture shape stays honest to the real API + demo/real render the
	 * same way.
	 */
	protected function normalize_items( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['message_rendered'] ) && is_string( $item['message_rendered'] ) ) {
				$items[ $i ]['message_rendered'] = $this->sanitize_rich_text( $item['message_rendered'] );
			}
			if ( isset( $item['created_at'] ) && is_string( $item['created_at'] ) && '' !== $item['created_at'] ) {
				$items[ $i ]['created_at'] = $this->relative_time( $item['created_at'] );
			}
			if ( isset( $item['reactions_count'] ) && is_numeric( $item['reactions_count'] ) ) {
				$items[ $i ]['reactions_summary'] = $this->pluralize( (int) $item['reactions_count'], 'like', 'likes' );
			}
			if ( isset( $item['comments_count'] ) && is_numeric( $item['comments_count'] ) ) {
				$items[ $i ]['comments_summary'] = $this->pluralize( (int) $item['comments_count'], 'comment', 'comments' );
			}
			// Multi-image posts arrive on `meta.media_items` (array).
			// Single-image posts arrive on `meta.media_preview` with
			// type=meta_data. Video/poll variants have neither and skip.
			// The wp_fcom_media_archive DB table is intentionally NOT queried
			// here — it holds abandoned uploads too, and would over-count.
			$images = $this->build_images_from_meta( isset( $item['meta'] ) ? $item['meta'] : array() );
			if ( ! empty( $images ) ) {
				$items[ $i ]['images'] = $images;
			}
		}
		return $items;
	}

	protected function build_images_from_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return array();
		}
		// Preferred: `media_items` array (fcom multi-image posts).
		if ( isset( $meta['media_items'] ) && is_array( $meta['media_items'] ) ) {
			$out = array();
			foreach ( $meta['media_items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				// media_items includes only images per fcom's contract; guard
				// anyway in case future kinds land on the same field.
				$type = isset( $item['type'] ) ? (string) $item['type'] : '';
				if ( '' !== $type && 'image' !== $type ) {
					continue;
				}
				if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
					continue;
				}
				$out[] = array(
					'url'    => (string) $item['url'],
					'width'  => isset( $item['width'] ) ? (int) $item['width'] : null,
					'height' => isset( $item['height'] ) ? (int) $item['height'] : null,
				);
			}
			return $out;
		}
		// Fallback: single-image via media_preview (only for uploaded images,
		// not oembed video thumbs).
		if ( isset( $meta['media_preview'] ) && is_array( $meta['media_preview'] ) ) {
			$mp = $meta['media_preview'];
			if ( ( isset( $mp['type'] ) && 'meta_data' === $mp['type'] )
				&& ! empty( $mp['image'] ) && is_string( $mp['image'] ) ) {
				return array( array(
					'url'    => (string) $mp['image'],
					'width'  => isset( $mp['width'] ) ? (int) $mp['width'] : null,
					'height' => isset( $mp['height'] ) ? (int) $mp['height'] : null,
				) );
			}
		}
		return array();
	}

	protected function pluralize( $n, $singular, $plural ) {
		return $n . ' ' . ( 1 === $n ? $singular : $plural );
	}

	/**
	 * Sanitize inline-formatted body text via wp_kses with a small explicit
	 * safelist. Preserves author formatting (bold / italic / links / paragraph
	 * breaks) while blocking scripts, iframes, and all attribute-based XSS
	 * vectors. Consumed by the `appza-rich-text` primitive on the renderer
	 * side via dangerouslySetInnerHTML — because we sanitize here, the
	 * innerHTML sink is safe.
	 */
	protected function sanitize_rich_text( $html ) {
		$allowed = array(
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'a'          => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'title'  => true,
			),
			'br'         => array(),
			'p'          => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
		);
		return trim( wp_kses( $html, $allowed ) );
	}

	protected function relative_time( $datetime_str ) {
		try {
			$then = new \DateTime( $datetime_str, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $datetime_str;
		}
		$diff = time() - $then->getTimestamp();
		if ( $diff < 0 ) {
			return $datetime_str;
		}
		if ( $diff < 60 )       return $diff . 's';
		if ( $diff < 3600 )     return (int) floor( $diff / 60 ) . 'm';
		if ( $diff < 86400 )    return (int) floor( $diff / 3600 ) . 'h';
		if ( $diff < 2592000 )  return (int) floor( $diff / 86400 ) . 'd';
		if ( $diff < 31536000 ) return (int) floor( $diff / 2592000 ) . 'mo';
		return (int) floor( $diff / 31536000 ) . 'y';
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
		$now = time();
		return array(
			array(
				'id'               => 1,
				'title'            => 'The Benefits of Reading',
				'message_rendered' => 'Reading every day helps improve knowledge, vocabulary, and focus. It also reduces stress and encourages creativity. Even a few minutes of reading daily can have a positive impact on personal growth and learning.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 5 * 60 ),
				'content_type'     => 'text',
				'reactions_count'  => 45,
				'comments_count'   => 4,
				'xprofile'         => array(
					'display_name' => 'John Deo',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Say Hello',
					'slug'  => 'say-hello',
				),
			),
			array(
				'id'               => 2,
				'title'            => 'Tips for Better Sleep',
				'message_rendered' => 'Stick to a schedule, dim the lights an hour before bed, and keep your phone out of arms reach. Small habits compound fast.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 12,
				'comments_count'   => 2,
				'xprofile'         => array(
					'display_name' => 'Bill Gets',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Tips',
					'slug'  => 'tips',
				),
			),
			array(
				'id'               => 3,
				'title'            => 'Welcome to the Community',
				'message_rendered' => 'New here? Introduce yourself in the welcome thread — say where you are joining from and what you are working on.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 86400 ),
				'content_type'     => 'text',
				'reactions_count'  => 89,
				'comments_count'   => 12,
				'xprofile'         => array(
					'display_name' => 'Community Bot',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Start Here',
					'slug'  => 'start-here',
				),
			),
			array(
				'id'               => 4,
				'title'            => 'Weekend photo dump',
				'message_rendered' => 'Some shots from the trip — mostly early-morning light. Camera stayed dry.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 6 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 24,
				'comments_count'   => 3,
				'xprofile'         => array(
					'display_name' => 'Ada L.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Photography',
					'slug'  => 'photography',
				),
				'meta'             => array(
					'media_preview' => array(
						'type'     => 'meta_data',
						'provider' => 'uploader',
						'image'    => 'https://picsum.photos/seed/appza-demo-image/800/450',
					),
				),
			),
			array(
				'id'               => 5,
				'title'            => 'Livestream recap',
				'message_rendered' => 'Full replay of last night\'s Q&A — questions from about the 12-minute mark.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 26 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 61,
				'comments_count'   => 9,
				'xprofile'         => array(
					'display_name' => 'Grace H.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Streams',
					'slug'  => 'streams',
				),
				'meta'             => array(
					'media_preview' => array(
						'type'         => 'oembed',
						'provider'     => 'youtube',
						'image'        => 'https://picsum.photos/seed/appza-demo-video/800/450',
						'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
						'video_url'    => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
						'video_player' => 'youtube',
					),
				),
			),
			array(
				'id'               => 6,
				'title'            => 'Where should the next meetup be?',
				'message_rendered' => 'Pick one. Voting closes end of week.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 30 * 60 ),
				'content_type'     => 'survey',
				'reactions_count'  => 8,
				'comments_count'   => 2,
				'xprofile'         => array(
					'display_name' => 'Yuki N.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Events',
					'slug'  => 'events',
				),
				'meta'             => array(
					'survey_config' => array(
						'type'    => 'multi_choice',
						'options' => array(
							array( 'label' => 'Berlin',    'slug' => 'opt_1' ),
							array( 'label' => 'Lisbon',    'slug' => 'opt_2' ),
							array( 'label' => 'Bengaluru', 'slug' => 'opt_3' ),
							array( 'label' => 'Bogotá',    'slug' => 'opt_4' ),
						),
					),
				),
			),
		);
	}
}
