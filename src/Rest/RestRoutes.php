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