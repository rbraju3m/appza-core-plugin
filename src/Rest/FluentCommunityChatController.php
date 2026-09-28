<?php
/**
 * Chat routes in the fcom-mobile namespace: fcom-mobile drops threadDetails and addresses threads by numeric id only.
 * The preview proxy rewrites `created_at` and kses-strips `message_rendered`, hence `time_label` and the split `images[]`.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityChatController {

	private const PER_PAGE = 30;

	private const ACTIVE_WINDOW = 300;

	/** Stamped on our chat uploads; FC overwrites it with 'chat_message' when a message claims the file. */
	private const PENDING_SOURCE = 'chat_message_pending';

	public function register_hooks() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_fc_chat_media' ), 10, 3 );
	}

	/** FC attaches any media_key to a chat message; refuse keys the sender doesn't own, on FC's own route too. */
	public function guard_fc_chat_media( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#^/fluent-community/v2/chat/messages/\d+$#', (string) $request->get_route() ) ) {
			return $response;
		}
		$items = $request->get_param( 'mediaItems' );
		if ( is_array( $items ) && $this->has_unclaimable_media( $items, get_current_user_id() ) ) {
			return new \WP_Error( 'appza_chat_media_not_yours', __( 'That attachment is not yours to send.', 'appza-core' ), array( 'status' => 403 ) );
		}
		return $response;
	}

	private function has_unclaimable_media( array $items, int $viewer ): bool {
		if ( ! class_exists( '\FluentCommunity\App\Models\Media' ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( is_array( $item ) && 'giphy' === ( $item['provider'] ?? '' ) ) {
				continue;
			}
			$url   = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : (string) $item;
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query, $args );
			$key = isset( $args['media_key'] ) ? (string) $args['media_key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$media = \FluentCommunity\App\Models\Media::where( 'media_key', $key )->first();
			if ( ! $media ) {
				continue;
			}
			$source    = (string) $media->object_source;
			$unclaimed = self::PENDING_SOURCE === $source || ( '' === $source && empty( $media->feed_id ) );
			if ( (int) $media->user_id !== $viewer || ! $unclaimed ) {
				return true;
			}
		}
		return false;
	}

	public function permission_check() {
		return PortalGuard::member();
	}

	/** A list of one: the preview proxy reads a bare object as zero rows. */
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

	/** Oldest-first; FC walks newest-first, so reverse after slicing to keep `before_id` meaning "the page above". */
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

		// Hide messages from inactive profiles, as FC's own listing does.
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
				// The name sits above the first message of a run, the avatar beside the last.
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

	/**
	 * Takes thread_id or space_slug; fcom-mobile's messages/add needs a numeric id.
	 * Loopback HTTP, not a nested dispatch: FC reads its payload from php://input.
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
		if ( $media && $this->has_unclaimable_media( $media, $viewer ) ) {
			return $this->error( 403, __( 'That attachment is not yours to send.', 'appza-core' ) );
		}

		$payload = array( 'text' => $text );
		if ( $media ) {
			$payload['mediaItems'] = $media;
		}

		$reply_to = (int) $request->get_param( 'reply_to' );
		if ( $reply_to > 0 ) {
			$payload['reply_to'] = $reply_to;
			// FC stores the quoted text verbatim, so the client sends what it showed.
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

	/**
	 * FC's delete leaves the Media row and file behind, and chat media is keyed to the thread, so read attachments first.
	 * Named /remove because fcom-mobile already owns /chat/messages/delete in this namespace.
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

		// Mirror FC's guards so this route never deletes what FC would refuse.
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

		$swept = $this->sweep_media( $urls, (int) $thread->id, (int) $message->user_id );

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

	/** @return array<int, string> */
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
	 * Scoped to this thread's chat media; skips a file a surviving message still shows (same upload sent twice).
	 *
	 * @param array<int, string> $urls
	 */
	private function sweep_media( array $urls, int $thread_id, int $author_id ): int {
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
				->where( 'user_id', $author_id )
				->get();

			foreach ( $rows as $row ) {
				// Media::delete() unlinks the file via its deleting hook.
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

	/** FC creates the Media row at upload time, so an unsent image must be discarded. Uploader only. */
	public function discard_media( \WP_REST_Request $request ) {
		if ( ! class_exists( '\FluentCommunity\App\Models\Media' ) ) {
			return $this->error( 400, __( 'Media is not available on this site.', 'appza-core' ) );
		}

		$viewer = get_current_user_id();
		$key    = trim( (string) $request->get_param( 'media_key' ) );
		if ( '' === $key ) {
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
			return $this->ok_message( __( 'Attachment discarded', 'appza-core' ), array( 'discarded' => 0 ) );
		}
		if ( (int) $media->user_id !== $viewer ) {
			return $this->error( 403, __( 'That attachment is not yours to discard.', 'appza-core' ) );
		}
		// Only a PENDING upload, so a wrong key can't reach a sent message's or a post's image.
		if ( self::PENDING_SOURCE !== (string) $media->object_source ) {
			return $this->error( 409, __( 'That attachment is already part of a message.', 'appza-core' ) );
		}
		if ( $this->media_is_referenced( (string) $media->media_url, (int) $media->feed_id ) ) {
			return $this->error( 409, __( 'That attachment is already part of a message.', 'appza-core' ) );
		}

		$media->delete();

		return $this->ok_message( __( 'Attachment discarded', 'appza-core' ), array( 'discarded' => 1 ) );
	}

	/** Daily WP-cron sweep of pending chat uploads that were never sent (app killed mid-compose, lost connection). */
	public function sweep_abandoned_media( int $older_than = 7200, int $limit = 200 ): int {
		if ( ! class_exists( '\FluentCommunity\App\Models\Media' ) || ! $this->chat_available() ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $older_than ) );

		// Only our pending marker: an unmarked row may be a post draft's image.
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
			$media->delete();
			$swept++;
		}

		return $swept;
	}

	/** Thread-scoped: FC stores chat media with feed_id = thread, and an unscoped LIKE would scan every message. */
	private function media_is_referenced( string $media_url, int $thread_id ): bool {
		if ( '' === $media_url ) {
			return false;
		}
		return \FluentMessaging\App\Models\Message::where( 'thread_id', $thread_id )
			->where( 'text', 'LIKE', '%' . $this->esc_like( $media_url ) . '%' )
			->exists();
	}

	/** Nested dispatch is safe here: FC's UploadHelper reads $_FILES directly, not php://input. */
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

		// Mark our upload pending: until a message claims it, FC's row looks exactly like a post draft's upload.
		// FC's claim path matches by media_key alone, so it still overwrites this on send.
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

	/** DMs only: a community thread belongs to the space (FC's deleteThread refuses it too). */
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

		// Same rules as FC's deleteThread: DMs only, and a current participant.
		if ( ! empty( $thread->provider ) && 'fcom' !== $thread->provider ) {
			return $this->error( 400, __( 'A group conversation can only be deleted by its admin.', 'appza-core' ) );
		}
		$member = \FluentMessaging\App\Models\ThreadUser::where( 'thread_id', $thread->id )
			->where( 'user_id', $viewer )
			->whereIn( 'status', array( 'active', 'blocked' ) )
			->first();

		if ( ! $member ) {
			return $this->error( 403, __( 'You are not part of this conversation.', 'appza-core' ) );
		}

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

	/**
	 * @return array<string, mixed>
	 */
	private function present_thread( $thread, int $viewer ): array {
		$profile = \FluentCommunity\App\Services\Helper::getCurrentProfile();
		$user    = \FluentCommunity\App\Models\User::find( $viewer );

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

		// FC blanks last_activity after five minutes, so non-empty means "active now".
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
			// FC publishes no permission bag for a DM; mirror its addMessage guard instead.
			'can_send_message'        => 'user' === $type
				? $this->can_send_dm( $thread, $viewer )
				: ! empty( $info['can_send_message'] ),
			'can_delete_any_messages' => ! empty( $info['can_delete_any_messages'] ),
			'can_leave'               => in_array( $type, array( 'community', 'group' ), true ),
			'can_block'               => 'user' === $type,
			'can_delete_conversation' => 'user' === $type,
			'is_blocked'              => ! empty( $transformed->blocked_thread ),
			'has_left'                => ! empty( $transformed->left_thread ),
			'is_unavailable'          => ! empty( $transformed->is_unavailable ),
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
			// FC's deleteMessage refuses system messages.
			'can_delete'        => ! $is_system && ( $author_id === $viewer || $can_delete_any ),
		);
	}

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
			// A space with Group Chat off keeps its thread with status 'disabled'.
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

	/** FC's addMessage guard; a block flips both the thread and the blocker's membership status. */
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

	/** FC's reply meta carries no author name. */
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

	/**
	 * The proxy's wp_kses strips FC's chat_text / chat_medias wrappers (and the images), so split them here.
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

		$body = preg_replace( '#<div class="chat_medias">.*?</div>\s*$#is', '', $html );
		$body = is_string( $body ) ? $body : $html;
		if ( preg_match( '#<div class="chat_text">(.*)</div>#is', $body, $inner ) ) {
			$body = $inner[1];
		}

		return array( trim( (string) $body ), $images );
	}

	private function plain_text( string $html ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** Not the proxy's relative_time(), which jumps from days to months and never yields "2w". */
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

	/**
	 * Loopback, not rest_do_request: FC reads its payload from php://input, so a nested dispatch would carry OUR body.
	 *
	 * @return array{0:int, 1:array} [status, decoded body]; status 0 = never completed.
	 */
	private function loopback_post( string $path, array $payload, \WP_REST_Request $request ): array {
		$headers = array( 'Content-Type' => 'application/json' );

		$auth = $request->get_header( 'authorization' );
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

	/** FC and WP disagree on error shape: `message` may be a string or a field-error bag. */
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

	private function chat_available(): bool {
		return class_exists( '\FluentMessaging\App\Models\Thread' )
			&& class_exists( '\FluentMessaging\App\Services\ChatHelper' );
	}

	/** @param array<string, mixed> $data */
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
