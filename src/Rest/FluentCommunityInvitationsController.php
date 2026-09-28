<?php
/**
 * Space invitations under the fcom-mobile namespace.
 * Reads query the DB directly: FC's own list filters to invitations the caller sent, even for moderators.
 * Writes go through FC's routes so FC owns validation, tokens and email; FC has no invitation role, so we stamp meta.role.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityInvitationsController {

	private const MANAGER_ROLES = array( 'admin', 'moderator' );

	public function permission_check() {
		return PortalGuard::member();
	}

	public function index( \WP_REST_Request $request ) {
		global $wpdb;

		$space_slug = (string) $request->get_param( 'space_slug' );
		$status     = (string) $request->get_param( 'status' );
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

		$user_id  = get_current_user_id();
		$is_admin = $this->can_manage_space( (int) $space->id, $user_id );

		$where  = array( "type = 'invitation'", 'post_id = %d' );
		$params = array( (int) $space->id );

		if ( ! $is_admin ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		if ( $status !== '' && $status !== 'all' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$table     = $wpdb->prefix . 'fcom_post_comments';
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params )
		);

		$offset = ( $page - 1 ) * $per_page;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			)
		);
		$rows = $rows ?: array();

		$names = $this->display_names( wp_list_pluck( $rows, 'user_id' ) );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->present( $row, $names );
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $items,
				'meta' => array(
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
					'is_admin' => $is_admin,
				),
			)
		);
	}

	// An email that already belongs to a WP user is added directly (no mail); FC's endpoint would email them.
	public function store( \WP_REST_Request $request ) {
		$space_slug   = (string) $request->get_param( 'space_slug' );
		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		$invitee_name = sanitize_text_field( (string) $request->get_param( 'invitee_name' ) );
		$role         = $this->normalize_role( (string) $request->get_param( 'role' ) );

		$space = $this->resolve_space( $space_slug );
		if ( ! $space ) {
			return $this->error( 400, __( 'A valid space slug is required.', 'appza-core' ) );
		}
		if ( $email === '' || ! is_email( $email ) ) {
			return $this->error( 400, __( 'Please enter a valid email address.', 'appza-core' ) );
		}
		if ( ! $this->can_manage_space( (int) $space->id, get_current_user_id() ) ) {
			return $this->error( 403, __( 'You do not have permission to invite people to this space.', 'appza-core' ) );
		}

		$existing = get_user_by( 'email', $email );

		if ( $existing ) {
			if ( $this->is_space_member( (int) $space->id, (int) $existing->ID ) ) {
				return $this->error( 409, __( 'That person is already a member of this space.', 'appza-core' ) );
			}

			$added = $this->dispatch(
				'POST',
				'/fcom-mobile/api/v1/spaces/add-member',
				array(
					'space_slug' => $space_slug,
					'user_id'    => (int) $existing->ID,
					'role'       => $role,
				)
			);
			if ( ! $added['ok'] ) {
				return $this->error( $added['status'], $added['message'] );
			}

			$display = $existing->display_name ? $existing->display_name : $email;

			return rest_ensure_response(
				array(
					'response' => array(
						'status'  => 200,
						/* translators: 1: person's name, 2: membership role */
						'message' => sprintf( __( '%1$s joined the space as %2$s.', 'appza-core' ), $display, $this->role_label( $role ) ),
					),
					'data' => array(
						'joined'  => true,
						'user_id' => (int) $existing->ID,
						'role'    => $role,
					),
				)
			);
		}

		$created = $this->dispatch(
			'POST',
			'/fluent-community/v2/invitations',
			array(
				'email'        => $email,
				'invitee_name' => $invitee_name,
				'space_id'     => (int) $space->id,
			)
		);
		if ( ! $created['ok'] ) {
			return $this->error( $created['status'], $created['message'] );
		}

		$invitation_id = 0;
		if ( isset( $created['data']['invitation']['id'] ) ) {
			$invitation_id = (int) $created['data']['invitation']['id'];
			$this->stamp_role( $invitation_id, $role );
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => $created['message'] ? $created['message'] : __( 'Invitation has been sent successfully.', 'appza-core' ),
				),
				'data' => array(
					'joined'        => false,
					'invitation_id' => $invitation_id,
					'role'          => $role,
				),
			)
		);
	}

	public function destroy( \WP_REST_Request $request ) {
		$invitation_id = (int) $request->get_param( 'invitation_id' );
		if ( $invitation_id <= 0 ) {
			return $this->error( 400, __( 'An invitation id is required.', 'appza-core' ) );
		}

		// FC checks the caller moderates the invitation's space.
		$res = $this->dispatch( 'DELETE', '/fluent-community/v2/invitations/' . $invitation_id, array() );
		if ( ! $res['ok'] ) {
			return $this->error( $res['status'], $this->friendly( $res ) );
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => $res['message'] ? $res['message'] : __( 'Invitation deleted.', 'appza-core' ),
				),
				'data' => array( 'invitation_id' => $invitation_id ),
			)
		);
	}

	public function resend( \WP_REST_Request $request ) {
		$invitation_id = (int) $request->get_param( 'invitation_id' );
		if ( $invitation_id <= 0 ) {
			return $this->error( 400, __( 'An invitation id is required.', 'appza-core' ) );
		}

		$res = $this->dispatch( 'POST', '/fluent-community/v2/invitations/' . $invitation_id . '/resend', array() );
		if ( ! $res['ok'] ) {
			return $this->error( $res['status'], $this->friendly( $res ) );
		}

		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => $res['message'] ? $res['message'] : __( 'Invitation resent.', 'appza-core' ),
				),
				'data' => array( 'invitation_id' => $invitation_id ),
			)
		);
	}

	private function present( $row, array $names ): array {
		$meta    = $this->read_meta( $row->meta );
		$is_link = trim( (string) $row->message ) === '';
		$status  = (string) $row->status;
		$usage   = (int) $row->reactions_count;

		$limit       = isset( $meta['limit'] ) ? (int) $meta['limit'] : 0;
		$expire_date = isset( $meta['expire_date'] ) ? (string) $meta['expire_date'] : '';
		$role        = isset( $meta['role'] ) && $meta['role'] !== '' ? $this->normalize_role( (string) $meta['role'] ) : '';

		// FC flips spent/lapsed links to 'expired' lazily on read; mirror it.
		if ( $is_link && $status === 'active' ) {
			$lapsed = $expire_date !== '' && strtotime( $expire_date ) < strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) );
			$spent  = $limit > 0 && $usage >= $limit;
			if ( $lapsed || $spent ) {
				$status = 'expired';
				$this->mark_expired( (int) $row->id );
			}
		}

		$title = $is_link
			? sprintf( '%s(%s)', (string) ( $meta['title'] ?? '' ), __( 'Invitation Link', 'appza-core' ) )
			: trim( sprintf( '%s <%s>', (string) ( $meta['invitee_name'] ?? '' ), (string) $row->message ) );

		// Mirrors FC's Invitation::isValid().
		$is_valid = $is_link ? ( $status === 'active' ) : ( $status === 'pending' );

		// Deliberately wider than the portal: FC's accept flow rejects expired tokens on use.
		$can_copy = $is_valid || $status === 'expired';

		$access_url = $can_copy
			? $this->access_url( (string) $row->message_rendered )
			: '';

		// Link invites only: on an email invite reactions_count is the SEND count, not usage.
		$meta_parts = array();
		if ( $is_link ) {
			if ( $limit > 0 ) {
				$meta_parts[] = __( 'Limit', 'appza-core' ) . ': ' . $limit;
			}
			if ( $usage > 0 ) {
				$meta_parts[] = __( 'Usage', 'appza-core' ) . ': ' . $usage;
			}
			if ( $expire_date !== '' ) {
				$meta_parts[] = __( 'Expire Date', 'appza-core' ) . ': ' . date_i18n( 'j M', strtotime( $expire_date ) );
			}
		}

		$chip_label = $role !== '' ? $this->role_label( $role ) : $this->status_label( $status );
		$chip_tone  = $role !== '' ? 'success' : $this->status_tone( $status );

		return array(
			'id'           => (int) $row->id,
			'kind'         => $is_link ? 'link' : 'email',
			'title'        => $title,
			'email'        => $is_link ? '' : (string) $row->message,
			'invitee_name' => (string) ( $meta['invitee_name'] ?? '' ),
			'role'         => $role,
			'role_label'   => $role !== '' ? $this->role_label( $role ) : '',
			'status'       => $status,
			'status_label' => $this->status_label( $status ),
			'chip_label'   => $chip_label,
			'chip_tone'    => $chip_tone,
			'access_url'   => $access_url,
			'can_copy'     => $access_url !== '',
			// FC refuses past five sends.
			'can_resend'   => $status === 'pending' && $usage <= 5,
			'can_delete'   => true,
			'limit'        => $limit,
			'usage'        => $usage,
			'expire_date'  => $expire_date,
			'meta_line'    => implode( ' | ', $meta_parts ),
			'invited_by'   => $names[ (int) $row->user_id ] ?? '',
			'created_at'   => (string) $row->created_at,
		);
	}

	// Params are mirrored into $_REQUEST/$_GET: FC's Request wrapper reads some fields off the superglobals.
	private function dispatch( string $method, string $route, array $body ): array {
		$req = new \WP_REST_Request( $method, $route );
		if ( $body ) {
			$req->set_body_params( $body );
			$req->set_query_params( $body );
		}

		$prev_request = $_REQUEST;
		$prev_get     = $_GET;
		foreach ( $body as $key => $value ) {
			if ( is_scalar( $value ) || is_array( $value ) ) {
				$_REQUEST[ $key ] = $value;
				$_GET[ $key ]     = $value;
			}
		}

		try {
			$response = rest_ensure_response( rest_do_request( $req ) );
		} finally {
			$_REQUEST = $prev_request;
			$_GET     = $prev_get;
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 500,
				'message' => $response->get_error_message(),
				'data'    => array(),
			);
		}

		$status = (int) $response->get_status();
		$data   = $response->get_data();
		$data   = is_array( $data ) ? $data : array();

		// fcom-mobile answers HTTP 200 with the real status in response.status; FC uses the HTTP status.
		$inner = isset( $data['response']['status'] ) ? (int) $data['response']['status'] : null;
		$ok    = $inner !== null ? $inner === 200 : ( $status >= 200 && $status < 300 );

		$message = '';
		if ( isset( $data['response']['message'] ) && is_string( $data['response']['message'] ) ) {
			$message = $data['response']['message'];
		} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
			$message = $data['message'];
		}

		return array(
			'ok'      => $ok,
			'status'  => $inner !== null ? $inner : $status,
			'message' => $message,
			'data'    => $data,
		);
	}

	// FC answers a missing invitation with raw ORM exception text.
	private function friendly( array $res ): string {
		if ( (int) $res['status'] === 404 ) {
			return __( 'That invitation no longer exists.', 'appza-core' );
		}

		return $res['message'] ? $res['message'] : __( 'Something went wrong.', 'appza-core' );
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

	private function can_manage_space( int $space_id, int $user_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$role = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}fcom_space_user
				 WHERE space_id = %d AND user_id = %s AND status = 'active' LIMIT 1",
				$space_id,
				(string) $user_id
			)
		);

		return in_array( $role, self::MANAGER_ROLES, true );
	}

	private function is_space_member( int $space_id, int $user_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}fcom_space_user
				 WHERE space_id = %d AND user_id = %s AND status = 'active' LIMIT 1",
				$space_id,
				(string) $user_id
			)
		);
	}

	private function display_names( array $user_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, display_name FROM {$wpdb->prefix}fcom_xprofile WHERE user_id IN ($placeholders)",
				$ids
			)
		);

		$names = array();
		foreach ( $rows ?: array() as $row ) {
			$names[ (int) $row->user_id ] = (string) $row->display_name;
		}

		foreach ( $ids as $id ) {
			if ( empty( $names[ $id ] ) ) {
				$user          = get_userdata( $id );
				$names[ $id ] = $user ? $user->display_name : '';
			}
		}

		return $names;
	}

	private function read_meta( $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$meta = maybe_unserialize( $raw );

		return is_array( $meta ) ? $meta : array();
	}

	private function stamp_role( int $invitation_id, string $role ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fcom_post_comments';
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$table} WHERE id = %d", $invitation_id ) );
		$meta  = $this->read_meta( $raw );

		$meta['role'] = $role;

		$wpdb->update( $table, array( 'meta' => maybe_serialize( $meta ) ), array( 'id' => $invitation_id ) );
	}

	private function mark_expired( int $invitation_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'fcom_post_comments',
			array( 'status' => 'expired' ),
			array( 'id' => $invitation_id )
		);
	}

	private function access_url( string $token ): string {
		if ( $token === '' ) {
			return '';
		}

		$base = class_exists( '\FluentCommunity\App\Services\Helper' )
			? \FluentCommunity\App\Services\Helper::baseUrl( '/' )
			: home_url( '/' );

		return add_query_arg(
			array(
				'fcom_action'      => 'auth',
				'invitation_token' => $token,
			),
			$base
		);
	}

	private function normalize_role( string $role ): string {
		$role = strtolower( trim( $role ) );

		return in_array( $role, array( 'member', 'moderator', 'admin' ), true ) ? $role : 'member';
	}

	private function role_label( string $role ): string {
		$labels = array(
			'member'    => __( 'Member', 'appza-core' ),
			'moderator' => __( 'Moderator', 'appza-core' ),
			'admin'     => __( 'Admin', 'appza-core' ),
		);

		return $labels[ $role ] ?? ucfirst( $role );
	}

	private function status_label( string $status ): string {
		$labels = array(
			'pending'  => __( 'Pending', 'appza-core' ),
			'accepted' => __( 'Accepted', 'appza-core' ),
			'active'   => __( 'Active', 'appza-core' ),
			'expired'  => __( 'Expired', 'appza-core' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}

	private function status_tone( string $status ): string {
		switch ( $status ) {
			case 'pending':
				return 'warning';
			case 'expired':
				return 'muted';
			case 'accepted':
			case 'active':
				return 'success';
			default:
				return 'muted';
		}
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
