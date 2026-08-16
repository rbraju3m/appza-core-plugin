<?php
/**
 * Space documents — the DOCUMENTS sub-tab on space-detail.
 *
 *   GET /wp-json/fcom-mobile/api/v1/spaces/documents
 *
 * Registered under the fcom-mobile namespace from our plugin, same as
 * the invitations + users-search controllers (P68). fcom-mobile does
 * ship `/documents`, but it proxies FluentCommunity Pro's route
 * verbatim and that is not usable from the catalog as-is:
 *
 *   1. It demands a numeric `space_id`. Everything on the mobile side
 *      addresses spaces by slug — the space-detail screen only ever
 *      knows `space_slug`.
 *   2. It returns raw document *feeds*, whose `meta.document_lists` is
 *      a snapshot frozen when the feed was created. The filename is
 *      not really in there: each entry carries `title`, which is
 *      `settings.title ?? settings.original_name ?? basename(url)`
 *      resolved AT CREATION TIME. Rename a file afterwards through
 *      /documents/update and the snapshot still shows the old name —
 *      and the design puts that filename front and centre.
 *
 * So we re-derive the file list from the live Media rows the way
 * FeedsHelper already does for `document_ids`, and compose the two
 * display strings the card needs server-side (P71) — "Uploaded 2
 * files" and "8 days ago by Nusrat Jahan" — because both are
 * conditional and have to stay identical to the web portal.
 *
 * A "document" is a Feed with content_type='document'; its files are
 * Media rows with object_source='space_document' and feed_id set.
 * Both 'published' and 'unlisted' documents belong in this tab —
 * 'unlisted' is what "Publish as a post" being off produces, and the
 * portal lists those here too. Scheduled ones are deliberately absent
 * until they go live.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityDocumentsController {

	/**
	 * Statuses that belong in the documents tab. Mirrors FC Pro's
	 * DocumentController::index.
	 */
	private const VISIBLE_STATUSES = array( 'published', 'unlisted' );

	public function permission_check() {
		return is_user_logged_in();
	}

	/* ---------------------------------------------------------------
	 * GET /spaces/documents
	 * ------------------------------------------------------------- */

	public function index( \WP_REST_Request $request ) {
		global $wpdb;

		$space_slug = (string) $request->get_param( 'space_slug' );
		$search     = trim( (string) $request->get_param( 'search' ) );
		$page       = (int) $request->get_param( 'page' );
		$per_page   = (int) $request->get_param( 'per_page' );

		if ( $page <= 0 ) {
			$page = 1;
		}
		if ( $per_page <= 0 || $per_page > 100 ) {
			$per_page = 20;
		}

		$space = $this->resolve_space( $space_slug );
		if ( ! $space ) {
			return $this->error( 400, __( 'A valid space slug is required.', 'appza-core' ) );
		}

		$user_id = get_current_user_id();
		if ( ! $this->can_view( (int) $space->id, $user_id ) ) {
			return $this->error( 403, __( 'You do not have permission to view documents in this space.', 'appza-core' ) );
		}

		$feeds_table = $wpdb->prefix . 'fcom_posts';
		$statuses    = implode(
			',',
			array_map(
				static function ( $s ) {
					return "'" . esc_sql( $s ) . "'";
				},
				self::VISIBLE_STATUSES
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, title, message, created_at
				 FROM {$feeds_table}
				 WHERE space_id = %d AND content_type = 'document' AND status IN ({$statuses})
				 ORDER BY created_at DESC",
				(int) $space->id
			)
		);
		$rows = $rows ?: array();

		// Files come from the live Media rows, one query for the whole
		// page rather than one per feed.
		$files_by_feed = $this->files_for_feeds( wp_list_pluck( $rows, 'id' ) );
		$names         = $this->display_names( wp_list_pluck( $rows, 'user_id' ) );

		$items = array();
		foreach ( $rows as $row ) {
			$files = $files_by_feed[ (int) $row->id ] ?? array();

			// A document feed whose files have all been deleted is
			// noise — the portal shows an empty card, we drop it.
			if ( ! $files ) {
				continue;
			}

			// Search matches the post's own text OR any filename, so
			// typing part of a filename finds its card. Done here
			// rather than in SQL because the filename lives inside a
			// serialised settings blob on a different table.
			if ( $search !== '' && ! $this->matches_search( $row, $files, $search ) ) {
				continue;
			}

			$items[] = $this->present( $row, $files, $names );
		}

		$total  = count( $items );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $items, $offset, $per_page );

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $items,
				'meta' => array(
					'total'      => $total,
					'page'       => $page,
					'per_page'   => $per_page,
					'search'     => $search,
					'can_upload' => $this->can_upload( (int) $space->id, $user_id ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /spaces/documents/upload — one file per call
	 * ------------------------------------------------------------- */

	/**
	 * Slug-addressed wrapper over fcom-mobile's /documents/upload.
	 *
	 * Same two reasons as the listing route: FC Pro's upload demands a
	 * numeric `space_id`, and the mobile side only ever knows the slug.
	 * The file itself is handed straight through as file params —
	 * FileSystem::put ends up in wp_handle_upload, which refuses
	 * anything that isn't a genuine POSTed upload, so the multipart
	 * request has to survive intact rather than being reconstructed.
	 *
	 * One file per call, because FC's processSpaceFiles only ever reads
	 * `$uploadedFiles[0]`. The client uploads N files as N calls and
	 * collects the returned media objects for the create step.
	 */
	public function upload( \WP_REST_Request $request ) {
		$space = $this->resolve_space( (string) $request->get_param( 'space_slug' ) );
		if ( ! $space ) {
			return $this->error( 400, __( 'A valid space slug is required.', 'appza-core' ) );
		}

		$user_id = get_current_user_id();
		if ( ! $this->can_upload( (int) $space->id, $user_id ) ) {
			return $this->error( 403, __( 'You do not have permission to upload documents to this space.', 'appza-core' ) );
		}

		$files = $request->get_file_params();
		if ( ! $files ) {
			return $this->error( 400, __( 'No file was uploaded.', 'appza-core' ) );
		}

		$sub = new \WP_REST_Request( 'POST', '/fcom-mobile/api/v1/documents/upload' );
		$sub->set_body_params( array( 'space_id' => (int) $space->id ) );
		$sub->set_file_params( $files );

		$response = rest_do_request( $sub );
		$body     = $response->get_data();
		if ( $response->is_error() ) {
			return $this->error( 400, $this->upstream_message( $body, __( 'The upload failed.', 'appza-core' ) ) );
		}

		$file = isset( $body['items'] ) ? $body['items'] : null;
		if ( ! is_array( $file ) || empty( $file['media_key'] ) ) {
			$message = isset( $body['response']['message'] ) && $body['response']['message']
				? (string) $body['response']['message']
				: __( 'The upload did not return a file.', 'appza-core' );
			return $this->error( 400, $message );
		}

		// The design lets a file be renamed before it is committed. FC
		// stores the display name on the Media row's settings, and its
		// own /documents/update is the only thing that also repairs the
		// feed snapshot later — so rename through that rather than
		// writing another plugin's table by hand.
		$rename = trim( (string) $request->get_param( 'title' ) );
		if ( $rename !== '' && $rename !== (string) ( $file['title'] ?? '' ) ) {
			list( $rename_status, $rename_body ) = $this->loopback_post(
				'/documents/update',
				array(
					'id'    => (int) $file['id'],
					'title' => $rename,
				),
				$request
			);
			if ( $rename_status >= 200 && $rename_status < 300 ) {
				$file['title'] = $rename;
			} else {
				// Not fatal: the file IS uploaded. Report the original
				// name rather than losing the upload over a label.
				$file['rename_error'] = $this->upstream_message( $rename_body, __( 'The file kept its original name.', 'appza-core' ) );
			}
		}

		// Same per-file shape the listing route emits, so the draft's
		// pending rows and the saved card's rows render identically.
		$file['kind'] = $this->file_kind(
			isset( $file['type'] ) ? (string) $file['type'] : '',
			isset( $file['title'] ) ? (string) $file['title'] : ''
		);

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $file,
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /spaces/documents/file/rename
	 * ------------------------------------------------------------- */

	/**
	 * Rename a file that is already attached to a post.
	 *
	 * A file picked but not yet committed is renamed at upload time; this
	 * is the other case — editing a document post that already exists.
	 * Routed through FC's own /documents/update because that is the only
	 * thing that ALSO repairs the feed's `document_lists` snapshot, which
	 * the portal reads. Writing the Media row directly would rename the
	 * file everywhere except the portal.
	 */
	public function rename_file( \WP_REST_Request $request ) {
		$media_id = (int) $request->get_param( 'media_id' );
		$title    = trim( (string) $request->get_param( 'title' ) );

		if ( $media_id <= 0 || $title === '' ) {
			return $this->error( 400, __( 'A file and a new name are both required.', 'appza-core' ) );
		}

		list( $status, $body ) = $this->loopback_post(
			'/documents/update',
			array(
				'id'    => $media_id,
				'title' => $title,
			),
			$request
		);

		if ( $status === 0 || $status < 200 || $status >= 300 ) {
			return $this->error(
				$status >= 400 ? $status : 400,
				$this->upstream_message( $body, __( 'Could not rename the file.', 'appza-core' ) )
			);
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => array(
					'id'    => $media_id,
					'title' => $title,
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /spaces/documents/create — the document post itself
	 * ------------------------------------------------------------- */

	/**
	 * Creates the document feed from already-uploaded files.
	 *
	 * Wrapping /feeds/create rather than calling it from the catalog
	 * keeps three pieces of business logic on this side of the wire:
	 *
	 *   - `publish` (the design's "Publish as a post" switch) maps to
	 *     status `published` vs `unlisted`. Unlisted documents still
	 *     appear in the DOCUMENTS tab; they just stay out of the feed.
	 *   - A space can require at least one topic, and FC's error for
	 *     that arrives as a bare 422 the client would have to parse.
	 *   - FC requires a non-empty message. The design makes both text
	 *     fields optional, so we fall back title -> filename summary
	 *     rather than rejecting an upload that is otherwise complete.
	 */
	public function create( \WP_REST_Request $request ) {
		$space = $this->resolve_space( (string) $request->get_param( 'space_slug' ) );
		if ( ! $space ) {
			return $this->error( 400, __( 'A valid space slug is required.', 'appza-core' ) );
		}

		$user_id = get_current_user_id();
		if ( ! $this->can_upload( (int) $space->id, $user_id ) ) {
			return $this->error( 403, __( 'You do not have permission to add documents to this space.', 'appza-core' ) );
		}

		$documents = $request->get_param( 'document_ids' );
		$documents = is_array( $documents ) ? array_values( $documents ) : array();
		if ( ! $documents ) {
			return $this->error( 400, __( 'Attach at least one file before uploading.', 'appza-core' ) );
		}

		$title   = trim( (string) $request->get_param( 'title' ) );
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( $message === '' ) {
			/* translators: %d: number of uploaded files */
			$message = $title !== ''
				? $title
				: sprintf( _n( 'Uploaded %d file', 'Uploaded %d files', count( $documents ), 'appza-core' ), count( $documents ) );
		}

		$topics = $request->get_param( 'topic_ids' );
		$topics = is_array( $topics ) ? array_values( array_map( 'intval', $topics ) ) : array();

		$publish = $request->get_param( 'publish' );
		$publish = ( $publish === true || $publish === 'true' || $publish === 1 || $publish === '1' );

		$payload = array(
			'space'        => (string) $space->slug,
			'title'        => $title,
			'message'      => $message,
			'content_type' => 'document',
			'document_ids' => $documents,
			'topic_ids'    => $topics,
			'status'       => $publish ? 'published' : 'unlisted',
		);

		$scheduled_at = trim( (string) $request->get_param( 'scheduled_at' ) );
		if ( $scheduled_at !== '' ) {
			$payload['scheduled_at'] = $scheduled_at;
		}

		// This one CANNOT be a nested rest_do_request.
		//
		// FluentCommunity's own Request object reads the JSON payload from
		// `php://input` and the $_POST/$_GET superglobals — not from the
		// WP_REST_Request it is handed. So a sub-request dispatched inside
		// our own HTTP request reaches FC's validator with OUR body, not
		// the one we just built, and comes back "Message is required"
		// however carefully the sub-request is populated. (It appears to
		// work when dispatched from a plain PHP page, because there is no
		// outer body to shadow it — which makes this an easy one to
		// "verify" wrongly.)
		//
		// A loopback HTTP POST gives FC a request whose php://input really
		// is this payload. The caller's Authorization header rides along so
		// FC sees the same user; every write path in the app authenticates
		// that way.
		list( $status, $body ) = $this->loopback_post( '/feeds/create', $payload, $request );
		if ( $status === 0 ) {
			return $this->error( 502, $this->upstream_message( $body, __( 'Could not reach the community.', 'appza-core' ) ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = $this->upstream_message( $body, '' );
			// FC returns this when the space requires a topic and none
			// was picked. Say so in the app's own words — the raw
			// message names the web portal's field.
			if ( stripos( $message, 'topic' ) !== false ) {
				$message = __( 'Pick at least one topic before uploading.', 'appza-core' );
			}
			return $this->error(
				$status >= 400 ? $status : 400,
				$message !== '' ? $message : __( 'Could not create the document.', 'appza-core' )
			);
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => is_array( $body ) && isset( $body['items'] ) ? $body['items'] : $body,
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Presentation
	 * ------------------------------------------------------------- */

	/**
	 * One card: the group header, its meta line, and its file rows.
	 * Both strings are composed here rather than in the renderer
	 * because they are conditional and must read exactly as the portal
	 * does (P71).
	 */
	private function present( $row, array $files, array $names ): array {
		$count  = count( $files );
		$author = $names[ (string) $row->user_id ] ?? '';

		/* translators: %d: number of uploaded files */
		$title = sprintf( _n( 'Uploaded %d file', 'Uploaded %d files', $count, 'appza-core' ), $count );

		$ago = $this->time_ago( (string) $row->created_at );
		if ( $author !== '' ) {
			/* translators: 1: relative time e.g. "8 days ago", 2: author display name */
			$meta_line = sprintf( __( '%1$s by %2$s', 'appza-core' ), $ago, $author );
		} else {
			$meta_line = $ago;
		}

		return array(
			'id'         => (int) $row->id,
			'title'      => $title,
			'meta_line'  => $meta_line,
			'author'     => $author,
			'file_count' => $count,
			'created_at' => (string) $row->created_at,
			'post_title' => (string) $row->title,
			'message'    => (string) $row->message,
			'files'      => $files,
		);
	}

	/**
	 * Files of ONE feed, in the same shape the documents tab renders.
	 *
	 * Public because the preview proxy needs it to enrich document posts
	 * in the feed — where the only file data FC ships is the frozen
	 * `meta.document_lists` snapshot with an unusable portal URL.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function files_for_feed( int $feed_id ): array {
		$byFeed = $this->files_for_feeds( array( $feed_id ) );
		return $byFeed[ $feed_id ] ?? array();
	}

	/**
	 * Live file rows keyed by feed id.
	 *
	 * `title` resolves the same way Media::getFileTitle does, so a file
	 * renamed via /documents/update shows its new name immediately
	 * instead of whatever the feed meta snapshot froze.
	 */
	private function files_for_feeds( array $feed_ids ): array {
		global $wpdb;

		$feed_ids = array_values( array_unique( array_map( 'intval', $feed_ids ) ) );
		if ( ! $feed_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $feed_ids ), '%d' ) );
		$table        = $wpdb->prefix . 'fcom_media_archive';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, feed_id, media_key, media_type, media_url, media_path, settings
				 FROM {$table}
				 WHERE feed_id IN ({$placeholders})
				   AND object_source = 'space_document'
				   AND is_active = 1
				 ORDER BY id ASC",
				$feed_ids
			)
		);
		$rows = $rows ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$settings = maybe_unserialize( $row->settings );
			$settings = is_array( $settings ) ? $settings : array();

			$title = '';
			foreach ( array( 'title', 'original_name' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
					$title = $settings[ $key ];
					break;
				}
			}
			if ( $title === '' ) {
				$title = basename( (string) $row->media_url );
			}

			$out[ (int) $row->feed_id ][] = array(
				'id'        => (int) $row->id,
				'media_key' => (string) $row->media_key,
				'title'     => $title,
				'type'      => (string) $row->media_type,
				// What tapping a file row opens — and it has to be the FILE.
				//
				// This used to point at fcom-mobile's /documents/download,
				// which reads like the right thing and is not: that route
				// returns JSON ({file_name, media_type, file_url} with the
				// URL base64-encoded) for an app to act on, and it requires
				// authentication. Opening it in a browser or a WebView gets
				// a 401 or a page of JSON, never a download — which is
				// exactly what Noor saw on the phone.
				//
				// media_url is the direct uploads URL, and it is what
				// fcom-mobile itself hands back for the local driver.
				'url'       => $this->file_url( $row ),
				'media_url' => $this->absolute_url( (string) $row->media_url ),
				// Kept so a client that wants the JSON contract (file name,
				// mime, signed URL for remote storage) can still ask.
				'download_api_url' => add_query_arg(
					array(
						'media_key' => rawurlencode( (string) $row->media_key ),
						'media_id'  => (int) $row->id,
					),
					home_url( '/wp-json/fcom-mobile/api/v1/documents/download' )
				),
				// 'image' gets the picture glyph in the design, every
				// other kind gets the document glyph.
				'kind'      => $this->file_kind( (string) $row->media_type, $title ),
			);
		}

		return $out;
	}

	/**
	 * The URL that actually yields the file.
	 *
	 * `media_url` when FC recorded one. When it didn't — rows whose upload
	 * bypassed wp_handle_upload — fall back to deriving it from
	 * media_path, which is still a real location under wp-content/uploads.
	 * Returns '' when neither resolves, so the client can render the row
	 * without a dead link rather than opening a 404.
	 */
	private function file_url( $row ): string {
		$url = $this->absolute_url( (string) $row->media_url );
		if ( $url !== '' ) {
			return $url;
		}

		$path = (string) ( $row->media_path ?? '' );
		if ( $path === '' ) {
			return '';
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || strpos( $path, $uploads['basedir'] ) !== 0 ) {
			return '';
		}
		$relative = ltrim( substr( $path, strlen( $uploads['basedir'] ) ), '/' );
		// media_path is a directory on rows seeded without a real file.
		if ( $relative === '' || substr( $path, -1 ) === '/' ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . $relative;
	}

	/**
	 * Absolutize a stored media URL. Already-absolute URLs pass through.
	 */
	private function absolute_url( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * Which glyph the row shows. Falls back to the extension because
	 * media_type is null on rows migrated in from older uploads.
	 */
	private function file_kind( string $mime, string $title ): string {
		if ( $mime !== '' && strpos( $mime, 'image/' ) === 0 ) {
			return 'image';
		}
		if ( $mime === '' ) {
			$ext = strtolower( (string) pathinfo( $title, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg' ), true ) ) {
				return 'image';
			}
		}
		return 'document';
	}

	private function matches_search( $row, array $files, string $search ): bool {
		$needle = $this->lower( $search );

		foreach ( array( (string) $row->title, (string) $row->message ) as $haystack ) {
			if ( $haystack !== '' && strpos( $this->lower( $haystack ), $needle ) !== false ) {
				return true;
			}
		}
		foreach ( $files as $file ) {
			if ( strpos( $this->lower( (string) $file['title'] ), $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	private function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * "8 days ago" — WordPress's own phrasing so the app matches the
	 * portal, which renders the same relative string.
	 */
	private function time_ago( string $created_at ): string {
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

	private function display_names( array $user_ids ): array {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'strval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, display_name FROM {$wpdb->prefix}fcom_xprofile WHERE user_id IN ({$placeholders})",
				$user_ids
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->user_id ] = (string) $row->display_name;
		}

		// Anyone without an FC profile still has a WP display name.
		foreach ( $user_ids as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				$user = get_userdata( (int) $id );
				if ( $user ) {
					$out[ $id ] = (string) $user->display_name;
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------- */

	private function can_view( int $space_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return $this->space_role( $space_id, $user_id ) !== '';
	}

	/**
	 * Reported in `meta.can_upload` so the FAB can be hidden for
	 * read-only members. Chunk 2 gates the upload path on it too.
	 */
	private function can_upload( int $space_id, int $user_id ): bool {
		return $this->can_view( $space_id, $user_id );
	}

	private function space_role( int $space_id, int $user_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}fcom_space_user
				 WHERE space_id = %d AND user_id = %s AND status = 'active' LIMIT 1",
				$space_id,
				(string) $user_id
			)
		);
	}

	private function resolve_space( string $slug ) {
		global $wpdb;

		$slug = trim( $slug );
		if ( $slug === '' ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, slug, title FROM {$wpdb->prefix}fcom_spaces WHERE slug = %s LIMIT 1",
				$slug
			)
		);
	}

	/**
	 * POST to an fcom-mobile route over loopback HTTP, forwarding the
	 * caller's credentials.
	 *
	 * Not `rest_do_request`: FluentCommunity's Request reads its JSON
	 * payload from `php://input` and the superglobals rather than from
	 * the WP_REST_Request it is given, so a nested dispatch reaches FC
	 * carrying OUR request body instead of the one being sent. Only a
	 * real HTTP request gives it the right `php://input`.
	 *
	 * Returns [status, decoded body]; status 0 means the request never
	 * completed.
	 */
	private function loopback_post( string $route, array $payload, \WP_REST_Request $request ): array {
		$headers = array( 'Content-Type' => 'application/json' );

		$auth = $request->get_header( 'authorization' );
		if ( ! is_string( $auth ) || $auth === '' ) {
			$auth = $request->get_header( 'x_appza_auth' );
		}
		if ( is_string( $auth ) && $auth !== '' ) {
			$headers['Authorization'] = $auth;
		}

		$raw = wp_remote_post(
			home_url( '/wp-json/fcom-mobile/api/v1' . $route ),
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $raw ) ) {
			return array( 0, array( 'message' => $raw->get_error_message() ) );
		}

		$body   = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		$http   = (int) wp_remote_retrieve_response_code( $raw );
		$status = isset( $body['response']['status'] ) ? (int) $body['response']['status'] : $http;

		return array( $status, is_array( $body ) ? $body : array() );
	}

	/**
	 * Best-effort human message out of an upstream body.
	 *
	 * Neither fcom-mobile nor FC settles on one error shape: a failure
	 * arrives as `response.message`, or WP's `message`, or a `errors`
	 * bag keyed by field. Reading only one of them turned a 422 into an
	 * uncaught TypeError once already.
	 */
	private function upstream_message( $body, string $fallback ): string {
		if ( ! is_array( $body ) ) {
			return $fallback;
		}
		foreach ( array( array( 'response', 'message' ), array( 'message' ) ) as $path ) {
			$value = $body;
			foreach ( $path as $key ) {
				$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
			}
			if ( is_string( $value ) && $value !== '' ) {
				return $value;
			}
			// FC returns `message` as a field-error bag on validation
			// failures ({"required":"Message is required"}), so the string
			// check alone silently loses the only useful text.
			if ( is_array( $value ) ) {
				foreach ( $value as $one ) {
					if ( is_string( $one ) && $one !== '' ) {
						return $one;
					}
				}
			}
		}
		if ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
			foreach ( $body['errors'] as $field_errors ) {
				if ( is_string( $field_errors ) && $field_errors !== '' ) {
					return $field_errors;
				}
				if ( is_array( $field_errors ) ) {
					foreach ( $field_errors as $one ) {
						if ( is_string( $one ) && $one !== '' ) {
							return $one;
						}
					}
				}
			}
		}
		return $fallback;
	}

	private function error( int $status, string $message ) {
		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => $status,
					'message' => $message,
				),
				'data' => array(),
			)
		);
	}
}
