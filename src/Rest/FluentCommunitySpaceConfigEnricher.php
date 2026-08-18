<?php
/**
 * Attaches a per-space `appza_config` blob to the fcom-mobile
 * `/spaces/by/slug` response so the mobile renderer can apply
 * per-space overrides for switcher / subtabs / cover / topics
 * on the Space Detail screen.
 *
 * Config source: wp_appza_space_config table (SpaceConfigSchema).
 * Rows are keyed by space slug; the config_json blob is JSON-decoded
 * and merged onto response items[].appza_config.
 *
 * Hooks on `rest_request_after_callbacks` (not `rest_post_dispatch`)
 * so the enrichment fires for both the outer serve_request AND the
 * internal rest_do_request calls the WP-admin simulator makes via
 * PreviewProxyController.
 *
 * ---------------------------------------------------------------------
 * appza_config schema (all keys optional; missing = renderer defaults)
 * ---------------------------------------------------------------------
 *
 * {
 *   "switcher": {
 *     "hidden":            bool,            // hide the whole switcher strip
 *     "spaces_whitelist":  ["slug","slug"], // only these spaces appear;
 *                                           //   missing = show every one.
 *                                           //   Renamed to avoid clashing
 *                                           //   with the mirrored `spaces`
 *                                           //   prop that carries the full
 *                                           //   entry objects.
 *     "_style": {                           // stamped as CSS custom props
 *       "gap":              "8px",
 *       "chip_radius":      "16px",
 *       "chip_bg_color":    "#f2f2f2",
 *       "chip_text_color":  "#111",
 *       "active_bg_color":  "#2563eb",
 *       "active_text_color":"#ffffff"
 *     }
 *   },
 *
 *   "subtabs": [                             // FULL replace; if present,
 *     {                                      //   the entire tab list is this
 *       "value":         "posts",            //   array (order preserved).
 *       "label":         "POSTS",
 *       "hidden":        false,              // hide this row w/o removing
 *       "icon":          "chatbox-outline",  // Ionicon name (optional)
 *       "target_screen": "custom-screen"     // if set, tap does nav.push to
 *                                            //   this screen instead of the
 *                                            //   default set_subtab dispatch.
 *     }
 *   ],
 *
 *   "topics": {
 *     "topic_order":    ["dev","design"],   // reorder chips; unlisted topics
 *                                           //   keep source order behind these
 *     "hidden_topics":  ["business"],       // drop these topic slugs
 *     "section_header": "Filter by",
 *     "all_label":      "All",
 *     "prepend_all":    true,
 *     "_style": {
 *       "gap":                 "6px",
 *       "chip_radius":         "12px",
 *       "active_border_color": "#2563eb",
 *       "font_weight":         "500"
 *     }
 *   },
 *
 *   "cover": {
 *     "hidden":           bool,
 *     "height":           "220px",
 *     "aspect_ratio":     "16 / 9",
 *     "overlay_title":    "Welcome to LazyCoders",
 *     "overlay_gradient": "linear-gradient(180deg, transparent, rgba(0,0,0,0.6))"
 *   }
 * }
 *
 * Directive keys handled by the mobile renderer (PlacementRenderer):
 *   - `hidden`            → placement is skipped entirely
 *   - `spaces_whitelist`  → filter mirrored `spaces` prop by these slugs
 *   - `hidden_topics`     → drop matching entries from mirrored `topics`
 *   - `topic_order`       → reorder mirrored `topics` by this list
 *   - `_style`            → CSS custom props stamped onto the wrapper
 *   - `overlay_title`     → text overlay child on the cover image
 *   - `overlay_gradient`  → gradient overlay on the cover image
 * Everything else is treated as a raw prop override merged onto
 * default_props_override (section_header, all_label, height, ...).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Schema\SpaceConfigSchema;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunitySpaceConfigEnricher {

	const ROUTE = '/fcom-mobile/api/v1/spaces/by/slug';

	public function register_hooks() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'attach_config' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 */
	public function attach_config( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		if ( (string) $request->get_route() !== self::ROUTE ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return $response;
		}
		$items = $data['items'];

		// fcom-mobile's /spaces/by/slug wraps the space row inside
		// items.space (a single-key associative wrapper the mobile
		// dataFetch layer unwraps on the client). Enrich the wrapped
		// space row directly so appza_config lands at items[0].appza_config
		// after the client unwrap. Also handle the plain single-row and
		// list shapes for future callers of this filter.
		if ( is_array( $items ) && isset( $items['space'] ) && is_array( $items['space'] ) ) {
			$items['space'] = $this->enrich_row( $items['space'] );
		} elseif ( is_array( $items ) && ! empty( $items ) && is_int( array_key_first( $items ) ) ) {
			foreach ( $items as $i => $row ) {
				if ( is_array( $row ) ) {
					$items[ $i ] = $this->enrich_row( $row );
				}
			}
		} elseif ( is_array( $items ) ) {
			$items = $this->enrich_row( $items );
		}

		$data['items'] = $items;
		$response->set_data( $data );

		return $response;
	}

	protected function enrich_row( array $row ) {
		$slug = isset( $row['slug'] ) && is_string( $row['slug'] ) ? $row['slug'] : '';
		if ( $slug === '' ) {
			return $row;
		}
		$config = $this->get_config( $slug );
		// Always set the key (empty object → renderer treats as "no
		// overrides"), so the shape is predictable client-side.
		$row['appza_config'] = $config ?: new \stdClass();
		$row                 = $this->add_media_audio_permission( $row, $slug );
		$row                 = $this->add_chat_permission( $row, $slug );
		return $row;
	}

	/**
	 * Adds `permissions.can_view_chat` + `chat_thread_id` — whether the
	 * CHAT sub-tab should appear, and which conversation it opens.
	 *
	 * This IS the space's "Group Chat?" toggle. FluentMessaging creates a
	 * Thread row when the toggle goes on and flips that row's status to
	 * 'disabled' when it goes off (it never deletes it, so the history
	 * survives a re-enable). So "has an ACTIVE thread" is the honest
	 * reading of the toggle, and it is one indexed lookup.
	 *
	 * Same reason it lives here as can_view_media_audio: the sub-tab
	 * strip gates entries with `requires_permission`, which reads the
	 * permissions bag on the fc-space-detail row the AppZet already
	 * resolves — so the gate costs no extra request, and the renderer
	 * knows before the tab is ever tapped.
	 *
	 * `chat_thread_id` rides along so a future caller can address the
	 * thread directly; the CHAT tab itself does not need it, because our
	 * chat routes accept a space slug for exactly this reason.
	 *
	 * Unlike the audio flag this one is set EXPLICITLY to false rather
	 * than left unset. Failing open is right for a tab whose absence is
	 * cosmetic; a CHAT tab that opens an empty screen because the site
	 * has no messaging plugin is a dead end, so the safe direction here
	 * is the other way.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_chat_permission( array $row, string $slug ) {
		$permissions = isset( $row['permissions'] ) && is_array( $row['permissions'] ) ? $row['permissions'] : array();

		$permissions['can_view_chat'] = false;
		$row['chat_thread_id']        = 0;

		// Membership is part of the question, not a separate one: FC's
		// hasChatAccess() demands an active space pivot for a community
		// thread, so a non-member who tapped CHAT would get a 403 and an
		// empty screen. FluentMessaging's own portal header-link filter
		// makes the same check before it offers the link.
		$is_member = ! empty( $permissions['is_member'] );

		if ( $is_member
			&& class_exists( '\FluentMessaging\App\Services\ChatHelper' )
			&& class_exists( '\FluentCommunity\App\Models\Space' ) ) {
			try {
				$space = \FluentCommunity\App\Models\Space::where( 'slug', $slug )->first();
				if ( $space ) {
					$thread = \FluentMessaging\App\Services\ChatHelper::getChatThread( $space->id );
					if ( $thread && 'active' === $thread->status ) {
						$permissions['can_view_chat'] = true;
						$row['chat_thread_id']        = (int) $thread->id;
					}
				}
			} catch ( \Throwable $e ) {
				// Keep the explicit false — see the note above.
			}
		}

		$row['permissions'] = $permissions;
		return $row;
	}

	/**
	 * Adds `permissions.can_view_media_audio` — whether the Audios tab
	 * should appear on the MEDIA sub-tab.
	 *
	 * Why it lives here rather than on the media response, where FC Pro
	 * actually publishes it as `has_audio`: the preview proxy's contract
	 * is that a DataSource resolves to a flat array of items, and it drops
	 * every envelope key for every DS. `has_audio` therefore cannot reach
	 * the renderer that way, and bending that contract for one flag — or
	 * stamping a scalar onto every media row, which a space with zero
	 * photos could not carry anyway — are both worse than putting it next
	 * to the permission it belongs beside.
	 *
	 * Landing it on the space payload also means the renderer knows before
	 * the MEDIA tab is ever opened, and the tab strip gates on it with the
	 * same `requires_permission` mechanism the MEDIA sub-tab itself uses.
	 *
	 * Only computed for a space whose gallery is actually on: the check is
	 * a LIKE over the media table, and every space-detail load would
	 * otherwise pay for it to answer a question about a tab the viewer
	 * cannot reach. Left unset when we cannot answer, which reads as
	 * "show the tab" — the safe direction.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function add_media_audio_permission( array $row, string $slug ) {
		$permissions = isset( $row['permissions'] ) && is_array( $row['permissions'] ) ? $row['permissions'] : null;
		if ( null === $permissions || empty( $permissions['can_view_media'] ) ) {
			return $row;
		}
		if ( ! class_exists( '\FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService' ) ) {
			return $row;
		}
		if ( ! class_exists( '\FluentCommunity\App\Models\Space' ) ) {
			return $row;
		}

		try {
			$space = \FluentCommunity\App\Models\Space::where( 'slug', $slug )->first();
			if ( ! $space ) {
				return $row;
			}
			$permissions['can_view_media_audio'] = (bool) \FluentCommunityPro\App\Modules\MediaGallery\Services\MediaGalleryService::spaceHasAudio( $space );
			$row['permissions']                  = $permissions;
		} catch ( \Throwable $e ) {
			// Leave the key unset — the tab shows, which beats hiding a
			// tab because a query failed.
		}

		return $row;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function get_config( string $slug ) {
		global $wpdb;
		$table = SpaceConfigSchema::table_name();
		$json  = $wpdb->get_var(
			$wpdb->prepare( "SELECT config_json FROM {$table} WHERE space_slug = %s LIMIT 1", $slug )
		);
		if ( ! is_string( $json ) || $json === '' ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
