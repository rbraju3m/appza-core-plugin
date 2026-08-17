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
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = $this->add_upload_video_url( $row );
			if ( self::ROUTE_SINGLE === $route ) {
				$row = $this->add_appbar_title( $row );
			}
			$rows[ $i ] = $row;
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
	 * A never-empty label for the post-detail app bar.
	 *
	 * Most FluentCommunity posts are status-style and carry `title = ''`,
	 * so binding the bar straight to the post title would leave it blank
	 * on roughly half of them — and the renderer's field-mapping merger
	 * skips null but NOT empty string, so the catalog default would not
	 * rescue it either.
	 *
	 * Falls back the way the portal reads in context: the post's own
	 * title, else the space it lives in, else a plain generic. Composed
	 * here rather than in the renderer so the bar has one thing to bind
	 * and no conditional.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_appbar_title( array $row ) {
		$title = isset( $row['title'] ) && is_string( $row['title'] ) ? trim( $row['title'] ) : '';
		if ( '' === $title && isset( $row['space']['title'] ) && is_string( $row['space']['title'] ) ) {
			$title = trim( $row['space']['title'] );
		}
		if ( '' === $title ) {
			$title = __( 'Post', 'appza-core' );
		}
		$row['appbar_title'] = $title;

		return $row;
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

		// Audio is NOT handled here. It lives on `meta.audio_medias[]`, a
		// list, because FluentPlayer allows several audios per post where
		// it allows one video — and the post card now renders that array
		// through its own child (children[29], appza-audio-list), one
		// player per file.
		//
		// This used to synthesize a media_preview from the first entry so
		// the video child could show it. That showed exactly one player no
		// matter how many were attached, and now that the card handles the
		// array properly, synthesizing would render the first audio TWICE.
		if ( ! isset( $meta['media_preview'] ) || ! is_array( $meta['media_preview'] ) ) {
			return $row;
		}
		$mp = $meta['media_preview'];

		// Never overwrite a real oembed URL.
		if ( ! empty( $mp['video_url'] ) ) {
			return $row;
		}
		// Video only, for the reason above.
		if ( empty( $mp['content_type'] ) || 'video' !== $mp['content_type'] ) {
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
