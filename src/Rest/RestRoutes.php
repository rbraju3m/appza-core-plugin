<?php
/**
 * Registers every REST route under the appza/v1 namespace.
 *
 * Routes added here are wired into WP via the `rest_api_init` action by the
 * orchestrator. Chunk 3 ships /bootstrap; /customizations, /auth/* land in
 * later Phase 1B slices.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RestRoutes {

	public function register_routes() {
		$bootstrap      = new BootstrapController();
		$customizations = new CustomizationsController();
		$preview_proxy  = new PreviewProxyController();
		$sync           = new SyncFromCoreController();
		$source_ints    = new SourceIntegrationsController();
		$active_sources = new ActiveSourceIntegrationsController();
		$templates_list = new TemplatesController();
		$build_requests = new BuildRequestsController();
		$space_config   = new SpaceConfigController();

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/source-integrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $source_ints, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $templates_list, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/active-source-integrations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $active_sources, 'get' ),
					'permission_callback' => array( $active_sources, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $active_sources, 'put' ),
					'permission_callback' => array( $active_sources, 'permission_check' ),
					'args'                => array(
						'slugs' => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/bootstrap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $bootstrap, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'template' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/customizations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $customizations, 'list' ),
					'permission_callback' => array( $customizations, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $customizations, 'upsert' ),
					'permission_callback' => array( $customizations, 'permission_check' ),
					'args'                => array(
						'scope'                 => array( 'required' => true, 'type' => 'string' ),
						'target_slug'           => array( 'type' => 'string' ),
						'target_slug_composite' => array( 'type' => 'string' ),
						'target_column'         => array( 'required' => true, 'type' => 'string' ),
						'override_value'        => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/customizations/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $customizations, 'delete' ),
				'permission_callback' => array( $customizations, 'permission_check' ),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/sync-from-core',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $sync, 'handle' ),
				'permission_callback' => array( $sync, 'permission_check' ),
				'args'                => array(
					'template' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/build-requests',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $build_requests, 'create' ),
				'permission_callback' => array( $build_requests, 'permission_check' ),
				'args'                => array(
					'source_integration_slug' => array( 'required' => true, 'type' => 'string' ),
					'template_slug'           => array( 'required' => true, 'type' => 'string' ),
					'base_url_mode'           => array( 'required' => true, 'type' => 'string' ),
					'custom_base_url'         => array( 'type' => 'string' ),
				),
			)
		);

		// Space Config editor endpoints. Admin-only (manage_options).
		// list_all returns FC spaces + all stored configs so the editor
		// can render a single-page picker + form. get_one/save handle
		// per-slug retrieval + upsert. See SpaceConfigController.
		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/space-config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $space_config, 'list_all' ),
				'permission_callback' => array( $space_config, 'permission_check' ),
			)
		);
		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/space-config/(?P<slug>[a-zA-Z0-9\-_]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $space_config, 'get_one' ),
					'permission_callback' => array( $space_config, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $space_config, 'save' ),
					'permission_callback' => array( $space_config, 'permission_check' ),
				),
			)
		);

		// "Remove Preview" from the post 3-dot menu (design 22). Same
		// namespace rationale as the invitation routes below: FC has this
		// as DELETE feeds/{id}/media-preview behind its OWN router, which
		// is unreachable from here, and fcom-mobile ships nothing for it.
		// POST rather than DELETE to match every other fcom-mobile
		// mutation the catalog wires.
		$media_preview = new FluentCommunityFeedMediaPreviewController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/feeds/remove-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $media_preview, 'destroy' ),
				'permission_callback' => array( $media_preview, 'permission_check' ),
				'args'                => array(
					'feed_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// Space invitations for the Invite User modal. Same namespace
		// rationale as /users/search below — fcom-mobile ships no
		// invitation endpoints of its own and is third-party, so we
		// add ours to its namespace rather than editing it (P68).
		$invitations = new FluentCommunityInvitationsController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/invitations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $invitations, 'index' ),
					'permission_callback' => array( $invitations, 'permission_check' ),
					'args'                => array(
						'space_slug' => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'page'       => array( 'type' => 'integer' ),
						'per_page'   => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $invitations, 'store' ),
					'permission_callback' => array( $invitations, 'permission_check' ),
					'args'                => array(
						'space_slug'   => array( 'type' => 'string', 'required' => true ),
						'email'        => array( 'type' => 'string', 'required' => true ),
						'invitee_name' => array( 'type' => 'string' ),
						'role'         => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Delete + resend are POST (not DELETE) to stay consistent
		// with the other write routes the catalog wires — every
		// fcom-mobile space mutation (add-member, remove-member) is a
		// POST, so the renderer's HTTP action needs no special case.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/invitations/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $invitations, 'destroy' ),
				'permission_callback' => array( $invitations, 'permission_check' ),
				'args'                => array(
					'invitation_id' => array( 'type' => 'integer', 'required' => true ),
					'space_slug'    => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/invitations/resend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $invitations, 'resend' ),
				'permission_callback' => array( $invitations, 'permission_check' ),
				'args'                => array(
					'invitation_id' => array( 'type' => 'integer', 'required' => true ),
					'space_slug'    => array( 'type' => 'string' ),
				),
			)
		);

		// Space documents for the DOCUMENTS sub-tab. fcom-mobile does
		// ship /documents, but it wants a numeric space_id (the mobile
		// side only knows slugs) and returns feeds whose file list is
		// a creation-time snapshot — the filename the design shows can
		// be stale there. Ours takes a slug and re-derives files from
		// the live Media rows. Same namespace rationale as above.
		$documents = new FluentCommunityDocumentsController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/documents',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $documents, 'index' ),
				'permission_callback' => array( $documents, 'permission_check' ),
				'args'                => array(
					'space_slug' => array( 'type' => 'string' ),
					'search'     => array( 'type' => 'string' ),
					'page'       => array( 'type' => 'integer' ),
					'per_page'   => array( 'type' => 'integer' ),
				),
			)
		);

		// Add-document write paths. Both are slug-addressed wrappers —
		// FC Pro's upload wants a numeric space_id, and /feeds/create
		// needs status + topic + message rules applied before it sees
		// the payload. See FluentCommunityDocumentsController.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/documents/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $documents, 'upload' ),
				'permission_callback' => array( $documents, 'permission_check' ),
				// No `args` type-checking here: the file rides in as a
				// multipart part, and declaring the text field required
				// would reject the request before the file is read.
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/documents/create',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $documents, 'create' ),
				'permission_callback' => array( $documents, 'permission_check' ),
				'args'                => array(
					'space_slug'   => array( 'type' => 'string', 'required' => true ),
					'title'        => array( 'type' => 'string' ),
					'message'      => array( 'type' => 'string' ),
					'document_ids' => array( 'type' => 'array', 'required' => true ),
					'topic_ids'    => array( 'type' => 'array' ),
					'publish'      => array( 'type' => 'boolean' ),
					'scheduled_at' => array( 'type' => 'string' ),
				),
			)
		);

		// Renaming a file that is already on a post. Adding and removing
		// files on an existing post needs no endpoint of its own: FC's
		// feed update takes the full desired document_ids list and works
		// out the difference itself.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/documents/file/rename',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $documents, 'rename_file' ),
				'permission_callback' => array( $documents, 'permission_check' ),
				'args'                => array(
					'media_id' => array( 'type' => 'integer', 'required' => true ),
					'title'    => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		// User search for the Add Member modal. Registered under the
		// fcom-mobile namespace (not appza/v1) because the mobile
		// catalog's fc-users-search DataSource always calls it via
		// the fcom-mobile prefix. WordPress does not gate
		// register_rest_route by which plugin owns the namespace,
		// and per project policy we never edit the fcom-mobile
		// plugin itself.
		$users_search = new FluentCommunityUsersSearchController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/users/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $users_search, 'handle' ),
				'permission_callback' => array( $users_search, 'permission_check' ),
				'args'                => array(
					'search'     => array( 'type' => 'string' ),
					'space_slug' => array( 'type' => 'string' ),
					'per_page'   => array( 'type' => 'integer' ),
				),
			)
		);

		// The edit screen's "Move to a different space" destinations.
		// A route of ours rather than the composer picker with a filter
		// on it: that picker answers with the VIEWER's spaces, and
		// FeedsController::update refuses a move into any space the
		// POST'S AUTHOR is not an active member of. Same namespace
		// rationale as users/search above.
		$move_spaces = new FluentCommunityMoveSpaceOptionsController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/feeds/move-space-options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $move_spaces, 'handle' ),
				'permission_callback' => array( $move_spaces, 'permission_check' ),
				'args'                => array(
					'feed_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// A member profile's "Add to Space or Course" sheet: the spaces
		// and courses the member is not already in. See the controller
		// for the portal behaviour it mirrors.
		$add_to_space = new FluentCommunityAddToSpaceOptionsController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/members/add-to-options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $add_to_space, 'handle' ),
				'permission_callback' => array( $add_to_space, 'permission_check' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// Chat — the space CHAT tab and the conversation screen. Three
		// routes fcom-mobile does not cover: it drops the thread details
		// from its messages response, every one of its chat routes wants
		// a numeric thread id (the CHAT tab only has a slug), and it
		// never wrapped FC's delete-thread. Everything it DOES wrap
		// (messages/add, messages/delete, threads/leave|block|unblock,
		// chat/spaces/members) is used as-is. Same namespace rationale
		// as the invitations + documents routes above.
		$chat = new FluentCommunityChatController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/thread',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $chat, 'thread' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					// Any ONE of the three identifies a thread; they are
					// tried in this order.
					'thread_id'  => array( 'type' => 'integer' ),
					'space_slug' => array( 'type' => 'string' ),
					'user_id'    => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/messages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $chat, 'messages' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'thread_id'  => array( 'type' => 'integer' ),
					'space_slug' => array( 'type' => 'string' ),
					'user_id'    => array( 'type' => 'integer' ),
					'per_page'   => array( 'type' => 'integer' ),
					'before_id'  => array( 'type' => 'integer' ),
				),
			)
		);

		// Send + attach. fcom-mobile does wrap the send, but only by
		// numeric thread id, and the CHAT tab has a slug — so these take
		// the same thread_id-or-space_slug pair the read routes take and
		// the client never has to resolve a thread before it can talk.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/messages/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'send' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'thread_id'  => array( 'type' => 'integer' ),
					'space_slug' => array( 'type' => 'string' ),
					'text'       => array( 'type' => 'string' ),
					'reply_to'   => array( 'type' => 'integer' ),
					'reply_text' => array( 'type' => 'string' ),
					'media'      => array( 'type' => 'array' ),
				),
			)
		);

		// Delete, with the image swept up behind it. Named `/remove`
		// because fcom-mobile already owns `/chat/messages/delete` in
		// this namespace — registering the same route twice leaves which
		// callback answers up to registration order. Ours delegates the
		// delete to that route and adds the sweep either side.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/messages/remove',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'remove' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'message_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// Throwing away a pending attachment. The scheduled sweep catches
		// what no client call can — an app killed mid-compose — but the
		// user who simply changes their mind should not have to wait a
		// day for the file to go.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/media/discard',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'discard_media' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'media_key' => array( 'type' => 'string' ),
					// The client holds the URL the upload returned, which
					// carries the key as a query arg — either will do.
					'url'       => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/messages/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'upload' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				// No `args` type-checking: the image rides in as a
				// multipart part, and declaring a required text field
				// would reject the request before the file is read.
			)
		);

		// POST rather than DELETE, for the same reason the invitation
		// routes are: every write the catalog wires is a POST, so the
		// renderer's HTTP action needs no special case.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/threads/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'delete_thread' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'thread_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// Leaderboard — the viewer's card, the levels card, the ranking
		// list. fcom-mobile's /leader-board answers with all three ranges
		// at once under items[0]['data-list'][<key>], which a DataSource
		// cannot bind, and neither of its other two routes carries the
		// lock verdict or the viewer-relative arithmetic the portal
		// shows. Public read: the board is visible to logged-out visitors
		// whenever the site's privacy setting says `everybody`, which is
		// FluentCommunity's default. Everything viewer-specific gates
		// itself inside the controller. Same namespace rationale as the
		// chat + invitations + documents routes above.
		$leaderboard = new FluentCommunityLeaderboardController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/leaderboard/me',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $leaderboard, 'me' ),
				'permission_callback' => array( $leaderboard, 'permission_check' ),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/leaderboard/levels',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $leaderboard, 'levels_route' ),
				'permission_callback' => array( $leaderboard, 'permission_check' ),
				'args'                => array(
					// preview (default) | all — VIEW ALL and the points
					// sheet ask for `all`.
					'scope'         => array( 'type' => 'string' ),
					'preview_count' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/leaderboard/ranks',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $leaderboard, 'ranks' ),
				'permission_callback' => array( $leaderboard, 'permission_check' ),
				'args'                => array(
					// FluentCommunity Pro's own range keys.
					'range'    => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);

		// All Members — the directory and the row above it. fcom-mobile's
		// /members asks the right FluentCommunity endpoint and then keeps
		// only the rows, dropping the total the title counts and the
		// viewer's follow levels the ⋮ menu needs. Named `/directory`
		// because fcom-mobile already owns `/members`. Public read: the
		// site's "who can view all members" setting decides, inside the
		// controller. Same namespace rationale as the leaderboard above.
		$members_directory = new FluentCommunityMembersDirectoryController();
		$directory_args    = array(
			'search'   => array( 'type' => 'string' ),
			// last_activity | display_name | created_at
			'sort_by'  => array( 'type' => 'string' ),
			// ASC | DESC — empty keeps FluentCommunity's per-column default.
			'sort_dir' => array( 'type' => 'string' ),
			// active | pending | blocked | deactivated — honoured for a
			// community admin only, as the portal's tabs are.
			'status'   => array( 'type' => 'string' ),
			'page'     => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
			// "Joined %s" — the card's date line, worded by the catalog.
			'joined_format' => array( 'type' => 'string' ),
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/members/directory',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $members_directory, 'directory' ),
				'permission_callback' => array( $members_directory, 'permission_check' ),
				'args'                => $directory_args,
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/members/directory/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $members_directory, 'summary' ),
				'permission_callback' => array( $members_directory, 'permission_check' ),
				'args'                => array_merge(
					$directory_args,
					array(
						// "Members (%d)" — the catalog's words, the count ours.
						'title_format'    => array( 'type' => 'string' ),
						// The status strip's own ask: no row for a viewer
						// who may not see the tabs.
						'for_status_tabs' => array( 'type' => 'boolean' ),
					)
				),
			)
		);

		// Course list — All Courses and My Courses (Courses doc, states
		// 1–4). fcom-mobile's /courses has the filters but no progress,
		// its /all-courses has progress but no filters, and neither says
		// whether a course is paid or shows a secret course to its admin.
		// Named `/catalog` because fcom-mobile owns `/courses`. Public
		// read, as FluentCommunity's is. Same namespace rationale as the
		// leaderboard above.
		$courses = new FluentCommunityCoursesController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/courses/catalog',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $courses, 'catalog' ),
				'permission_callback' => array( $courses, 'permission_check' ),
				'args'                => array(
					'search'     => array( 'type' => 'string' ),
					'topic_slug' => array( 'type' => 'string' ),
					// alphabetical (default) | latest | oldest — FC's keys.
					'sort_by'    => array( 'type' => 'string' ),
					// empty = All Courses; `enrolled` = My Courses.
					'type'       => array( 'type' => 'string' ),
					'page'       => array( 'type' => 'integer' ),
					'per_page'   => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/preview/data',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $preview_proxy, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ds'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
					'template' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
					'params'   => array( 'type' => 'string' ),
				),
			)
		);
	}
}