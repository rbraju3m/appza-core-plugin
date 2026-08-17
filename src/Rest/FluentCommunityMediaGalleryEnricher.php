<?php
/**
 * Reshapes fcom-mobile's `/media-gallery` response for the MEDIA sub-tab
 * on Space Detail.
 *
 * Two jobs, both of which exist because the response we get is not the
 * response FluentCommunity Pro produces.
 *
 * ---------------------------------------------------------------------
 * 1. Flatten each item
 * ---------------------------------------------------------------------
 *
 * FC Pro's MediaGalleryService hands back:
 *
 *   {id, url, media_type, kind, settings, created_at:{date,…},
 *    feed:{id, slug, title, permalink, created_at, author:{…}}}
 *
 * Two things there are awkward for the renderer. The author and the feed
 * sit two levels down, and `created_at` is a serialized DateTime OBJECT,
 * not a string — so nothing that expects a date can read it, including
 * the preview proxy's own relative-time formatter.
 *
 * We flatten to leaf keys so a field_mapping binds a path that exists:
 * `title`, `poster`, `feed_slug`, `feed_permalink`, `author_name`, and
 * so on. The nested `feed` / `settings` objects are left in place —
 * removing them would break any consumer we haven't thought of, and they
 * cost nothing.
 *
 * ---------------------------------------------------------------------
 * 2. Restore the envelope keys fcom-mobile drops
 * ---------------------------------------------------------------------
 *
 * FC Pro returns `has_more`, `cursor` and `has_audio` alongside `items`.
 * fcom-mobile's FCOM_Mobile_Media_Gallery helper copies ONLY `items` onto
 * its own envelope, so all three are lost before they reach us. The
 * Audios tab is supposed to disappear when a space has no audio at all
 * (that is what the portal does), which is impossible without
 * `has_audio`.
 *
 * fcom-mobile is a third-party plug-in, so we do not edit it — we
 * recompute here instead, the same way FC Pro does.
 *
 * ---------------------------------------------------------------------
 * Known interactions, so nobody debugs these twice
 * ---------------------------------------------------------------------
 *
 * - `created_at` does not survive as an ISO string on the client. The
 *   preview proxy's normalize_items() rewrites any string `created_at`
 *   into its compact relative form ("8d"), which is what every other
 *   card in the app shows, so that is the useful outcome. The full
 *   WordPress phrasing ("8 days ago") is published separately as
 *   `created_relative`, which the proxy leaves alone. Bind whichever
 *   one the design calls for.
 *
 * - The proxy also stamps `my_reactions` onto every item carrying a
 *   numeric `id`, treating it as a feed id. A media id is not a feed id,
 *   so the value is meaningless here. Nothing in the media UI binds it,
 *   and narrowing that shared code path risks the feed — left alone
 *   deliberately.
 *
 * @package appza-core
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityMediaGalleryEnricher {

	const ROUTE = '/fcom-mobile/api/v1/media-gallery';

	/**
	 * Mirrors FC Pro's MediaGalleryController default. Only used to work
	 * out `has_more` when the request did not name a page size.
	 */
	const DEFAULT_PER_PAGE = 24;

	public function register_hooks() {
		// rest_request_after_callbacks, not rest_post_dispatch, so the
		// filter also fires for the internal rest_do_request the
		// WP-admin simulator makes through PreviewProxyController.
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
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		// fcom-mobile answers a permission failure or a missing space with
		// its own status inside the body and an empty items array. Nothing
		// to reshape, and re-deriving has_audio for a space the viewer
		// cannot see would leak whether it has audio.
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
		// FC Pro clamps to [12, 48] before slicing, so compare against the
		// same clamped value or a request for per_page=1000 always looks
		// like "there is more".
		$per_page = min( 48, max( 12, $per_page ) );

		// FC Pro fetches per_page + 1 rows and truncates, so a full page is
		// the has_more signal. It is wrong only when the total is an exact
		// multiple of the page size, where this says "more" and the next
		// page comes back empty. Nothing consumes it yet; the cost of that
		// case is one wasted request whenever paging does get wired.
		$data['has_more'] = count( $items ) >= $per_page;
		$last             = end( $items );
		$data['cursor']   = ( $data['has_more'] && is_array( $last ) && isset( $last['id'] ) )
			? (int) $last['id']
			: null;

		// Only on the first page, exactly as FC Pro does — it is a separate
		// EXISTS query and the answer cannot change mid-scroll.
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
		// Present on a video only when the uploader supplied one; FC does
		// not generate posters, so this is empty for everything we have
		// seen. The tile falls back to a muted <video preload="metadata">
		// for the thumbnail, which is what the portal does too.
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
		// See the class docblock: the proxy rewrites `created_at` into its
		// compact relative form on the way out. `created_relative` is the
		// one that survives verbatim.
		$item['created_at']       = $created;
		$item['created_relative'] = $created ? $this->time_ago( $created ) : '';

		return $item;
	}

	/**
	 * Display name for the file. Same precedence the DOCUMENTS tab uses
	 * (settings.title → settings.original_name → basename) so a file
	 * reads the same wherever it appears.
	 *
	 * Deliberately does NOT fall back to the feed title: that is a post
	 * title, and putting it in a filename slot would show "test audio 1"
	 * where the audio row promises a filename.
	 */
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

	/**
	 * Recovers the original filename from FC's storage name.
	 *
	 * FileSystem::putFile() prefixes every upload with
	 * `fluentcom-<32 chars>-fluentcom-`, and the migration helpers use the
	 * same shape with an md5 in the middle. Left in place, a photo shows
	 * (and downloads as)
	 * "fluentcom-vnYmlKCC7xVKmyYNQyCJfaFLJibAjuF6-fluentcom-86.webp"
	 * instead of "86.webp" — the same complaint P80 records against the
	 * document file rows.
	 *
	 * Anchored and length-bounded so a user-uploaded file that genuinely
	 * begins with the word "fluentcom" keeps its name.
	 */
	protected function strip_storage_prefix( string $name ) {
		$stripped = preg_replace( '/^fluentcom-[A-Za-z0-9]{32}-fluentcom-/', '', $name, 1 );

		return ( is_string( $stripped ) && '' !== $stripped ) ? $stripped : $name;
	}

	/**
	 * Normalizes whatever FC hands us for a date into 'Y-m-d H:i:s'.
	 *
	 * At the point this filter runs, an Eloquent date is still a live
	 * \DateTime — the familiar {date, timezone_type, timezone} shape is
	 * what json_encode() makes of it later, so matching on that array
	 * alone silently yields '' for every row. All three forms are handled
	 * because the response can reach us either way depending on whether a
	 * caller has already serialized it.
	 */
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

	/**
	 * "8 days ago" — WordPress's own phrasing, matching the documents
	 * card so the two tabs speak the same way.
	 */
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
	 * Whether the space has at least one audio row.
	 *
	 * Delegates to FC Pro's own service so the definition of "audio"
	 * stays in one place — it is a `media_type = fluent_player` row whose
	 * serialized settings carry viewType=audio, which is not a rule we
	 * want a second copy of. Reading from a third-party plug-in is fine;
	 * writing to one is not.
	 *
	 * @return bool|null  null when we cannot answer (Pro inactive, no
	 *                    slug, unknown space) — the caller then omits the
	 *                    key rather than claiming false, so the Audios tab
	 *                    degrades to visible instead of vanishing.
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
