<?php
/**
 * Rescues FC's feed-update flow for mobile-app clients whose submitted
 * media URLs won't match the DB byte-for-byte.
 *
 * The problem:
 *   FC's `FeedsHelper::processFeedMetaData()` (update branch) matches
 *   incoming `media_images[].url` against `wp_fcom_media_archive.media_url`
 *   with an exact `WHERE media_url = ?` — no host normalization. But our
 *   `PreviewProxyController::rewrite_urls_for_current_host()` rewrites
 *   the API response's stored URLs to whatever host the mobile client
 *   hit us on (cloudflared tunnel, LAN IP, etc.). The app then submits
 *   those rewritten URLs back on update, they don't match, FC's fallback
 *   returns nothing, and the deactivation loop (`whereNotIn('id', [])`
 *   matches every row) kills every media row on the feed. FC's create
 *   fallback path then materializes brand-new duplicate rows for the
 *   same files, so the DB grows on every failed edit.
 *
 * Why we can't fix this at rest_pre_dispatch:
 *   Two earlier attempts modified the WP_REST_Request body via
 *   `set_body(wp_json_encode($normalized))` — enriching URLs with
 *   `?media_key=` and separately rewriting the host back to siteurl.
 *   Neither took effect because FC's framework Request::isJson() reads
 *   `php://input` directly, ignoring the WP_REST_Request params. That
 *   original body has the tunnel URLs and there's no PHP API to
 *   overwrite php://input.
 *
 * The fix here:
 *   Restore state AFTER FC's controller returns. We stashed the
 *   submitted URL paths in the pre-dispatch phase. In post-dispatch:
 *     1. For every row on this feed whose media_url path matches a
 *        submitted path, flip is_active=1 and ensure feed_id + source.
 *     2. Rebuild the response's meta.media_items so the mobile app sees
 *        the correct set on the returned feed (otherwise the app would
 *        refresh into an "images gone" state).
 *   FC's own deactivation happened during the controller call, but
 *   we un-do it targetedly for the exact rows the client submitted.
 *   No third-party plug-in code is modified.
 */

namespace AppzaCore\Plugin\Rest;

