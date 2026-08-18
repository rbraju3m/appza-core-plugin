<?php
/**
 * Chat — the space CHAT tab and the one-to-one conversation screen.
 *
 *   GET  /wp-json/fcom-mobile/api/v1/chat/thread
 *   GET  /wp-json/fcom-mobile/api/v1/chat/messages
 *   POST /wp-json/fcom-mobile/api/v1/chat/threads/delete
 *
 * Registered under the fcom-mobile namespace from our plugin, the same
 * call the invitations, users-search and documents controllers make
 * (P68). fcom-mobile is third-party and is never edited.
 *
 * Why these three and not fcom-mobile's own:
 *
 *   1. `/chat/spaces/messages` throws `threadDetails` away. FC composes
 *      it in ChatHelper::transformThread() and it is the entire top of
 *      both designs — title, photo, and the permission bag
 *      (can_send_message / can_view_members / can_delete_any_messages),
 *      plus `blocked_thread` / `left_thread` and `info.last_activity`,
 *      which FC has ALREADY blanked unless it is inside five minutes.
 *      That field is the "Active now" line in designs 5 and 6; there is
 *      nothing else to compute it from.
 *
 *   2. Every fcom-mobile chat route is addressed by numeric thread id.
 *      The space CHAT tab only ever knows a slug, so opening it would
 *      cost a round trip to resolve the thread before the first fetch.
 *      Both read routes here take `thread_id` OR `space_slug` OR
 *      `user_id`.
 *
 *   3. There is no delete-thread wrapper at all. FC has
 *      `chat/threads/delete/{id}`; fcom-mobile never exposed it, and
 *      design 9 needs it.
 *
 * Writes that fcom-mobile DOES wrap are used as-is and are absent here:
 * messages/add (it passes `reply_to` / `reply_text` straight through),
 * messages/delete, threads/leave, threads/block, threads/unblock,
 * chat/spaces/members.
 *
 * ---------------------------------------------------------------------
 * Two field-naming rules the preview proxy imposes
 * ---------------------------------------------------------------------
 *
 * `created_at` — the proxy rewrites ANY string in that key through its
 * own relative_time(), whose scale jumps from days straight to months.
 * The designs show "2w". So the label is computed here and shipped as
 * `time_label`; the raw stamp rides as `created_at_iso`, which the
 * proxy does not touch.
 *
 * `message_rendered` — the proxy runs that key through wp_kses with a
 * small inline allow-list, which is what makes the renderer's
 * appza-rich-text innerHTML sink safe. FC stores a chat message as
 * `<div class="chat_text">…</div>` plus an optional `<div
 * class="chat_medias"><img …></div>`; both wrappers would be stripped
 * and the images lost. So the two halves are split here — prose into
 * `message_rendered`, image URLs into `images[]`.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityChatController {

	/**
	 * Messages per page. FC's own default is 20; the design fills a
	 * phone screen with roughly a dozen, and there is no "load older"
	 * control yet, so a slightly deeper first page keeps scrollback
	 * useful without paying for it twice.
	 */
	private const PER_PAGE = 30;

	/** Seconds within which FC still considers a profile "active now". */
	private const ACTIVE_WINDOW = 300;

	/**
	 * `object_source` we stamp on a chat upload the moment it lands, so
	 * an abandoned one can be told apart from an abandoned post upload.
	 * FluentCommunity overwrites it with 'chat_message' when a message
	 * claims the file.
	 */
	private const PENDING_SOURCE = 'chat_message_pending';

	public function permission_check() {
		return is_user_logged_in();
	}

	/* ---------------------------------------------------------------
	 * GET /chat/thread
	 * ------------------------------------------------------------- */

	/**
	 * One thread, flattened to leaf keys, as a list of one.
	 *
	 * A list of one rather than a bare object because the preview proxy
	 * resolves every DataSource to an array of items and a bare object
	 * reads downstream as zero rows — the same wrapping the feed-by-slug
	 * enricher had to add.
	 */
	public function thread( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->ok( array() );
		}

		$viewer = get_current_user_id();
		$thread = $this->resolve_thread( $request, $viewer );

		if ( ! $thread ) {
			return $this->ok( array() );
		}
		if ( ! \FluentMessaging\App\Services\ChatHelper::hasChatAccess( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You do not have access to this conversation.', 'appza-core' ) );
		}

		return $this->ok( array( $this->present_thread( $thread, $viewer ) ) );
	}

	/* ---------------------------------------------------------------
	 * GET /chat/messages
	 * ------------------------------------------------------------- */

	/**
	 * Messages oldest-first, which is the order they render in.
	 *
	 * FC returns newest-first because its cursor walks backwards; the
	 * reversal happens after the slice so `before_id` still means "the
	 * page above this one".
	 */
	public function messages( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->ok( array() );
		}

		$viewer = get_current_user_id();
		$thread = $this->resolve_thread( $request, $viewer );

		if ( ! $thread ) {
			return $this->ok( array() );
		}
		if ( ! \FluentMessaging\App\Services\ChatHelper::hasChatAccess( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You do not have access to this conversation.', 'appza-core' ) );
		}

		$per_page  = (int) $request->get_param( 'per_page' );
		$per_page  = ( $per_page > 0 && $per_page <= 100 ) ? $per_page : self::PER_PAGE;
		$before_id = (int) $request->get_param( 'before_id' );

		$query = \FluentMessaging\App\Models\Message::where( 'thread_id', $thread->id )
			->orderBy( 'id', 'DESC' );

		// A community thread hides messages from profiles that are no
		// longer active, exactly as FC's own listing does — otherwise a
		// banned member's posts stay in every space conversation.
		if ( $thread->space_id ) {
			$query->whereHas(
				'xprofile',
				function ( $q ) {
					$q->where( 'status', 'active' );
				}
			);
		}
		if ( $before_id > 0 ) {
			$query->where( 'id', '<', $before_id );
		}

		// One extra row answers "is there an older page" without a count.
		$rows     = $query->limit( $per_page + 1 )->get();
		$has_more = $rows->count() > $per_page;
		if ( $has_more ) {
			$rows = $rows->slice( 0, $per_page );
		}

		$rows = array_reverse( array_values( $rows->all() ) );

		$can_delete_any = $this->can_delete_any( $thread, $viewer );
		$profiles       = $this->profiles_for( $rows );

		$items    = array();
		$prev_uid = null;
		foreach ( $rows as $i => $row ) {
			$uid  = (int) $row->user_id;
			$next = isset( $rows[ $i + 1 ] ) ? (int) $rows[ $i + 1 ]->user_id : null;

			$items[] = $this->present_message(
				$row,
				$profiles[ $uid ] ?? null,
				$viewer,
				$can_delete_any,
				// A run is a block of consecutive messages by one
				// author. The design puts the name above the FIRST of a
				// run and the avatar beside the LAST — so these are two
				// different flags, not one.
				//
				// Both are computed per page, so a run split across a
				// page boundary repeats its header. Harmless while there
				// is no "load older" control; revisit with one.
				$uid !== $prev_uid,
				$uid !== $next
			);
			$prev_uid = $uid;
		}

		return $this->ok(
			$items,
			array(
				'thread_id' => (int) $thread->id,
				'has_more'  => $has_more,
				'before_id' => $rows ? (int) $rows[0]->id : 0,
				'last_id'   => $rows ? (int) $rows[ count( $rows ) - 1 ]->id : 0,
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /chat/messages/send
	 * ------------------------------------------------------------- */

	/**
	 * Send a message.
	 *
	 * fcom-mobile's /chat/messages/add would do, except that it takes a
	 * numeric thread id — and the space CHAT tab only ever has a slug.
	 * Rather than make the client resolve the thread before every send,
	 * or thread a resolved id through renderer state where it would
	 * outlive the screen that produced it, this takes the same
	 * thread_id-or-space_slug pair both read routes take.
	 *
	 * `reply_to` / `reply_text` and `media` pass through to FC unchanged:
	 * FC stores the reply on the message meta and re-resolves each media
	 * URL back to its Media row, so nothing here needs to understand
	 * either.
	 *
	 * The write goes over loopback HTTP, not a nested dispatch, because
	 * FluentCommunity reads its payload from php://input — a nested call
	 * would reach it carrying OUR body (P78).
	 */
	public function send( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->error( 400, __( 'Chat is not available on this site.', 'appza-core' ) );
		}

		$viewer = get_current_user_id();
		$thread = $this->resolve_thread( $request, $viewer );

		if ( ! $thread ) {
			return $this->error( 404, __( 'This conversation could not be found.', 'appza-core' ) );
		}
		if ( ! \FluentMessaging\App\Services\ChatHelper::hasChatAccess( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You do not have access to this conversation.', 'appza-core' ) );
		}

		$text  = trim( (string) $request->get_param( 'text' ) );
		$media = $request->get_param( 'media' );
		$media = is_array( $media ) ? array_values( array_filter( array_map( 'strval', $media ) ) ) : array();

		if ( '' === $text && ! $media ) {
			return $this->error( 400, __( 'A message needs some text or an image.', 'appza-core' ) );
		}

		$payload = array( 'text' => $text );
		if ( $media ) {
			$payload['mediaItems'] = $media;
		}

		$reply_to = (int) $request->get_param( 'reply_to' );
		if ( $reply_to > 0 ) {
			$payload['reply_to'] = $reply_to;
			// FC stores the quoted text verbatim rather than re-reading
			// the replied-to message, so the client sends what it showed.
			$payload['reply_text'] = (string) $request->get_param( 'reply_text' );
		}

		list( $status, $body ) = $this->loopback_post(
			"/fluent-community/v2/chat/messages/{$thread->id}",
			$payload,
			$request
		);

		if ( 200 !== $status ) {
			return $this->error( $status ?: 400, $this->upstream_message( $body, __( 'The message could not be sent.', 'appza-core' ) ) );
		}

		$message = isset( $body['message'] ) && is_array( $body['message'] ) ? $body['message'] : array();

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => __( 'Message sent', 'appza-core' ),
				),
				'data' => array(
					'id'        => isset( $message['id'] ) ? (int) $message['id'] : 0,
					'thread_id' => (int) $thread->id,
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /chat/messages/remove
	 * ------------------------------------------------------------- */

	/**
	 * Delete a message AND the image it carried.
	 *
	 * FluentCommunity's own delete drops the message row and stops
	 * there — the Media row stays active and the file stays on disk, so
	 * every deleted image message leaves an orphan behind. Nothing links
	 * the two back together afterwards either: chat media is stored with
	 * `feed_id` set to the THREAD, not the message, so once the message
	 * text is gone there is no way to tell which file belonged to it.
	 * The sweep therefore has to read the message BEFORE it is deleted,
	 * which is why this is a route of its own rather than a filter on
	 * the way out.
	 *
	 * Named `/remove` rather than `/delete` only because fcom-mobile
	 * already owns `/chat/messages/delete` in this namespace, and
	 * registering the same route twice leaves which callback answers up
	 * to registration order.
	 *
	 * The delete itself is delegated to fcom-mobile's route so FC's own
	 * before/after hooks and broadcast still run; this adds the sweep on
	 * either side of it and nothing else.
	 *
	 * Media::delete() unlinks the file through its `deleting` hook, so
	 * the row and the file go together. FC's own thread-delete settles
	 * for `is_active = 0`, which leaves the file — worth deviating from,
	 * since the whole point here is not to leave one.
	 */
	public function remove( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->error( 400, __( 'Chat is not available on this site.', 'appza-core' ) );
		}

		$viewer     = get_current_user_id();
		$message_id = (int) $request->get_param( 'message_id' );
		$message    = $message_id > 0 ? \FluentMessaging\App\Models\Message::find( $message_id ) : null;

		if ( ! $message ) {
			return $this->error( 404, __( 'That message could not be found.', 'appza-core' ) );
		}

		$thread = $message->thread;
		if ( ! $thread || ! \FluentMessaging\App\Services\ChatHelper::hasChatAccess( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You do not have access to this conversation.', 'appza-core' ) );
		}

		// Mirror FC's own guards rather than trusting the client, so this
		// route can never delete something its own route would refuse.
		$meta = is_array( $message->meta ) ? $message->meta : array();
		if ( ! empty( $meta['system_event'] ) ) {
			return $this->error( 400, __( 'System messages cannot be deleted.', 'appza-core' ) );
		}
		if ( (int) $message->user_id !== $viewer && ! $this->can_delete_any( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You are not allowed to delete this message.', 'appza-core' ) );
		}

		// Read the attachments while the message still exists.
		$urls = $this->image_urls_in( (string) $message->text );

		list( $status, $body ) = $this->loopback_post(
			'/fcom-mobile/api/v1/chat/messages/delete',
			array( 'message_id' => $message_id ),
			$request
		);
		$inner = isset( $body['response']['status'] ) ? (int) $body['response']['status'] : $status;
		if ( 200 !== $inner ) {
			return $this->error( $inner ?: 400, $this->upstream_message( $body, __( 'The message could not be deleted.', 'appza-core' ) ) );
		}

		$swept = $this->sweep_media( $urls, (int) $thread->id );

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => __( 'Message deleted', 'appza-core' ),
				),
				'data' => array(
					'id'          => $message_id,
					'media_swept' => $swept,
				),
			)
		);
	}

	/**
	 * The `src` of every image in a stored chat message.
	 *
	 * @return array<int, string>
	 */
	private function image_urls_in( string $html ): array {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[1] as $src ) {
			$src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
			if ( '' !== $src ) {
				$out[] = $src;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Delete the Media rows behind the given URLs, file included.
	 *
	 * Scoped to this thread's chat media, so a malformed URL cannot reach
	 * a post's attachment or another conversation's. And skipped whenever
	 * a surviving message in the thread still shows the same image —
	 * sending one uploaded file twice is enough to make that real, and
	 * the second message would be left pointing at a deleted file.
	 *
	 * @param array<int, string> $urls
	 */
	private function sweep_media( array $urls, int $thread_id ): int {
		if ( ! $urls || ! class_exists( '\FluentCommunity\App\Models\Media' ) ) {
			return 0;
		}

		$swept = 0;
		foreach ( $urls as $url ) {
			if ( $this->media_is_referenced( $url, $thread_id ) ) {
				continue;
			}

			$rows = \FluentCommunity\App\Models\Media::where( 'object_source', 'chat_message' )
				->where( 'feed_id', $thread_id )
				->where( 'media_url', $url )
				->get();

			foreach ( $rows as $row ) {
				// Fires the model's `deleting` hook, which unlinks the
				// local file — the row and the file go together.
				$row->delete();
				$swept++;
			}
		}

		return $swept;
	}

	private function esc_like( string $value ): string {
		global $wpdb;
		return $wpdb->esc_like( $value );
	}

	/* ---------------------------------------------------------------
	 * POST /chat/media/discard  +  the scheduled sweep
	 * ------------------------------------------------------------- */

	/**
	 * Throw away an image that was uploaded but never sent.
	 *
	 * FluentCommunity creates the Media row at UPLOAD time, not at send
	 * time, so a composer the user changes their mind about leaves a row
	 * and a file behind that nothing will ever reference. Tapping the ×
	 * on a pending thumbnail comes here.
	 *
	 * Ownership, not just access: only the uploader can discard, so one
	 * member of a conversation cannot delete another's pending file by
	 * guessing a media_key.
	 */
	public function discard_media( \WP_REST_Request $request ) {
		if ( ! class_exists( '\FluentCommunity\App\Models\Media' ) ) {
			return $this->error( 400, __( 'Media is not available on this site.', 'appza-core' ) );
		}

		$viewer = get_current_user_id();
		$key    = trim( (string) $request->get_param( 'media_key' ) );
		if ( '' === $key ) {
			// The client holds the URL the upload returned, which carries
			// the key as a query arg.
			$url = (string) $request->get_param( 'url' );
			if ( '' !== $url ) {
				$query = wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( (string) $query, $args );
				$key = isset( $args['media_key'] ) ? (string) $args['media_key'] : '';
			}
		}
		if ( '' === $key ) {
			return $this->error( 400, __( 'A media key is required.', 'appza-core' ) );
		}

		$media = \FluentCommunity\App\Models\Media::where( 'media_key', $key )->first();

		if ( ! $media ) {
			// Already gone — the caller wanted it gone, so say yes.
			return $this->ok_message( __( 'Attachment discarded', 'appza-core' ), array( 'discarded' => 0 ) );
		}
		if ( (int) $media->user_id !== $viewer ) {
			return $this->error( 403, __( 'That attachment is not yours to discard.', 'appza-core' ) );
		}
		// Only ever a PENDING upload of ours. The × means "unsend this
		// one I have not sent"; a client passing the wrong key must not
		// be able to reach a sent message's image, or a post's.
		if ( self::PENDING_SOURCE !== (string) $media->object_source ) {
			return $this->error( 409, __( 'That attachment is already part of a message.', 'appza-core' ) );
		}
		if ( $this->media_is_referenced( (string) $media->media_url, (int) $media->feed_id ) ) {
			return $this->error( 409, __( 'That attachment is already part of a message.', 'appza-core' ) );
		}

		$media->delete();

		return $this->ok_message( __( 'Attachment discarded', 'appza-core' ), array( 'discarded' => 1 ) );
	}

	/**
	 * Delete chat images that were uploaded, never sent, and are old
	 * enough that nobody is still composing with them.
	 *
	 * The explicit discard above covers the user who changes their mind.
	 * This covers everything else — an app killed mid-compose, a lost
	 * connection between the upload and the send — which no client-side
	 * call can reach. Runs daily on WP-cron.
	 *
	 * @param int $older_than Seconds an upload must have survived before
	 *                        it counts as abandoned. Two hours is far
	 *                        past any real compose, so an in-progress
	 *                        message is never at risk.
	 * @param int $limit      Rows examined per run, so one pass can never
	 *                        become an unbounded job on a busy site.
	 * @return int            How many were swept.
	 */
	public function sweep_abandoned_media( int $older_than = 7200, int $limit = 200 ): int {
		if ( ! class_exists( '\FluentCommunity\App\Models\Media' ) || ! $this->chat_available() ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $older_than ) );

		// Only our own pending uploads. A row FluentCommunity has already
		// claimed is a sent message's image and belongs to the
		// conversation; a row with no marker at all belongs to some other
		// composer entirely, very likely a post draft.
		$candidates = \FluentCommunity\App\Models\Media::where( 'object_source', self::PENDING_SOURCE )
			->where( 'created_at', '<', $cutoff )
			->orderBy( 'id' )
			->limit( max( 1, $limit ) )
			->get();

		$swept = 0;
		foreach ( $candidates as $media ) {
			if ( $this->media_is_referenced( (string) $media->media_url, (int) $media->feed_id ) ) {
				continue;
			}
			// Row and file together — see the note on sweep_media().
			$media->delete();
			$swept++;
		}

		return $swept;
	}

	/**
	 * Is any message in this conversation still showing this file?
	 *
	 * Scoped to the thread because that is the only place a chat image
	 * can be referenced — FC stores chat media with `feed_id` set to the
	 * thread — and an unscoped LIKE over every message would be a table
	 * scan per candidate.
	 */
	private function media_is_referenced( string $media_url, int $thread_id ): bool {
		if ( '' === $media_url ) {
			return false;
		}
		return \FluentMessaging\App\Models\Message::where( 'thread_id', $thread_id )
			->where( 'text', 'LIKE', '%' . $this->esc_like( $media_url ) . '%' )
			->exists();
	}

	/* ---------------------------------------------------------------
	 * POST /chat/messages/upload
	 * ------------------------------------------------------------- */

	/**
	 * One image, uploaded ahead of the message that will carry it.
	 *
	 * FC's own media_upload route is addressed by numeric thread id and
	 * fcom-mobile never wrapped it, so this is the slug-addressable
	 * front door — the same two reasons the read routes above exist.
	 *
	 * The nested dispatch is safe here even though P78 says writes go
	 * over loopback: FluentCommunity's UploadHelper reads `$_FILES`
	 * directly rather than the request object, and this IS the outermost
	 * HTTP request, so the superglobal is already the real multipart
	 * body. The documents upload takes the same route for the same
	 * reason.
	 *
	 * Returns the media URL, which is what `mediaItems` on the send call
	 * expects — FC re-resolves the Media row from it.
	 */
	public function upload( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->error( 400, __( 'Chat is not available on this site.', 'appza-core' ) );
		}

		$viewer = get_current_user_id();
		$thread = $this->resolve_thread( $request, $viewer );

		if ( ! $thread ) {
			return $this->error( 404, __( 'This conversation could not be found.', 'appza-core' ) );
		}
		if ( ! \FluentMessaging\App\Services\ChatHelper::hasChatAccess( $thread, $viewer ) ) {
			return $this->error( 403, __( 'You do not have access to this conversation.', 'appza-core' ) );
		}

		$files = $request->get_file_params();
		if ( ! $files ) {
			return $this->error( 400, __( 'No file was uploaded.', 'appza-core' ) );
		}

		$sub = new \WP_REST_Request( 'POST', "/fluent-community/v2/chat/messages/{$thread->id}/media_upload" );
		$sub->set_file_params( $files );

		$response = rest_do_request( $sub );
		$body     = $response->get_data();

		if ( $response->is_error() || empty( $body['url'] ) ) {
			$message = __( 'The upload failed.', 'appza-core' );
			if ( is_array( $body ) && ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
				$message = $body['message'];
			}
			return $this->error( 400, $message );
		}

		// Mark the row as OURS and unclaimed.
		//
		// FluentCommunity creates the Media row here, at upload, and only
		// stamps object_source / feed_id when a message finally claims it.
		// Until then an abandoned chat upload is indistinguishable from an
		// abandoned POST upload — same empty object_source, same null
		// feed_id — so a sweep written against that shape would also bin
		// the image a half-written post draft is holding in the portal.
		//
		// Stamping a marker of our own makes the sweep exact: it only ever
		// looks at uploads this route made. FC's claim path finds media by
		// media_key with no object_source filter, so it still overwrites
		// this to 'chat_message' on send — a claimed row leaves the pending
		// state by itself, with nothing here to keep in step.
		if ( ! empty( $body['media_key'] ) && class_exists( '\FluentCommunity\App\Models\Media' ) ) {
			try {
				\FluentCommunity\App\Models\Media::where( 'media_key', (string) $body['media_key'] )
					->update(
						array(
							'object_source' => self::PENDING_SOURCE,
							'feed_id'       => (int) $thread->id,
						)
					);
			} catch ( \Throwable $e ) {
				// A missed marker costs the row a sweep, not the upload.
			}
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => array(
					'url'       => (string) $body['url'],
					'media_key' => isset( $body['media_key'] ) ? (string) $body['media_key'] : '',
					'type'      => isset( $body['type'] ) ? (string) $body['type'] : '',
					'width'     => isset( $body['width'] ) ? (int) $body['width'] : 0,
					'height'    => isset( $body['height'] ) ? (int) $body['height'] : 0,
					'thread_id' => (int) $thread->id,
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * POST /chat/threads/delete
	 * ------------------------------------------------------------- */

	/**
	 * Design 9 — delete for both participants.
	 *
	 * FC's own deleteThread refuses anything with a space_id, which is
	 * the rule we want: a community conversation belongs to the space,
	 * not to either member, and leaving is the affordance for that
	 * (design 4). The guard is repeated here so the refusal comes back
	 * as our envelope rather than FC's.
	 */
	public function delete_thread( \WP_REST_Request $request ) {
		if ( ! $this->chat_available() ) {
			return $this->error( 400, __( 'Chat is not available on this site.', 'appza-core' ) );
		}

		$viewer    = get_current_user_id();
		$thread_id = (int) $request->get_param( 'thread_id' );
		$thread    = $thread_id > 0 ? \FluentMessaging\App\Models\Thread::find( $thread_id ) : null;

		if ( ! $thread ) {
			return $this->error( 404, __( 'This conversation could not be found.', 'appza-core' ) );
		}
		if ( $thread->space_id ) {
			return $this->error( 400, __( 'A community conversation cannot be deleted. Leave it instead.', 'appza-core' ) );
		}

		$member = \FluentMessaging\App\Models\ThreadUser::where( 'thread_id', $thread->id )
			->where( 'user_id', $viewer )
			->first();

		if ( ! $member ) {
			return $this->error( 403, __( 'You are not part of this conversation.', 'appza-core' ) );
		}

		// Cascades to messages, memberships and chat media — see
		// Thread::deleteChatThread().
		$thread->deleteChatThread();

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => __( 'Conversation deleted', 'appza-core' ),
				),
				'data' => array( 'deleted' => true ),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Presentation
	 * ------------------------------------------------------------- */

	/**
	 * @return array<string, mixed>
	 */
	private function present_thread( $thread, int $viewer ): array {
		$profile = \FluentCommunity\App\Services\Helper::getCurrentProfile();
		$user    = \FluentCommunity\App\Models\User::find( $viewer );

		// Everything the appbar and both 3-dot surfaces need is already
		// composed by FC — reuse it rather than re-deriving the three
		// thread shapes (community / group / DM) ourselves.
		$transformed = \FluentMessaging\App\Services\ChatHelper::transformThread(
			$thread,
			$user,
			$user && method_exists( $user, 'isCommunityModerator' ) ? $user->isCommunityModerator() : false
		);

		$info = array();
		if ( isset( $transformed->info ) ) {
			$info = json_decode( wp_json_encode( $transformed->info ), true );
			$info = is_array( $info ) ? $info : array();
		}

		$type  = isset( $transformed->type ) ? (string) $transformed->type : 'user';
		$title = isset( $info['title'] ) ? (string) $info['title'] : (string) $thread->title;

		$space_slug      = '';
		$space_permalink = '';
		if ( $thread->space_id && $thread->space ) {
			$space_slug      = (string) $thread->space->slug;
			$space_permalink = method_exists( $thread->space, 'getPermalink' ) ? (string) $thread->space->getPermalink() : '';
		}

		// FC blanks last_activity itself once it is older than five
		// minutes, so a non-empty value IS "active now". Recomputing the
		// window here would only let the two disagree.
		$last_activity = isset( $info['last_activity'] ) ? (string) $info['last_activity'] : '';

		$username = isset( $info['username'] ) ? (string) $info['username'] : '';

		return array(
			'id'                      => (int) $thread->id,
			'type'                    => $type,
			'title'                   => $title,
			'photo'                   => isset( $info['photo'] ) ? (string) $info['photo'] : '',
			'initials'                => $this->initials( $title ),
			'username'                => $username,
			'handle'                  => $username !== '' ? '@' . $username : '',
			'permalink'               => isset( $info['permalink'] ) ? (string) $info['permalink'] : '',
			'space_slug'              => $space_slug,
			'space_permalink'         => $space_permalink,
			'member_count'            => $this->member_count( (int) $thread->id ),
			'is_active_now'           => $last_activity !== '',
			'can_view_members'        => ! empty( $info['can_view_members'] ),
			// transformUserThread() publishes no permission bag at all —
			// FC only computes one for community and group threads — so
			// a DM would report "cannot send" and the composer would be
			// disabled on every one-to-one conversation. For a DM the
			// rule is FC's own addMessage guard: an active membership row
			// on a thread that is still active.
			'can_send_message'        => 'user' === $type
				? $this->can_send_dm( $thread, $viewer )
				: ! empty( $info['can_send_message'] ),
			'can_delete_any_messages' => ! empty( $info['can_delete_any_messages'] ),
			// Only a DM can be blocked or deleted; only a community or
			// group thread can be left. The two 3-dot surfaces branch on
			// `type`, but the buttons inside them gate on these so a
			// thread in an odd state cannot offer an action FC will
			// refuse.
			'can_leave'               => in_array( $type, array( 'community', 'group' ), true ),
			'can_block'               => 'user' === $type,
			'can_delete_conversation' => 'user' === $type,
			'is_blocked'              => ! empty( $transformed->blocked_thread ),
			'has_left'                => ! empty( $transformed->left_thread ),
			'is_unavailable'          => ! empty( $transformed->is_unavailable ),
			// Present so a caller that only asked for the thread still
			// knows whether the composer has anything to talk to.
			'message_count'           => (int) $thread->message_count,
			'viewer_id'               => $viewer,
			'viewer_name'             => $profile ? (string) $profile->display_name : '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present_message(
		$message,
		$profile,
		int $viewer,
		bool $can_delete_any,
		bool $starts_run,
		bool $ends_run
	): array {
		$meta      = is_array( $message->meta ) ? $message->meta : array();
		$is_system = ! empty( $meta['system_event'] );
		$author_id = (int) $message->user_id;

		list( $body, $images ) = $this->split_message_text( (string) $message->text );

		$name = $profile ? (string) $profile->display_name : '';

		$reply_to   = isset( $meta['reply_to'] ) ? (int) $meta['reply_to'] : 0;
		$reply_text = isset( $meta['reply_text'] ) ? (string) $meta['reply_text'] : '';

		return array(
			'id'                => (int) $message->id,
			'thread_id'         => (int) $message->thread_id,
			'user_id'           => $author_id,
			'is_mine'           => $author_id === $viewer,
			'author_name'       => $name,
			'author_avatar'     => $profile ? (string) $profile->avatar : '',
			'author_initials'   => $this->initials( $name ),
			'author_username'   => $profile ? (string) $profile->username : '',
			// Two flags, not one: the name sits above the first message
			// of a run, the avatar beside the last.
			'show_sender'       => $starts_run && ! $is_system,
			'show_avatar'       => $ends_run && ! $is_system,
			'message_rendered'  => $body,
			'images'            => $images,
			'time_label'        => $this->time_label( (string) $message->created_at ),
			'created_at_iso'    => (string) $message->created_at,
			'has_reply'         => $reply_to > 0,
			'reply_to_id'       => $reply_to,
			'reply_to_name'     => $reply_to > 0 ? $this->reply_author_name( $reply_to ) : '',
			'reply_to_text'     => $reply_to > 0 ? $this->plain_text( $reply_text ) : '',
			'is_system'         => $is_system,
			// System messages are immutable in FC — deleteMessage
			// refuses them outright, so never offer the affordance.
			'can_delete'        => ! $is_system && ( $author_id === $viewer || $can_delete_any ),
		);
	}

	/* ---------------------------------------------------------------
	 * Resolution + access
	 * ------------------------------------------------------------- */

	/**
	 * thread_id, else space_slug, else user_id. The space CHAT tab uses
	 * the second so it can open a conversation knowing only a slug.
	 */
	private function resolve_thread( \WP_REST_Request $request, int $viewer ) {
		$thread_id = (int) $request->get_param( 'thread_id' );
		if ( $thread_id > 0 ) {
			return \FluentMessaging\App\Models\Thread::find( $thread_id );
		}

		$space_slug = trim( (string) $request->get_param( 'space_slug' ) );
		if ( $space_slug !== '' ) {
			$space = \FluentCommunity\App\Models\Space::where( 'slug', $space_slug )->first();
			if ( ! $space ) {
				return null;
			}
			$thread = \FluentMessaging\App\Services\ChatHelper::getChatThread( $space->id );
			// A space whose Group Chat toggle is off keeps its thread row
			// with status 'disabled'. Treat that as no chat at all rather
			// than serving a conversation the portal has hidden.
			if ( $thread && 'active' !== $thread->status ) {
				return null;
			}
			return $thread;
		}

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id > 0 ) {
			return \FluentMessaging\App\Services\ChatHelper::getUserToUserThread( $viewer, $user_id );
		}

		return null;
	}

	/**
	 * Mirrors FC's addMessage guard for a one-to-one thread: the thread
	 * is active and the viewer's own membership row is active.
	 *
	 * Blocking flips both — Thread.status to 'inactive' and the
	 * blocker's ThreadUser to 'blocked' — so this returns false on
	 * either side of a block without needing a separate check.
	 */
	private function can_send_dm( $thread, int $viewer ): bool {
		if ( 'active' !== $thread->status ) {
			return false;
		}
		return \FluentMessaging\App\Models\ThreadUser::where( 'thread_id', $thread->id )
			->where( 'user_id', $viewer )
			->where( 'status', 'active' )
			->exists();
	}

	private function can_delete_any( $thread, int $viewer ): bool {
		if ( ! $thread->space_id ) {
			return false;
		}
		$user = \FluentCommunity\App\Models\User::find( $viewer );
		if ( ! $user || ! method_exists( $user, 'hasSpacePermission' ) ) {
			return false;
		}
		try {
			return (bool) $user->hasSpacePermission( 'delete_any_feed', $thread->space );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function member_count( int $thread_id ): int {
		return (int) \FluentMessaging\App\Models\ThreadUser::where( 'thread_id', $thread_id )
			->where( 'status', 'active' )
			->count();
	}

	/**
	 * One query for every author on the page.
	 *
	 * @param array<int, object> $rows
	 * @return array<int, object>
	 */
	private function profiles_for( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ (int) $row->user_id ] = true;
		}
		$ids = array_keys( $ids );
		if ( ! $ids ) {
			return array();
		}

		$profiles = \FluentCommunity\App\Models\XProfile::whereIn( 'user_id', $ids )->get();

		$out = array();
		foreach ( $profiles as $profile ) {
			$out[ (int) $profile->user_id ] = $profile;
		}
		return $out;
	}

	/**
	 * Display name of the message being replied to.
	 *
	 * FC stores only `reply_to` + `reply_text` on the meta — the name is
	 * not in there, and the quote block in design 7 leads with it. One
	 * lookup per distinct reply on the page; a static cache keeps a run
	 * of replies to the same message down to one.
	 */
	private function reply_author_name( int $message_id ): string {
		static $cache = array();
		if ( isset( $cache[ $message_id ] ) ) {
			return $cache[ $message_id ];
		}

		$name    = '';
		$message = \FluentMessaging\App\Models\Message::find( $message_id );
		if ( $message ) {
			$profile = \FluentCommunity\App\Models\XProfile::where( 'user_id', $message->user_id )->first();
			if ( $profile ) {
				$name = (string) $profile->display_name;
			}
		}

		$cache[ $message_id ] = $name;
		return $name;
	}

	/* ---------------------------------------------------------------
	 * Text
	 * ------------------------------------------------------------- */

	/**
	 * Split FC's stored message HTML into prose and image URLs.
	 *
	 * Stored shape is `<div class="chat_text">…</div>` optionally
	 * followed by `<div class="chat_medias"><div class="chat_media"><img
	 * src="…"></div>…</div>`. Both wrappers die in the proxy's wp_kses
	 * pass, taking the images with them, so they are separated here.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function split_message_text( string $html ): array {
		$images = array();
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$src = esc_url_raw( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );
				if ( $src !== '' ) {
					$images[] = $src;
				}
			}
		}

		// Drop the media block wholesale, then unwrap the text one. A
		// message that is only images leaves an empty body, which the
		// bubble renders as image-only.
		$body = preg_replace( '#<div class="chat_medias">.*?</div>\s*$#is', '', $html );
		$body = is_string( $body ) ? $body : $html;
		if ( preg_match( '#<div class="chat_text">(.*)</div>#is', $body, $inner ) ) {
			$body = $inner[1];
		}

		return array( trim( (string) $body ), $images );
	}

	/**
	 * The quoted line in design 7 is one line of plain text — any markup
	 * in it would only fight the ellipsis.
	 */
	private function plain_text( string $html ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Compact age, with a weeks step.
	 *
	 * Not `created_at` and not the proxy's relative_time(), which jumps
	 * from days to months and so can never produce the "2w" the designs
	 * show.
	 */
	private function time_label( string $stamp ): string {
		if ( '' === $stamp ) {
			return '';
		}
		try {
			$then = new \DateTime( $stamp, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}

		$diff = time() - $then->getTimestamp();
		if ( $diff < 0 ) {
			$diff = 0;
		}

		if ( $diff < 60 ) {
			return __( 'now', 'appza-core' );
		}
		if ( $diff < 3600 ) {
			return (int) floor( $diff / 60 ) . 'm';
		}
		if ( $diff < 86400 ) {
			return (int) floor( $diff / 3600 ) . 'h';
		}
		if ( $diff < 604800 ) {
			return (int) floor( $diff / 86400 ) . 'd';
		}
		if ( $diff < 2592000 ) {
			return (int) floor( $diff / 604800 ) . 'w';
		}
		if ( $diff < 31536000 ) {
			return (int) floor( $diff / 2592000 ) . 'mo';
		}
		return (int) floor( $diff / 31536000 ) . 'y';
	}

	/**
	 * "Private for everyone" → PF, "MD Saiful" → MS. Matches the
	 * fallback avatars throughout the designs.
	 */
	private function initials( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return '';
		}

		$words   = preg_split( '/\s+/u', $name ) ?: array();
		$letters = '';
		foreach ( $words as $word ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
			if ( '' === trim( $first ) ) {
				continue;
			}
			$letters .= $first;
			if ( strlen( $letters ) >= 2 ) {
				break;
			}
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letters ) : strtoupper( $letters );
	}

	/* ---------------------------------------------------------------
	 * Loopback
	 * ------------------------------------------------------------- */

	/**
	 * POST to a REST route over loopback HTTP, forwarding the caller's
	 * credentials.
	 *
	 * Not `rest_do_request`: FluentCommunity's Request reads its JSON
	 * payload from `php://input` and the superglobals rather than from
	 * the WP_REST_Request it is handed, so a nested dispatch reaches it
	 * carrying OUR request body instead of the one being sent (P78).
	 *
	 * Forwarding the Authorization header is enough because /fcom-login
	 * issues a WordPress application password — so the Basic credential
	 * the app holds is one core itself resolves on the way back in.
	 *
	 * @param string $path Full REST path after /wp-json, e.g. /fluent-community/v2/...
	 * @return array{0:int, 1:array} [status, decoded body]; status 0 = never completed.
	 */
	private function loopback_post( string $path, array $payload, \WP_REST_Request $request ): array {
		$headers = array( 'Content-Type' => 'application/json' );

		$auth = $request->get_header( 'authorization' );
		if ( ! is_string( $auth ) || '' === $auth ) {
			$auth = $request->get_header( 'x_appza_auth' );
		}
		if ( is_string( $auth ) && '' !== $auth ) {
			$headers['Authorization'] = $auth;
		}

		$raw = wp_remote_post(
			home_url( '/wp-json' . $path ),
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $raw ) ) {
			return array( 0, array( 'message' => $raw->get_error_message() ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		$http = (int) wp_remote_retrieve_response_code( $raw );

		return array( $http, is_array( $body ) ? $body : array() );
	}

	/**
	 * Best-effort human message out of an upstream body. Neither FC nor
	 * WP settles on one error shape — `message` is sometimes a string and
	 * sometimes a field-error bag — so reading only one of them turns a
	 * validation failure into a blank toast.
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
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $one ) {
					if ( is_string( $one ) && '' !== $one ) {
						return $one;
					}
				}
			}
		}
		return $fallback;
	}

	/* ---------------------------------------------------------------
	 * Envelope
	 * ------------------------------------------------------------- */

	/**
	 * FluentMessaging is an optional add-on. Every route degrades to an
	 * empty list rather than fatalling on a site without it — the CHAT
	 * tab is gated on the space payload's can_view_chat, which is false
	 * there anyway, so this only catches a direct call.
	 */
	private function chat_available(): bool {
		return class_exists( '\FluentMessaging\App\Models\Thread' )
			&& class_exists( '\FluentMessaging\App\Services\ChatHelper' );
	}

	/**
	 * A write's envelope — a message plus whatever it did, rather than a
	 * list of rows.
	 *
	 * @param array<string, mixed> $data
	 */
	private function ok_message( string $message, array $data = array() ) {
		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => $message,
				),
				'data' => $data,
			)
		);
	}

	/**
	 * @param array<int, mixed>    $items
	 * @param array<string, mixed> $meta
	 */
	private function ok( array $items, array $meta = array() ) {
		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $items,
				'meta' => $meta,
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
