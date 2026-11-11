<?php
/**
 * GET/POST /wp-json/appza/v1/site-template — the template this site's app is built from (Friendly v2 P2).
 * POST /wp-json/appza/v1/onboarding/tour-seen — the builder tour, remembered per WordPress user.
 *
 * Sites that never saved one keep opening where they did before (see resolve()).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Services\CoreClient;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SiteTemplateController {

	const OPTION_KEY        = 'appza_core_site_template';
	const TOUR_META_KEY     = 'appza_core_tour_seen';
	const INTRO_META_KEY    = 'appza_core_intro_hidden';
	const FALLBACK_TEMPLATE = 'fluent-community-default';

	protected $core;
	protected $snapshots;

	public function __construct( CoreClient $core = null, CatalogSnapshotRepository $snapshots = null ) {
		$this->core      = $core ?: new CoreClient();
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
	}

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The saved template; else the default the builder always opened before P2, when it is synced; else the newest
	 * synced one (a site that only ever synced another template); else the default.
	 */
	public static function resolve( CatalogSnapshotRepository $snapshots = null ) {
		$saved = self::saved();
		if ( '' !== $saved ) {
			return $saved;
		}
		$snapshots = $snapshots ?: new CatalogSnapshotRepository();
		if ( null !== $snapshots->find_by_template_slug( self::FALLBACK_TEMPLATE ) ) {
			return self::FALLBACK_TEMPLATE;
		}
		$newest = self::newest_synced( $snapshots );
		return '' !== $newest ? $newest : self::FALLBACK_TEMPLATE;
	}

	public static function saved() {
		$raw = get_option( self::OPTION_KEY, '' );
		return is_string( $raw ) ? sanitize_title( $raw ) : '';
	}

	public static function tour_seen( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id ? (bool) get_user_meta( $user_id, self::TOUR_META_KEY, true ) : false;
	}

	public function get( \WP_REST_Request $request ) {
		$template = self::resolve( $this->snapshots );
		return rest_ensure_response( array(
			'template' => $template,
			'saved'    => '' !== self::saved(),
			'synced'   => null !== $this->snapshots->find_by_template_slug( $template ),
		) );
	}

	public function put( \WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'template' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'appza_site_template_missing', __( 'Choose a design for your app.', 'appza-core' ), array( 'status' => 400 ) );
		}

		$active = get_option( ActiveSourceIntegrationsController::OPTION_KEY, array() );
		$active = is_array( $active ) ? array_values( array_filter( array_map( 'strval', $active ) ) ) : array();
		if ( empty( $active ) ) {
			return new \WP_Error( 'appza_site_template_no_platform', __( 'Choose your platform first.', 'appza-core' ), array( 'status' => 400 ) );
		}

		// Only a template Core offers for the platforms this site picked.
		$envelope = $this->core->fetch_templates( $active );
		if ( is_wp_error( $envelope ) ) {
			return new \WP_Error(
				'appza_site_template_core_unreachable',
				__( 'Could not reach APPZA to check this design. Check the connection on the Sync page and try again.', 'appza-core' ),
				array( 'status' => 502, 'detail' => $envelope->get_error_message() )
			);
		}
		$known = array();
		foreach ( $envelope['templates'] as $row ) {
			if ( ! empty( $row['slug'] ) ) {
				$known[] = (string) $row['slug'];
			}
		}
		if ( ! in_array( $slug, $known, true ) ) {
			return new \WP_Error( 'appza_site_template_unknown', __( 'That design is not available for your platform.', 'appza-core' ), array( 'status' => 400 ) );
		}

		update_option( self::OPTION_KEY, $slug, false );

		return rest_ensure_response( array(
			'template' => $slug,
			'saved'    => true,
			'synced'   => null !== $this->snapshots->find_by_template_slug( $slug ),
		) );
	}

	/** "Don't show this again" on the builder's opening modal, per WordPress user (P1e). */
	public static function intro_hidden( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id ? (bool) get_user_meta( $user_id, self::INTRO_META_KEY, true ) : false;
	}

	public function set_intro_hidden( \WP_REST_Request $request ) {
		$hidden = rest_sanitize_boolean( $request->get_param( 'hidden' ) );
		if ( $hidden ) {
			update_user_meta( get_current_user_id(), self::INTRO_META_KEY, 1 );
		} else {
			delete_user_meta( get_current_user_id(), self::INTRO_META_KEY );
		}
		return rest_ensure_response( array( 'intro_hidden' => $hidden ) );
	}

	public function mark_tour_seen( \WP_REST_Request $request ) {
		update_user_meta( get_current_user_id(), self::TOUR_META_KEY, 1 );
		return rest_ensure_response( array( 'tour_seen' => true ) );
	}

	protected static function newest_synced( CatalogSnapshotRepository $snapshots ) {
		$newest = '';
		$at     = '';
		foreach ( $snapshots->all() as $row ) {
			$when = (string) ( $row['updated_at'] ?? $row['fetched_at'] ?? '' );
			if ( '' === $newest || strcmp( $when, $at ) > 0 ) {
				$newest = (string) $row['template_slug'];
				$at     = $when;
			}
		}
		return $newest;
	}
}
