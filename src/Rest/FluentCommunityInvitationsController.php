<?php
/**
 * Space invitations — list / create / delete / resend.
 *
 *   GET  /wp-json/fcom-mobile/api/v1/spaces/invitations
 *   POST /wp-json/fcom-mobile/api/v1/spaces/invitations
 *   POST /wp-json/fcom-mobile/api/v1/spaces/invitations/delete
 *   POST /wp-json/fcom-mobile/api/v1/spaces/invitations/resend
 *
 * Registered under the fcom-mobile namespace (not appza/v1) per P68 —
 * the mobile catalog treats invitations as FluentCommunity data and
 * always calls them via the fcom-mobile prefix. fcom-mobile itself
 * ships no invitation endpoints, and it is third-party so we never
 * edit it; WordPress lets any plugin add routes to any namespace.
 *
 * Reads go straight to the DB. FluentCommunity's own list endpoint
 * (GET /fluent-community/v2/invitations) hard-filters on
 * `user_id = <current user>` BEFORE its own is_mod branch runs, so a
 * space moderator can only ever see invitations they personally sent
 * — which would empty out the "Invited by" column the design shows.
 * Ours scopes by space and lets space admins/moderators see every
 * invitation for that space.
 *
 * Writes are dispatched internally into FluentCommunity's own routes
 * via rest_do_request() so FC keeps ownership of validation, the
 * permission checks, the invitation token, and the outbound email.
 * The one thing FC has no concept of is a membership *role* on an
 * invitation, so we stamp `meta.role` onto the row after FC creates
 * it, and read it back on list.
 *
 * Invitations live in wp_fcom_post_comments with type='invitation'
 * and come in two shapes:
 *   - email invite: message=<email>, status='pending',
 *     meta={invitee_name, role?}
 *   - link invite:  message='',      status='active',
 *     meta={title, limit, expire_date, role?}; reactions_count is
 *     the number of times the link has been used.
 *
 * Auth: logged-in WP user. Space admins + moderators (and WP users
 * with manage_options) see and manage the whole space's invitations;
 * everyone else is scoped to their own.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityInvitationsController {

	/**
	 * Roles that may see + manage every invitation in a space.
	 */
	private const MANAGER_ROLES = array( 'admin', 'moderator' );

	public function permission_check() {
		return PortalGuard::member();
	}

	/* ---------------------------------------------------------------
	 * GET /spaces/invitations
	 * ------------------------------------------------------------- */

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

		// Non-managers only ever see the invitations they sent.
		if ( ! $is_admin ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		// 'all' (and an empty value) means no status filter, matching
		// the portal's own status dropdown.
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

	/* ---------------------------------------------------------------
	 * POST /spaces/invitations
	 * ------------------------------------------------------------- */

	/**
	 * Two outcomes, decided by whether the email already belongs to a
	 * WP user:
	 *   - known email  → add them to the space immediately with the
	 *     chosen role (no email sent). This is what the design's
	 *     helper text promises; FluentCommunity's own endpoint would
	 *     email them instead.
	 *   - new email    → let FC create the invitation + send the mail,
	 *     then stamp the chosen role onto meta.
	 */
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

		// FC has no role concept on invitations — stamp ours onto the
		// row it just created so the list can show the role chip and a
		// future accept-handler can apply it.
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

	/* ---------------------------------------------------------------
	 * POST /spaces/invitations/delete
	 * ------------------------------------------------------------- */

	public function destroy( \WP_REST_Request $request ) {
		$invitation_id = (int) $request->get_param( 'invitation_id' );
		if ( $invitation_id <= 0 ) {
			return $this->error( 400, __( 'An invitation id is required.', 'appza-core' ) );
		}

		// FC verifies the caller is a moderator of the invitation's
		// space before deleting, so no extra gate is layered here.
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

	/* ---------------------------------------------------------------
	 * POST /spaces/invitations/resend
	 * ------------------------------------------------------------- */

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

	/* ---------------------------------------------------------------
	 * Row presentation
	 * ------------------------------------------------------------- */

	/**
	 * Shape one DB row into what the modal renders. Everything the
	 * design shows as a single line of text is composed here rather
	 * than in the renderer, because the composition is conditional
	 * (usage and expiry only appear when set) and must stay identical
	 * to the web portal's own row rendering.
	 */
	private function present( $row, array $names ): array {
		$meta    = $this->read_meta( $row->meta );
		$is_link = trim( (string) $row->message ) === '';
		$status  = (string) $row->status;
		$usage   = (int) $row->reactions_count;

		$limit       = isset( $meta['limit'] ) ? (int) $meta['limit'] : 0;
		$expire_date = isset( $meta['expire_date'] ) ? (string) $meta['expire_date'] : '';
		$role        = isset( $meta['role'] ) && $meta['role'] !== '' ? $this->normalize_role( (string) $meta['role'] ) : '';

		// FluentCommunity flips a spent or lapsed link to 'expired'
		// lazily, on read. Mirror that here so the app and the portal
		// never disagree about a link's state.
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

		// Usable per FluentCommunity's Invitation::isValid(): an email
		// invite while pending, a link invite while active.
		$is_valid = $is_link ? ( $status === 'active' ) : ( $status === 'pending' );

		// Expired invitations keep their Copy Link, per the design. The
		// URL still exists — FluentCommunity's accept flow is what
		// rejects it on use — so this is wider than the web portal,
		// which hides the control once an invitation lapses.
		$can_copy = $is_valid || $status === 'expired';

		$access_url = $can_copy
			? $this->access_url( (string) $row->message_rendered )
			: '';

		// Link invites only, exactly as the portal renders it. On an
		// email invite reactions_count counts how many times the mail
		// has been SENT, not how many times a link was used, so
		// surfacing it as "Usage" would be a lie.
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

		// The chip reads as the membership role when the invitation
		// carries one (everything created from the app does); links
		// made in the web portal have no role, so they fall back to
		// showing their status.
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
			// Same rule the portal uses: pending only, and FC refuses
			// past five sends. Link invites are never 'pending', so
			// they fall out of this naturally.
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

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/**
	 * Run a WP REST route in-process. Used for every write so that
	 * FluentCommunity's own controllers own validation, permissions
	 * and the invitation email.
	 *
	 * Body params are also mirrored into $_REQUEST/$_GET for the
	 * duration of the call: FC's framework Request wrapper reads a
	 * few fields (per_page, page, status, …) straight off the PHP
	 * superglobals rather than off WP_REST_Request (P61).
	 */
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

		// fcom-mobile routes always answer 200 and carry the real
		// outcome in response.status; FC's own routes use the HTTP
		// status directly. Honour whichever one is present.
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

	/**
	 * FluentCommunity answers a missing invitation with its raw ORM
	 * exception text ("No query results for model [\FluentCommunity\
	 * Modules\Auth\Classes\Invitation] 32"), which would go straight
	 * into a toast. Swap that one case for something readable and
	 * pass every other message through untouched.
	 */
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

	/**
	 * Space admins + moderators manage the whole space's invitations.
	 * WP administrators are treated as space admins so a site owner
	 * who never joined the space can still run it.
	 */
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

	/**
	 * Display names for the "Invited by" line, preferring the
	 * FluentCommunity profile name over the WP one.
	 */
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

	/**
	 * Semantic tone rather than a colour — the modal AppZet maps each
	 * tone to an author-editable colour so the chip stays themeable.
	 */
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
