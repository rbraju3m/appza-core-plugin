<?php
/**
 * Leaderboard — the viewer's card, the levels card, and the ranking list.
 *
 *   GET /wp-json/fcom-mobile/api/v1/leaderboard/me
 *   GET /wp-json/fcom-mobile/api/v1/leaderboard/levels
 *   GET /wp-json/fcom-mobile/api/v1/leaderboard/ranks
 *
 * Registered under the fcom-mobile namespace from our plugin, the same
 * call the chat, invitations, users-search and documents controllers
 * make (P68). fcom-mobile and FluentCommunity are third-party and are
 * never edited.
 *
 * ---------------------------------------------------------------------
 * Why these three and not fcom-mobile's own
 * ---------------------------------------------------------------------
 *
 *   1. `/leader-board` answers with every range at once, nested:
 *      `items[0]['data-list']['7_days' | '30_days' | 'all_time']`. A
 *      DataSource binds a FLAT list of rows, so that shape resolves to
 *      one unusable row. Splitting it per range also lets the tab strip
 *      drive a query param the way every other strip in this app does,
 *      instead of fetching three boards to show one.
 *
 *   2. `/leader-board/levels` returns the level definitions and nothing
 *      about the viewer. The whole of the levels card is the padlock —
 *      `myLevel.max_points >= thatLevel.max_points` — and deriving it in
 *      two shells is two chances to disagree with the portal.
 *
 *   3. `/leader-board/profile` fetches the entire XProfile through a
 *      loopback to FC's `/profile/{username}` for four fields, and still
 *      leaves "N points to level up" to the client.
 *
 * ---------------------------------------------------------------------
 * Everything the designs show as text is composed here
 * ---------------------------------------------------------------------
 *
 * `points_label`, `level_label`, `level_up_label`, the levels sheet's
 * "2,000 Points" — all pre-worded server-side (P71). Two reasons: the
 * strings are translated, and the app must not be the second place that
 * decides what a number means. It is also the whole of the
 * blank-vs-"+0" question on the ranking list: a member who scored
 * nothing in the selected range gets `points_label => ''`, the row's
 * `visible_when` drops the child, and no shell ever has to know that
 * zero is special.
 *
 * ---------------------------------------------------------------------
 * The two admin gates
 * ---------------------------------------------------------------------
 *
 * Features → "Leaderboards Module" (`leader_board_module`) turns the
 * whole feature off; the bootstrap drops the screen entirely so the
 * drawer carries no dead entry. Privacy → "Who can view leaderboard top
 * members?" (`leaderboard_members_visibility`) is per-viewer and is
 * resolved through FluentCommunity's own `canViewLeaderboardMembers()`,
 * so `everybody` / `logged_in` / `admin_only` behave exactly as they do
 * in the portal. A viewer who fails it gets zero ranking rows and the
 * card falls through to its empty state; their own card and levels are
 * untouched, because the privacy setting is about other people.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityLeaderboardController {

	/** FluentCommunity Pro's own range keys. Renaming one breaks the fetch. */
	private const RANGES = array(
		'7_days'   => 7,
		'30_days'  => 30,
		'all_time' => 0,
	);

	/**
	 * Levels shown before VIEW ALL. Three in every design; overridable
	 * per request so the catalog owns the number, not this file.
	 */
	private const PREVIEW_LEVELS = 3;

	/**
	 * FC Pro clamps its own leaderboard limit and builds its five-minute
	 * cache key from it, so asking for a number it does not already hold
	 * costs a fresh query. Ten is what the portal asks for.
	 */
	private const DEFAULT_LIMIT = 10;

	/** Rank → medal. Anything past third shows a plain number. */
	private const MEDALS = array( 1 => 'gold', 2 => 'silver', 3 => 'bronze' );

	/**
	 * Public read, like every other list this app renders: the ranking
	 * board is visible to logged-out visitors when the site says
	 * `everybody`, which is FluentCommunity's default. Everything
	 * viewer-specific gates itself below.
	 */
	public function permission_check() {
		return true;
	}

	/* ---------------------------------------------------------------
	 * GET /leaderboard/me
	 * ------------------------------------------------------------- */

	/**
	 * The viewer's own card, as a list of one.
	 *
	 * A list of one rather than a bare object because the preview proxy
	 * resolves every DataSource to an array of items, and a bare object
	 * reads downstream as zero rows.
	 *
	 * Zero rows is a real answer here and the renderer depends on it:
	 * the AppZet carries `hide_when_empty`, so a guest — or a site with
	 * the module switched off — gets no card at all rather than an empty
	 * shell. That is what makes Noor's signed-out design fall out
	 * without a placement of its own.
	 */
	public function me( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! $this->module_enabled() || ! is_user_logged_in() ) {
			return $this->ok( array() );
		}

		$xprofile = $this->viewer_profile();
		if ( ! $xprofile ) {
			return $this->ok( array() );
		}

		// FC recomputes a member's total from their posts' and comments'
		// reaction counts at most hourly, behind its own cache. Calling
		// it here is what the portal does on every render, and without
		// it the card can sit a like or two behind the board below it.
		$this->renew_points( $xprofile );

		$points = (int) $xprofile->total_points;
		$level  = $this->helper()::getLevelByPoint( $points );
		$levels = $this->levels();
		$last   = end( $levels );

		$is_max = is_array( $last ) && isset( $last['slug'], $level['slug'] ) && $last['slug'] === $level['slug'];

		// The portal's own arithmetic: the next level starts one point
		// past this one's ceiling. Suppressed at the top, where the
		// ceiling is ten billion and the sentence would be nonsense.
		$to_level_up = max( 0, (int) $level['max_points'] - $points + 1 );

		$name = (string) $xprofile->display_name;

		return $this->ok(
			array(
				array(
					'user_id'            => (int) $xprofile->user_id,
					'is_logged_in'       => true,
					'display_name'       => $name,
					'username'           => (string) $xprofile->username,
					'avatar'             => (string) $xprofile->avatar,
					'initials'           => $this->initials( $name ),
					'level'              => (int) $level['level'],
					'level_title'        => (string) $level['title'],
					'level_tagline'      => (string) $level['tagline'],
					/* translators: %d is the level number. */
					'level_label'        => sprintf( __( 'Level %d', 'appza-core' ), (int) $level['level'] ),
					'total_points'       => $points,
					'points_label'       => number_format_i18n( $points ),
					'points_to_level_up' => $is_max ? 0 : $to_level_up,
					'level_up_label'     => $is_max ? '' : $this->level_up_label( $to_level_up ),
					'is_max_level'       => $is_max,
					'module_enabled'     => true,
					'can_view_members'   => $this->can_view_members(),
					'members_visibility' => (string) $this->privacy_setting(),
					'denied_message'     => __( 'You do not have permission to view the leaderboard', 'appza-core' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------
	 * GET /leaderboard/levels
	 * ------------------------------------------------------------- */

	/**
	 * The configured levels, ordered by threshold, each carrying the
	 * lock verdict for the current viewer.
	 *
	 * `scope=preview` (the default) returns the first few and stamps
	 * `has_more`; `scope=all` returns every level and is what VIEW ALL
	 * and the points sheet ask for. Trimming server-side rather than in
	 * the card keeps the rows it rendered and the rows it fetched from
	 * ever disagreeing.
	 *
	 * A guest gets nothing: every row would be locked, which is not a
	 * card worth drawing, and the design has none.
	 */
	public function levels_route( \WP_REST_Request $request ) {
		if ( ! $this->module_enabled() || ! is_user_logged_in() ) {
			return $this->ok( array() );
		}

		$xprofile = $this->viewer_profile();
		if ( ! $xprofile ) {
			return $this->ok( array() );
		}

		$this->renew_points( $xprofile );

		$levels  = $this->levels();
		$total   = count( $levels );
		$mine    = $this->helper()::getLevelByPoint( (int) $xprofile->total_points );
		$my_ceil = (int) ( $mine['max_points'] ?? 0 );

		$scope = (string) $request->get_param( 'scope' );
		$count = (int) $request->get_param( 'preview_count' );
		if ( $count < 1 ) {
			$count = self::PREVIEW_LEVELS;
		}

		$shown    = 'all' === $scope ? $levels : array_slice( $levels, 0, $count );
		$has_more = count( $shown ) < $total;

		$rows = array();
		foreach ( $shown as $level ) {
			$rows[] = array(
				'slug'         => (string) ( $level['slug'] ?? '' ),
				'level'        => (int) ( $level['level'] ?? 0 ),
				'title'        => (string) ( $level['title'] ?? '' ),
				'tagline'      => (string) ( $level['tagline'] ?? '' ),
				'min_points'   => (int) ( $level['min_points'] ?? 0 ),
				'max_points'   => (int) ( $level['max_points'] ?? 0 ),
				'points_label' => $this->points_threshold_label( (int) ( $level['min_points'] ?? 0 ) ),
				// The portal's test, verbatim: a level is reached once
				// the viewer's own ceiling is at least as high as its.
				'is_unlocked'  => $my_ceil >= (int) ( $level['max_points'] ?? 0 ),
				'is_current'   => isset( $mine['slug'] ) && $mine['slug'] === ( $level['slug'] ?? '' ),
				// Stamped on every row, not just the last: the renderer
				// publishes the card's verdict from the first row it got,
				// and a row-position-dependent field would make that a
				// coin toss on an empty fetch.
				'has_more'     => $has_more,
				'total_levels' => $total,
			);
		}

		return $this->ok( $rows );
	}

	/* ---------------------------------------------------------------
	 * GET /leaderboard/ranks
	 * ------------------------------------------------------------- */

	/**
	 * Top members for one range, flattened to leaf keys.
	 *
	 * Zero rows covers both readings the card's empty state carries:
	 * nobody has scored in this range, and the viewer is not allowed to
	 * see who has. Returning an empty list rather than a 403 is
	 * deliberate — the proxy turns an upstream error into a silent
	 * fixture fallback, so a denial dressed as an error would render as
	 * demo data.
	 */
	public function ranks( \WP_REST_Request $request ) {
		if ( ! $this->module_enabled() || ! $this->can_view_members() ) {
			return $this->ok( array() );
		}

		$range = (string) $request->get_param( 'range' );
		if ( ! isset( self::RANGES[ $range ] ) ) {
			$range = '7_days';
		}

		$limit = (int) $request->get_param( 'per_page' );
		if ( $limit < 1 || $limit > 50 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$board = $this->helper()::getLeaderBoard( self::RANGES[ $range ], $limit, true );
		if ( ! is_array( $board ) || ! $board ) {
			return $this->ok( array() );
		}

		$board = array_map(
			static function ( $entry ) {
				return (array) $entry;
			},
			$board
		);

		$user_ids = array();
		foreach ( $board as $entry ) {
			if ( isset( $entry['user_id'] ) ) {
				$user_ids[] = (int) $entry['user_id'];
			}
		}
		if ( ! $user_ids ) {
			return $this->ok( array() );
		}

		$profiles = \FluentCommunity\App\Models\XProfile::whereIn( 'user_id', $user_ids )
			->where( 'status', 'active' )
			->select( \FluentCommunity\App\Services\ProfileHelper::getXProfilePublicFields() )
			->get()
			->keyBy( 'user_id' );

		$viewer   = get_current_user_id();
		$all_time = 'all_time' === $range;
		$rank     = 0;
		$rows     = array();

		foreach ( $board as $entry ) {
			$user_id = (int) ( $entry['user_id'] ?? 0 );
			$profile = $profiles[ $user_id ] ?? null;
			// A member whose profile is gone or deactivated drops out
			// rather than leaving a gap in the numbering — which is why
			// the rank counter lives here and not in the loop header.
			if ( ! $profile ) {
				continue;
			}

			$points = (int) ( $entry['total_points'] ?? 0 );

			if ( $all_time ) {
				$this->maybe_sync_total( $profile, $points );
			}

			$rank++;
			$name = (string) $profile->display_name;

			$rows[] = array(
				'user_id'      => $user_id,
				'rank'         => $rank,
				'display_name' => $name,
				'username'     => (string) $profile->username,
				'avatar'       => (string) $profile->avatar,
				'initials'     => $this->initials( $name ),
				'permalink'    => (string) $profile->permalink,
				'is_verified'  => (bool) $profile->is_verified,
				'points'       => $points,
				// Blank at zero — the portal hides the column rather than
				// printing a nought. The '+' says "gained in this window"
				// and would be a lie on the all-time total.
				'points_label' => $points > 0
					? ( $all_time ? '' : '+' ) . number_format_i18n( $points )
					: '',
				'medal'        => self::MEDALS[ $rank ] ?? '',
				'is_me'        => $viewer > 0 && $viewer === $user_id,
			);
		}

		return $this->ok( $rows, array( 'range' => $range ) );
	}

	/* ---------------------------------------------------------------
	 * Gates
	 * ------------------------------------------------------------- */

	/**
	 * The module is on AND FC Pro is actually installed. Both halves
	 * matter: the settings flag survives deactivating Pro, and every
	 * call below goes through a Pro class.
	 */
	public function module_enabled(): bool {
		if ( ! class_exists( '\\FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper' ) ) {
			return false;
		}
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return false;
		}

		return \FluentCommunity\App\Services\Helper::isFeatureEnabled( 'leader_board_module' );
	}

	/** Privacy → "Who can view leaderboard top members?", for THIS viewer. */
	private function can_view_members(): bool {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return false;
		}

		return (bool) \FluentCommunity\App\Functions\Utility::canViewLeaderboardMembers();
	}

	/** The raw setting, for the app to report rather than re-decide. */
	private function privacy_setting(): string {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return '';
		}

		$value = \FluentCommunity\App\Functions\Utility::getPrivacySetting( 'leaderboard_members_visibility' );

		return is_string( $value ) ? $value : '';
	}

	/* ---------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------- */

	/** @return class-string */
	private function helper(): string {
		return '\\FluentCommunityPro\\App\\Modules\\LeaderBoard\\Services\\LeaderBoardHelper';
	}

	/** @return array<string, array<string, mixed>> ordered by threshold */
	private function levels(): array {
		$levels = $this->helper()::getDynamicLevels();
		if ( ! is_array( $levels ) ) {
			return array();
		}

		// getDynamicLevels merges saved settings over defaults with
		// wp_parse_args, which preserves KEY order, not point order. The
		// save path sorts before writing, so this is belt-and-braces —
		// but a hand-edited option would otherwise put a padlock above
		// an unlocked level.
		$levels = array_values( $levels );
		usort(
			$levels,
			static function ( $a, $b ) {
				return (int) ( $a['min_points'] ?? 0 ) <=> (int) ( $b['min_points'] ?? 0 );
			}
		);

		return $levels;
	}

	private function viewer_profile() {
		if ( ! class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			return null;
		}

		$user = \FluentCommunity\App\Services\Helper::getCurrentUser();
		if ( ! $user || ! $user->exists() ) {
			return null;
		}

		return $user->xprofile ?: null;
	}

	private function renew_points( $xprofile ): void {
		$this->helper()::maybeRenewPoints( $xprofile );
	}

	/**
	 * The all-time board is computed from live reaction rows, while
	 * XProfile.total_points is a cached mirror refreshed hourly. When
	 * the board is ahead, the portal writes the mirror forward and fires
	 * `user_points_updated`, which is what drives the level-up trigger.
	 * Mirrored here so a member who only ever opens the app still levels
	 * up. Never writes backwards.
	 */
	private function maybe_sync_total( $profile, int $points ): void {
		if ( (int) $profile->total_points >= $points ) {
			return;
		}

		$model = \FluentCommunity\App\Models\XProfile::where( 'user_id', $profile->user_id )->first();
		if ( ! $model ) {
			return;
		}

		$old                 = (int) $model->total_points;
		$model->total_points = $points;
		$model->save();

		do_action( 'fluent_community/user_points_updated', $model, $old );
	}

	/**
	 * "5 points to level up". No exclamation mark — the portal has one,
	 * Noor's design does not, and the design is the contract.
	 */
	private function level_up_label( int $points ): string {
		return sprintf(
			/* translators: %s is the number of points needed, already formatted. */
			_n( '%s point to level up', '%s points to level up', $points, 'appza-core' ),
			number_format_i18n( $points )
		);
	}

	/** "0 Points" / "2,000 Points" — the points sheet's right-hand column. */
	private function points_threshold_label( int $points ): string {
		return sprintf(
			/* translators: %s is a point threshold, already formatted. */
			_n( '%s Point', '%s Points', $points, 'appza-core' ),
			number_format_i18n( $points )
		);
	}

	/**
	 * "Md Eftiar" → ME. The fallback circle when a member has no photo;
	 * empty when there is no name, because a stand-in glyph invents a
	 * person who is not there (P87).
	 */
	private function initials( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return '';
		}

		$words   = preg_split( '/\s+/u', $name ) ?: array();
		$letters = '';
		foreach ( $words as $word ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
			if ( '' === trim( $first ) ) {
				continue;
			}
			$letters .= $first;
			if ( mb_strlen( $letters ) >= 2 ) {
				break;
			}
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letters ) : strtoupper( $letters );
	}

	/**
	 * @param array<int, mixed>    $items
	 * @param array<string, mixed> $meta
	 */
	private function ok( array $items, array $meta = array() ) {
		return rest_ensure_response(
			array(
				'response' => array(
					'status'  => 200,
					'message' => 'OK',
				),
				'data' => $items,
				'meta' => $meta,
			)
		);
	}
}
