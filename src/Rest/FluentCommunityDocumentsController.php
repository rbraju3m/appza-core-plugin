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
				"SELECT id, feed_id, media_key, media_type, media_url, settings
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
				// 'image' gets the picture glyph in the design, every
				// other kind gets the document glyph.
				'kind'      => $this->file_kind( (string) $row->media_type, $title ),
			);
		}

		return $out;
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
