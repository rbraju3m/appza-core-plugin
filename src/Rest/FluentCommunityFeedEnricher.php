<?php
/**
 * Normalizes fcom-mobile's feed responses for the renderer.
 *
 * Covers both the LIST (`/feeds`, which serves the home feed and the
 * space feed alike) and the SINGLE post (`/feeds/by/slug`, which powers
 * post-detail).
 *
 * Two jobs. The media normalization applies to every feed row on either
 * route; the list-wrapping applies only to the single-post route.
 *
 * ---------------------------------------------------------------------
 * 1. Return the post as a LIST of one  (/feeds/by/slug only)
 * ---------------------------------------------------------------------
 *
 * The route answers with `items` set to the feed row itself — a bare
 * object, not a list. Every other feed endpoint returns a list, and the
 * whole read path is built on that: the preview proxy hands `items`
 * straight through, and the renderer's fetch layer only recognizes an
 * array, a `{items: [...]}`, or a SINGLE-KEY wrapper it can unwrap (which
 * is how `/spaces/by/slug`'s `{space: {...}}` works). A multi-key bare
 * object matches none of those and resolves to ZERO rows, so the screen
 * renders empty with no error anywhere.
 *
 * Wrapping it here rather than teaching the fetch layer to accept bare
 * objects keeps that rule strict: "a DataSource resolves to a list" stays
 * true, and one endpoint's odd shape is corrected at the edge. It also
 * means the proxy's normalize_items() pass — relative timestamps,
 * reaction/comment summaries, images from meta, document files, viewer
 * reactions — runs over this post exactly as it does over the feed list,
 * so post-detail and the feed card agree without a second code path.
 *
 * ---------------------------------------------------------------------
 * 2. Publish `media_preview.video_url` for FluentPlayer uploads
 * ---------------------------------------------------------------------
 *
 * `fc-post-card` binds its video child to `meta.media_preview.video_url`
 * and its poster to `.image`, and shows that child only when
 * `media_preview.type == 'oembed'`. FluentCommunity fills those for a
 * real OEMBED (YouTube, Vimeo). A FluentPlayer UPLOAD carries the file on
 * `media_preview.url` (mirrored in `settings.src`) and sets no
 * `video_url`, so the binding resolved to nothing and the post showed no
 * video. An AUDIO post has no `media_preview` at all — its file lives on
 * `meta.audio_medias[]` — so it failed the visibility gate outright.
 *
 * Filling the fields the catalog already asks for means no field_mapping
 * change and no new child: one card renders oembeds, uploaded video and
 * uploaded audio. `video_url` is only ever written when absent, so a real
 * oembed is untouched.
 *
 * @package appza-core
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedEnricher {

	/** One post, as a bare object. Powers post-detail. */
	const ROUTE_SINGLE = '/fcom-mobile/api/v1/feeds/by/slug';

	/** The feed list — serves both the home feed and a space's feed. */
	const ROUTE_LIST = '/fcom-mobile/api/v1/feeds';

	public function register_hooks() {
		// rest_request_after_callbacks, like the sibling enrichers, so it
		// also fires for the internal rest_do_request the simulator's
		// preview proxy makes.
		add_filter( 'rest_request_after_callbacks', [ $this, 'reshape' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 */
	public function reshape( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		// Exact comparison, so ROUTE_LIST does not also swallow
		// ROUTE_SINGLE (which is a path under it).
		$route = (string) $request->get_route();
		if ( self::ROUTE_SINGLE !== $route && self::ROUTE_LIST !== $route ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		$items = $data['items'];

		// The single-post route hands back the row itself rather than a
		// list. Wrap it, then treat it as a one-row list from here on so
		// both routes share the media pass below.
		//
		// `isset($items[0])` distinguishes them: a list has a 0 key, a feed
		// row does not. Guarding rather than trusting the route means a
		// future fcom-mobile release that starts returning a proper list
		// stops double-wrapping on its own.
		$wrapped_single = false;
		if ( self::ROUTE_SINGLE === $route && ! isset( $items[0] ) ) {
			$items          = array( $items );
			$wrapped_single = true;
		}

		// The list route paginates on some callers, putting rows under
		// `items.data` instead of directly on `items`.
		$paginated = ! $wrapped_single && ! isset( $items[0] ) && isset( $items['data'] ) && is_array( $items['data'] );
		$rows      = $paginated ? $items['data'] : $items;
		if ( ! is_array( $rows ) ) {
			return $response;
		}

		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) ) {
				$rows[ $i ] = $this->add_upload_video_url( $row );
			}
		}

		if ( $paginated ) {
			$items['data'] = $rows;
		} else {
			$items = $rows;
		}
		$data['items'] = $items;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_upload_video_url( array $row ) {
		if ( ! isset( $row['meta'] ) || ! is_array( $row['meta'] ) ) {
			return $row;
		}
		$meta = $row['meta'];

		// An AUDIO post carries nothing on media_preview at all — its file
		// lives on `meta.audio_medias[]`, a separate list, because
		// FluentPlayer allows several audios per post where it allows one
		// video. Synthesize the preview the card binds so a single audio
		// post plays without the card needing to know about a second field.
		//
		// Only the FIRST entry is surfaced. A post with several audios shows
		// one player; rendering all of them needs a repeated child on the
		// card, which is its own piece of work.
		if ( empty( $meta['media_preview'] ) && ! empty( $meta['audio_medias'] ) && is_array( $meta['audio_medias'] ) ) {
			$first = null;
			foreach ( $meta['audio_medias'] as $candidate ) {
				if ( is_array( $candidate ) ) {
					$first = $candidate;
					break;
				}
			}
			if ( $first ) {
				$meta['media_preview'] = array(
					'type'         => 'oembed',
					'content_type' => isset( $first['content_type'] ) ? $first['content_type'] : 'audio',
					'url'          => isset( $first['url'] ) ? $first['url'] : '',
					'title'        => isset( $first['title'] ) ? $first['title'] : '',
					'player'       => isset( $first['player'] ) ? $first['player'] : 'fluent_player',
					'provider'     => isset( $first['provider'] ) ? $first['provider'] : 'upload',
					'settings'     => isset( $first['settings'] ) && is_array( $first['settings'] ) ? $first['settings'] : array(),
				);
				$row['meta'] = $meta;
			}
		}

		if ( ! isset( $meta['media_preview'] ) || ! is_array( $meta['media_preview'] ) ) {
			return $row;
		}
		$mp = $meta['media_preview'];

		// Never overwrite a real oembed URL.
		if ( ! empty( $mp['video_url'] ) ) {
			return $row;
		}
		if ( empty( $mp['content_type'] ) || ! in_array( $mp['content_type'], array( 'video', 'audio' ), true ) ) {
			return $row;
		}

		$src = '';
		if ( ! empty( $mp['url'] ) && is_string( $mp['url'] ) ) {
			$src = $mp['url'];
		} elseif ( isset( $mp['settings']['src'] ) && is_string( $mp['settings']['src'] ) ) {
			$src = $mp['settings']['src'];
		}
		if ( '' === $src ) {
			return $row;
		}

		$mp['video_url'] = $src;
		// FluentCommunity splits video from audio on settings.viewType, the
		// same rule the media gallery uses. Published so a client can tell
		// them apart without re-deriving it; the renderer also infers from
		// the file extension, so this is belt and braces.
		$mp['media_kind'] = ( isset( $mp['settings']['viewType'] ) && 'audio' === $mp['settings']['viewType'] )
			? 'audio'
			: 'video';

		$meta['media_preview'] = $mp;
		$row['meta']           = $meta;

		return $row;
	}
}
