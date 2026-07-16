<?php
/**
 * POST /wp-json/appza/v1/build-requests
 *   Body:
 *     {
 *       source_integration_slug: string,
 *       template_slug: string,
 *       base_url_mode: 'current' | 'custom',
 *       custom_base_url?: string | null
 *     }
 *   Returns:
 *     {
 *       id: int,
 *       resolved_base_url: string,
 *       template_slug: string,
 *       source_integration_slug: string,
 *       cli_command: string,
 *       requested_at: string (ISO 8601)
 *     }
 *
 * v0 storage: bounded JSON list in WP option `appza_build_requests_log`
 * (last N=50). No table yet — the full `wp_appza_build_requests` schema
 * comes with the App entity (see memory/Plans & Analysis/
 * appza-app-entity-spec.md).
 *
 * Why no server-side shell-exec of `pnpm build`:
 *   - Web PHP process runs as `www-data`; pnpm/node are user-installed
 *     under `rbs-02`'s NVM path (not on the PHP-FPM PATH).
 *   - The monorepo is owned by `rbs-02`, not `www-data` — no write.
 *   - No async job runner installed.
 * So this endpoint returns the exact CLI command Raju runs in a terminal.
 * When the App entity ships, this becomes a real queue-processor + async
 * status polling.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BuildRequestsController {

	const OPTION_KEY   = 'appza_build_requests_log';
	const MAX_KEEP     = 50;
	const MONOREPO_DIR = '/var/www/html/appza-mobile';

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function create( \WP_REST_Request $request ) {
		$si_slug     = sanitize_title( (string) $request->get_param( 'source_integration_slug' ) );
		$tpl_slug    = sanitize_title( (string) $request->get_param( 'template_slug' ) );
		$mode        = (string) $request->get_param( 'base_url_mode' );
		$custom_url  = $request->get_param( 'custom_base_url' );

		if ( '' === $si_slug ) {
			return new \WP_Error(
				'appza_build_bad_si',
				__( 'source_integration_slug is required', 'appza-core' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $tpl_slug ) {
			return new \WP_Error(
				'appza_build_bad_template',
				__( 'template_slug is required', 'appza-core' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $mode, array( 'current', 'custom' ), true ) ) {
			return new \WP_Error(
				'appza_build_bad_mode',
				__( 'base_url_mode must be "current" or "custom"', 'appza-core' ),
				array( 'status' => 400 )
			);
		}

		if ( 'current' === $mode ) {
			$resolved_url = rtrim( (string) get_site_url(), '/' );
		} else {
			$resolved_url = is_string( $custom_url ) ? trim( $custom_url ) : '';
			if ( '' === $resolved_url || ! wp_http_validate_url( $resolved_url ) ) {
				return new \WP_Error(
					'appza_build_bad_custom_url',
					__( 'custom_base_url must be a valid absolute URL', 'appza-core' ),
					array( 'status' => 400 )
				);
			}
			$resolved_url = esc_url_raw( $resolved_url );
		}

		// Gate the request against the site's actual activated SIs so a
		// stale UI can't queue a build for something that was just turned off.
		$active = get_option( ActiveSourceIntegrationsController::OPTION_KEY, array() );
		if ( is_array( $active ) && ! in_array( $si_slug, $active, true ) ) {
			return new \WP_Error(
				'appza_build_si_inactive',
				__( 'That Source Integration is not currently activated on this site.', 'appza-core' ),
				array( 'status' => 409 )
			);
		}

		$id           = time();
		$requested_at = gmdate( 'c' );
		$user_id      = get_current_user_id();

		$row = array(
			'id'                      => $id,
			'source_integration_slug' => $si_slug,
			'template_slug'           => $tpl_slug,
			'base_url_mode'           => $mode,
			'resolved_base_url'       => $resolved_url,
			'requested_by'            => $user_id,
			'requested_at'            => $requested_at,
			'status'                  => 'pending_manual_execution',
		);

		$this->append_log( $row );

		return rest_ensure_response( array(
			'id'                      => $id,
			'source_integration_slug' => $si_slug,
			'template_slug'           => $tpl_slug,
			'resolved_base_url'       => $resolved_url,
			'requested_at'            => $requested_at,
			'cli_command'             => $this->build_cli_command( $resolved_url, $tpl_slug ),
		) );
	}

	protected function build_cli_command( $base_url, $template_slug ) {
		// Env vars consumed by @appza/mobile at Vite build time (VITE_APPZA_SITE_URL
		// + VITE_APPZA_TEMPLATE) — see packages/mobile/src/App.tsx.
		return sprintf(
			"cd %s && VITE_APPZA_SITE_URL=%s VITE_APPZA_TEMPLATE=%s pnpm --filter @appza/mobile build",
			escapeshellarg( self::MONOREPO_DIR ),
			escapeshellarg( $base_url ),
			escapeshellarg( $template_slug )
		);
	}

	protected function append_log( array $row ) {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $row;
		if ( count( $log ) > self::MAX_KEEP ) {
			$log = array_slice( $log, -1 * self::MAX_KEEP );
		}
		update_option( self::OPTION_KEY, $log, false );
	}
}
