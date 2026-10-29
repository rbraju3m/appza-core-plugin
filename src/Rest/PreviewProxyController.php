<?php
/**
 * GET /wp-json/appza/v1/preview/data?ds=<slug>&params=<json> — proxies a catalog DataSource to its WP REST
 * endpoint for both the simulator and the mobile app. Viewer = get_current_user_id() (core-validated auth).
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

		// Stale clients still sending X-Appza-Auth get a 400, not silent guest data.
		$viewer_id = get_current_user_id();
		$legacy    = $request->get_header( 'x_appza_auth' );
		if ( 0 === $viewer_id && is_string( $legacy ) && '' !== $legacy ) {
			return new \WP_Error(
				'appza_legacy_auth_header',
				__( 'X-Appza-Auth is no longer accepted; send the application password as the Authorization header.', 'appza-core' ),
				array( 'status' => 400 )
			);
		}
		$auth_header = $this->extract_auth_header( $request );
		$auth_bucket = self::bucket_for_user( $viewer_id );

		// The generation in the key lets bump_generation() invalidate all of a viewer's cached reads at once.
		$generation = self::generation_for( $auth_bucket );
		$cache_key  = 'appza_preview_' . $auth_bucket . '_' . $generation . '_'
			. md5( $template_slug . '|' . $ds_slug . '|' . self::canonical_params( $params_json ) . '|' . $mode );

		// The DataSource's own cache_ttl; 0 means never cache (e.g. fc-bookmarks).
		$cache_ttl = $this->resolve_cache_ttl( $template_slug, $ds_slug );
		$endpoint  = 'demo' === $mode ? null : $this->resolve_endpoint( $template_slug, $ds_slug );
		if ( null === $endpoint || strlen( $params_json ) > 1024 ) {
			$cache_ttl = 0;
		}

		// nocache=1 skips the cached read but still writes the fresh result.
		$nocache = '1' === (string) $request->get_param( 'nocache' );
		if ( ! $nocache && $cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return rest_ensure_response( $this->rewrite_urls_for_current_host( $cached ) );
			}
		}

		if ( 'demo' === $mode ) {
			$payload = $this->fixture_for( $ds_slug );
		} elseif ( 'real' === $mode ) {
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		} else {
			$payload  = $endpoint ? $this->fetch_upstream( $endpoint, $params_json, $auth_header ) : null;
			if ( ! is_array( $payload ) ) {
				$payload = $this->fixture_for( $ds_slug );
			}
		}

		$payload = $this->normalize_items( $payload, $viewer_id );

		// Upstream /spaces omits space_pivot; stamp it so the card's View Space gate holds on the My tab.
		if ( 'fc-spaces-my' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( is_array( $item ) && ! isset( $item['space_pivot'] ) ) {
					$payload[ $i ]['space_pivot'] = array(
						'status' => 'active',
					);
				}
			}
		}

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

				// FC Pro sets `follow` (0 blocked, 1 following, 2 +notify) only when a row exists, so absent means
				// "not following", not blocked. Absent on your own profile too.
				$profile_user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
				$follow_level    = ( array_key_exists( 'follow', $item ) && is_numeric( $item['follow'] ) )
					? (int) $item['follow']
					: null;

				$payload[ $i ]['is_self_profile'] = ( $viewer_id > 0 && $profile_user_id === $viewer_id );
				$payload[ $i ]['follow_level']    = $follow_level;
				$payload[ $i ]['is_following']    = ( null !== $follow_level && $follow_level >= 1 );
				$payload[ $i ]['is_notifying']    = ( 2 === $follow_level );
				// FC's follow() rejects blocked users.
				$payload[ $i ]['is_blocked_by_viewer'] = ( 0 === $follow_level );

				// FC's own per-viewer profile_nav_actions decides whether messaging is allowed.
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

				// FC's `is_moderator` describes the profile's subject, not the viewer.
				$viewer_roles = $this->viewer_roles( $viewer_id );
				$payload[ $i ]['viewer_is_moderator'] = $viewer_roles['is_moderator'] || $viewer_roles['is_admin'];

				// Menu visible_when cannot negate, so publish positive fields.
				$is_self = ( $viewer_id > 0 && $profile_user_id === $viewer_id );

				// Mirrors FC FollowController::block: not self, target not a moderator, viewer not Helper::isModerator()
				// (a site admin who is not a moderator may still block).
				$target_is_moderator = ( 'yes' === (string) ( $item['is_moderator'] ?? '' ) );
				$payload[ $i ]['can_block'] = (
					! $is_self
					&& 0 !== $follow_level
					&& ! $viewer_roles['is_moderator']
					&& ! $target_is_moderator
				);
				$payload[ $i ]['can_unblock'] = ( ! $is_self && 0 === $follow_level );

				// Counts may be withheld by privacy settings or a disabled module; default to 0.
				$followers  = isset( $item['followers_count'] ) ? (int) $item['followers_count'] : 0;
				$followings = isset( $item['followings_count'] ) ? (int) $item['followings_count'] : 0;
				$payload[ $i ]['followers_count']  = $followers;
				$payload[ $i ]['followings_count'] = $followings;

				/* translators: %d: number of people following this member */
				$payload[ $i ]['followers_count_label'] = sprintf( _n( '%d Follower', '%d Followers', $followers, 'appza-core' ), $followers );
				/* translators: %d: number of people this member follows */
				$payload[ $i ]['followings_count_label'] = sprintf( _n( '%d Following', '%d Followings', $followings, 'appza-core' ), $followings );

				// fcom-mobile has no email route; inverted because a field binds `readonly` and bindings cannot negate.
				$payload[ $i ]['email_readonly'] = empty( $item['can_change_email'] );

				// Mirrors FC patchProfile: can_deactive_account === 'yes' || isSiteAdmin(); own profile only.
				$can_deactivate = false;
				$utility        = '\\FluentCommunity\\App\\Functions\\Utility';
				$fc_helper      = '\\FluentCommunity\\App\\Services\\Helper';
				if ( class_exists( $utility ) ) {
					$can_deactivate = 'yes' === call_user_func( array( $utility, 'getPrivacySetting' ), 'can_deactive_account' );
				}
				if ( ! $can_deactivate && class_exists( $fc_helper ) && method_exists( $fc_helper, 'isSiteAdmin' ) ) {
					$can_deactivate = (bool) call_user_func( array( $fc_helper, 'isSiteAdmin' ), $viewer_id );
				}
				$payload[ $i ]['can_deactivate_account'] = $can_deactivate
					&& ! empty( $payload[ $i ]['is_self_profile'] );

				// FC's space picker is behind AdminPolicy (isSiteAdmin), so gate on that rather than moderator.
				$can_add_to_space = false;
				if ( class_exists( $fc_helper ) && method_exists( $fc_helper, 'isSiteAdmin' ) ) {
					$can_add_to_space = (bool) call_user_func( array( $fc_helper, 'isSiteAdmin' ), $viewer_id );
				}
				$payload[ $i ]['can_add_to_space'] = $can_add_to_space
					&& empty( $payload[ $i ]['is_self_profile'] );

				// profile_navs is already filtered per viewer by FC; don't hand-maintain the tab list.
				$payload[ $i ]['tabs'] = $this->profile_tabs( $item );

				// created_at is already relative ("8mo") via normalize_items; last_activity is not.
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

				// created_at_ts, because created_at has already been rewritten by normalize_items.
				$joined_ts = isset( $item['created_at_ts'] ) ? (int) $item['created_at_ts'] : 0;
				$payload[ $i ]['joined_label'] = $joined_ts > 0
					/* translators: %s: a spelled-out age, e.g. "9 months" */
					? sprintf( __( 'Joined %s ago', 'appza-core' ), $this->long_age( $joined_ts ) )
					: '';

				// FC publishes last_activity only when its privacy setting allows, so this label doubles as the gate.
				$last_ts = ! empty( $item['last_activity'] ) && is_string( $item['last_activity'] )
					? strtotime( $item['last_activity'] . ' UTC' )
					: false;
				$payload[ $i ]['last_seen_label'] = $last_ts
					/* translators: %s: a spelled-out age, e.g. "10 minutes" */
					? sprintf( __( 'Last seen %s ago', 'appza-core' ), $this->long_age( (int) $last_ts ) )
					: '';

				// FC Pro's per-field privacy rules have already been applied to custom_field_groups.
				$payload[ $i ]['custom_field_view'] = $this->custom_field_view(
					isset( $item['custom_field_groups'] ) && is_array( $item['custom_field_groups'] )
						? $item['custom_field_groups']
						: array()
				);

				$payload[ $i ]['social_link_view'] = $this->social_link_view(
					isset( $item['social_links'] ) ? (array) $item['social_links'] : array()
				);

				// social_links is an object keyed by provider; a strip expands an array.
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

				// Every provider the site offers, joined with the member's stored handles (bare handles, not URLs).
				$payload[ $i ]['social_link_fields'] = $this->social_link_fields( $raw_socials );
				$payload[ $i ]['has_social_links']   = ! empty( $payload[ $i ]['social_link_fields'] );

				// Badge definitions live in an option (XProfile's `badge` attribute is always null). has_badges exists
				// because visible_when cannot test for a non-empty array.
				$badges = $this->resolve_badges( $item );
				$payload[ $i ]['badges']     = $badges;
				$payload[ $i ]['has_badges'] = ! empty( $badges );

				$payload[ $i ]['username_at'] = ! empty( $item['username'] )
					? '@' . $item['username']
					: '';

				// A menu row's visible_when cannot read settings.*, so the site half of the gate is resolved here.
				$moderation_on = false;
				$helper        = '\\FluentCommunity\\App\\Services\\Helper';
				if ( class_exists( $helper ) && method_exists( $helper, 'isFeatureEnabled' ) ) {
					$moderation_on = (bool) call_user_func( array( $helper, 'isFeatureEnabled' ), 'content_moderation' );
				}
				// Read from $payload: viewer_is_moderator was derived above and is not on $item.
				$payload[ $i ]['can_moderate'] = $moderation_on
					&& ! empty( $payload[ $i ]['viewer_is_moderator'] );

				// FC Pro publishes no blocked count; it is this profile's follows at level 0.
				$blocked = $this->blocked_count( $profile_user_id );
				$payload[ $i ]['blocked_count'] = $blocked;
				/* translators: %d: how many members this member has blocked */
				$payload[ $i ]['blocked_count_label'] = sprintf( _n( '%d Blocked', '%d Blocked', $blocked, 'appza-core' ), $blocked );

				// FC Pro adds scheduled_posts_count only when > 0 and the viewer is the owner or a site admin.
				if ( isset( $item['scheduled_posts_count'] ) && (int) $item['scheduled_posts_count'] > 0 ) {
					$scheduled = (int) $item['scheduled_posts_count'];
					/* translators: %d: how many posts the member has scheduled */
					$payload[ $i ]['scheduled_posts_label'] = sprintf( _n( 'You have %d scheduled post', 'You have %d scheduled posts', $scheduled, 'appza-core' ), $scheduled );
				}

				// /leaderboard/me only answers for the viewer; this bar belongs to the profile owner.
				foreach ( $this->level_facts( $points ) as $key => $value ) {
					$payload[ $i ][ $key ] = $value;
				}
			}
		}

		// Spaces-tab ⋮: site admins only, never on your own profile.
		if ( 'fc-member-spaces' === $ds_slug && is_array( $payload ) ) {
			$params_decoded = json_decode( $params_json, true );
			$profile_member = is_array( $params_decoded ) ? (int) ( $params_decoded['member_id'] ?? 0 ) : 0;
			$is_site_admin  = false;
			if ( $viewer_id > 0 && method_exists( '\\FluentCommunity\\App\\Services\\Helper', 'isSiteAdmin' ) ) {
				$is_site_admin = (bool) \FluentCommunity\App\Services\Helper::isSiteAdmin( $viewer_id );
			}
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$payload[ $i ]['profile_member_id'] = $profile_member;
				$payload[ $i ]['can_remove_member'] = $is_site_admin
					&& $profile_member > 0
					&& $profile_member !== $viewer_id;
			}
		}

		// FC posts often have no title, so comment_context falls back to the body. The post's slug is promoted
		// because post-detail resolves from it and a comment has no slug of its own.
		if ( 'fc-member-comments' === $ds_slug && is_array( $payload ) ) {
			$comment_avatars = array();
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
				$payload[ $i ]['post_title'] = $title;

				$comment_user = (int) ( $item['user_id'] ?? 0 );
				$payload[ $i ]['can_delete'] = $viewer_id > 0 && $comment_user === $viewer_id;

				// Comment rows carry only the post author's xprofile; look up the commenter once per member.
				if ( $comment_user > 0 && ! array_key_exists( $comment_user, $comment_avatars ) ) {
					$comment_avatars[ $comment_user ] = '';
					if ( class_exists( '\\FluentCommunity\\App\\Models\\XProfile' ) ) {
						$xp = \FluentCommunity\App\Models\XProfile::where( 'user_id', $comment_user )->first();
						if ( $xp ) {
							$comment_avatars[ $comment_user ] = (string) $xp->avatar;
						}
					}
				}
				$payload[ $i ]['avatar'] = $comment_avatars[ $comment_user ] ?? '';

				if ( ! empty( $post['slug'] ) ) {
					$payload[ $i ]['slug'] = (string) $post['slug'];
				}
			}
		}

		// FC returns follow pivots with the person under `follower` or `followed` (blocked list included);
		// flatten so one card serves all three lists. FC's `follow` is a level (null/0 blocked/>0).
		if ( in_array( $ds_slug, array( 'fc-member-followers', 'fc-member-followings', 'fc-blocked-users' ), true ) && is_array( $payload ) ) {
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

		// scheduled_at is the site's wall-clock (wp_timezone()), so it is printed as is, not shifted.
		if ( 'fc-scheduled-posts' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$author = isset( $item['xprofile'] ) && is_array( $item['xprofile'] ) ? $item['xprofile'] : array();
				$payload[ $i ]['author_name']   = isset( $author['display_name'] ) ? (string) $author['display_name'] : '';
				$payload[ $i ]['author_avatar'] = isset( $author['avatar'] ) ? (string) $author['avatar'] : '';

				$space = isset( $item['space'] ) && is_array( $item['space'] ) ? $item['space'] : array();
				$payload[ $i ]['space_title'] = isset( $space['title'] ) ? (string) $space['title'] : '';

				$payload[ $i ]['title'] = isset( $item['title'] ) ? (string) $item['title'] : '';

				$payload[ $i ]['scheduled_for_label'] = '';
				if ( ! empty( $item['scheduled_at'] ) ) {
					$when = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $item['scheduled_at'], wp_timezone() );
					if ( $when ) {
						/* translators: %s: date and time, e.g. "Sep 26, 2026, 3:45 PM" */
						$payload[ $i ]['scheduled_for_label'] = sprintf( __( 'Scheduled for %s', 'appza-core' ), $when->format( 'M j, Y, g:i A' ) );
					}
				}

				$plain = isset( $item['message'] ) ? wp_strip_all_tags( (string) $item['message'] ) : '';
				$plain = trim( preg_replace( '/\s+/u', ' ', $plain ) );
				$payload[ $i ]['excerpt'] = mb_strlen( $plain ) > 140 ? rtrim( mb_substr( $plain, 0, 140 ) ) . '…' : $plain;
			}
		}

		// FC cannot create an empty DM thread (message is required), so synthesize a thread row from the intent
		// user's profile. id 0 tells the send handler to create the thread.
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

		// FC returns comments flat with parent_id; thread them to two levels, as its portal does. getComments is
		// hardcoded to created_at ASC, so sort keys are added for client-side ordering. Post detail writes
		// "N Reply" singular on purpose (designs); lesson comments pluralise.
		if ( 'fc-post-comments' === $ds_slug && is_array( $payload ) ) {
			$payload = $this->thread_comments( $payload );
		} elseif ( 'fc-lesson-comments' === $ds_slug && is_array( $payload ) ) {
			$payload = $this->thread_comments( $payload, true );
		}

		// Link icons arrive as emoji, image URL or inline SVG; resolve to one link_icon_url (SVG as a data URI).
		if ( 'fc-feed-links' === $ds_slug && is_array( $payload ) ) {
			foreach ( $payload as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$payload[ $i ]['link_icon_url'] = $this->resolve_link_icon( $item );
			}
		}

		// Cached host-neutral; the request's host is applied per response.
		if ( $cache_ttl > 0 ) {
			set_transient( $cache_key, $payload, $cache_ttl );
		}

		return rest_ensure_response( $this->rewrite_urls_for_current_host( $payload ) );
	}

	/** An explicit 0 means do not cache; missing, non-numeric or negative falls back to CACHE_TTL. */
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

	public static function auth_bucket_for( \WP_REST_Request $request ) {
		return self::bucket_for_user( get_current_user_id() );
	}

	public static function bucket_for_user( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 ? 'u' . $user_id : 'anon';
	}

	public static function generation_for( $auth_bucket ) {
		return (int) get_option( 'appza_preview_gen_' . $auth_bucket, 0 );
	}

	/**
	 * Invalidate a viewer's cached reads after a write. A stale row makes the next reaction toggle send
	 * the opposite direction and silently undo the user's like.
	 */
	public static function bump_generation( $auth_bucket ) {
		$option = 'appza_preview_gen_' . $auth_bucket;
		update_option( $option, self::generation_for( $auth_bucket ) + 1, false );
	}

	/** Authorization header for the wp_remote_get path only; never decides the viewer. */
	protected function extract_auth_header( \WP_REST_Request $request ) {
		$std = $request->get_header( 'authorization' );
		if ( is_string( $std ) && '' !== $std ) {
			return $std;
		}
		return null;
	}

	/** "9 months" rather than "9mo"; same boundaries as relative_time() so the two never disagree. */
	protected function long_age( $timestamp ) {
		$diff = max( 0, time() - (int) $timestamp );

		if ( $diff < 60 ) {
			return __( 'a moment', 'appza-core' );
		}
		$steps = array(
			array( 3600, 60, 'minute', 'minutes' ),
			array( 86400, 3600, 'hour', 'hours' ),
			array( 2592000, 86400, 'day', 'days' ),
			array( 31536000, 2592000, 'month', 'months' ),
		);
		foreach ( $steps as $step ) {
			list( $below, $unit, $one, $many ) = $step;
			if ( $diff < $below ) {
				$n = (int) floor( $diff / $unit );
				return sprintf( '%d %s', $n, 1 === $n ? $one : $many );
			}
		}
		$years = (int) floor( $diff / 31536000 );
		return sprintf( '%d %s', $years, 1 === $years ? 'year' : 'years' );
	}

	/**
	 * FC Pro custom-field groups for display: empty fields/groups dropped, values formatted, urls marked.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function custom_field_view( array $groups ) {
		$cards = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$rows = array();

			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || ! array_key_exists( 'value', $field ) ) {
					continue;
				}
				$type    = (string) ( $field['type'] ?? 'text' );
				$value   = $field['value'];
				$options = isset( $field['options'] ) ? (array) $field['options'] : array();
				$display = '';

				if ( 'multiselect' === $type ) {
					$labels = array();
					foreach ( (array) $value as $key ) {
						$key      = (string) $key;
						$labels[] = isset( $options[ $key ] ) ? (string) $options[ $key ] : $key;
					}
					$display = implode( ', ', array_filter( $labels, 'strlen' ) );
				} elseif ( 'date' === $type && is_string( $value ) && '' !== $value ) {
					$date    = \DateTime::createFromFormat( 'Y-m-d', $value );
					$display = $date ? $date->format( 'M j, Y' ) : $value;
				} elseif ( is_scalar( $value ) ) {
					$display = trim( (string) $value );
				}

				if ( '' === $display ) {
					continue;
				}

				$rows[] = array(
					'label' => (string) ( $field['label'] ?? '' ),
					'value' => $display,
					'href'  => 'url' === $type ? $display : '',
				);
			}

			if ( empty( $rows ) ) {
				continue;
			}

			$cards[] = array(
				'heading' => (string) ( $group['label'] ?? '' ),
				'rows'    => $rows,
			);
		}

		return $cards;
	}

	/**
	 * Filled-in social handles as display rows. Glyphs are ionicon names, to avoid injecting FC's raw SVG.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function social_link_view( array $stored ) {
		$helper    = '\\FluentCommunity\\App\\Services\\ProfileHelper';
		$providers = class_exists( $helper ) && method_exists( $helper, 'socialLinkProviders' )
			? (array) call_user_func( array( $helper, 'socialLinkProviders' ), true )
			: array();

		$glyphs = array(
			'instagram' => 'logo-instagram',
			'twitter'   => 'logo-twitter',
			'youtube'   => 'logo-youtube',
			'linkedin'  => 'logo-linkedin',
			'fb'        => 'logo-facebook',
			'tiktok'    => 'logo-tiktok',
			'pinterest' => 'logo-pinterest',
			'reddit'    => 'logo-reddit',
			'snapchat'  => 'logo-snapchat',
		);

		$rows = array();
		foreach ( $stored as $key => $handle ) {
			if ( ! is_string( $key ) || ! is_scalar( $handle ) ) {
				continue;
			}
			$handle = trim( (string) $handle );
			// Skip providers the site has since disabled.
			if ( '' === $handle || ! isset( $providers[ $key ] ) ) {
				continue;
			}
			$domain = (string) ( ( (array) $providers[ $key ] )['domain'] ?? '' );
			$rows[] = array(
				'label' => '',
				'value' => $handle,
				'href'  => $domain . ltrim( $handle, '@' ),
				'icon'  => $glyphs[ $key ] ?? 'link-outline',
			);
		}

		if ( empty( $rows ) ) {
			return array();
		}

		return array(
			array(
				'heading' => __( 'Social Link', 'appza-core' ),
				'rows'    => $rows,
			),
		);
	}

	/**
	 * Every enabled provider joined with the member's stored handle (a bare handle, as updateProfile stores it).
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function social_link_fields( array $stored ) {
		$helper = '\\FluentCommunity\\App\\Services\\ProfileHelper';
		if ( ! class_exists( $helper ) || ! method_exists( $helper, 'socialLinkProviders' ) ) {
			return array();
		}

		$providers = call_user_func( array( $helper, 'socialLinkProviders' ), true );
		if ( ! is_array( $providers ) ) {
			return array();
		}

		$fields = array();
		foreach ( $providers as $key => $provider ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$provider = (array) $provider;
			$value    = isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] )
				? (string) $stored[ $key ]
				: '';

			$fields[] = array(
				'key'         => $key,
				'label'       => isset( $provider['title'] ) ? (string) $provider['title'] : $key,
				'domain'      => isset( $provider['domain'] ) ? (string) $provider['domain'] : '',
				'placeholder' => isset( $provider['placeholder'] ) ? (string) $provider['placeholder'] : '',
				'value'       => $value,
			);
		}

		return $fields;
	}

	/**
	 * Resolve badge slugs against FC Pro's `user_badges` option; no endpoint returns resolved badges.
	 * meta.badge_slug is the fallback because the members endpoint publishes only that.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function resolve_badges( $item ) {
		$definitions = $this->badge_definitions();
		if ( empty( $definitions ) ) {
			return array();
		}

		$slugs = array();
		if ( isset( $item['badge_slugs'] ) ) {
			$slugs = (array) $item['badge_slugs'];
		} elseif ( isset( $item['meta']['badge_slug'] ) ) {
			$slugs = (array) $item['meta']['badge_slug'];
		}

		$badges = array();
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || ! isset( $definitions[ $slug ] ) ) {
				continue;
			}
			$badge  = (array) $definitions[ $slug ];
			$config = isset( $badge['config'] ) ? (array) $badge['config'] : array();

			$label = isset( $badge['title'] ) ? (string) $badge['title'] : '';
			$emoji = isset( $config['emoji'] ) ? (string) $config['emoji'] : '';

			$badges[] = array(
				'value' => $slug,
				'label' => $label,
				'emoji' => $emoji,
				'logo'  => isset( $config['logo'] ) ? (string) $config['logo'] : '',
				// A logo-only badge draws its title alone; show_label = no draws the emoji alone.
				'chip_label' => ( isset( $badge['show_label'] ) && 'no' === $badge['show_label'] && '' !== $emoji )
					? $emoji
					: trim( $emoji . ' ' . $label ),
				// FC Pro stores colours at the badge's top level (`color`, `background_color`), not in config.
				'text_color' => isset( $badge['color'] ) && '' !== $badge['color']
					? (string) $badge['color']
					: ( isset( $config['text_color'] ) ? (string) $config['text_color'] : '' ),
				'bg_color'   => isset( $badge['background_color'] ) && '' !== $badge['background_color']
					? (string) $badge['background_color']
					: ( isset( $config['bg_color'] ) ? (string) $config['bg_color'] : '' ),
			);
		}

		return $badges;
	}

	/** @return array<string,mixed> */
	protected function badge_definitions() {
		$utility = '\\FluentCommunity\\App\\Functions\\Utility';
		if ( ! class_exists( $utility ) ) {
			return array();
		}
		$badges = call_user_func( array( $utility, 'getOption' ), 'user_badges', array() );
		return is_array( $badges ) ? $badges : array();
	}

	/**
	 * Blocks are follows at level 0, counted off the model rather than a nested rest_do_request.
	 * whereHas('followed') matches FollowHandler so deleted users are not counted.
	 */
	protected function blocked_count( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return 0;
		}

		$model = '\\FluentCommunity\\App\\Models\\XProfile';
		if ( ! class_exists( $model ) ) {
			return 0;
		}

		try {
			$xprofile = call_user_func( array( $model, 'where' ), 'user_id', $user_id );
			$xprofile = $xprofile ? $xprofile->first() : null;
			if ( ! $xprofile || ! method_exists( $xprofile, 'follows' ) ) {
				return 0;
			}
			return (int) $xprofile->follows()->where( 'level', 0 )->whereHas( 'followed' )->count();
		} catch ( \Throwable $e ) {
			// The follows relation is Pro-only.
			return 0;
		}
	}

	/**
	 * Same arithmetic as /leaderboard/me, but for the profile owner. Every key is always returned so a
	 * binding never hits an absent path.
	 *
	 * @return array<string,mixed>
	 */
	protected function level_facts( $points ) {
		$points = max( 0, (int) $points );
		$none   = array(
			'has_level_bar'      => false,
			'level'              => 0,
			'level_label'        => '',
			'level_title'        => '',
			'points_label'       => number_format_i18n( $points ),
			'points_to_level_up' => 0,
			'level_up_label'     => '',
			'is_max_level'       => false,
		);

		// Hidden when the leaderboard module is off, as in FC's portal.
		$helper = '\\FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper';
		$fc     = '\\FluentCommunity\\App\\Services\\Helper';
		if ( ! class_exists( $helper ) || ! class_exists( $fc ) ) {
			return $none;
		}
		if ( ! call_user_func( array( $fc, 'isFeatureEnabled' ), 'leader_board_module' ) ) {
			return $none;
		}

		try {
			$level = (array) call_user_func( array( $helper, 'getLevelByPoint' ), $points );
			if ( empty( $level ) ) {
				return $none;
			}

			// getDynamicLevels keeps key order, not point order, so sort before taking the last level.
			$levels = (array) call_user_func( array( $helper, 'getDynamicLevels' ) );
			$levels = array_values( $levels );
			usort(
				$levels,
				static function ( $a, $b ) {
					return (int) ( $a['min_points'] ?? 0 ) <=> (int) ( $b['min_points'] ?? 0 );
				}
			);
			$last = end( $levels );
			$is_max = is_array( $last ) && isset( $last['slug'], $level['slug'] ) && $last['slug'] === $level['slug'];

			$to_level_up = max( 0, (int) ( $level['max_points'] ?? 0 ) - $points + 1 );

			return array(
				'has_level_bar' => true,
				'level'         => (int) ( $level['level'] ?? 0 ),
				/* translators: %d: the level number */
				'level_label'   => sprintf( __( 'Level %d', 'appza-core' ), (int) ( $level['level'] ?? 0 ) ),
				'level_title'   => (string) ( $level['title'] ?? '' ),
				'points_label'  => number_format_i18n( $points ),
				'points_to_level_up' => $is_max ? 0 : $to_level_up,
				'level_up_label'     => $is_max
					? ''
					/* translators: %s: how many points until the next level */
					: sprintf( __( '%s points to level up', 'appza-core' ), number_format_i18n( $to_level_up ) ),
				'is_max_level'  => $is_max,
			);
		} catch ( \Throwable $e ) {
			return $none;
		}
	}

	/**
	 * FC profile_navs mapped to our tab values; unknown slugs pass through.
	 *
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

		// In-process dispatch inherits core's current user; no switching needed.
		if ( '' !== $endpoint && '/' === $endpoint[0] && 0 === strpos( $endpoint, '/wp-json/' ) ) {
			$route = substr( $endpoint, strlen( '/wp-json' ) );
			$rest_request = new \WP_REST_Request( 'GET', $route );
			foreach ( $params as $k => $v ) {
				$rest_request->set_param( $k, $v );
			}
			$rest_request->set_query_params( $params );
			// FC reads $_REQUEST/$_GET directly (e.g. per_page), so mirror the params there, then restore.
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
			try {
				$response = rest_do_request( $rest_request );
			} finally {
				$_REQUEST = $prev_request_super;
				$_GET     = $prev_get_super;
			}
			if ( $response->is_error() ) {
				return null;
			}
			$decoded = $response->get_data();
			if ( ! is_array( $decoded ) ) {
				return null;
			}
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
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $decoded['data'];
		}
		if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
			return $decoded['items'];
		}
		return $decoded;
	}

	/** Shared with the DOCUMENTS tab so both derive file rows the same way. */
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
		// FC eager-loads only type='like'; bookmarks share the table, so fetch the viewer's reactions here.
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
				// Survey votes have type 'survey_vote' and the option slug in object_type, so the query above misses them.
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
				// Keep the epoch: created_at becomes "24m" below and can no longer be sorted.
				$items[ $i ]['created_at_ts'] = (int) strtotime( $item['created_at'] );
				$items[ $i ]['created_at']    = $this->relative_time( $item['created_at'] );
			}
			$items[ $i ]['byline'] = $this->build_byline(
				isset( $items[ $i ]['created_at'] ) ? $items[ $i ]['created_at'] : '',
				isset( $item['space'] ) ? $item['space'] : null
			);
			// FC omits vote_counts/percentage on options nobody picked.
			if ( isset( $item['content_type'] ) && 'survey' === $item['content_type'] ) {
				$items[ $i ]['meta'] = $this->normalize_survey(
					isset( $items[ $i ]['meta'] ) && is_array( $items[ $i ]['meta'] ) ? $items[ $i ]['meta'] : array(),
					isset( $my_votes_by_feed[ (int) $item['id'] ] ) ? $my_votes_by_feed[ (int) $item['id'] ] : array()
				);
			}
			// Null meta cannot be flagged and must stay null.
			if ( isset( $items[ $i ]['meta'] ) && is_array( $items[ $i ]['meta'] ) ) {
				$items[ $i ]['meta'] = $this->add_moderation_banner_text( $items[ $i ]['meta'], 'post' );
			}
			if ( ! empty( $item['comments'] ) && is_array( $item['comments'] ) ) {
				$items[ $i ]['comments'] = $this->normalize_comments(
					$items[ $i ]['comments'],
					isset( $item['permalink'] ) && is_string( $item['permalink'] ) ? $item['permalink'] : ''
				);
			}
			if ( ! empty( $item['reactions'] ) && is_array( $item['reactions'] ) ) {
				$items[ $i ]['reaction_avatars'] = $this->build_reaction_avatars( $item['reactions'] );
			}
			// Empty rather than "0 likes", so `visible_when exists` hides it.
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
			// fcom_media_archive is not queried: it also holds abandoned uploads.
			$images = $this->build_images_from_meta( isset( $item['meta'] ) ? $item['meta'] : array() );
			if ( ! empty( $images ) ) {
				$items[ $i ]['images'] = $images;
			}
			// meta.document_lists URLs need a portal session and return HTML; derive files from live Media rows.
			if ( isset( $item['content_type'] ) && 'document' === $item['content_type'] ) {
				$files = $this->documents_controller()->files_for_feed( (int) $item['id'] );
				if ( ! empty( $files ) ) {
					$items[ $i ]['files'] = $files;
				}
			}
			if ( isset( $item['ctaButtons'] ) && is_array( $item['ctaButtons'] ) ) {
				$items[ $i ]['ctaButtons'] = $this->normalize_cta_buttons( $item['ctaButtons'] );
			}
			// /spaces/all-spaces leaves cover_photo null; use FC's placeholder. `id` excludes feed links,
			// which also carry privacy and slug.
			$has_space_shape = isset( $item['privacy'] ) && isset( $item['slug'] ) && isset( $item['id'] );
			$cover_missing = ! isset( $item['cover_photo'] ) || null === $item['cover_photo'] || '' === $item['cover_photo'];
			if ( $has_space_shape && $cover_missing ) {
				$fc_placeholder_file = WP_PLUGIN_DIR . '/fluent-community/assets/images/space-placeholder.jpg';
				if ( file_exists( $fc_placeholder_file ) ) {
					$items[ $i ]['cover_photo'] = plugins_url( 'fluent-community/assets/images/space-placeholder.jpg' );
				}
			}
		}
		return $items;
	}

	protected function rewrite_urls_for_current_host( $data ) {
		$stored = rtrim( (string) get_option( 'siteurl', '' ), '/' );
		if ( '' === $stored ) {
			return $data;
		}
		// Match both schemes (e.g. HTTPS behind a tunnel).
		$stored_host = preg_replace( '#^https?://#i', '', $stored );

		$scheme = ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			: ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) ? 'https' : 'http' );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return $data;
		}
		$host = ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] )
			? (string) $_SERVER['HTTP_X_FORWARDED_HOST']
			: ( ! empty( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : $stored_host );
		$host = strtolower( rtrim( trim( $host ), '/' ) );
		if ( ! $this->is_rewrite_host_allowed( $host, $stored_host ) ) {
			return $data;
		}
		$target = $scheme . '://' . $host;

		if ( $target === $stored ) {
			return $data;
		}
		$from_variants = array(
			'http://' . $stored_host,
			'https://' . $stored_host,
		);
		return $this->walk_and_replace_urls( $data, $from_variants, $target );
	}

	/** The site's own host, or one listed in APPZA_REWRITE_HOSTS (comma list, `*.` wildcard). */
	protected function is_rewrite_host_allowed( $host, $stored_host ) {
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+(:[0-9]{1,5})?$/', $host ) ) {
			return false;
		}
		$home_host = preg_replace( '#^https?://#i', '', rtrim( (string) get_option( 'home', '' ), '/' ) );
		if ( $host === strtolower( $stored_host ) || $host === strtolower( $home_host ) ) {
			return true;
		}
		if ( ! defined( 'APPZA_REWRITE_HOSTS' ) ) {
			return false;
		}
		foreach ( explode( ',', strtolower( (string) APPZA_REWRITE_HOSTS ) ) as $allowed ) {
			$allowed = trim( $allowed );
			if ( '' === $allowed ) {
				continue;
			}
			if ( $host === $allowed ) {
				return true;
			}
			if ( 0 === strpos( $allowed, '*.' ) && substr( $host, -strlen( $allowed ) + 1 ) === substr( $allowed, 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/** Params with keys sorted, so key order doesn't split the cache. */
	protected static function canonical_params( $params_json ) {
		$decoded = json_decode( (string) $params_json, true );
		if ( ! is_array( $decoded ) ) {
			return (string) $params_json;
		}
		$sort = function ( &$a ) use ( &$sort ) {
			if ( array_keys( $a ) !== range( 0, count( $a ) - 1 ) ) {
				ksort( $a );
			}
			foreach ( $a as &$v ) {
				if ( is_array( $v ) ) {
					$sort( $v );
				}
			}
		};
		$sort( $decoded );
		return (string) wp_json_encode( $decoded );
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
					// Ionic `light` is the pale ground + dark text pair FC's `is-text` button draws.
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
	 * Chip image for a feed link: icon_image, else shape_svg as a data URI (an <img> cannot run script
	 * the way inlined SVG can). The emoji stays separate because it is text.
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

	/** Mirrors FC's ReportReview wording; unflagged meta is returned untouched so the `exists` gate stays false. */
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
		if ( isset( $meta['media_items'] ) && is_array( $meta['media_items'] ) ) {
			$out = array();
			foreach ( $meta['media_items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
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
		// Single uploaded image, or a GIPHY gif (FC stores those as type image,
		// provider giphy — no media row); oembed thumbnails are excluded.
		if ( isset( $meta['media_preview'] ) && is_array( $meta['media_preview'] ) ) {
			$mp       = $meta['media_preview'];
			$type     = isset( $mp['type'] ) ? (string) $mp['type'] : '';
			$is_giphy = 'image' === $type && isset( $mp['provider'] ) && 'giphy' === $mp['provider'];
			if ( ( 'meta_data' === $type || $is_giphy )
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
	 * Survey options with `voted`, zero-filled counts and recomputed percentages (FC's stored share goes
	 * stale when options are added later). total_votes counts votes, not voters.
	 *
	 * @param array<int, string> $myVotes Option slugs this viewer picked.
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
		$meta['survey_config']['has_voted'] = ! empty( $myVotes );

		return $meta;
	}

	/** Pinned first (FC allows one sticky per post), relative times, sanitized bodies, comment permalinks. */
	protected function normalize_comments( array $comments, $postPermalink = '' ) {
		foreach ( $comments as $i => $comment ) {
			if ( ! is_array( $comment ) ) {
				continue;
			}
			if ( isset( $comment['created_at'] ) && is_string( $comment['created_at'] ) && '' !== $comment['created_at'] ) {
				// Don't overwrite a good stamp on thread_comments()' second pass.
				if ( empty( $comments[ $i ]['created_at_ts'] ) ) {
					$comments[ $i ]['created_at_ts'] = (int) strtotime( $comment['created_at'] );
				}
				$comments[ $i ]['created_at'] = $this->relative_time( $comment['created_at'] );
			}
			if ( isset( $comment['message_rendered'] ) && is_string( $comment['message_rendered'] ) ) {
				$comments[ $i ]['message_rendered'] = $this->sanitize_rich_text( $comment['message_rendered'] );
			}
			if ( isset( $comment['meta'] ) && is_array( $comment['meta'] ) ) {
				$comments[ $i ]['meta'] = $this->add_moderation_banner_text( $comment['meta'], 'comment' );
			}
			// FC comments have no permalink of their own; the portal anchors to the post.
			if ( '' !== $postPermalink && isset( $comment['id'] ) ) {
				$comments[ $i ]['permalink'] = $postPermalink . '#comment-' . (int) $comment['id'];
			}
		}

		// usort is stable on PHP 8+, so non-sticky rows keep FC's order.
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

	/** Flat rows in, two-level tree out. */
	protected function thread_comments( array $rows, $plural = false ) {
		$byId    = array();
		$permalink = '';

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$byId[ (int) $row['id'] ] = $row;
			}
		}

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

		// The seen-set guards against a cyclic parent_id (e.g. a restored dump).
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

			// created_at is already relative here, so prefer the stamped epoch.
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

		// Promote replies whose ancestor is missing (e.g. moderated away) rather than drop them.
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
				$parent['reply_count_label'] = $plural
					? sprintf( _n( '%d Reply', '%d Replies', (int) $parent['reply_count'], 'appza-core' ), $parent['reply_count'] )
					: sprintf( __( '%d Reply', 'appza-core' ), $parent['reply_count'] );
			}
			$parent['sort_replies'] = $parent['reply_count'];
			$out[] = $parent;
		}

		// Top level only: one pass over the nested array would re-sort children into the parents.
		return $this->normalize_comments( $out, $permalink );
	}

	/**
	 * Up to four distinct liker faces.
	 *
	 * @return array<int, array{user_id:int, avatar:string, display_name:string}>
	 */
	protected function build_reaction_avatars( array $reactions ) {
		$out = [];
		foreach ( $reactions as $reaction ) {
			if ( ! is_array( $reaction ) ) {
				continue;
			}
			// Bookmarks share the table; a private bookmark must not show a face.
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

	/** The renderer injects this via dangerouslySetInnerHTML, so keep the safelist tight. */
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

	/** FC's moderator/admin verdicts for the current user; defensive because FC may be absent. */
	protected function viewer_roles( $viewer_id = 0 ) {
		$none = array( 'is_moderator' => false, 'is_admin' => false );
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return $none;
		}
		if ( ! method_exists( '\\FluentCommunity\\App\\Services\\Helper', 'isModerator' ) ) {
			return $none;
		}

		// FC's helpers read the current user, so only answer for the viewer.
		if ( (int) $viewer_id !== get_current_user_id() ) {
			return $none;
		}

		$roles = array( 'is_moderator' => false, 'is_admin' => false );

		// A site admin is not necessarily Helper::isModerator(); callers choose which flag they need.
		if ( method_exists( '\\FluentCommunity\\App\\Services\\Helper', 'isSiteAdmin' ) ) {
			$roles['is_admin'] = (bool) \FluentCommunity\App\Services\Helper::isSiteAdmin( $viewer_id ?: null );
		}
		$roles['is_moderator'] = (bool) \FluentCommunity\App\Services\Helper::isModerator();

		return $roles;
	}

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

	protected function fixture_fc_bookmarks() {
		$rows = $this->fixture_fc_feed();
		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) ) {
				$rows[ $i ]['bookmarked'] = true;
			}
		}

		return $rows;
	}

	protected function fixture_fc_current_user() {
		return array(
			array(
				'avatar'       => 'https://i.pravatar.cc/80?img=12',
				'display_name' => 'Syed',
				'first_name'   => 'Syed',
			),
		);
	}

	protected function fixture_fc_welcome_banner() {
		return array(
			array(
				'enabled'              => 'yes',
				'title'                => 'Build Mobile Apps With Zero Coding',
				'description_rendered' => '<p>Bring your website to the mobile world without a single line of code, be it blog, e-commerce, LMS or anything else! It uses custom components to make your app and also provides access to component properties to have better control, allowing you to make the app exactly how you want it to be.</p>',
				'mediaType'            => 'image',
				'bannerImage'          => 'https://picsum.photos/seed/appza-banner/800/450',
				'bannerVideo'          => null,
				'allowClose'           => 'yes',
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
