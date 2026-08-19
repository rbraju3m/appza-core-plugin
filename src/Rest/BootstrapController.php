<?php
/**
 * GET /wp-json/appza/v1/bootstrap?template=<slug>
 *
 * Single endpoint the APPZA mobile app calls on cold boot + foreground
 * revalidation. Returns the DC#13 Q5 envelope per appza-implementation-plan.md
 * § 4.13. Public-read v1 per DC#13 Q4 — no auth required.
 *
 * v1 behaviour: reads the local snapshot for the requested template_slug.
 * Empty-catalog branch when no snapshot row exists (first-install state):
 * envelope returns with catalog_snapshot_version = 0 and empty catalog arrays,
 * so the mobile app can render its onboarding / "site not configured yet"
 * state without erroring.
 *
 * Customizations always empty for v1 (wp_appza_customizations lands in
 * Phase 1B.5+). Auth block in runtime_config is empty until the JWT flow
 * lands (DC#13 Q4 — own phase).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogMetaRepository;
use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;
use AppzaCore\Plugin\Repository\CustomizationRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BootstrapController {

	protected $snapshots;
	protected $meta;
	protected $customizations;

	public function __construct(
		CatalogSnapshotRepository $snapshots = null,
		CatalogMetaRepository $meta = null,
		CustomizationRepository $customizations = null
	) {
		$this->snapshots      = $snapshots ?: new CatalogSnapshotRepository();
		$this->meta           = $meta ?: new CatalogMetaRepository();
		$this->customizations = $customizations ?: new CustomizationRepository();
	}

	public function handle( \WP_REST_Request $request ) {
		$template_slug = (string) $request->get_param( 'template' );

		if ( '' === $template_slug ) {
			return new \WP_Error( 'appza_bootstrap_missing_template', __( 'template query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		$meta     = $this->meta->get();

		$catalog = $snapshot && is_array( $snapshot['snapshot_blob'] )
			? $this->normalize_catalog_shape( $snapshot['snapshot_blob'] )
			: $this->empty_catalog( $template_slug );

		// Features -> "Leaderboards Module" off takes the Leaderboard page
		// out of the catalog entirely rather than leaving it to render an
		// explanation. The hamburger drawer lists every template screen it
		// is handed, so a screen left in place would be a live entry
		// leading to a dead page — which is exactly what FluentCommunity
		// does in its own portal sidebar when the module is off.
		$catalog = $this->apply_feature_gates( $catalog );

		$customizations_tree = $this->customizations->all_as_tree();

		$response = array(
			'schema_version'           => APPZA_CORE_CONTRACTS_VERSION,
			'catalog_snapshot_version' => $snapshot ? (int) $snapshot['catalog_snapshot_version'] : 0,
			'customizations_version'   => (int) $meta['customizations_version'],
			'catalog'                  => $catalog,
			// Real customizations payload — `scope -> target_key -> column ->
			// override_value` nested object. Empty when no overrides exist
			// (still {}, never []). Phase 1B.5a wires the read path; the
			// admin UI to edit these ships in Phase 1B.5b.
			'customizations'           => empty( (array) $customizations_tree )
				? new \stdClass()
				: $customizations_tree,
			'runtime_config'           => $this->runtime_config( $catalog ),
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Restore object-shape for known leaf-bag fields after the repository's
	 * json_decode($blob, true) collapsed `{}` to PHP empty array. Without
	 * this, the round-trip Core -> WP-cache -> bootstrap response converts
	 * object-shaped fields (tokens, screen_tokens) into JSON arrays,
	 * breaking the wire contract documented in DC#09 / DC#10.
	 */
	protected function normalize_catalog_shape( array $catalog ) {
		if ( isset( $catalog['template'] ) && is_array( $catalog['template'] ) ) {
			$catalog['template']['tokens'] = $this->normalize_object( $catalog['template']['tokens'] ?? null );
		}
		if ( ! empty( $catalog['template_screens'] ) && is_array( $catalog['template_screens'] ) ) {
			foreach ( $catalog['template_screens'] as &$screen ) {
				if ( is_array( $screen ) ) {
					$screen['screen_tokens'] = $this->normalize_object( $screen['screen_tokens'] ?? null, true );
				}
			}
			unset( $screen );
		}
		return $catalog;
	}

	protected function normalize_object( $value, $allow_null = false ) {
		if ( null === $value ) {
			return $allow_null ? null : new \stdClass();
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return new \stdClass();
		}
		return $value;
	}

	protected function empty_catalog( $template_slug ) {
		return array(
			'template'           => array( 'slug' => $template_slug ),
			'superstructures'    => array(),
			'appzets'            => array(),
			'data_sources'       => array(),
			'actions'            => array(),
			'primitives'         => array(),
			'primitive_props'    => array(),
			'app_map'            => null,
			'source_integration' => null,
		);
	}

	protected function runtime_config( array $catalog ) {
		$source_integration_slug = null;
		if ( isset( $catalog['source_integration']['slug'] ) ) {
			$source_integration_slug = (string) $catalog['source_integration']['slug'];
		}

		return array(
			'wp_base_url'             => get_site_url(),
			'source_integration_slug' => $source_integration_slug,
			'locale_default'          => get_locale(),
			'auth'                    => null,
			'fluent_community'        => $this->fluent_community_settings(),
		);
	}

	/**
	 * Drop screens whose feature is switched off in the Source
	 * Integration's own settings.
	 *
	 * Only the Leaderboard so far. The screen is removed rather than
	 * flagged because the drawer is generated from `template_screens`:
	 * anything left in the list is a tappable entry, and a page that
	 * exists only to say the feature is off is worse than no page. The
	 * AppZets and data sources stay in the snapshot — they are harmless
	 * unreferenced rows, and pruning them would mean re-walking the
	 * reachability graph on every bootstrap.
	 *
	 * @param array<string, mixed> $catalog
	 * @return array<string, mixed>
	 */
	protected function apply_feature_gates( array $catalog ) {
		if ( empty( $catalog['template_screens'] ) || ! is_array( $catalog['template_screens'] ) ) {
			return $catalog;
		}

		$gated = array();
		if ( ! $this->leaderboard_enabled() ) {
			$gated[] = 'leaderboard';
		}

		if ( ! $gated ) {
			return $catalog;
		}

		$catalog['template_screens'] = array_values(
			array_filter(
				$catalog['template_screens'],
				static function ( $screen ) use ( $gated ) {
					$slug = is_array( $screen ) ? ( $screen['app_map_screen_slug'] ?? '' ) : '';
					return ! in_array( $slug, $gated, true );
				}
			)
		);

		return $catalog;
	}

	/**
	 * Features -> "Leaderboards Module", plus FluentCommunity Pro
	 * actually being installed. The settings flag survives deactivating
	 * Pro, and every leaderboard route goes through a Pro class.
	 */
	protected function leaderboard_enabled() {
		if ( ! class_exists( '\\FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper' ) ) {
			return false;
		}
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return false;
		}

		return (bool) \FluentCommunity\App\Services\Helper::isFeatureEnabled( 'leader_board_module' );
	}

	/**
	 * Surface the FluentCommunity customization settings the mobile
	 * renderer honors so the app matches whatever the site admin picked
	 * in Settings → Customization. Currently just member_list_layout
	 * (classic = list, grid = tile grid); more entries can join here
	 * (rich_post_layout, default_feed_layout, etc.) as the renderer
	 * gains support for them.
	 *
	 * Returns null when FluentCommunity isn't installed so the mobile
	 * bootstrap loader can distinguish "no FC on this site" from
	 * "FC present with default settings".
	 *
	 * @return array<string, string>|null
	 */
	protected function fluent_community_settings() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return null;
		}

		$layout = \FluentCommunity\App\Functions\Utility::getCustomizationSetting( 'member_list_layout' );
		$layout = in_array( $layout, array( 'classic', 'grid' ), true ) ? $layout : 'classic';

		return array(
			'member_list_layout'             => $layout,
			// Features -> "Leaderboards Module". The screen is already
			// gone from the catalog when this is false; the flag rides
			// along so a shell can explain itself rather than silently
			// missing a page.
			'leaderboard_enabled'            => $this->leaderboard_enabled(),
			// Privacy -> "Who can view leaderboard top members?", raw.
			// Deliberately NOT resolved to a yes/no here: bootstrap is
			// public-read and cached per template, so a per-viewer verdict
			// computed at this point would be whoever warmed the cache.
			// The verdict travels with the leaderboard payload instead,
			// which is fetched per request and per viewer.
			'leaderboard_members_visibility' => $this->leaderboard_visibility(),
		);
	}

	/**
	 * @return string everybody | logged_in | admin_only, or '' when
	 *                FluentCommunity is not installed.
	 */
	protected function leaderboard_visibility() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return '';
		}

		$value = \FluentCommunity\App\Functions\Utility::getPrivacySetting( 'leaderboard_members_visibility' );

		return is_string( $value ) ? $value : '';
	}
}