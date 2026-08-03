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

		// Forward the browser's Authorization header (if any) to the upstream
		// endpoint so user-scoped data sources (fc-current-user, feeds for
		// the logged-in user, etc.) return the actual signed-in user's data
		// instead of empty/anonymous responses. Cache key includes an auth
		// hash so different users don't see each other's data.
		$auth_header = $this->extract_auth_header( $request );
		$auth_bucket = $auth_header ? substr( md5( $auth_header ), 0, 12 ) : 'anon';

		$cache_key = 'appza_preview_' . md5( $template_slug . '|' . $ds_slug . '|' . $params_json . '|' . $mode . '|' . $auth_bucket );
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
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		} else {
			$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = $this->fixture_for( $ds_slug );
			}
		}

		$viewer_id = $this->resolve_viewer_id( $auth_header );
		$payload = $this->normalize_items( $payload, $viewer_id );

		// fc-spaces-my is by-definition a "joined spaces" list, but the
		// upstream /spaces payload omits space_pivot. Stamp a synthetic
		// pivot so the shared fc-space-card visible_when for View Space
		// (children[10] "space_pivot exists") evaluates true — otherwise
		// the My tab renders Join CTAs on spaces the viewer already joined.
		if ( 'fc-spaces-my' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( is_array( $item ) && ! isset( $item['space_pivot'] ) ) {
					$payload[ $i ]['space_pivot'] = array(
						'status' => 'active',
					);
				}
			}
		}

		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return rest_ensure_response( $payload );
	}

	/**
	 * Read the incoming Authorization header. Preference order:
	 *   1. Explicit `X-Appza-Auth` header (set by the plug-in-admin so
	 *      the browser can pass Basic Auth without the WP REST layer
	 *      short-circuiting on the standard Authorization header).
	 *   2. Standard `Authorization` header if present + non-empty.
	 * Returns the raw header value (e.g. `Basic <base64>`) or null.
	 */
	protected function extract_auth_header( \WP_REST_Request $request ) {
		$appza = $request->get_header( 'x_appza_auth' );
		if ( is_string( $appza ) && '' !== $appza ) {
			return $appza;
		}
		$std = $request->get_header( 'authorization' );
		if ( is_string( $std ) && '' !== $std ) {
			return $std;
		}
		return null;
	}

	/**
	 * Decode the Basic Auth header we forwarded to the upstream + resolve
	 * the WP user id from the username. Used to enrich feed items with the
	 * viewer's own reaction state (like/bookmark) so the renderer can bind
	 * icon + color per-item without a second round-trip.
	 *
	 * FC's feed listing eager-loads only `reactions.type = 'like'`, so
	 * bookmark state never surfaces there — we query the reactions table
	 * directly in normalize_items() using the id resolved here.
	 */
	protected function resolve_viewer_id( $auth_header ) {
		if ( ! is_string( $auth_header ) || 0 !== stripos( $auth_header, 'basic ' ) ) {
			return 0;
		}
		$b64 = trim( substr( $auth_header, 6 ) );
		$decoded = base64_decode( $b64, true );
		if ( ! is_string( $decoded ) || strpos( $decoded, ':' ) === false ) {
			return 0;
		}
		list( $username, ) = explode( ':', $decoded, 2 );
		if ( '' === $username ) {
			return 0;
		}
		$user = get_user_by( 'login', $username );
		return $user ? (int) $user->ID : 0;
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

	protected function fetch_upstream( $endpoint, $params_json, $auth_header = null ) {
		$params = array();
		if ( '' !== $params_json ) {
			$decoded = json_decode( $params_json, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			}
		}

		// Upstream endpoints all live on this same WP install (fcom-mobile,
		// appza-builder, tutor-lms-mobile). Prefer rest_do_request over
		// wp_remote_get: it dispatches the callback in-process so the WP
		// current-user context (cookie session from the WP-admin simulator)
		// is preserved without having to forward cookies or invent an
		// Authorization header. This is what lets fcom-mobile's transformer
		// see the correct viewer via Helper::getCurrentUser() and attach a
		// meaningful permissions bag — otherwise every menu row gated on
		// data.permissions.* is hidden.
		//
		// Externally-called endpoints (mobile app with Basic Auth) still
		// fall through wp_remote_get so the Authorization header round-trips
		// correctly across the wire boundary.
		if ( '' !== $endpoint && '/' === $endpoint[0] && 0 === strpos( $endpoint, '/wp-json/' ) ) {
			$route = substr( $endpoint, strlen( '/wp-json' ) );
			// Resolve the viewer from the forwarded Basic Auth token BEFORE
			// dispatching so the sub-request's Helper::getCurrentUser() sees
			// the same user as the browser. WP core does not parse Basic
			// Auth; without this step get_current_user_id() stays 0 and
			// every permission (edit / delete / moderate) resolves to false.
			$prev_user_id = get_current_user_id();
			$sub_user_id  = $this->resolve_viewer_id( $auth_header );
			if ( $sub_user_id > 0 && $sub_user_id !== $prev_user_id ) {
				wp_set_current_user( $sub_user_id );
			}
			$rest_request = new \WP_REST_Request( 'GET', $route );
			foreach ( $params as $k => $v ) {
				$rest_request->set_param( $k, $v );
			}
			if ( is_string( $auth_header ) && '' !== $auth_header ) {
				$rest_request->set_header( 'Authorization', $auth_header );
			}
			$response = rest_do_request( $rest_request );
			// Restore the original viewer (if we changed it) so the outer
			// request continues with the correct context — e.g. subsequent
			// normalize_items() runs against the caller's viewer id.
			if ( $sub_user_id > 0 && $sub_user_id !== $prev_user_id ) {
				wp_set_current_user( $prev_user_id );
			}
			if ( $response->is_error() ) {
				return null;
			}
			$decoded = $response->get_data();
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

		$url = home_url( $endpoint );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'APPZA-Preview-Proxy',
		);
		// Forward the caller's Basic Auth so user-scoped upstream endpoints
		// (fc-current-user etc.) return the actual signed-in user's data.
		if ( is_string( $auth_header ) && '' !== $auth_header ) {
			$headers['Authorization'] = $auth_header;
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 8,
			'headers' => $headers,
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
	protected function normalize_items( $items, $viewer_id = 0 ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		// Batch-fetch the viewer's own reactions for the returned feed_ids
		// so each item can carry a `my_reactions` array without an
		// per-item query. FC eager-loads `type='like'` on the feed listing
		// but bookmark reactions are stored in the same table, so this is
		// the only place the renderer can learn bookmark state.
		$my_reactions_by_feed = array();
		if ( $viewer_id > 0 ) {
			$feed_ids = array();
			foreach ( $items as $it ) {
				if ( is_array( $it ) && isset( $it['id'] ) && is_numeric( $it['id'] ) ) {
					$feed_ids[] = (int) $it['id'];
				}
			}
			if ( ! empty( $feed_ids ) ) {
				global $wpdb;
				$placeholders = implode( ',', array_fill( 0, count( $feed_ids ), '%d' ) );
				$params = array_merge( array( $viewer_id ), $feed_ids );
				$sql = "SELECT object_id, type FROM {$wpdb->prefix}fcom_post_reactions WHERE object_type = 'feed' AND user_id = %d AND object_id IN ($placeholders)";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$fid = isset( $r['object_id'] ) ? (int) $r['object_id'] : 0;
						$type = isset( $r['type'] ) ? (string) $r['type'] : '';
						if ( $fid && $type ) {
							if ( ! isset( $my_reactions_by_feed[ $fid ] ) ) {
								$my_reactions_by_feed[ $fid ] = array();
							}
							$my_reactions_by_feed[ $fid ][] = $type;
						}
					}
				}
			}
		}
		foreach ( $items as $i => $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$fid = (int) $item['id'];
				$items[ $i ]['my_reactions'] = isset( $my_reactions_by_feed[ $fid ] )
					? $my_reactions_by_feed[ $fid ]
					: array();
			}
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
			// Banner CTA buttons: derive Ionic fill/color from the API's
			// `type` string so the renderer can bind them directly without
			// hardcoding per-slot styling. type=primary → filled primary;
			// type=secondary → outlined primary; type=link → clear/text.
			if ( isset( $item['ctaButtons'] ) && is_array( $item['ctaButtons'] ) ) {
				$items[ $i ]['ctaButtons'] = $this->normalize_cta_buttons( $item['ctaButtons'] );
			}
			// Space cover fallback. `/spaces/all-spaces` returns
			// cover_photo=null when the space wasn't given a header image;
			// `/spaces/discover` fills with FC's placeholder. Detect space
			// items by the shape (privacy + slug present) and substitute the
			// FC placeholder so fc-space-card renders a visual cover instead
			// of the "Image" empty-state block.
			$has_space_shape = isset( $item['privacy'] ) && isset( $item['slug'] );
			$cover_missing = ! isset( $item['cover_photo'] ) || null === $item['cover_photo'] || '' === $item['cover_photo'];
			if ( $has_space_shape && $cover_missing ) {
				$fc_placeholder_file = WP_PLUGIN_DIR . '/fluent-community/assets/images/space-placeholder.jpg';
				if ( file_exists( $fc_placeholder_file ) ) {
					$items[ $i ]['cover_photo'] = plugins_url( 'fluent-community/assets/images/space-placeholder.jpg' );
				}
			}
		}
		// Rewrite media URLs to point at the current request host.
		// FluentCommunity stores absolute URLs at write time using WP's
		// `siteurl` option (e.g. http://www.tutor-lms-mobile.local/...).
		// On a mobile app hitting the site via a public tunnel or LAN IP,
		// those stored URLs are unreachable from the client. Walk the
		// response, string-replace stored siteurl with the incoming host
		// + scheme so images resolve everywhere.
		$items = $this->rewrite_urls_for_current_host( $items );
		return $items;
	}

	protected function rewrite_urls_for_current_host( $data ) {
		$stored = rtrim( (string) get_option( 'siteurl', '' ), '/' );
		if ( '' === $stored ) {
			return $data;
		}
		// Also strip a common http/https prefix pair so a stored siteurl of
		// http://foo works for an incoming https://foo request (WP HTTPS
		// admin behind a tunnel is common) and vice versa.
		$stored_host = preg_replace( '#^https?://#i', '', $stored );

		$scheme = ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			: ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) ? 'https' : 'http' );
		$host = ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] )
			? (string) $_SERVER['HTTP_X_FORWARDED_HOST']
			: ( ! empty( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : $stored_host );
		$target = $scheme . '://' . rtrim( $host, '/' );

		// Nothing to do if incoming request already came in on the stored URL.
		if ( $target === $stored ) {
			return $data;
		}
		$from_variants = array(
			'http://' . $stored_host,
			'https://' . $stored_host,
		);
		return $this->walk_and_replace_urls( $data, $from_variants, $target );
	}

	protected function walk_and_replace_urls( $node, array $from_variants, $to ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = $this->walk_and_replace_urls( $v, $from_variants, $to );
			}
			return $node;
		}
		if ( is_string( $node ) ) {
			foreach ( $from_variants as $from ) {
				if ( strpos( $node, $from ) !== false ) {
					$node = str_replace( $from, $to, $node );
				}
			}
			return $node;
		}
		return $node;
	}

	protected function normalize_cta_buttons( $buttons ) {
		if ( ! is_array( $buttons ) ) {
			return $buttons;
		}
		$out = array();
		foreach ( $buttons as $btn ) {
			if ( ! is_array( $btn ) ) {
				continue;
			}
			$type = isset( $btn['type'] ) ? (string) $btn['type'] : 'primary';
			switch ( $type ) {
				case 'secondary':
					$btn['fill']  = 'outline';
					$btn['color'] = 'primary';
					break;
				case 'link':
					$btn['fill']  = 'clear';
					$btn['color'] = 'primary';
					break;
				case 'muted':
				case 'ghost':
					$btn['fill']  = 'solid';
					$btn['color'] = 'medium';
					break;
				case 'primary':
				default:
					$btn['fill']  = 'solid';
					$btn['color'] = 'primary';
					break;
			}
			$out[] = $btn;
		}
		return $out;
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
			case 'fc-welcome-banner':
				return $this->fixture_fc_welcome_banner();
			case 'fc-current-user':
				return $this->fixture_fc_current_user();
			case 'fc-spaces-all':
			case 'fc-spaces-discover':
				return $this->fixture_fc_spaces_all();
			case 'fc-spaces-my':
				return $this->fixture_fc_spaces_my();
			case 'fc-space-detail':
				return $this->fixture_fc_space_detail();
			case 'fc-space-feeds':
				return $this->fixture_fc_space_feeds();
			case 'fc-space-members':
				return $this->fixture_fc_space_members();
			case 'fc-space-documents':
				return $this->fixture_fc_space_documents();
			default:
				return array();
		}
	}

	/**
	 * Demo current-user profile for the composer teaser avatar. Real
	 * endpoint (/wp-json/fcom-mobile/api/v1/user/profile-avatar) needs
	 * an auth session — this fixture lets the plug-in-admin simulator
	 * still render an avatar without login.
	 */
	protected function fixture_fc_current_user() {
		return array(
			array(
				'avatar'       => 'https://i.pravatar.cc/80?img=12',
				'display_name' => 'Syed',
				'first_name'   => 'Syed',
			),
		);
	}

	/**
	 * Demo banner content matching the image-1 design: hero image + title +
	 * short description + 4 CTAs in the 2-per-row layout. Used when the real
	 * fcom-mobile welcome-banner endpoint is unavailable OR the caller
	 * explicitly requests mode=demo.
	 */
	protected function fixture_fc_welcome_banner() {
		return array(
			array(
				'enabled'              => 'yes',
				'title'                => 'Build Mobile Apps With Zero Coding',
				'description_rendered' => '<p>Bring your website to the mobile world without a single line of code, be it blog, e-commerce, LMS or anything else. Custom components give you full control over the look and feel.</p>',
				'mediaType'            => 'image',
				'bannerImage'          => 'https://picsum.photos/seed/appza-banner/800/450',
				'bannerVideo'          => null,
				'ctaButtons'           => array(
					array( 'label' => 'Try Demo',      'link' => 'https://appza.io/demo',     'type' => 'primary',   'newTab' => 'yes' ),
					array( 'label' => 'Buy Now',       'link' => 'https://appza.io/buy',      'type' => 'secondary', 'newTab' => 'yes' ),
					array( 'label' => 'Download Free', 'link' => 'https://appza.io/download', 'type' => 'primary',   'newTab' => 'yes' ),
					array( 'label' => 'Pricing',       'link' => 'https://appza.io/pricing',  'type' => 'link',      'newTab' => 'yes' ),
				),
			),
		);
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

	/**
	 * Demo "all spaces" list mirroring the fcom-mobile /spaces/discover
	 * shape — cover_photo + title + description + members_count +
	 * privacy + optional space_pivot (present = viewer is a member; the
	 * renderer switches the CTA + member badge on this). Two entries so
	 * one card shows the joined state and the other the Join state.
	 */
	protected function fixture_fc_spaces_all() {
		return array(
			array(
				'id'                   => 1,
				'title'                => 'LazyTasks',
				'slug'                 => 'lazytasks',
				'cover_photo'          => 'https://picsum.photos/seed/lazytasks/800/450',
				'description'          => 'Task management for teams that ship.',
				'description_rendered' => '<p>Task management for teams that ship.</p>',
				'privacy'              => 'public',
				'members_count'        => 128,
				'space_pivot'          => array( 'status' => 'active', 'role' => 'member' ),
			),
			array(
				'id'                   => 2,
				'title'                => 'Appza',
				'slug'                 => 'appza',
				'cover_photo'          => 'https://picsum.photos/seed/appza/800/450',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress</p>',
				'privacy'              => 'public',
				'members_count'        => 50,
				'space_pivot'          => array( 'status' => 'active', 'role' => 'admin' ),
			),
			array(
				'id'                   => 3,
				'title'                => 'Woocommerce',
				'slug'                 => 'woocommerce',
				'cover_photo'          => 'https://picsum.photos/seed/woocommerce/800/450',
				'description'          => 'Sell anything with WordPress commerce.',
				'description_rendered' => '<p>Sell anything with WordPress commerce.</p>',
				'privacy'              => 'public',
				'members_count'        => 342,
			),
			array(
				'id'                   => 4,
				'title'                => 'TutorLMS',
				'slug'                 => 'tutorlms',
				'cover_photo'          => 'https://picsum.photos/seed/tutorlms/800/450',
				'description'          => 'Course creation + student community.',
				'description_rendered' => '<p>Course creation + student community.</p>',
				'privacy'              => 'private',
				'members_count'        => 187,
			),
		);
	}

	/**
	 * Demo Space Details header + settings for the "Appza Community" space
	 * (id=2, slug=appza). Includes topics so the "Choose topic" strip has
	 * content, permissions so composer + Members visibility gate correctly,
	 * and membership pivot so the joined-state UI paints.
	 */
	protected function fixture_fc_space_detail() {
		return array(
			array(
				'id'                   => 2,
				'slug'                 => 'appza',
				'title'                => 'Appza Community',
				'cover_photo'          => 'https://picsum.photos/seed/appza/1200/500',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress — discuss design, dev, business, research.</p>',
				'type'                 => 'community',
				'privacy'              => 'public',
				'status'               => 'published',
				'members_count'        => 50,
				'settings'             => array(
					'topic_required'        => 'yes',
					'layout_style'          => 'timeline',
					'disable_post_sort_by'  => 'no',
					'default_post_sort_by'  => 'latest',
					'shape_svg_id'          => null,
				),
				'permissions'          => array(
					'can_create_post'   => true,
					'can_view_members'  => true,
					'can_manage_space'  => false,
					'can_join'          => true,
					'can_leave'         => true,
				),
				'topics'               => array(
					array(
						'id'          => 11,
						'title'       => 'Design',
						'description' => 'UI, UX, brand',
						'slug'        => 'design',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 12,
						'title'       => 'Development',
						'description' => 'Engineering + tooling',
						'slug'        => 'development',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 13,
						'title'       => 'Business',
						'description' => 'Sales, marketing, ops',
						'slug'        => 'business',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 14,
						'title'       => 'Research',
						'description' => 'User research + insights',
						'slug'        => 'research',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
				),
				'header_links'         => array(
					array( 'title' => 'About',      'route' => 'about' ),
					array( 'title' => 'Guidelines', 'route' => 'guidelines' ),
				),
				'membership'           => array(
					'pivot' => array(
						'status' => 'active',
						'role'   => 'admin',
					),
				),
				'space_pivot'          => array( 'status' => 'active', 'role' => 'admin' ),
			),
		);
	}

	/**
	 * Demo feed for the Space Details FEEDS tab. First post carries a
	 * Development topic term + featured image + one comment so the design
	 * mock (topic pill + hero + inline first-comment preview) has real
	 * data. Later posts are minimal.
	 */
	protected function fixture_fc_space_feeds() {
		$now = time();
		return array(
			array(
				'id'               => 101,
				'slug'             => 'appza-shipping-space-details',
				'title'            => 'Shipping the new Space Details screen',
				'message_rendered' => '<p>We just landed the redesigned Space Details screen — topics strip, sticky tabs, and a cleaner post composer. Let us know what you think!</p>',
				'featured_image'   => 'https://picsum.photos/seed/appza-post-101/800/450',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 45 * 60 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 12,
				'status'           => 'published',
				'is_sticky'        => 0,
				'comments_count'   => 1,
				'reactions_count'  => 24,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/101',
				'xprofile'         => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
					'is_verified'  => true,
					'badge'        => 'Admin',
					'permalink'    => 'https://appza-legacy.test/u/syed',
				),
				'comments'         => array(
					array(
						'id'                => 5001,
						// Skeleton renders this as text (not HTML), so ship the stripped
							// plain form. Real FC returns both `content` (plain) and
							// `content_rendered` (HTML); when the SS binding retargets
							// to `content` this can carry the HTML again.
							'content'           => 'Love the new topics strip — makes it way easier to jump around.',
							'content_rendered'  => 'Love the new topics strip — makes it way easier to jump around.',
						'xprofile'          => array(
							'user_id'      => 21,
							'display_name' => 'Jaime Bosco',
							'username'     => 'jaime',
							'avatar'       => 'https://i.pravatar.cc/80?img=15',
						),
						'created_at'        => gmdate( 'Y-m-d H:i:s', $now - 30 * 60 ),
						'reactions_count'   => 3,
						'replies_count'     => 0,
					),
				),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(
					array(
						'title' => 'Development',
						'slug'  => 'development',
						'pivot' => array( 'post_id' => 101, 'term_id' => 12 ),
					),
				),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
			array(
				'id'               => 102,
				'slug'             => 'weekly-standup-notes',
				'title'            => 'Weekly standup notes',
				'message_rendered' => '<p>Quick recap from this week: roadmap for the mobile builder, plus new topic filters. Full notes in the thread.</p>',
				'featured_image'   => null,
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 6 * 3600 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 21,
				'status'           => 'published',
				'is_sticky'        => 0,
				'comments_count'   => 0,
				'reactions_count'  => 8,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/102',
				'xprofile'         => array(
					'user_id'      => 21,
					'display_name' => 'Jaime Bosco',
					'username'     => 'jaime',
					'avatar'       => 'https://i.pravatar.cc/80?img=15',
					'is_verified'  => false,
					'badge'        => null,
					'permalink'    => 'https://appza-legacy.test/u/jaime',
				),
				'comments'         => array(),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(
					array(
						'title' => 'Business',
						'slug'  => 'business',
						'pivot' => array( 'post_id' => 102, 'term_id' => 13 ),
					),
				),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
			array(
				'id'               => 103,
				'slug'             => 'welcome-new-members',
				'title'            => 'Welcome new members',
				'message_rendered' => '<p>Big welcome to everyone who joined this week. Introduce yourselves in the thread!</p>',
				'featured_image'   => null,
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 86400 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 12,
				'status'           => 'published',
				'is_sticky'        => 1,
				'comments_count'   => 0,
				'reactions_count'  => 42,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/103',
				'xprofile'         => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
					'is_verified'  => true,
					'badge'        => 'Admin',
					'permalink'    => 'https://appza-legacy.test/u/syed',
				),
				'comments'         => array(),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
		);
	}

	/**
	 * Placeholder MEMBERS-tab data. This arc renders a placeholder screen
	 * for the tab; a minimal fixture is enough to prove the DS wiring.
	 */
	protected function fixture_fc_space_members() {
		return array(
			array(
				'user_id'           => 12,
				'display_name'      => 'Syed Khan',
				'username'          => 'syed',
				'avatar'            => 'https://i.pravatar.cc/80?img=12',
				'short_description' => 'Admin · Appza founder',
				'badge'             => 'Admin',
				'xprofile'          => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
				),
			),
			array(
				'user_id'           => 21,
				'display_name'      => 'Jaime Bosco',
				'username'          => 'jaime',
				'avatar'            => 'https://i.pravatar.cc/80?img=15',
				'short_description' => 'Community moderator',
				'badge'             => 'Moderator',
				'xprofile'          => array(
					'user_id'      => 21,
					'display_name' => 'Jaime Bosco',
					'username'     => 'jaime',
					'avatar'       => 'https://i.pravatar.cc/80?img=15',
				),
			),
			array(
				'user_id'           => 34,
				'display_name'      => 'Ada Lovelace',
				'username'          => 'ada',
				'avatar'            => 'https://i.pravatar.cc/80?img=45',
				'short_description' => 'Engineer',
				'badge'             => null,
				'xprofile'          => array(
					'user_id'      => 34,
					'display_name' => 'Ada Lovelace',
					'username'     => 'ada',
					'avatar'       => 'https://i.pravatar.cc/80?img=45',
				),
			),
		);
	}

	/**
	 * Placeholder DOCUMENTS-tab data — this arc renders a placeholder
	 * screen for the tab; a minimal fixture is enough to prove wiring.
	 */
	protected function fixture_fc_space_documents() {
		$now = time();
		return array(
			array(
				'id'         => 901,
				'title'      => 'Onboarding Guide.pdf',
				'file_url'   => 'https://picsum.photos/seed/appza-doc-901/600/800',
				'file_type'  => 'pdf',
				'size'       => 245678,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3 * 86400 ),
				'uploader'   => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
				),
			),
			array(
				'id'         => 902,
				'title'      => 'Brand Assets.zip',
				'file_url'   => 'https://picsum.photos/seed/appza-doc-902/600/800',
				'file_type'  => 'zip',
				'size'       => 1287654,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 10 * 86400 ),
				'uploader'   => array(
					'user_id'      => 21,
					'display_name' => 'Jaime Bosco',
					'avatar'       => 'https://i.pravatar.cc/80?img=15',
				),
			),
		);
	}

	/**
	 * Demo "my spaces" list — subset of the discover list where the
	 * viewer is a member. Handle() stamps space_pivot on this ds_slug
	 * so all cards render the View Space CTA (children[10] visible_when
	 * depends on space_pivot exists).
	 */
	protected function fixture_fc_spaces_my() {
		return array(
			array(
				'id'                   => 2,
				'title'                => 'appza',
				'slug'                 => 'appza',
				'cover_photo'          => 'https://picsum.photos/seed/appza/800/450',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress</p>',
				'privacy'              => 'public',
				'members_count'        => 50,
			),
			array(
				'id'                   => 3,
				'title'                => 'lazycoders',
				'slug'                 => 'lazycoders',
				'cover_photo'          => 'https://picsum.photos/seed/lazycoders/800/450',
				'description'          => 'A dynamic startup pioneering native mobile app development.',
				'description_rendered' => '<p>A dynamic startup pioneering native mobile app development.</p>',
				'privacy'              => 'private',
				'members_count'        => 250,
			),
		);
	}
}