use FluentCommunity\App\Models\Media;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedUpdateEnricher {

	const ROUTE = '/fcom-mobile/api/v1/feeds/update';

	/**
	 * @var array<int, array<int, string>> Feed id → submitted path list.
	 * Populated on pre-dispatch, consumed + cleared on post-dispatch.
	 * Keyed by feed id so concurrent updates on different feeds don't
	 * collide (each REST request is single-threaded per PHP process
	 * anyway; the keying is defensive).
	 */
	protected $pendingByFeedId = [];

	public function register_hooks() {
		add_filter( 'rest_pre_dispatch', [ $this, 'capture_submitted_paths' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'restore_media' ], 10, 3 );
	}

	/**
	 * Pre-dispatch: read the submitted media_images and stash their
	 * URL paths keyed by feed id, so the post-dispatch handler knows
	 * what to restore. We do NOT try to modify the request body — see
	 * the file docblock for why that doesn't work.
	 */
	public function capture_submitted_paths( $result, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $result;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return $result;
		}
		$feedId = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $feedId <= 0 ) {
			return $result;
		}
		$mediaImages = isset( $params['media_images'] ) ? $params['media_images'] : null;
		if ( ! is_array( $mediaImages ) || empty( $mediaImages ) ) {
			return $result;
		}
		$paths = [];
		foreach ( $mediaImages as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$u = isset( $img['url'] ) ? (string) $img['url'] : '';
			if ( $u === '' ) {
				continue;
			}
			$p = $this->urlToPath( $u );
			if ( $p !== '' ) {
				$paths[] = $p;
			}
		}
		if ( ! empty( $paths ) ) {
			$this->pendingByFeedId[ $feedId ] = array_values( array_unique( $paths ) );
		}
		return $result;
	}

	/**
	 * Post-dispatch: reactivate the rows the client meant to keep and
	 * fix up the response so the app renders the correct set.
	 *
	 * @param \WP_REST_Response|\WP_Error|mixed $response
	 * @param array                             $handler
	 * @param \WP_REST_Request                  $request
	 * @return mixed
	 */
	public function restore_media( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}
		if ( ! class_exists( Media::class ) ) {
			return $response;
		}
		$params = $request->get_json_params();
		$feedId = ( is_array( $params ) && isset( $params['id'] ) ) ? (int) $params['id'] : 0;
		if ( $feedId <= 0 ) {
			return $response;
		}
		$paths = isset( $this->pendingByFeedId[ $feedId ] ) ? $this->pendingByFeedId[ $feedId ] : [];
		unset( $this->pendingByFeedId[ $feedId ] );
		if ( empty( $paths ) ) {
			return $response;
		}

		// Reactivate every row on this feed whose path matches one the
		// client submitted. When FC created duplicate NEW rows for the
		// same files (which it does when its own URL match fails), we
		// prefer the OLDEST row per path so the historical media_id
		// survives — the newer copy is unlinked and deactivated. Keeps
		// the DB from growing forever across edits.
		$rows = Media::where( 'object_source', 'feed' )
			->where( 'feed_id', $feedId )
			->orderBy( 'id' )
			->get();

		$pathSet = array_flip( $paths );
		/** @var array<string, Media> $keepByPath  path → first (oldest) row we keep */
		$keepByPath = [];
		$demoteIds  = [];
		foreach ( $rows as $row ) {
			$p = $this->urlToPath( (string) $row->media_url );
			if ( $p === '' || ! isset( $pathSet[ $p ] ) ) {
				continue;
			}
			if ( ! isset( $keepByPath[ $p ] ) ) {
				$keepByPath[ $p ] = $row;
			} else {
				// Duplicate for the same path — FC created a redundant
				// row in a prior failed edit. Deactivate the newer one.
				$demoteIds[] = (int) $row->id;
			}
		}

		$reactivateIds = [];
		foreach ( $keepByPath as $row ) {
			if ( (int) $row->is_active !== 1 ) {
				$reactivateIds[] = (int) $row->id;
			}
		}
		if ( ! empty( $reactivateIds ) ) {
			Media::whereIn( 'id', $reactivateIds )->update( [ 'is_active' => 1 ] );
		}
		if ( ! empty( $demoteIds ) ) {
			Media::whereIn( 'id', $demoteIds )->update( [ 'is_active' => 0, 'feed_id' => null ] );
		}

		// Rebuild the feed's meta.media_items in the response AND on
		// disk so the app renders the correct set immediately.
		$keepIds = array_map(
			function ( $row ) {
				return (int) $row->id;
			},
			array_values( $keepByPath )
		);
		$this->sync_feed_media_meta( $feedId, $keepIds );

		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$data['items']['meta']         = $this->build_meta_from_ids( $feedId, $keepIds, $data['items']['meta'] ?? [] );
				$data['items']['content_type'] = $data['items']['content_type'] ?? 'image';
				$response->set_data( $data );
			}
		}

		return $response;
	}

	/**
	 * Persist meta.media_items on the feed row so the next feed fetch
	 * returns the correct set. When exactly one row is being kept we
	 * mirror FC's single-image shape (media_preview); ≥2 uses media_items.
	 */
	protected function sync_feed_media_meta( $feedId, array $keepIds ) {
		if ( empty( $keepIds ) ) {
			return;
		}
		$feed = \FluentCommunity\App\Models\Feed::find( $feedId );
		if ( ! $feed ) {
			return;
		}
		$meta = is_array( $feed->meta ) ? $feed->meta : [];
		$meta = $this->build_meta_from_ids( $feedId, $keepIds, $meta );
		$feed->meta = $meta;
		$feed->save();
	}

	/**
	 * Compose meta.media_items / meta.media_preview from the kept row
	 * ids, preserving other meta keys already on the feed. Single image
	 * → media_preview (FC's own shape); multi → media_items array.
	 */
	protected function build_meta_from_ids( $feedId, array $keepIds, $existingMeta ) {
		if ( ! is_array( $existingMeta ) ) {
			$existingMeta = [];
		}
		if ( empty( $keepIds ) ) {
			return $existingMeta;
		}
		$rows = Media::whereIn( 'id', $keepIds )->orderByRaw( 'FIELD(id, ' . implode( ',', $keepIds ) . ')' )->get();
		if ( count( $rows ) === 1 ) {
			$row = $rows->first();
			$existingMeta['media_preview'] = [
				'is_uploaded' => true,
				'image'       => $row->public_url,
				'type'        => 'meta_data',
				'provider'    => 'uploader',
				'width'       => isset( $row->settings['width'] ) ? $row->settings['width'] : null,
				'height'      => isset( $row->settings['height'] ) ? $row->settings['height'] : null,
				'media_id'    => (int) $row->id,
			];
			$existingMeta['media_preview'] = array_filter( $existingMeta['media_preview'], function ( $v ) {
				return $v !== null;
			} );
			unset( $existingMeta['media_items'] );
		} else {
			$items = [];
			foreach ( $rows as $row ) {
				$items[] = array_filter( [
					'media_id' => (int) $row->id,
					'url'      => (string) $row->public_url,
					'type'     => 'image',
					'width'    => isset( $row->settings['width'] ) ? $row->settings['width'] : null,
					'height'   => isset( $row->settings['height'] ) ? $row->settings['height'] : null,
					'provider' => 'uploader',
				], function ( $v ) {
					return $v !== null;
				} );
			}
			$existingMeta['media_items'] = $items;
			unset( $existingMeta['media_preview'] );
		}
		return $existingMeta;
	}

	/**
	 * Reduce a URL to its path portion. Host-agnostic so a file
	 * uploaded via `http://foo.local` and served through
	 * `https://tunnel.io` matches on path alone.
	 */
	protected function urlToPath( $url ) {
		if ( $url === '' ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}
		return $path;
	}
}
