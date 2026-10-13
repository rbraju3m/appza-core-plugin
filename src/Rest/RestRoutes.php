<?php

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
		$screen_layout  = new ScreenLayoutController();
		$design_index   = new DesignIndexController();

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
			'/design-index',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $design_index, 'handle' ),
				'permission_callback' => array( $design_index, 'permission_check' ),
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

		$layout_methods = array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $screen_layout, 'show' ),
				'permission_callback' => array( $screen_layout, 'permission_check' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $screen_layout, 'save' ),
				'permission_callback' => array( $screen_layout, 'permission_check' ),
				'args'                => array(
					'items'        => array( 'required' => true ),
					// Not `required`: WordPress reads a JSON null as missing, and null means "no row yet".
					'base_version' => array(),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $screen_layout, 'reset' ),
				'permission_callback' => array( $screen_layout, 'permission_check' ),
			),
		);
		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/screen-layout/(?P<template>[a-z0-9-]+)/(?P<screen>[a-z0-9-]+)/(?P<part>[a-z-]+)',
			$layout_methods
		);
		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/chrome-layout/(?P<template>[a-z0-9-]+)/(?P<part>[a-z-]+)',
			$layout_methods
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
					'custom_base_url'         => array( 'type' => array( 'string', 'null' ) ),
				),
			)
		);

		register_rest_route(
			APPZA_CORE_REST_NAMESPACE,
			'/build-requests/(?P<id>[0-9a-f-]{36})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $build_requests, 'show' ),
				'permission_callback' => array( $build_requests, 'permission_check' ),
			)
		);

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

		// Routes below live in the third-party fcom-mobile namespace (the catalog calls them there); fcom-mobile itself is never edited.
		// Writes are POST, like every fcom-mobile mutation, so the renderer's HTTP action needs no special case.
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

		register_rest_route(
			'fcom-mobile/api/v1',
			'/spaces/documents/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $documents, 'upload' ),
				'permission_callback' => array( $documents, 'permission_check' ),
				// No `args`: a required text field would reject the multipart request before the file is read.
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

		// Not the composer picker: FC refuses a move into a space the post's AUTHOR (not the viewer) is not in.
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

		$chat = new FluentCommunityChatController();
		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/thread',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $chat, 'thread' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
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

		// Not `/delete`: fcom-mobile owns that route, and a duplicate would answer by registration order.
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

		register_rest_route(
			'fcom-mobile/api/v1',
			'/chat/media/discard',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'discard_media' ),
				'permission_callback' => array( $chat, 'permission_check' ),
				'args'                => array(
					'media_key' => array( 'type' => 'string' ),
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
				// No `args`: see /spaces/documents/upload.
			)
		);

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

		// Public read (FC default privacy is `everybody`); viewer-specific data gates inside the controller.
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
					// preview (default) | all
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
					'range'    => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);

		// Public read: the site's "who can view all members" setting is enforced in the controller.
		$members_directory = new FluentCommunityMembersDirectoryController();
		$directory_args    = array(
			'search'   => array( 'type' => 'string' ),
			// last_activity | display_name | created_at
			'sort_by'  => array( 'type' => 'string' ),
			// ASC | DESC; empty keeps FC's per-column default.
			'sort_dir' => array( 'type' => 'string' ),
			// active | pending | blocked | deactivated; community admins only.
			'status'   => array( 'type' => 'string' ),
			'page'     => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
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
						'title_format'    => array( 'type' => 'string' ),
						'for_status_tabs' => array( 'type' => 'boolean' ),
					)
				),
			)
		);

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
					// alphabetical (default) | latest | oldest
					'sort_by'    => array( 'type' => 'string' ),
					// empty = All Courses; `enrolled` = My Courses.
					'type'       => array( 'type' => 'string' ),
					'page'       => array( 'type' => 'integer' ),
					'per_page'   => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'fcom-mobile/api/v1',
			'/courses/detail',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $courses, 'detail' ),
				'permission_callback' => array( $courses, 'permission_check' ),
				'args'                => array(
					'course_slug' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			'fcom-mobile/api/v1',
			'/courses/detail/sections',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $courses, 'detail_sections' ),
				'permission_callback' => array( $courses, 'permission_check' ),
				'args'                => array(
					'course_slug' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			'fcom-mobile/api/v1',
			'/courses/detail/students',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $courses, 'detail_students' ),
				'permission_callback' => array( $courses, 'permission_check' ),
				'args'                => array(
					'course_id' => array( 'type' => 'integer', 'required' => true ),
					'search'    => array( 'type' => 'string' ),
					// created_at (default) | display_name | last_activity
					'sort_by'   => array( 'type' => 'string' ),
					'sort_dir'  => array( 'type' => 'string' ),
				),
			)
		);

		// No lesson_slug opens the viewer's next lesson.
		register_rest_route(
			'fcom-mobile/api/v1',
			'/courses/lesson',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $courses, 'lesson' ),
				'permission_callback' => array( $courses, 'permission_check' ),
				'args'                => array(
					'course_slug'     => array( 'type' => 'string', 'required' => true ),
					'lesson_slug'     => array( 'type' => 'string' ),
					'position_format' => array( 'type' => 'string' ),
					'discussion_format' => array( 'type' => 'string' ),
					'discussion_none'   => array( 'type' => 'string' ),
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