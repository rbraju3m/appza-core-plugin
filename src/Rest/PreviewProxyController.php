<?php
/**
 * GET /wp-json/appza/v1/preview/data?ds=<slug>&params=<json>
 *
 * Backs the renderer's `useDataSource()` hook. Routes a catalog DataSource
 * slug to the matching WP REST endpoint that lives on this WP install
 * (typically a fcom-mobile / appza-builder controller). Caches per-session
 * via WP transients (5 min TTL) so the simulator's many AppZets each
 * fetching from the same source don't hammer the upstream.
 *
 * Same proxy serves both the plugin-admin simulator AND the Capacitor
 * mobile app, so the renderer never branches on context.
 *
 * Fallback: when the upstream returns 4xx/5xx OR the catalog row's endpoint
 * is missing (dev environment with broken legacy plugins), a small fixture
 * dataset keyed by data source slug is served so the preview still renders
 * realistic content.
 *
 * No auth at v1 (matches the bootstrap endpoint posture — admin-only access
 * is enforced by the React simulator's WP-admin context, and customer
 * mobile-app reads will gain JWT in Phase 1B.6).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Repository\CatalogSnapshotRepository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewProxyController {

	const CACHE_TTL = 300;

	protected $snapshots;

	public function __construct( CatalogSnapshotRepository $snapshots = null ) {
		$this->snapshots = $snapshots ?: new CatalogSnapshotRepository();
	}

	/** @var FluentCommunityDocumentsController|null */
	protected $documents = null;

	public function handle( \WP_REST_Request $request ) {
		$ds_slug      = sanitize_title( (string) $request->get_param( 'ds' ) );
		$params_json  = (string) $request->get_param( 'params' );
		$template_slug = sanitize_title( (string) $request->get_param( 'template' ) ) ?: 'fluent-community-default';
		$mode         = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( ! in_array( $mode, array( 'demo', 'real', 'auto' ), true ) ) {
			$mode = 'auto';
		}

		if ( '' === $ds_slug ) {
			return new \WP_Error( 'appza_preview_missing_ds', __( 'ds query parameter is required', 'appza-core' ), array( 'status' => 400 ) );
		}

		// Forward the browser's Authorization header (if any) to the upstream
		// endpoint so user-scoped data sources (fc-current-user, feeds for
		// the logged-in user, etc.) return the actual signed-in user's data
		// instead of empty/anonymous responses. Cache key includes an auth
		// hash so different users don't see each other's data.
		$auth_header = $this->extract_auth_header( $request );
		$auth_bucket = $auth_header ? substr( md5( $auth_header ), 0, 12 ) : 'anon';

		// The viewer's cache generation rides IN the key, so a write can
		// invalidate every cached read this viewer holds by incrementing
		// one integer — no enumerating transients, and it works the same
		// whether transients live in the options table or an external
		// object cache. Orphaned entries are already TTL-bounded and
		// simply expire unread. See bump_generation() below.
		$generation = self::generation_for( $auth_bucket );
		$cache_key  = 'appza_preview_' . $auth_bucket . '_' . $generation . '_'
			. md5( $template_slug . '|' . $ds_slug . '|' . $params_json . '|' . $mode );

		// The catalog's own cache_ttl for this DataSource. 0 means "never
		// cache" — fc-bookmarks declares it because the Saved Posts list
		// changes the moment the viewer un-saves something. Until now the
		// column was decorative: every source was cached for a flat 300s
		// regardless of what it asked for, so a source that had declared
		// itself uncacheable was cached for five minutes.
		$cache_ttl = $this->resolve_cache_ttl( $template_slug, $ds_slug );

		// `nocache=1` bypasses the transient read (still writes fresh result
		// after fetch, so subsequent normal reads benefit). Used by
		// plug-in-admin's Refresh button + window-focus revalidation to
		// pull new upstream posts without waiting out the TTL.
		$nocache = '1' === (string) $request->get_param( 'nocache' );
		if ( ! $nocache && $cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return rest_ensure_response( $cached );
			}
		}

		if ( 'demo' === $mode ) {
			$payload = $this->fixture_for( $ds_slug );
		} elseif ( 'real' === $mode ) {
			$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		} else {
			$endpoint = $this->resolve_endpoint( $template_slug, $ds_slug );
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = $this->fixture_for( $ds_slug );
			}
		}

		$viewer_id = $this->resolve_viewer_id( $auth_header );
		$payload = $this->normalize_items( $payload, $viewer_id );

		// fc-spaces-my is by-definition a "joined spaces" list, but the
		// upstream /spaces payload omits space_pivot. Stamp a synthetic
		// pivot so the shared fc-space-card visible_when for View Space
		// (children[10] "space_pivot exists") evaluates true — otherwise
		// the My tab renders Join CTAs on spaces the viewer already joined.
		if ( 'fc-spaces-my' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( is_array( $item ) && ! isset( $item['space_pivot'] ) ) {
					$payload[ $i ]['space_pivot'] = array(
						'status' => 'active',
					);
				}
			}
		}

		// The profile header's second line. FluentCommunity's portal draws
		// "@username · N points" there and the endpoint carries no such
		// field — only the parts. Joined here rather than in two children
		// sharing a grid cell, which is the mistake D69 unpicked on the
		// post card's byline.
		if ( 'fc-member-profile' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$bits = array();
				if ( ! empty( $item['username'] ) ) {
					$bits[] = '@' . $item['username'];
				}
				$points = isset( $item['total_points'] ) ? (int) $item['total_points'] : 0;
				if ( $points > 0 ) {
					/* translators: %d: the member's points total */
					$bits[] = sprintf( _n( '%d point', '%d points', $points, 'appza-core' ), $points );
				}
				$payload[ $i ]['profile_byline'] = implode( ' · ', $bits );

				// The follow cluster's gates, and the two header counts.
				//
				// FluentCommunity Pro appends `follow` — the LEVEL — only when
				// a row exists for this viewer -> this profile, and unfollow
				// DELETES that row. So an absent key is the "not following"
				// state, not a missing field; reading it as 0 would say
				// "blocked", which is a different thing entirely.
				//
				//   0 = blocked · 1 = following · 2 = following + notify
				//
				// Derived here rather than in the catalog because a field
				// mapping can only bind a PATH, and "absent, not zero" is not
				// a path. Both viewer-relative keys are also absent on your
				// own profile by design — FC skips them when viewer == target.
				$profile_user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
				$follow_level    = ( array_key_exists( 'follow', $item ) && is_numeric( $item['follow'] ) )
					? (int) $item['follow']
					: null;

				$payload[ $i ]['is_self_profile'] = ( $viewer_id > 0 && $profile_user_id === $viewer_id );
				$payload[ $i ]['follow_level']    = $follow_level;
				$payload[ $i ]['is_following']    = ( null !== $follow_level && $follow_level >= 1 );
				$payload[ $i ]['is_notifying']    = ( 2 === $follow_level );
				// FC's follow() rejects a blocked user outright ("already
				// following or blocked"), so a Follow button drawn on one is a
				// button that can only fail. The cluster gates on this.
				$payload[ $i ]['is_blocked_by_viewer'] = ( 0 === $follow_level );

				// Whether this viewer may MESSAGE this member, and it is
				// FluentCommunity's answer rather than ours (2026-09-02).
				//
				// `profile_nav_actions` is FC's own per-viewer action list
				// for a profile; today it holds exactly one entry — Message,
				// pointing at `/portal/chat?user_id=N`. Reading it here is
				// the same reasoning as D76, where the profile tab strip
				// stopped being a list we kept: messaging can be switched
				// off, a profile can be private, and both rules are FC's to
				// change. A `show_message` toggle of ours would be a second
				// copy of them, wrong the day either moves.
				$can_message = false;
				if ( isset( $item['profile_nav_actions'] ) && is_array( $item['profile_nav_actions'] ) ) {
					foreach ( $item['profile_nav_actions'] as $nav_action ) {
						if ( ! is_array( $nav_action ) ) {
							continue;
						}
						$css = (string) ( $nav_action['css_class'] ?? '' );
						$url = (string) ( $nav_action['url'] ?? '' );
						if ( false !== strpos( $css, 'fcom_chat_button' ) || false !== strpos( $url, '/chat?user_id=' ) ) {
							$can_message = true;
							break;
						}
					}
				}
				$payload[ $i ]['can_message'] = $can_message;

				// Whether the VIEWER is a moderator — which nothing on this
				// row answered. `is_moderator` is FluentCommunity's word for
				// the SUBJECT ("no" on demo_student's row while raju, an
				// admin, was looking at it), so the ⋯ menu's
				// Add To Space/Course row had no flag to gate on.
				$viewer_roles = $this->viewer_roles( $viewer_id );
				$payload[ $i ]['viewer_is_moderator'] = $viewer_roles['is_moderator'] || $viewer_roles['is_admin'];

				// The ⋯ menu's two block rows, as POSITIVE fields.
				//
				// A menu row's `visible_when` is a truthiness test on a
				// PATH — there is no negation in that grammar and adding
				// one would change how every menu in the catalog is read.
				// So "not blocked" gets its own field, the way
				// `permissions.can_report` and the rest already do.
				$is_self = ( $viewer_id > 0 && $profile_user_id === $viewer_id );

				// FluentCommunity refuses a block for three reasons, and
				// all three are knowable here — so the row is hidden
				// rather than offered and then refused. MEASURED, not
				// guessed: tapping Block as raju answered "Sorry, You
				// cannot block a user as you are a moderator."
				//
				// Its rules, in its own order (FollowController::block):
				// not yourself, not a moderator as the TARGET, and not a
				// moderator as the VIEWER. That last one uses
				// `Helper::isModerator()` specifically — a site admin who
				// is not a community moderator may still block — which is
				// why this reads the strict flag and the ⋯ menu's
				// Add To Space/Course row reads the union.
				$target_is_moderator = ( 'yes' === (string) ( $item['is_moderator'] ?? '' ) );
				$payload[ $i ]['can_block'] = (
					! $is_self
					&& 0 !== $follow_level
					&& ! $viewer_roles['is_moderator']
					&& ! $target_is_moderator
				);
				$payload[ $i ]['can_unblock'] = ( ! $is_self && 0 === $follow_level );

				// Both counts are WITHHELD rather than zeroed — by the privacy
				// setting (who_can_see_followers / _followings) and by the
				// module being off. Default to 0 so the two header buttons
				// always bind a number instead of an undefined.
				$followers  = isset( $item['followers_count'] ) ? (int) $item['followers_count'] : 0;
				$followings = isset( $item['followings_count'] ) ? (int) $item['followings_count'] : 0;
				$payload[ $i ]['followers_count']  = $followers;
				$payload[ $i ]['followings_count'] = $followings;

				// A count button binds ONE text, and a bare "0" says nothing —
				// the same reason the byline above is joined here rather than
				// split across two children sharing a cell (D69). The words are
				// FluentCommunity's own: it says Followers and Followings.
				/* translators: %d: number of people following this member */
				$payload[ $i ]['followers_count_label'] = sprintf( _n( '%d Follower', '%d Followers', $followers, 'appza-core' ), $followers );
				/* translators: %d: number of people this member follows */
				$payload[ $i ]['followings_count_label'] = sprintf( _n( '%d Following', '%d Followings', $followings, 'appza-core' ), $followings );

				// The edit form's email field. FluentCommunity changes an
				// address inline for `edit_users`, or for the profile's owner on
				// a site whose can_change_email privacy setting is yes — and
				// fcom-mobile exposes no email route at all. It publishes the
				// verdict as `can_change_email`; inverted here because a field
				// binds `readonly`, and a binding cannot negate.
				$payload[ $i ]['email_readonly'] = empty( $item['can_change_email'] );

				// The profile's tab strip, which is FluentCommunity's OWN nav
				// rather than our copy of it. `profile_navs` is already
				// filtered per viewer — Spaces and Courses appear only with
				// canViewUserSpaces, and Courses additionally needs the course
				// module — so a hand-kept list here would be a second copy of
				// a rule FluentCommunity changes at will.
				//
				// The renderer expands a strip from `tabs` on the AppZet or, as
				// here, off the bound ROW. One profile row means one strip.
				$payload[ $i ]['tabs'] = $this->profile_tabs( $item );

				// The About tab. FluentCommunity's portal is the reference —
				// Noor has no design for it — and everything it shows is
				// already on this row. Composed here for the same reason the
				// byline is: a text child binds ONE string, and three children
				// sharing a line is the mistake D69 unpicked.
				//
				// `created_at` has already been rewritten to a compact relative
				// form by normalize_items ("8mo"); last_activity has not.
				$bits = array();
				if ( ! empty( $item['created_at'] ) ) {
					/* translators: %s: a relative time, e.g. "8mo" */
					$bits[] = sprintf( __( 'Joined %s ago', 'appza-core' ), $item['created_at'] );
				}
				if ( ! empty( $item['last_activity'] ) && is_string( $item['last_activity'] ) ) {
					/* translators: %s: a relative time, e.g. "4d" */
					$bits[] = sprintf( __( 'Active %s ago', 'appza-core' ), $this->relative_time( $item['last_activity'] ) );
				}
				$points = isset( $item['total_points'] ) ? (int) $item['total_points'] : 0;
				if ( $points > 0 ) {
					/* translators: %d: the member's points total */
					$bits[] = sprintf( _n( '%d point', '%d points', $points, 'appza-core' ), $points );
				}
				$payload[ $i ]['about_stats'] = implode( ' · ', $bits );

				// Social links arrive as an OBJECT keyed by provider, and a
				// variadic strip expands an ARRAY. Empty on this box, so the
				// strip simply draws nothing.
				$socials = array();
				$raw_socials = isset( $item['social_links'] ) ? (array) $item['social_links'] : array();
				foreach ( $raw_socials as $provider => $url ) {
					if ( ! is_string( $url ) || '' === trim( $url ) ) {
						continue;
					}
					$socials[] = array(
						'value'     => (string) $provider,
						'label'     => ucfirst( str_replace( array( '_', '-' ), ' ', (string) $provider ) ),
						'permalink' => trim( $url ),
					);
				}
				$payload[ $i ]['social_link_list'] = $socials;
			}
		}

		// The profile's COMMENTS tab. Two derivations, both so a row can
		// be a card:
		//
		// `comment_context` — the line under the comment saying which post
		// it was left on. FluentCommunity does not require a post title
		// and most posts here have none, so it falls back to the post's
		// own text, trimmed.
		//
		// `slug` — the POST's slug, promoted to the top level. post-detail
		// resolves its row from `route.post_slug`, which reads the item the
		// tap passes as context; a comment has no slug of its own, so
		// without this the tap would arrive at post-detail with nothing to
		// look up.
		if ( 'fc-member-comments' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$post  = isset( $item['post'] ) && is_array( $item['post'] ) ? $item['post'] : array();
				$title = isset( $post['title'] ) ? trim( wp_strip_all_tags( (string) $post['title'] ) ) : '';
				if ( '' === $title ) {
					$body  = (string) ( $post['message_rendered'] ?? $post['message'] ?? '' );
					$title = trim( wp_trim_words( wp_strip_all_tags( $body ), 8, '…' ) );
				}
				if ( '' === $title ) {
					$title = __( 'a post', 'appza-core' );
				}
				/* translators: %s: the title of the post a comment was left on */
				$payload[ $i ]['comment_context'] = sprintf( __( 'on %s', 'appza-core' ), $title );

				if ( ! empty( $post['slug'] ) ) {
					$payload[ $i ]['slug'] = (string) $post['slug'];
				}
			}
		}

		// The two follow lists (2026-09-02).
		//
		// FluentCommunity answers both with a FOLLOW row — the pivot —
		// and hangs the person off a different key in each: `follower` on
		// /members/followers, `followed` on /members/followings. A card
		// binds a PATH, not a row (P138), so without flattening them here
		// the two lists would need two identical cards differing in one
		// word, and every later change would have to be made twice.
		//
		// The derived fields, all of them what the portal draws:
		//
		// `profile_byline` — "Joined 8mo ago", plus "· Last seen: 17h ago"
		// when FC has a last_activity. One string because a row binds one
		// text, which is the same reason the profile header's byline is
		// joined in the proxy rather than split across two children.
		//
		// `is_following` / `is_self` — the two gates the Follow button
		// needs. FC ships `follow` as the LEVEL (null when not following,
		// 0 when blocked, >0 when following), which no visible_when can
		// compare usefully, and a viewer's own row carries no button at
		// all — raju appears in demo_student's followers with none.
		if ( in_array( $ds_slug, array( 'fc-member-followers', 'fc-member-followings' ), true ) && is_array( $payload ) ) {
			$person_key = 'fc-member-followers' === $ds_slug ? 'follower' : 'followed';
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$person = isset( $item[ $person_key ] ) && is_array( $item[ $person_key ] )
					? $item[ $person_key ]
					: array();
				if ( empty( $person ) ) {
					continue;
				}

				foreach ( array( 'user_id', 'username', 'display_name', 'avatar', 'permalink', 'total_points', 'short_description' ) as $field ) {
					if ( isset( $person[ $field ] ) ) {
						$payload[ $i ][ $field ] = $person[ $field ];
					}
				}

				// "@name" as ONE string. fc-member-card does this with a
				// bespoke renderer transform keyed on its own slug; here
				// the flattening already runs, so the prefix costs a line
				// rather than a special case in the render path.
				if ( ! empty( $person['username'] ) ) {
					$payload[ $i ]['username_at'] = '@' . $person['username'];
				}

				$bits = array();
				if ( ! empty( $person['created_at'] ) ) {
					/* translators: %s: relative time, e.g. "8mo" */
					$bits[] = sprintf( __( 'Joined %s ago', 'appza-core' ), $this->relative_time( (string) $person['created_at'] ) );
				}
				if ( ! empty( $person['last_activity'] ) ) {
					/* translators: %s: relative time, e.g. "17h" */
					$bits[] = sprintf( __( 'Last seen: %s ago', 'appza-core' ), $this->relative_time( (string) $person['last_activity'] ) );
				}
				$payload[ $i ]['profile_byline'] = implode( ' · ', $bits );

				$level = isset( $person['follow'] ) ? $person['follow'] : null;
				$payload[ $i ]['is_following'] = ( null !== $level && '' !== $level && (int) $level > 0 );
				$payload[ $i ]['is_follower']  = ! empty( $person['is_follower'] );
				$payload[ $i ]['is_self']      = ( $viewer_id > 0 && (int) ( $person['user_id'] ?? 0 ) === (int) $viewer_id );
			}
		}

		// A DM that does not exist yet (2026-09-02).
		//
		// FluentCommunity cannot open an empty one-to-one thread:
		// `POST /chat/threads` validates `message` as REQUIRED and creates
		// the thread WITH its first message. Its own portal does not
		// pretend otherwise — the profile's Message button links to
		// `/portal/chat?user_id=N`, an INTENT rather than a thread.
		//
		// So the conversation screen has to be able to draw a person it
		// has no thread for. Asked for a thread with an `intent_user_id`
		// and no id, this answers with the same row shape the real
		// endpoint returns, built from that member's profile: the appbar
		// gets a name and a face, `can_send_message` is true so the
		// composer exists, and `id` is 0 — which is what the send handler
		// reads to know it must create the thread rather than post to it.
		if ( 'fc-chat-thread' === $ds_slug && empty( $payload ) ) {
			$decoded_params = json_decode( $params_json, true );
			$intent_user_id = is_array( $decoded_params ) && isset( $decoded_params['intent_user_id'] )
				? (int) $decoded_params['intent_user_id']
				: 0;
			if ( $intent_user_id > 0 ) {
				global $wpdb;
				$profile = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT user_id, username, display_name, avatar FROM {$wpdb->prefix}fcom_xprofile WHERE user_id = %d",
						$intent_user_id
					),
					ARRAY_A
				);
				if ( is_array( $profile ) ) {
					$name = (string) ( $profile['display_name'] ?? '' );
					$payload = array(
						array(
							'id'                      => 0,
							'type'                    => 'user',
							'title'                   => $name,
							'photo'                   => (string) ( $profile['avatar'] ?? '' ),
							'initials'                => $this->initials_for( $name ),
							'username'                => (string) ( $profile['username'] ?? '' ),
							'handle'                  => '@' . (string) ( $profile['username'] ?? '' ),
							'space_slug'              => '',
							'member_count'            => 2,
							'is_active_now'           => false,
							'can_view_members'        => false,
							// The whole point: a composer on a conversation
							// that does not exist yet.
							'can_send_message'        => true,
							'can_delete_any_messages' => false,
							'can_leave'               => false,
							'can_block'               => false,
							'can_delete_conversation' => false,
							'intent_user_id'          => $intent_user_id,
						),
					);
				}
			}
		}

		// The comment thread under post detail (the 28 FeedPostDetails
		// designs). Four derivations, all of them shape rather than style:
		//
		// `children` — FluentCommunity answers /feeds/{id}/comments with a
		// FLAT list carrying `parent_id`, and nothing threads it. The real
		// data nests arbitrarily deep (a reply to a reply to a reply), but
		// FC's own portal and Noor's designs 13 and 21 both draw exactly
		// TWO levels, so every descendant is flattened onto its top-level
		// ancestor rather than indented one step further per generation.
		// An indent staircase is what a depth-faithful tree would give,
		// and no design has one.
		//
		// `reply_to_name` — the blue name in front of a reply's body. It
		// is the author of the comment being replied to, NOT of the thread
		// root, which is the difference that makes a flattened list still
		// readable. FluentCommunity stores it nowhere: a reply's
		// message_rendered is the bare body, so without this the reply
		// reads as a non-sequitur.
		//
		// `reply_count_label` — "1 Reply" / "2 Reply". Not pluralised, on
		// purpose: designs 13 and 21 both write "Reply" singular and the
		// designs win.
		//
		// `sort_*` — the sort sheet has four orders and FluentCommunity's
		// endpoint accepts none of them (getComments is hardcoded to
		// created_at ASC), so the ordering happens client-side and these
		// are the keys it orders on. `sort_time` is the epoch of the
		// ORIGINAL created_at, which the relative-time pass above would
		// otherwise have destroyed.
		if ( 'fc-post-comments' === $ds_slug && is_array( $payload ) ) {
			$payload = $this->thread_comments( $payload );
		}

		// Feed links carry their icon in one of THREE fields — an emoji,
		// an uploaded image URL, or raw inline SVG markup — and a chip
		// can bind one image source, not three. Resolve them to a single
		// `link_icon_url` here, turning the SVG into a data URI so the
		// same <img> draws it; the emoji stays its own field because it
		// is text, not an image.
		if ( 'fc-feed-links' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$payload[ $i ]['link_icon_url'] = $this->resolve_link_icon( $item );
			}
		}

		if ( $cache_ttl > 0 ) {
			set_transient( $cache_key, $payload, $cache_ttl );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * The DataSource's declared cache_ttl, in seconds.
	 *
	 * An explicit 0 means "do not cache" and is honoured as such. A
	 * missing / non-numeric / negative value falls back to CACHE_TTL, so
	 * a source that never thought about caching behaves as it always has.
	 */
	protected function resolve_cache_ttl( $template_slug, $ds_slug ) {
		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		if ( ! $snapshot || ! is_array( $snapshot['snapshot_blob'] ) ) {
			return self::CACHE_TTL;
		}
		$data_sources = isset( $snapshot['snapshot_blob']['data_sources'] )
			? (array) $snapshot['snapshot_blob']['data_sources']
			: array();
		foreach ( $data_sources as $ds ) {
			if ( ! isset( $ds['slug'] ) || (string) $ds['slug'] !== $ds_slug ) {
				continue;
			}
			if ( ! array_key_exists( 'cache_ttl', $ds ) || ! is_numeric( $ds['cache_ttl'] ) ) {
				return self::CACHE_TTL;
			}
			$ttl = (int) $ds['cache_ttl'];
			return $ttl < 0 ? self::CACHE_TTL : $ttl;
		}
		return self::CACHE_TTL;
	}

	/**
	 * Bucket a request's credentials the same way the proxy does, so a
	 * write arriving on a DIFFERENT route can address the cache the
	 * reads populated.
	 *
	 * The app sends `X-Appza-Auth` on proxy reads and a plain
	 * `Authorization` on direct fcom-mobile writes, but the VALUE is the
	 * same credential string in both, which is what gets hashed.
	 */
	public static function auth_bucket_for( \WP_REST_Request $request ) {
		$header = $request->get_header( 'x_appza_auth' );
		if ( ! is_string( $header ) || '' === $header ) {
			$header = $request->get_header( 'authorization' );
		}
		return ( is_string( $header ) && '' !== $header )
			? substr( md5( $header ), 0, 12 )
			: 'anon';
	}

	/** Current cache generation for a viewer bucket. */
	public static function generation_for( $auth_bucket ) {
		return (int) get_option( 'appza_preview_gen_' . $auth_bucket, 0 );
	}

	/**
	 * Invalidate every cached read held for one viewer.
	 *
	 * Called after that viewer writes something through fcom-mobile. The
	 * motivating case: the reaction toggle's DIRECTION is computed from
	 * the row the client is holding, so a stale row does not merely look
	 * wrong — the next tap sends the OPPOSITE of what the user intended
	 * and silently removes a like they meant to add.
	 */
	public static function bump_generation( $auth_bucket ) {
		$option = 'appza_preview_gen_' . $auth_bucket;
		update_option( $option, self::generation_for( $auth_bucket ) + 1, false );
	}

	/**
	 * Read the incoming Authorization header. Preference order:
	 *   1. Explicit `X-Appza-Auth` header (set by the plug-in-admin so
	 *      the browser can pass Basic Auth without the WP REST layer
	 *      short-circuiting on the standard Authorization header).
	 *   2. Standard `Authorization` header if present + non-empty.
	 * Returns the raw header value (e.g. `Basic <base64>`) or null.
	 */
	protected function extract_auth_header( \WP_REST_Request $request ) {
		$appza = $request->get_header( 'x_appza_auth' );
		if ( is_string( $appza ) && '' !== $appza ) {
			return $appza;
		}
		$std = $request->get_header( 'authorization' );
		if ( is_string( $std ) && '' !== $std ) {
			return $std;
		}
		return null;
	}

	/**
	 * Decode the Basic Auth header we forwarded to the upstream + resolve
	 * the WP user id from the username. Used to enrich feed items with the
	 * viewer's own reaction state (like/bookmark) so the renderer can bind
	 * icon + color per-item without a second round-trip.
	 *
	 * FC's feed listing eager-loads only `reactions.type = 'like'`, so
	 * bookmark state never surfaces there — we query the reactions table
	 * directly in normalize_items() using the id resolved here.
	 */
	/**
	 * FluentCommunity's profile navs, as the tab strip's entries.
	 *
	 * Their slugs are the portal's route names; ours are the values the
	 * placements gate on. Anything unrecognised is passed through with its
	 * slug as the value, so a nav FC adds later draws as a tab rather than
	 * vanishing — it will simply have no placement answering it yet.
	 *
	 * @param array $item One profile row.
	 * @return array<int,array<string,string>>
	 */
	protected function profile_tabs( $item ) {
		$map = array(
			'user_profile'       => 'about',
			'user_profile_feeds' => 'posts',
			'user_spaces'        => 'spaces',
			'user_courses'       => 'courses',
			'user_comments'      => 'comments',
		);

		$navs = isset( $item['profile_navs'] ) && is_array( $item['profile_navs'] )
			? $item['profile_navs']
			: array();

		$tabs = array();
		foreach ( $navs as $nav ) {
			if ( ! is_array( $nav ) || empty( $nav['slug'] ) ) {
				continue;
			}
			$slug = (string) $nav['slug'];
			$tabs[] = array(
				'value' => $map[ $slug ] ?? $slug,
				'label' => isset( $nav['title'] ) ? (string) $nav['title'] : ucfirst( $map[ $slug ] ?? $slug ),
			);
		}

		return $tabs;
	}

	protected function resolve_viewer_id( $auth_header ) {
		if ( ! is_string( $auth_header ) || 0 !== stripos( $auth_header, 'basic ' ) ) {
			return 0;
		}
		$b64 = trim( substr( $auth_header, 6 ) );
		$decoded = base64_decode( $b64, true );
		if ( ! is_string( $decoded ) || strpos( $decoded, ':' ) === false ) {
			return 0;
		}
		list( $username, ) = explode( ':', $decoded, 2 );
		if ( '' === $username ) {
			return 0;
		}
		$user = get_user_by( 'login', $username );
		return $user ? (int) $user->ID : 0;
	}

	protected function resolve_endpoint( $template_slug, $ds_slug ) {
		$snapshot = $this->snapshots->find_by_template_slug( $template_slug );
		if ( ! $snapshot || ! is_array( $snapshot['snapshot_blob'] ) ) {
			return null;
		}
		$data_sources = isset( $snapshot['snapshot_blob']['data_sources'] )
			? (array) $snapshot['snapshot_blob']['data_sources']
			: array();
		foreach ( $data_sources as $ds ) {
			if ( isset( $ds['slug'] ) && (string) $ds['slug'] === $ds_slug ) {
				return isset( $ds['endpoint'] ) ? (string) $ds['endpoint'] : null;
			}
		}
		return null;
	}

	protected function fetch_upstream( $endpoint, $params_json, $auth_header = null ) {
		$params = array();
		if ( '' !== $params_json ) {
			$decoded = json_decode( $params_json, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			}
		}

		// Upstream endpoints all live on this same WP install (fcom-mobile,
		// appza-builder, tutor-lms-mobile). Prefer rest_do_request over
		// wp_remote_get: it dispatches the callback in-process so the WP
		// current-user context (cookie session from the WP-admin simulator)
		// is preserved without having to forward cookies or invent an
		// Authorization header. This is what lets fcom-mobile's transformer
		// see the correct viewer via Helper::getCurrentUser() and attach a
		// meaningful permissions bag — otherwise every menu row gated on
		// data.permissions.* is hidden.
		//
		// Externally-called endpoints (mobile app with Basic Auth) still
		// fall through wp_remote_get so the Authorization header round-trips
		// correctly across the wire boundary.
		if ( '' !== $endpoint && '/' === $endpoint[0] && 0 === strpos( $endpoint, '/wp-json/' ) ) {
			$route = substr( $endpoint, strlen( '/wp-json' ) );
			// Resolve the viewer from the forwarded Basic Auth token BEFORE
			// dispatching so the sub-request's Helper::getCurrentUser() sees
			// the same user as the browser. WP core does not parse Basic
			// Auth; without this step get_current_user_id() stays 0 and
			// every permission (edit / delete / moderate) resolves to false.
			$prev_user_id = get_current_user_id();
			$sub_user_id  = $this->resolve_viewer_id( $auth_header );
			if ( $sub_user_id > 0 && $sub_user_id !== $prev_user_id ) {
				wp_set_current_user( $sub_user_id );
			}
			$rest_request = new \WP_REST_Request( 'GET', $route );
			// Set as BOTH query params + generic params so downstream
			// handlers can read via either get_query_params() or get_params().
			foreach ( $params as $k => $v ) {
				$rest_request->set_param( $k, $v );
			}
			$rest_request->set_query_params( $params );
			// FluentCommunity's Model::getPerPage() reads $_REQUEST['per_page']
			// directly — it bypasses the WP_REST_Request. Same story for its
			// SpaceController's search / status / sort switches on some
			// endpoints. Inject the forwarded params into $_REQUEST/$_GET for
			// the duration of the sub-request so paginate() honors what the
			// client actually asked for (else it silently caps at 15/page).
			// Restore afterward so the outer request isn't polluted.
			$prev_request_super = $_REQUEST;
			$prev_get_super     = $_GET;
			foreach ( $params as $k => $v ) {
				$scalar = is_scalar( $v ) ? (string) $v : ( is_array( $v ) ? $v : null );
				if ( $scalar === null ) {
					continue;
				}
				$_REQUEST[ $k ] = $scalar;
				$_GET[ $k ]     = $scalar;
			}
			if ( is_string( $auth_header ) && '' !== $auth_header ) {
				$rest_request->set_header( 'Authorization', $auth_header );
			}
			$response = rest_do_request( $rest_request );
			// Restore superglobals + viewer so nothing downstream of this
			// sub-request sees the forged state.
			$_REQUEST = $prev_request_super;
			$_GET     = $prev_get_super;
			if ( $sub_user_id > 0 && $sub_user_id !== $prev_user_id ) {
				wp_set_current_user( $prev_user_id );
			}
			if ( $response->is_error() ) {
				return null;
			}
			$decoded = $response->get_data();
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			// Some upstreams wrap items: try common shapes.
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				return $decoded['data'];
			}
			if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
				return $decoded['items'];
			}
			return $decoded;
		}

		$url = home_url( $endpoint );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'APPZA-Preview-Proxy',
		);
		// Forward the caller's Basic Auth so user-scoped upstream endpoints
		// (fc-current-user etc.) return the actual signed-in user's data.
		if ( is_string( $auth_header ) && '' !== $auth_header ) {
			$headers['Authorization'] = $auth_header;
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 8,
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Some upstreams wrap items: try common shapes.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $decoded['data'];
		}
		if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
			return $decoded['items'];
		}
		return $decoded;
	}

	/**
	 * Normalize each item so text bindings render cleanly.
	 * - Strips HTML from `message_rendered` (rendered arrives as
	 *   `<p>...</p>` from FluentCommunity; text bindings would otherwise
	 *   show the literal tags).
	 * - Converts `created_at` datetime string to a compact relative form
	 *   (`5m`, `2h`, `3d`) so the text bindings don't blow up the header
	 *   row width.
	 *
	 * Applied to both fixture and real payloads at handle() time, so the
	 * fixture shape stays honest to the real API + demo/real render the
	 * same way.
	 */
	/**
	 * Lazily-built documents controller, reused for its file derivation so
	 * the feed card and the DOCUMENTS tab cannot drift apart on what a
	 * file row is.
	 */
	protected function documents_controller() {
		if ( null === $this->documents ) {
			$this->documents = new FluentCommunityDocumentsController();
		}
		return $this->documents;
	}

	protected function normalize_items( $items, $viewer_id = 0 ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		// Batch-fetch the viewer's own reactions for the returned feed_ids
		// so each item can carry a `my_reactions` array without an
		// per-item query. FC eager-loads `type='like'` on the feed listing
		// but bookmark reactions are stored in the same table, so this is
		// the only place the renderer can learn bookmark state.
		$my_reactions_by_feed = array();
		$my_votes_by_feed     = array();
		if ( $viewer_id > 0 ) {
			$feed_ids = array();
			foreach ( $items as $it ) {
				if ( is_array( $it ) && isset( $it['id'] ) && is_numeric( $it['id'] ) ) {
					$feed_ids[] = (int) $it['id'];
				}
			}
			if ( ! empty( $feed_ids ) ) {
				global $wpdb;
				$placeholders = implode( ',', array_fill( 0, count( $feed_ids ), '%d' ) );
				$params = array_merge( array( $viewer_id ), $feed_ids );
				$sql = "SELECT object_id, type FROM {$wpdb->prefix}fcom_post_reactions WHERE object_type = 'feed' AND user_id = %d AND object_id IN ($placeholders)";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$fid = isset( $r['object_id'] ) ? (int) $r['object_id'] : 0;
						$type = isset( $r['type'] ) ? (string) $r['type'] : '';
						if ( $fid && $type ) {
							if ( ! isset( $my_reactions_by_feed[ $fid ] ) ) {
								$my_reactions_by_feed[ $fid ] = array();
							}
							$my_reactions_by_feed[ $fid ][] = $type;
						}
					}
				}
				// The viewer's own SURVEY VOTES, batched the same way.
				//
				// A separate query because a survey vote is shaped
				// differently from a like: `type` is 'survey_vote' and
				// `object_type` carries the OPTION SLUG rather than the
				// literal 'feed', so the query above cannot see them.
				//
				// This is the whole difference between Noor's designs 8
				// and 9 (and 11 and 12): both draw a filled bar on the
				// winning option, and only the viewer's own vote fills
				// the radio. FluentCommunity's feed payload ships neither
				// the votes nor a flag, so before this the two states
				// were indistinguishable and every voted poll rendered as
				// design 9.
				$sql = "SELECT object_id, object_type FROM {$wpdb->prefix}fcom_post_reactions WHERE type = 'survey_vote' AND user_id = %d AND object_id IN ($placeholders)";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$fid  = isset( $r['object_id'] ) ? (int) $r['object_id'] : 0;
						$slug = isset( $r['object_type'] ) ? (string) $r['object_type'] : '';
						if ( $fid && '' !== $slug ) {
							if ( ! isset( $my_votes_by_feed[ $fid ] ) ) {
								$my_votes_by_feed[ $fid ] = array();
							}
							$my_votes_by_feed[ $fid ][] = $slug;
						}
					}
				}
			}
		}
		foreach ( $items as $i => $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$fid = (int) $item['id'];
				$items[ $i ]['my_reactions'] = isset( $my_reactions_by_feed[ $fid ] )
					? $my_reactions_by_feed[ $fid ]
					: array();
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['message_rendered'] ) && is_string( $item['message_rendered'] ) ) {
				$items[ $i ]['message_rendered'] = $this->sanitize_rich_text( $item['message_rendered'] );
			}
			if ( isset( $item['created_at'] ) && is_string( $item['created_at'] ) && '' !== $item['created_at'] ) {
				// Keep the machine-readable instant alongside the human one.
				// `created_at` becomes "24m" here, and anything downstream
				// that needs to ORDER by time — the comment sort sheet, for
				// one — cannot get it back: strtotime('24m') is false, and
				// "1mo" sorts before "5m" alphabetically. Stamped before the
				// rewrite, once, for every row the proxy touches.
				$items[ $i ]['created_at_ts'] = (int) strtotime( $item['created_at'] );
				$items[ $i ]['created_at']    = $this->relative_time( $item['created_at'] );
			}
			// The author byline as ONE string — "4h · Appza Community".
			//
			// The card used to draw this as two children sharing a grid
			// cell, the space name shoved right with `padding-left: 32px`.
			// That holds only while the timestamp is narrower than 32px:
			// "4h" fits, "12mo" overlaps. A grid cell cannot lay two
			// blockified items out in a row, so the honest place to join
			// them is here, next to the relative time this already
			// derives.
			//
			// Falls back to the time alone when the post has no space —
			// a profile post has none, and " · " with nothing after it
			// reads as a bug.
			$items[ $i ]['byline'] = $this->build_byline(
				isset( $items[ $i ]['created_at'] ) ? $items[ $i ]['created_at'] : '',
				isset( $item['space'] ) ? $item['space'] : null
			);
			// Mark the options this viewer voted for, and normalise the
			// two count fields FluentCommunity leaves OUT when they are
			// zero — an option nobody picked has no `vote_counts` and no
			// `percentage` key at all, so the card had nothing to bind
			// and drew a blank where "0%" belongs.
			if ( isset( $item['content_type'] ) && 'survey' === $item['content_type'] ) {
				$items[ $i ]['meta'] = $this->normalize_survey(
					isset( $items[ $i ]['meta'] ) && is_array( $items[ $i ]['meta'] ) ? $items[ $i ]['meta'] : array(),
					isset( $my_votes_by_feed[ (int) $item['id'] ] ) ? $my_votes_by_feed[ (int) $item['id'] ] : array()
				);
			}
			// The flagged banner's sentence (designs 28 and 34), derived
			// here for the same reason the byline was: it is THREE
			// sentences, with a noun that is "post" or "comment" and an
			// English plural, and no clause grammar can pick between
			// them. Three children with three gates would be three ways
			// to say one thing.
			// Only when there IS a meta object. A post with `meta: null`
			// cannot be flagged — the flag lives in that very object — so
			// turning null into [] here would change the payload's shape
			// for every unflagged row to say nothing new.
			if ( isset( $items[ $i ]['meta'] ) && is_array( $items[ $i ]['meta'] ) ) {
				$items[ $i ]['meta'] = $this->add_moderation_banner_text( $items[ $i ]['meta'], 'post' );
			}
			// The inline comments the card draws under the action strip
			// (designs 19, 26). Two jobs FluentCommunity does not do for
			// us: a PINNED comment has to come first, and each row needs
			// the same relative timestamp the post above it uses.
			if ( ! empty( $item['comments'] ) && is_array( $item['comments'] ) ) {
				$items[ $i ]['comments'] = $this->normalize_comments(
					$items[ $i ]['comments'],
					isset( $item['permalink'] ) && is_string( $item['permalink'] ) ? $item['permalink'] : ''
				);
			}
			// The stacked reaction avatars beside the counts (design 19).
			// Derived here rather than bound straight to `reactions[]`
			// because the card wants FACES, capped, and the raw array is
			// every reaction row with a whole xprofile hanging off it.
			if ( ! empty( $item['reactions'] ) && is_array( $item['reactions'] ) ) {
				$items[ $i ]['reaction_avatars'] = $this->build_reaction_avatars( $item['reactions'] );
			}
			// EMPTY, not "0 likes", when nothing has happened yet.
			//
			// Noor's designs 1-6 draw the action strip with no counts at
			// all on a post with none, and only design 18 onward shows
			// them. An empty string is what makes that expressible in the
			// catalog: `visible_when exists` treats '' as absent, so the
			// child disappears without needing a numeric comparison the
			// clause grammar does not have.
			if ( isset( $item['reactions_count'] ) && is_numeric( $item['reactions_count'] ) ) {
				$items[ $i ]['reactions_summary'] = (int) $item['reactions_count'] > 0
					? $this->pluralize( (int) $item['reactions_count'], 'like', 'likes' )
					: '';
			}
			if ( isset( $item['comments_count'] ) && is_numeric( $item['comments_count'] ) ) {
				$items[ $i ]['comments_summary'] = (int) $item['comments_count'] > 0
					? $this->pluralize( (int) $item['comments_count'], 'comment', 'comments' )
					: '';
			}
			// Multi-image posts arrive on `meta.media_items` (array).
			// Single-image posts arrive on `meta.media_preview` with
			// type=meta_data. Video/poll variants have neither and skip.
			// The wp_fcom_media_archive DB table is intentionally NOT queried
			// here — it holds abandoned uploads too, and would over-count.
			$images = $this->build_images_from_meta( isset( $item['meta'] ) ? $item['meta'] : array() );
			if ( ! empty( $images ) ) {
				$items[ $i ]['images'] = $images;
			}
			// Document posts ("Publish as a post" on) reach the feed with
			// their files only in `meta.document_lists` — a creation-time
			// snapshot whose `url` is FC's portal download route, which
			// needs a browser session and returns HTML, not a file. So the
			// feed card had nothing renderable to show.
			//
			// Re-derive from the live Media rows, exactly as the DOCUMENTS
			// tab does, and hand the card the same `files[]` shape it
			// already knows: {id, media_key, title, type, kind, url}.
			if ( isset( $item['content_type'] ) && 'document' === $item['content_type'] ) {
				$files = $this->documents_controller()->files_for_feed( (int) $item['id'] );
				if ( ! empty( $files ) ) {
					$items[ $i ]['files'] = $files;
				}
			}
			// Banner CTA buttons: derive Ionic fill/color from the API's
			// `type` string so the renderer can bind them directly without
			// hardcoding per-slot styling. FluentCommunity offers exactly
			// four types — primary → filled primary; secondary → outlined
			// primary; text → filled light (the soft grey one); link →
			// clear. `allowClose` is NOT touched here: it rides through
			// untransformed, so a course banner could gate its close × on
			// it later. The feed banner's own customizer never sets it.
			if ( isset( $item['ctaButtons'] ) && is_array( $item['ctaButtons'] ) ) {
				$items[ $i ]['ctaButtons'] = $this->normalize_cta_buttons( $item['ctaButtons'] );
			}
			// Space cover fallback. `/spaces/all-spaces` returns
			// cover_photo=null when the space wasn't given a header image;
			// `/spaces/discover` fills with FC's placeholder. Detect space
			// items by the shape (privacy + slug present) and substitute the
			// FC placeholder so fc-space-card renders a visual cover instead
			// of the "Image" empty-state block.
			// `id` joins the test because a FEED LINK also carries privacy
			// and slug, and was being handed a space's cover placeholder it
			// has no use for. A space always has an id; a link never does.
			$has_space_shape = isset( $item['privacy'] ) && isset( $item['slug'] ) && isset( $item['id'] );
			$cover_missing = ! isset( $item['cover_photo'] ) || null === $item['cover_photo'] || '' === $item['cover_photo'];
			if ( $has_space_shape && $cover_missing ) {
				$fc_placeholder_file = WP_PLUGIN_DIR . '/fluent-community/assets/images/space-placeholder.jpg';
				if ( file_exists( $fc_placeholder_file ) ) {
					$items[ $i ]['cover_photo'] = plugins_url( 'fluent-community/assets/images/space-placeholder.jpg' );
				}
			}
		}
		// Rewrite media URLs to point at the current request host.
		// FluentCommunity stores absolute URLs at write time using WP's
		// `siteurl` option (e.g. http://www.tutor-lms-mobile.local/...).
		// On a mobile app hitting the site via a public tunnel or LAN IP,
		// those stored URLs are unreachable from the client. Walk the
		// response, string-replace stored siteurl with the incoming host
		// + scheme so images resolve everywhere.
		$items = $this->rewrite_urls_for_current_host( $items );
		return $items;
	}

	protected function rewrite_urls_for_current_host( $data ) {
		$stored = rtrim( (string) get_option( 'siteurl', '' ), '/' );
		if ( '' === $stored ) {
			return $data;
		}
		// Also strip a common http/https prefix pair so a stored siteurl of
		// http://foo works for an incoming https://foo request (WP HTTPS
		// admin behind a tunnel is common) and vice versa.
		$stored_host = preg_replace( '#^https?://#i', '', $stored );

		$scheme = ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			: ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) ? 'https' : 'http' );
		$host = ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] )
			? (string) $_SERVER['HTTP_X_FORWARDED_HOST']
			: ( ! empty( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : $stored_host );
		$target = $scheme . '://' . rtrim( $host, '/' );

		// Nothing to do if incoming request already came in on the stored URL.
		if ( $target === $stored ) {
			return $data;
		}
		$from_variants = array(
			'http://' . $stored_host,
			'https://' . $stored_host,
		);
		return $this->walk_and_replace_urls( $data, $from_variants, $target );
	}

	protected function walk_and_replace_urls( $node, array $from_variants, $to ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = $this->walk_and_replace_urls( $v, $from_variants, $to );
			}
			return $node;
		}
		if ( is_string( $node ) ) {
			foreach ( $from_variants as $from ) {
				if ( strpos( $node, $from ) !== false ) {
					$node = str_replace( $from, $to, $node );
				}
			}
			return $node;
		}
		return $node;
	}

	protected function normalize_cta_buttons( $buttons ) {
		if ( ! is_array( $buttons ) ) {
			return $buttons;
		}
		$out = array();
		foreach ( $buttons as $btn ) {
			if ( ! is_array( $btn ) ) {
				continue;
			}
			$type = isset( $btn['type'] ) ? (string) $btn['type'] : 'primary';
			switch ( $type ) {
				case 'secondary':
					$btn['fill']  = 'outline';
					$btn['color'] = 'primary';
					break;
				case 'text':
					// The soft grey button — design 15's DOWNLOAD FREE. This is
					// the fourth type FluentCommunity actually offers (admin_app.js
					// Button Type dropdown: Primary / Secondary / Text / Link);
					// `muted` and `ghost` used to sit here and are written by
					// nothing, so a real `text` button was falling through to the
					// primary default and drawing filled blue.
					//
					// Ionic's `light` is a pale ground with a DARK contrast text
					// colour, which is the pair FC's `el-button is-text` draws.
					// `medium` would give white-on-grey.
					$btn['fill']  = 'solid';
					$btn['color'] = 'light';
					break;
				case 'link':
					$btn['fill']  = 'clear';
					$btn['color'] = 'primary';
					break;
				case 'primary':
				default:
					$btn['fill']  = 'solid';
					$btn['color'] = 'primary';
					break;
			}
			$out[] = $btn;
		}
		return $out;
	}

	/**
	 * The sentence the flagged banner draws (designs 28 and 34).
	 *
	 * FluentCommunity's own `ReportReview` component picks between three,
	 * and this reproduces its branches exactly rather than approximating
	 * them, so the app and the portal accuse a post of the same thing:
	 *
	 *   auto_flagged == 'yes'   "This <noun> has been flagged
	 *                            automatically by the site rule."
	 *   reports_count > 1       "This <noun> has been flagged with N reports"
	 *   otherwise               "…with N report"
	 *
	 * Returns the meta unchanged when the content is not flagged at all —
	 * an absent key is what the banner's `exists` gate reads, so writing
	 * an empty string here would draw an empty cream box on every card.
	 *
	 * @param array  $meta
	 * @param string $noun 'post' | 'comment'
	 * @return array
	 */
	/**
	 * One image source for a feed link's chip.
	 *
	 * FluentCommunity's link shape (CustomSanitizer::santizeLinkItem)
	 * offers `emoji`, `icon_image` and `shape_svg`, and a chip can bind
	 * one image. `icon_image` wins when both are set — it is the more
	 * deliberate choice, since an admin who uploads a file has replaced
	 * the shape rather than added to it. Raw SVG becomes a data URI so
	 * the same <img> draws it without the renderer learning to inject
	 * markup, which is also the safer half: an <img> cannot execute
	 * script the way an inlined <svg> can.
	 *
	 * The emoji is deliberately NOT folded in here. It is text, it
	 * inherits the chip's font, and rasterising it would make it worse.
	 *
	 * @param array $link
	 * @return string '' when the link has no image icon at all.
	 */
	protected function resolve_link_icon( array $link ) {
		$image = isset( $link['icon_image'] ) ? trim( (string) $link['icon_image'] ) : '';
		if ( '' !== $image ) {
			return $image;
		}

		$svg = isset( $link['shape_svg'] ) ? trim( (string) $link['shape_svg'] ) : '';
		if ( '' === $svg || false === stripos( $svg, '<svg' ) ) {
			return '';
		}

		return 'data:image/svg+xml;utf8,' . rawurlencode( $svg );
	}

	protected function add_moderation_banner_text( array $meta, $noun ) {
		$auto  = isset( $meta['auto_flagged'] ) ? (string) $meta['auto_flagged'] : '';
		$count = isset( $meta['reports_count'] ) ? (int) $meta['reports_count'] : 0;

		if ( 'yes' !== $auto && $count < 1 ) {
			return $meta;
		}

		if ( 'yes' === $auto ) {
			$meta['moderation_banner_text'] = sprintf(
				/* translators: %s: post or comment */
				__( 'This %s has been flagged automatically by the site rule', 'appza-core' ),
				$noun
			);
			return $meta;
		}

		$meta['moderation_banner_text'] = sprintf(
			/* translators: 1: post or comment, 2: number of reports */
			_n(
				'This %1$s has been flagged with %2$d report',
				'This %1$s has been flagged with %2$d reports',
				$count,
				'appza-core'
			),
			$noun,
			$count
		);

		return $meta;
	}

	protected function build_images_from_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return array();
		}
		// Preferred: `media_items` array (fcom multi-image posts).
		if ( isset( $meta['media_items'] ) && is_array( $meta['media_items'] ) ) {
			$out = array();
			foreach ( $meta['media_items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				// media_items includes only images per fcom's contract; guard
				// anyway in case future kinds land on the same field.
				$type = isset( $item['type'] ) ? (string) $item['type'] : '';
				if ( '' !== $type && 'image' !== $type ) {
					continue;
				}
				if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
					continue;
				}
				$out[] = array(
					'url'    => (string) $item['url'],
					'width'  => isset( $item['width'] ) ? (int) $item['width'] : null,
					'height' => isset( $item['height'] ) ? (int) $item['height'] : null,
				);
			}
			return $out;
		}
		// Fallback: single-image via media_preview (only for uploaded images,
		// not oembed video thumbs).
		if ( isset( $meta['media_preview'] ) && is_array( $meta['media_preview'] ) ) {
			$mp = $meta['media_preview'];
			if ( ( isset( $mp['type'] ) && 'meta_data' === $mp['type'] )
				&& ! empty( $mp['image'] ) && is_string( $mp['image'] ) ) {
				return array( array(
					'url'    => (string) $mp['image'],
					'width'  => isset( $mp['width'] ) ? (int) $mp['width'] : null,
					'height' => isset( $mp['height'] ) ? (int) $mp['height'] : null,
				) );
			}
		}
		return array();
	}

	protected function pluralize( $n, $singular, $plural ) {
		return $n . ' ' . ( 1 === $n ? $singular : $plural );
	}

	/**
	 * A survey post's options, ready for the card.
	 *
	 * Three jobs, all of them things FluentCommunity leaves to whoever
	 * draws the poll:
	 *
	 *   voted        whether THIS viewer picked the option. The votes are
	 *                Reaction rows and never reach the feed payload;
	 *                without this, designs 8 and 9 are the same picture.
	 *   vote_counts  absent, not 0, on an option nobody picked.
	 *   percentage   likewise. Recomputed here rather than trusted,
	 *                because FC writes it at vote time and an option
	 *                added later carries a stale share.
	 *
	 * `total_votes` is the "N Vote" line under the options. It counts
	 * VOTES, not voters — a multi-choice poll lets one person cast
	 * several, and design 11 shows 3 for two voters' picks.
	 *
	 * @param array $meta
	 * @param array<int, string> $myVotes Option slugs this viewer picked.
	 * @return array
	 */
	protected function normalize_survey( array $meta, array $myVotes ) {
		if ( empty( $meta['survey_config']['options'] ) || ! is_array( $meta['survey_config']['options'] ) ) {
			return $meta;
		}

		$options = $meta['survey_config']['options'];
		$total   = 0;
		foreach ( $options as $option ) {
			if ( is_array( $option ) ) {
				$total += isset( $option['vote_counts'] ) ? (int) $option['vote_counts'] : 0;
			}
		}

		foreach ( $options as $i => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$count = isset( $option['vote_counts'] ) ? (int) $option['vote_counts'] : 0;
			$slug  = isset( $option['slug'] ) ? (string) $option['slug'] : '';

			$options[ $i ]['vote_counts'] = $count;
			$options[ $i ]['percentage']  = $total > 0 ? (int) round( ( $count / $total ) * 100 ) : 0;
			$options[ $i ]['voted']       = '' !== $slug && in_array( $slug, $myVotes, true );
		}

		$meta['survey_config']['options']     = $options;
		$meta['survey_config']['total_votes'] = $total;
		// Whether the viewer has voted AT ALL. The row control is filled
		// only for the options they picked, but the poll as a whole
		// changes state on the first vote — which is what tells design 7
		// (nobody has voted) from design 9 (someone has, not me).
		$meta['survey_config']['has_voted'] = ! empty( $myVotes );

		return $meta;
	}

	/**
	 * Inline comments, ready for the card: pinned first, relative times.
	 *
	 * FluentCommunity returns them in creation order with a raw
	 * `created_at` and an `is_sticky` flag nobody has acted on. Design 26
	 * puts the pinned one at the top of the strip, and its own controller
	 * un-sticks every other comment on the post when one is pinned — so
	 * "pinned first" can only ever move ONE row, and a stable sort keeps
	 * the rest in the order FC chose.
	 *
	 * @param array  $comments
	 * @param string $postPermalink The parent post's URL, for comment links.
	 * @return array
	 */
	protected function normalize_comments( array $comments, $postPermalink = '' ) {
		foreach ( $comments as $i => $comment ) {
			if ( ! is_array( $comment ) ) {
				continue;
			}
			if ( isset( $comment['created_at'] ) && is_string( $comment['created_at'] ) && '' !== $comment['created_at'] ) {
				// Same reason as normalize_items(): stamp the epoch before
				// the string stops being one. Guarded so a second pass over
				// an already-relative value cannot overwrite a good stamp
				// with 0 — thread_comments runs this over the parents after
				// the generic pass has already run over them once.
				if ( empty( $comments[ $i ]['created_at_ts'] ) ) {
					$comments[ $i ]['created_at_ts'] = (int) strtotime( $comment['created_at'] );
				}
				$comments[ $i ]['created_at'] = $this->relative_time( $comment['created_at'] );
			}
			if ( isset( $comment['message_rendered'] ) && is_string( $comment['message_rendered'] ) ) {
				$comments[ $i ]['message_rendered'] = $this->sanitize_rich_text( $comment['message_rendered'] );
			}
			// Design 34's banner, with "comment" as the noun. Same guard as
			// the post's above: an unflagged comment's `meta` is null and
			// stays null.
			if ( isset( $comment['meta'] ) && is_array( $comment['meta'] ) ) {
				$comments[ $i ]['meta'] = $this->add_moderation_banner_text( $comment['meta'], 'comment' );
			}
			// "Copy link" on a comment (design 24). FluentCommunity gives
			// a comment no permalink of its own — the portal links to the
			// post and anchors to the comment, so we build the same thing
			// rather than inventing a route that would 404.
			if ( '' !== $postPermalink && isset( $comment['id'] ) ) {
				$comments[ $i ]['permalink'] = $postPermalink . '#comment-' . (int) $comment['id'];
			}
		}

		// usort is NOT stable before PHP 8.0; this box runs 8+, where it
		// is. Comparing only the sticky flag therefore leaves equal rows
		// in FluentCommunity's own order rather than reversing them.
		usort(
			$comments,
			static function ( $a, $b ) {
				$sa = ( is_array( $a ) && ! empty( $a['is_sticky'] ) ) ? 1 : 0;
				$sb = ( is_array( $b ) && ! empty( $b['is_sticky'] ) ) ? 1 : 0;
				return $sb <=> $sa;
			}
		);

		return $comments;
	}

	/**
	 * The standalone comment thread: flat rows in, two-level tree out.
	 *
	 * @param array $rows
	 * @return array
	 */
	protected function thread_comments( array $rows ) {
		$byId    = array();
		$permalink = '';

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$byId[ (int) $row['id'] ] = $row;
			}
		}

		// One permalink for the whole thread — every row belongs to the
		// same post, so resolving it per comment would be the same lookup
		// repeated for an answer that cannot differ.
		$firstPostId = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['post_id'] ) ) {
				$firstPostId = (int) $row['post_id'];
				break;
			}
		}
		if ( $firstPostId > 0 && class_exists( '\\FluentCommunity\\App\\Models\\Feed' ) ) {
			$feed = \FluentCommunity\App\Models\Feed::withoutGlobalScopes()->find( $firstPostId );
			if ( $feed && ! empty( $feed->permalink ) ) {
				$permalink = (string) $feed->permalink;
			}
		}

		// Resolve each row's TOP-LEVEL ancestor, and the display name of
		// its immediate parent. The walk is bounded by the row count so a
		// cyclic parent_id — which no writer should produce and a restored
		// dump might — cannot spin here.
		$rootOf   = array();
		$resolve  = function ( $id ) use ( &$byId, &$rootOf ) {
			$seen = array();
			$cur  = (int) $id;
			while ( isset( $byId[ $cur ] ) && ! empty( $byId[ $cur ]['parent_id'] ) ) {
				if ( isset( $seen[ $cur ] ) ) {
					break;
				}
				$seen[ $cur ] = true;
				$cur = (int) $byId[ $cur ]['parent_id'];
			}
			return $cur;
		};

		$parents = array();
		$replies = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}
			$id = (int) $row['id'];

			// The epoch normalize_items() stamped before it rewrote
			// created_at into "24m". Falling back to strtotime covers the
			// row that never went through that pass; it cannot be the only
			// source, because by the time this runs created_at is a phrase.
			$row['sort_time'] = isset( $row['created_at_ts'] )
				? (int) $row['created_at_ts']
				: ( is_string( $row['created_at'] ?? null ) ? (int) strtotime( $row['created_at'] ) : 0 );
			$row['sort_likes'] = isset( $row['reactions_count'] ) ? (int) $row['reactions_count'] : 0;

			if ( empty( $row['parent_id'] ) ) {
				$parents[ $id ] = $row;
				continue;
			}

			$parentId = (int) $row['parent_id'];
			if ( isset( $byId[ $parentId ]['xprofile']['display_name'] ) ) {
				$row['reply_to_name'] = (string) $byId[ $parentId ]['xprofile']['display_name'];
			}
			$rootId = $resolve( $id );
			$rootOf[ $id ] = $rootId;
			$replies[ $rootId ][] = $row;
		}

		// A reply whose ancestor is not in this response (a moderated-away
		// parent, say) would otherwise vanish with it. Promote it to the
		// top rather than dropping a comment somebody wrote.
		foreach ( $replies as $rootId => $kids ) {
			if ( isset( $parents[ $rootId ] ) ) {
				continue;
			}
			foreach ( $kids as $kid ) {
				$parents[ (int) $kid['id'] ] = $kid;
			}
			unset( $replies[ $rootId ] );
		}

		$out = array();
		foreach ( $parents as $id => $parent ) {
			$kids = isset( $replies[ $id ] ) ? $replies[ $id ] : array();
			usort(
				$kids,
				static function ( $a, $b ) {
					return ( $a['sort_time'] ?? 0 ) <=> ( $b['sort_time'] ?? 0 );
				}
			);
			$kids = $this->normalize_comments( $kids, $permalink );

			$parent['children']    = $kids;
			$parent['reply_count'] = count( $kids );
			if ( $parent['reply_count'] > 0 ) {
				/* translators: %d: how many replies a comment has. Singular "Reply" is deliberate — the designs write it that way at every count. */
				$parent['reply_count_label'] = sprintf( __( '%d Reply', 'appza-core' ), $parent['reply_count'] );
			}
			// The order the sheet's "Most replied" row sorts on. Present on
			// every parent, including the ones with none, so the comparison
			// never has to treat a missing key as a number.
			$parent['sort_replies'] = $parent['reply_count'];
			$out[] = $parent;
		}

		// Pinned first + relative times + sanitized bodies + comment
		// permalinks, for the top level. The replies went through the same
		// pass above; running it once over a nested array would have
		// re-sorted the children into the parents.
		return $this->normalize_comments( $out, $permalink );
	}

	/**
	 * The stacked faces beside "2 likes · 3 comments" (design 19).
	 *
	 * Capped at four: the design draws two and the stack stops reading as
	 * a stack much beyond that. De-duplicated by user, because one person
	 * who both liked and bookmarked a post is one face, not two.
	 *
	 * @param array $reactions
	 * @return array<int, array{user_id:int, avatar:string, display_name:string}>
	 */
	protected function build_reaction_avatars( array $reactions ) {
		$out = [];
		foreach ( $reactions as $reaction ) {
			if ( ! is_array( $reaction ) ) {
				continue;
			}
			// Bookmarks live in the same table as likes. A private
			// bookmark must not put someone's face on a public post.
			if ( isset( $reaction['type'] ) && 'like' !== $reaction['type'] ) {
				continue;
			}
			$profile = isset( $reaction['xprofile'] ) && is_array( $reaction['xprofile'] ) ? $reaction['xprofile'] : [];
			$uid     = isset( $profile['user_id'] ) ? (int) $profile['user_id'] : 0;
			$avatar  = isset( $profile['avatar'] ) && is_string( $profile['avatar'] ) ? $profile['avatar'] : '';
			if ( $uid <= 0 || '' === $avatar || isset( $out[ $uid ] ) ) {
				continue;
			}
			$out[ $uid ] = [
				'user_id'      => $uid,
				'avatar'       => $avatar,
				'display_name' => isset( $profile['display_name'] ) && is_string( $profile['display_name'] )
					? $profile['display_name']
					: '',
			];
			if ( count( $out ) >= 4 ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * "4h · Appza Community", or "4h" on a post with no space.
	 *
	 * The separator is U+00B7 MIDDLE DOT with hair spaces either side —
	 * the same glyph FluentCommunity's own portal byline uses, so the app
	 * and the portal read identically.
	 *
	 * @param string $when  Already-relative time ("4h", "1d").
	 * @param mixed  $space The row's `space` object, or null.
	 */
	protected function build_byline( $when, $space ) {
		$when = is_string( $when ) ? trim( $when ) : '';

		$title = '';
		if ( is_array( $space ) && isset( $space['title'] ) && is_string( $space['title'] ) ) {
			$title = trim( $space['title'] );
		}

		if ( '' === $title ) {
			return $when;
		}
		if ( '' === $when ) {
			return $title;
		}

		return $when . ' · ' . $title;
	}

	/**
	 * Sanitize inline-formatted body text via wp_kses with a small explicit
	 * safelist. Preserves author formatting (bold / italic / links / paragraph
	 * breaks) while blocking scripts, iframes, and all attribute-based XSS
	 * vectors. Consumed by the `appza-rich-text` primitive on the renderer
	 * side via dangerouslySetInnerHTML — because we sanitize here, the
	 * innerHTML sink is safe.
	 */
	protected function sanitize_rich_text( $html ) {
		$allowed = array(
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'a'          => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'title'  => true,
			),
			'br'         => array(),
			'p'          => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
		);
		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * What the CURRENT user is to this community — moderator, admin, or
	 * neither.
	 *
	 * Two flags rather than one because FluentCommunity draws the line in
	 * two different places: blocking is refused to a MODERATOR
	 * specifically, while managing members is open to either.
	 *
	 * FluentCommunity's own check, called defensively: this proxy runs
	 * inside a site where the class may be absent (FC deactivated) or may
	 * move, and a fatal here would take out every profile read rather than
	 * one menu row.
	 */
	protected function viewer_roles( $viewer_id = 0 ) {
		$none = array( 'is_moderator' => false, 'is_admin' => false );
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return $none;
		}
		if ( ! method_exists( '\\FluentCommunity\\App\\Services\\Helper', 'isModerator' ) ) {
			return $none;
		}

		// The viewer is set only DURING the upstream sub-request —
		// fetch_upstream restores the previous user before returning — so by
		// the time this normalisation runs, get_current_user_id() is 0 again
		// and every FC helper that reads the current user answers for a
		// guest. Both of these do. Set the viewer for the length of the
		// check and put it back, exactly as the sub-request does.
		$prev_user_id = get_current_user_id();
		$switched     = ( $viewer_id > 0 && $viewer_id !== $prev_user_id );
		if ( $switched ) {
			wp_set_current_user( $viewer_id );
		}

		$roles = array( 'is_moderator' => false, 'is_admin' => false );

		// isModerator() alone is NOT enough, and the portal proves it: raju
		// is a WordPress admin who sees "Add To Space/Course" there, and
		// Helper::isModerator() answers false for him — a community
		// MODERATOR is a narrower thing than a site admin. Either may manage
		// members, so the flag is the union; the endpoint remains the real
		// authority and refuses anyone this lets through by mistake.
		if ( method_exists( '\\FluentCommunity\\App\\Services\\Helper', 'isSiteAdmin' ) ) {
			$roles['is_admin'] = (bool) \FluentCommunity\App\Services\Helper::isSiteAdmin( $viewer_id ?: null );
		}
		$roles['is_moderator'] = (bool) \FluentCommunity\App\Services\Helper::isModerator();

		if ( $switched ) {
			wp_set_current_user( $prev_user_id );
		}

		return $roles;
	}

	/** Two-letter initials, for an avatar with no photo. */
	protected function initials_for( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		if ( ! $parts || '' === $parts[0] ) {
			return '';
		}
		$first = mb_substr( $parts[0], 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) : '';

		return mb_strtoupper( $first . $last );
	}

	protected function relative_time( $datetime_str ) {
		try {
			$then = new \DateTime( $datetime_str, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $datetime_str;
		}
		$diff = time() - $then->getTimestamp();
		if ( $diff < 0 ) {
			return $datetime_str;
		}
		if ( $diff < 60 )       return $diff . 's';
		if ( $diff < 3600 )     return (int) floor( $diff / 60 ) . 'm';
		if ( $diff < 86400 )    return (int) floor( $diff / 3600 ) . 'h';
		if ( $diff < 2592000 )  return (int) floor( $diff / 86400 ) . 'd';
		if ( $diff < 31536000 ) return (int) floor( $diff / 2592000 ) . 'mo';
		return (int) floor( $diff / 31536000 ) . 'y';
	}

	protected function fixture_for( $ds_slug ) {
		switch ( $ds_slug ) {
			case 'fc-feed':
				return $this->fixture_fc_feed();
			case 'fc-welcome-banner':
				return $this->fixture_fc_welcome_banner();
			case 'fc-current-user':
				return $this->fixture_fc_current_user();
			case 'fc-spaces-all':
			case 'fc-spaces-discover':
				return $this->fixture_fc_spaces_all();
			case 'fc-spaces-my':
				return $this->fixture_fc_spaces_my();
			case 'fc-space-detail':
				return $this->fixture_fc_space_detail();
			case 'fc-space-feeds':
				return $this->fixture_fc_space_feeds();
			case 'fc-space-members':
				return $this->fixture_fc_space_members();
			case 'fc-space-documents':
				return $this->fixture_fc_space_documents();
			case 'fc-bookmarks':
				return $this->fixture_fc_bookmarks();
			default:
				return array();
		}
	}

	/**
	 * Demo saved posts, so the Saved Posts screen is reviewable in the
	 * simulator's demo mode instead of showing its empty state.
	 *
	 * The feed fixture, marked bookmarked — the rows are shape-identical
	 * (the real route is FluentCommunity's own feed query filtered to the
	 * viewer's bookmarks) and inventing a second body of demo content
	 * would only give the two screens different-looking cards for no
	 * reason.
	 *
	 * `bookmarked` is what the card's glyph binds to, and on this screen
	 * every row is bookmarked by definition — a hollow glyph here would
	 * be demo data contradicting the page it is demoing.
	 */
	protected function fixture_fc_bookmarks() {
		$rows = $this->fixture_fc_feed();
		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) ) {
				$rows[ $i ]['bookmarked'] = true;
			}
		}

		return $rows;
	}

	/**
	 * Demo current-user profile for the composer teaser avatar. Real
	 * endpoint (/wp-json/fcom-mobile/api/v1/user/profile-avatar) needs
	 * an auth session — this fixture lets the plug-in-admin simulator
	 * still render an avatar without login.
	 */
	protected function fixture_fc_current_user() {
		return array(
			array(
				'avatar'       => 'https://i.pravatar.cc/80?img=12',
				'display_name' => 'Syed',
				'first_name'   => 'Syed',
			),
		);
	}

	/**
	 * Demo banner content matching the image-1 design: hero image + title +
	 * short description + 4 CTAs in the 2-per-row layout. Used when the real
	 * fcom-mobile welcome-banner endpoint is unavailable OR the caller
	 * explicitly requests mode=demo.
	 */
	protected function fixture_fc_welcome_banner() {
		return array(
			array(
				'enabled'              => 'yes',
				'title'                => 'Build Mobile Apps With Zero Coding',
				// Long enough to overflow the collapsed clamp, so the demo
				// exercises View More / View Less rather than only the
				// >2-buttons half of the verdict.
				'description_rendered' => '<p>Bring your website to the mobile world without a single line of code, be it blog, e-commerce, LMS or anything else! It uses custom components to make your app and also provides access to component properties to have better control, allowing you to make the app exactly how you want it to be.</p>',
				'mediaType'            => 'image',
				'bannerImage'          => 'https://picsum.photos/seed/appza-banner/800/450',
				'bannerVideo'          => null,
				'allowClose'           => 'yes',
				// One of each type, in the order FluentCommunity's Button
				// Type dropdown lists them — design 15 draws all four.
				'ctaButtons'           => array(
					array( 'label' => 'Try Demo',      'link' => 'https://appza.io/demo',     'type' => 'primary',   'newTab' => 'yes' ),
					array( 'label' => 'Buy Now',       'link' => 'https://appza.io/buy',      'type' => 'secondary', 'newTab' => 'yes' ),
					array( 'label' => 'Download Free', 'link' => 'https://appza.io/download', 'type' => 'text',      'newTab' => 'yes' ),
					array( 'label' => 'Pricing',       'link' => 'https://appza.io/pricing',  'type' => 'link',      'newTab' => 'yes' ),
				),
			),
		);
	}

	protected function fixture_fc_feed() {
		$now = time();
		return array(
			array(
				'id'               => 1,
				'title'            => 'The Benefits of Reading',
				'message_rendered' => 'Reading every day helps improve knowledge, vocabulary, and focus. It also reduces stress and encourages creativity. Even a few minutes of reading daily can have a positive impact on personal growth and learning.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 5 * 60 ),
				'content_type'     => 'text',
				'reactions_count'  => 45,
				'comments_count'   => 4,
				'xprofile'         => array(
					'display_name' => 'John Deo',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Say Hello',
					'slug'  => 'say-hello',
				),
			),
			array(
				'id'               => 2,
				'title'            => 'Tips for Better Sleep',
				'message_rendered' => 'Stick to a schedule, dim the lights an hour before bed, and keep your phone out of arms reach. Small habits compound fast.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 12,
				'comments_count'   => 2,
				'xprofile'         => array(
					'display_name' => 'Bill Gets',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Tips',
					'slug'  => 'tips',
				),
			),
			array(
				'id'               => 3,
				'title'            => 'Welcome to the Community',
				'message_rendered' => 'New here? Introduce yourself in the welcome thread — say where you are joining from and what you are working on.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 86400 ),
				'content_type'     => 'text',
				'reactions_count'  => 89,
				'comments_count'   => 12,
				'xprofile'         => array(
					'display_name' => 'Community Bot',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Start Here',
					'slug'  => 'start-here',
				),
			),
			array(
				'id'               => 4,
				'title'            => 'Weekend photo dump',
				'message_rendered' => 'Some shots from the trip — mostly early-morning light. Camera stayed dry.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 6 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 24,
				'comments_count'   => 3,
				'xprofile'         => array(
					'display_name' => 'Ada L.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Photography',
					'slug'  => 'photography',
				),
				'meta'             => array(
					'media_preview' => array(
						'type'     => 'meta_data',
						'provider' => 'uploader',
						'image'    => 'https://picsum.photos/seed/appza-demo-image/800/450',
					),
				),
			),
			array(
				'id'               => 5,
				'title'            => 'Livestream recap',
				'message_rendered' => 'Full replay of last night\'s Q&A — questions from about the 12-minute mark.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 26 * 3600 ),
				'content_type'     => 'text',
				'reactions_count'  => 61,
				'comments_count'   => 9,
				'xprofile'         => array(
					'display_name' => 'Grace H.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Streams',
					'slug'  => 'streams',
				),
				'meta'             => array(
					'media_preview' => array(
						'type'         => 'oembed',
						'provider'     => 'youtube',
						'image'        => 'https://picsum.photos/seed/appza-demo-video/800/450',
						'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
						'video_url'    => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
						'video_player' => 'youtube',
					),
				),
			),
			array(
				'id'               => 6,
				'title'            => 'Where should the next meetup be?',
				'message_rendered' => 'Pick one. Voting closes end of week.',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 30 * 60 ),
				'content_type'     => 'survey',
				'reactions_count'  => 8,
				'comments_count'   => 2,
				'xprofile'         => array(
					'display_name' => 'Yuki N.',
					'avatar'       => null,
				),
				'space'            => array(
					'title' => 'Events',
					'slug'  => 'events',
				),
				'meta'             => array(
					'survey_config' => array(
						'type'    => 'multi_choice',
						'options' => array(
							array( 'label' => 'Berlin',    'slug' => 'opt_1' ),
							array( 'label' => 'Lisbon',    'slug' => 'opt_2' ),
							array( 'label' => 'Bengaluru', 'slug' => 'opt_3' ),
							array( 'label' => 'Bogotá',    'slug' => 'opt_4' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Demo "all spaces" list mirroring the fcom-mobile /spaces/discover
	 * shape — cover_photo + title + description + members_count +
	 * privacy + optional space_pivot (present = viewer is a member; the
	 * renderer switches the CTA + member badge on this). Two entries so
	 * one card shows the joined state and the other the Join state.
	 */
	protected function fixture_fc_spaces_all() {
		return array(
			array(
				'id'                   => 1,
				'title'                => 'LazyTasks',
				'slug'                 => 'lazytasks',
				'cover_photo'          => 'https://picsum.photos/seed/lazytasks/800/450',
				'description'          => 'Task management for teams that ship.',
				'description_rendered' => '<p>Task management for teams that ship.</p>',
				'privacy'              => 'public',
				'members_count'        => 128,
				'space_pivot'          => array( 'status' => 'active', 'role' => 'member' ),
			),
			array(
				'id'                   => 2,
				'title'                => 'Appza',
				'slug'                 => 'appza',
				'cover_photo'          => 'https://picsum.photos/seed/appza/800/450',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress</p>',
				'privacy'              => 'public',
				'members_count'        => 50,
				'space_pivot'          => array( 'status' => 'active', 'role' => 'admin' ),
			),
			array(
				'id'                   => 3,
				'title'                => 'Woocommerce',
				'slug'                 => 'woocommerce',
				'cover_photo'          => 'https://picsum.photos/seed/woocommerce/800/450',
				'description'          => 'Sell anything with WordPress commerce.',
				'description_rendered' => '<p>Sell anything with WordPress commerce.</p>',
				'privacy'              => 'public',
				'members_count'        => 342,
			),
			array(
				'id'                   => 4,
				'title'                => 'TutorLMS',
				'slug'                 => 'tutorlms',
				'cover_photo'          => 'https://picsum.photos/seed/tutorlms/800/450',
				'description'          => 'Course creation + student community.',
				'description_rendered' => '<p>Course creation + student community.</p>',
				'privacy'              => 'private',
				'members_count'        => 187,
			),
		);
	}

	/**
	 * Demo Space Details header + settings for the "Appza Community" space
	 * (id=2, slug=appza). Includes topics so the "Choose topic" strip has
	 * content, permissions so composer + Members visibility gate correctly,
	 * and membership pivot so the joined-state UI paints.
	 */
	protected function fixture_fc_space_detail() {
		return array(
			array(
				'id'                   => 2,
				'slug'                 => 'appza',
				'title'                => 'Appza Community',
				'cover_photo'          => 'https://picsum.photos/seed/appza/1200/500',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress — discuss design, dev, business, research.</p>',
				'type'                 => 'community',
				'privacy'              => 'public',
				'status'               => 'published',
				'members_count'        => 50,
				'settings'             => array(
					'topic_required'        => 'yes',
					'layout_style'          => 'timeline',
					'disable_post_sort_by'  => 'no',
					'default_post_sort_by'  => 'latest',
					'shape_svg_id'          => null,
				),
				'permissions'          => array(
					'can_create_post'   => true,
					'can_view_members'  => true,
					'can_manage_space'  => false,
					'can_join'          => true,
					'can_leave'         => true,
				),
				'topics'               => array(
					array(
						'id'          => 11,
						'title'       => 'Design',
						'description' => 'UI, UX, brand',
						'slug'        => 'design',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 12,
						'title'       => 'Development',
						'description' => 'Engineering + tooling',
						'slug'        => 'development',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 13,
						'title'       => 'Business',
						'description' => 'Sales, marketing, ops',
						'slug'        => 'business',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
					array(
						'id'          => 14,
						'title'       => 'Research',
						'description' => 'User research + insights',
						'slug'        => 'research',
						'admin_only'  => 'no',
						'space_ids'   => array( 2 ),
					),
				),
				'header_links'         => array(
					array( 'title' => 'About',      'route' => 'about' ),
					array( 'title' => 'Guidelines', 'route' => 'guidelines' ),
				),
				'membership'           => array(
					'pivot' => array(
						'status' => 'active',
						'role'   => 'admin',
					),
				),
				'space_pivot'          => array( 'status' => 'active', 'role' => 'admin' ),
			),
		);
	}

	/**
	 * Demo feed for the Space Details FEEDS tab. First post carries a
	 * Development topic term + featured image + one comment so the design
	 * mock (topic pill + hero + inline first-comment preview) has real
	 * data. Later posts are minimal.
	 */
	protected function fixture_fc_space_feeds() {
		$now = time();
		return array(
			array(
				'id'               => 101,
				'slug'             => 'appza-shipping-space-details',
				'title'            => 'Shipping the new Space Details screen',
				'message_rendered' => '<p>We just landed the redesigned Space Details screen — topics strip, sticky tabs, and a cleaner post composer. Let us know what you think!</p>',
				'featured_image'   => 'https://picsum.photos/seed/appza-post-101/800/450',
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 45 * 60 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 12,
				'status'           => 'published',
				'is_sticky'        => 0,
				'comments_count'   => 1,
				'reactions_count'  => 24,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/101',
				'xprofile'         => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
					'is_verified'  => true,
					'badge'        => 'Admin',
					'permalink'    => 'https://appza-legacy.test/u/syed',
				),
				'comments'         => array(
					array(
						'id'                => 5001,
						// Skeleton renders this as text (not HTML), so ship the stripped
							// plain form. Real FC returns both `content` (plain) and
							// `content_rendered` (HTML); when the SS binding retargets
							// to `content` this can carry the HTML again.
							'content'           => 'Love the new topics strip — makes it way easier to jump around.',
							'content_rendered'  => 'Love the new topics strip — makes it way easier to jump around.',
						'xprofile'          => array(
							'user_id'      => 21,
							'display_name' => 'Jaime Bosco',
							'username'     => 'jaime',
							'avatar'       => 'https://i.pravatar.cc/80?img=15',
						),
						'created_at'        => gmdate( 'Y-m-d H:i:s', $now - 30 * 60 ),
						'reactions_count'   => 3,
						'replies_count'     => 0,
					),
				),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(
					array(
						'title' => 'Development',
						'slug'  => 'development',
						'pivot' => array( 'post_id' => 101, 'term_id' => 12 ),
					),
				),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
			array(
				'id'               => 102,
				'slug'             => 'weekly-standup-notes',
				'title'            => 'Weekly standup notes',
				'message_rendered' => '<p>Quick recap from this week: roadmap for the mobile builder, plus new topic filters. Full notes in the thread.</p>',
				'featured_image'   => null,
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 6 * 3600 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 21,
				'status'           => 'published',
				'is_sticky'        => 0,
				'comments_count'   => 0,
				'reactions_count'  => 8,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/102',
				'xprofile'         => array(
					'user_id'      => 21,
					'display_name' => 'Jaime Bosco',
					'username'     => 'jaime',
					'avatar'       => 'https://i.pravatar.cc/80?img=15',
					'is_verified'  => false,
					'badge'        => null,
					'permalink'    => 'https://appza-legacy.test/u/jaime',
				),
				'comments'         => array(),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(
					array(
						'title' => 'Business',
						'slug'  => 'business',
						'pivot' => array( 'post_id' => 102, 'term_id' => 13 ),
					),
				),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
			array(
				'id'               => 103,
				'slug'             => 'welcome-new-members',
				'title'            => 'Welcome new members',
				'message_rendered' => '<p>Big welcome to everyone who joined this week. Introduce yourselves in the thread!</p>',
				'featured_image'   => null,
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now - 2 * 86400 ),
				'privacy'          => 'public',
				'type'             => 'post',
				'content_type'     => 'text',
				'space_id'         => 2,
				'user_id'          => 12,
				'status'           => 'published',
				'is_sticky'        => 1,
				'comments_count'   => 0,
				'reactions_count'  => 42,
				'permalink'        => 'https://appza-legacy.test/space/appza/post/103',
				'xprofile'         => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
					'is_verified'  => true,
					'badge'        => 'Admin',
					'permalink'    => 'https://appza-legacy.test/u/syed',
				),
				'comments'         => array(),
				'space'            => array(
					'id'       => 2,
					'title'    => 'Appza Community',
					'slug'     => 'appza',
					'settings' => array( 'layout_style' => 'timeline' ),
				),
				'reactions'        => array(),
				'terms'            => array(),
				'permissions'      => array(
					'is_author'  => false,
					'can_edit'   => false,
					'can_delete' => false,
				),
			),
		);
	}

	/**
	 * Placeholder MEMBERS-tab data. This arc renders a placeholder screen
	 * for the tab; a minimal fixture is enough to prove the DS wiring.
	 */
	protected function fixture_fc_space_members() {
		return array(
			array(
				'user_id'           => 12,
				'display_name'      => 'Syed Khan',
				'username'          => 'syed',
				'avatar'            => 'https://i.pravatar.cc/80?img=12',
				'short_description' => 'Admin · Appza founder',
				'badge'             => 'Admin',
				'xprofile'          => array(
					'user_id'      => 12,
					'display_name' => 'Syed Khan',
					'username'     => 'syed',
					'avatar'       => 'https://i.pravatar.cc/80?img=12',
				),
			),
			array(
				'user_id'           => 21,
				'display_name'      => 'Jaime Bosco',
				'username'          => 'jaime',
				'avatar'            => 'https://i.pravatar.cc/80?img=15',
				'short_description' => 'Community moderator',
				'badge'             => 'Moderator',
				'xprofile'          => array(
					'user_id'      => 21,
					'display_name' => 'Jaime Bosco',
					'username'     => 'jaime',
					'avatar'       => 'https://i.pravatar.cc/80?img=15',
				),
			),
			array(
				'user_id'           => 34,
				'display_name'      => 'Ada Lovelace',
				'username'          => 'ada',
				'avatar'            => 'https://i.pravatar.cc/80?img=45',
				'short_description' => 'Engineer',
				'badge'             => null,
				'xprofile'          => array(
					'user_id'      => 34,
					'display_name' => 'Ada Lovelace',
					'username'     => 'ada',
					'avatar'       => 'https://i.pravatar.cc/80?img=45',
				),
			),
		);
	}

	/**
	 * Demo DOCUMENTS-tab data. Mirrors the live wrapper's shape exactly —
	 * one entry per document POST (not per file), display strings already
	 * composed, and a `files[]` array that fc-document-card expands via
	 * children_from_prop. Demo mode is what the builder sees before a site
	 * has real documents, so it has to render the same card as Real.
	 */
	protected function fixture_fc_space_documents() {
		$now = time();
		return array(
			array(
				'id'         => 901,
				'title'      => 'Uploaded 2 files',
				'meta_line'  => '8 days ago by Nusrat Jahan',
				'author'     => 'Nusrat Jahan',
				'file_count' => 2,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 8 * 86400 ),
				'files'      => array(
					array(
						'id'        => 9011,
						'media_key' => 'demo-9011',
						'title'     => 'Certificate -Nusrat-Jahan.pdf',
						'type'      => 'application/pdf',
						'url'       => '',
						'kind'      => 'document',
					),
					array(
						'id'        => 9012,
						'media_key' => 'demo-9012',
						'title'     => 'Screenshot_20260725-154546.Me.png',
						'type'      => 'image/png',
						'url'       => '',
						'kind'      => 'image',
					),
				),
			),
			array(
				'id'         => 902,
				'title'      => 'Uploaded 1 file',
				'meta_line'  => '22 days ago by Test User1',
				'author'     => 'Test User1',
				'file_count' => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 22 * 86400 ),
				'files'      => array(
					array(
						'id'        => 9021,
						'media_key' => 'demo-9021',
						'title'     => 'LazyTasks_Resolution_Guide_EN.docx',
						'type'      => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
						'url'       => '',
						'kind'      => 'document',
					),
				),
			),
		);
	}

	/**
	 * Demo "my spaces" list — subset of the discover list where the
	 * viewer is a member. Handle() stamps space_pivot on this ds_slug
	 * so all cards render the View Space CTA (children[10] visible_when
	 * depends on space_pivot exists).
	 */
	protected function fixture_fc_spaces_my() {
		return array(
			array(
				'id'                   => 2,
				'title'                => 'appza',
				'slug'                 => 'appza',
				'cover_photo'          => 'https://picsum.photos/seed/appza/800/450',
				'description'          => 'A Mobile App Builder Inside WordPress',
				'description_rendered' => '<p>A Mobile App Builder Inside WordPress</p>',
				'privacy'              => 'public',
				'members_count'        => 50,
			),
			array(
				'id'                   => 3,
				'title'                => 'lazycoders',
				'slug'                 => 'lazycoders',
				'cover_photo'          => 'https://picsum.photos/seed/lazycoders/800/450',
				'description'          => 'A dynamic startup pioneering native mobile app development.',
				'description_rendered' => '<p>A dynamic startup pioneering native mobile app development.</p>',
				'privacy'              => 'private',
				'members_count'        => 250,
			),
		);
	}
}
