<?php
/**
 * Normalizes fcom-mobile feed responses: /feeds/by/slug returns a bare multi-key object, which the
 * renderer's fetch layer resolves to zero rows, so it is wrapped as a list of one. FluentPlayer uploads
 * set no media_preview.video_url, which the post card binds, so it is filled from media_preview.url.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityFeedEnricher {

	const ROUTE_SINGLE    = '/fcom-mobile/api/v1/feeds/by/slug';
	const ROUTE_LIST      = '/fcom-mobile/api/v1/feeds';
	const ROUTE_BOOKMARKS = '/fcom-mobile/api/v1/feeds/bookmarks';
	const LIST_ROUTES      = [ self::ROUTE_LIST, self::ROUTE_BOOKMARKS ];

	public function register_hooks() {
		// Not rest_post_dispatch: must also fire for the simulator's internal rest_do_request.
		add_filter( 'rest_request_after_callbacks', [ $this, 'reshape' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 */
	public function reshape( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( self::ROUTE_SINGLE !== $route && ! in_array( $route, self::LIST_ROUTES, true ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		$items = $data['items'];

		// Guarded on isset($items[0]) so a future fcom-mobile that returns a list is not double-wrapped.
		$wrapped_single = false;
		if ( self::ROUTE_SINGLE === $route && ! isset( $items[0] ) ) {
			$items          = array( $items );
			$wrapped_single = true;
		}

		// Some callers get paginated rows under items.data.
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
	 * Many FC posts have title '', and field_mapping does not fall back on empty strings.
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

		// Audio (meta.audio_medias[]) is rendered by the card's own list; synthesizing here would show it twice.
		if ( ! isset( $meta['media_preview'] ) || ! is_array( $meta['media_preview'] ) ) {
			return $row;
		}
		$mp = $meta['media_preview'];

		// Never overwrite a real oembed URL.
		if ( ! empty( $mp['video_url'] ) ) {
			return $row;
		}
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
		$mp['media_kind'] = ( isset( $mp['settings']['viewType'] ) && 'audio' === $mp['settings']['viewType'] )
			? 'audio'
			: 'video';

		$meta['media_preview'] = $mp;
		$row['meta']           = $meta;

		return $row;
	}
}
