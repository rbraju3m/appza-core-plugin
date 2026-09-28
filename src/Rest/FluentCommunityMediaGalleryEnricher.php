<?php
/**
 * Flattens fcom-mobile /media-gallery items and restores has_more/cursor/has_audio,
 * which fcom-mobile drops from FC Pro's envelope.
 * The preview proxy rewrites string `created_at` to "8d"; `created_relative` survives verbatim.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMediaGalleryEnricher {

	const ROUTE = '/fcom-mobile/api/v1/media-gallery';

	// FC Pro MediaGalleryController default.
	const DEFAULT_PER_PAGE = 24;

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
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		// fcom-mobile reports errors in-body; recomputing has_audio then would leak it for a hidden space.
		$status = isset( $data['response']['status'] ) ? (int) $data['response']['status'] : 200;
		if ( 200 !== $status ) {
			return $response;
		}

		$items = array();
		foreach ( $data['items'] as $item ) {
			$items[] = is_array( $item ) ? $this->flatten_item( $item ) : $item;
		}
		$data['items'] = $items;

		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}
		// Match FC Pro's [12, 48] clamp.
		$per_page = min( 48, max( 12, $per_page ) );

		// FC Pro truncates its per_page + 1 fetch, so a full page means more (off by one on exact multiples).
		$data['has_more'] = count( $items ) >= $per_page;
		$last             = end( $items );
		$data['cursor']   = ( $data['has_more'] && is_array( $last ) && isset( $last['id'] ) )
			? (int) $last['id']
			: null;

		// First page only, as FC Pro does.
		if ( ! $request->get_param( 'cursor' ) ) {
			$has_audio = $this->space_has_audio( (string) $request->get_param( 'space_slug' ) );
			if ( null !== $has_audio ) {
				$data['has_audio'] = $has_audio;
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	protected function flatten_item( array $item ) {
		$settings = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array();
		$feed     = isset( $item['feed'] ) && is_array( $item['feed'] ) ? $item['feed'] : array();
		$author   = isset( $feed['author'] ) && is_array( $feed['author'] ) ? $feed['author'] : array();

		$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';

		$item['title'] = $this->file_title( $settings, $url );
		$item['poster'] = $this->str( $settings, 'posterSrc' );
		$item['width']  = isset( $settings['width'] ) && is_numeric( $settings['width'] ) ? (int) $settings['width'] : null;
		$item['height'] = isset( $settings['height'] ) && is_numeric( $settings['height'] ) ? (int) $settings['height'] : null;

		$item['feed_id']        = isset( $feed['id'] ) && is_numeric( $feed['id'] ) ? (int) $feed['id'] : null;
		$item['feed_slug']      = $this->str( $feed, 'slug' );
		$item['feed_title']     = $this->str( $feed, 'title' );
		$item['feed_permalink'] = $this->str( $feed, 'permalink' );

		$item['author_name']   = $this->str( $author, 'name' );
		$item['author_avatar'] = $this->str( $author, 'avatar' );

		$created = $this->date_string( isset( $item['created_at'] ) ? $item['created_at'] : null );
		$item['created_at']       = $created;
		$item['created_relative'] = $created ? $this->time_ago( $created ) : '';

		return $item;
	}

	// Deliberately no fallback to the feed title: that is a post title, not a filename.
	protected function file_title( array $settings, string $url ) {
		foreach ( array( 'title', 'original_name' ) as $key ) {
			$value = $this->str( $settings, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		if ( '' === $url ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return $this->strip_storage_prefix( urldecode( basename( $path ) ) );
	}

	// FC's FileSystem::putFile() prefixes uploads with `fluentcom-<32 chars>-fluentcom-`.
	protected function strip_storage_prefix( string $name ) {
		$stripped = preg_replace( '/^fluentcom-[A-Za-z0-9]{32}-fluentcom-/', '', $name, 1 );

		return ( is_string( $stripped ) && '' !== $stripped ) ? $stripped : $name;
	}

	// Eloquent dates are still live \DateTime objects here; the {date,…} array exists only after json_encode().
	protected function date_string( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d H:i:s' );
		}
		if ( is_array( $value ) && isset( $value['date'] ) && is_string( $value['date'] ) ) {
			return $value['date'];
		}
		if ( is_object( $value ) && isset( $value->date ) && is_string( $value->date ) ) {
			return $value->date;
		}

		return '';
	}

	protected function time_ago( string $created_at ) {
		$ts = strtotime( $created_at . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		$now = (int) current_time( 'timestamp', true );
		if ( $ts > $now ) {
			$ts = $now;
		}

		/* translators: %s: human-readable time difference, e.g. "8 days" */
		return sprintf( __( '%s ago', 'appza-core' ), human_time_diff( $ts, $now ) );
	}

	/**
	 * @return bool|null null when unknown, so the caller omits the key and the Audios tab stays visible.
	 */
	protected function space_has_audio( string $space_slug ) {
		if ( '' === $space_slug ) {
			return null;
		}
		if ( ! class_exists( '\FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService' ) ) {
			return null;
		}
		if ( ! class_exists( '\FluentCommunity\App\Models\Space' ) ) {
			return null;
		}

		try {
			$space = \FluentCommunity\App\Models\Space::where( 'slug', $space_slug )->first();
			if ( ! $space ) {
				return null;
			}

			return (bool) \FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService::spaceHasAudio( $space );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $bag
	 */
	protected function str( array $bag, string $key ) {
		return isset( $bag[ $key ] ) && is_string( $bag[ $key ] ) ? $bag[ $key ] : '';
	}
}
