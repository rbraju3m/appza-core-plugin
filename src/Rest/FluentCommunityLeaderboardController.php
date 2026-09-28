<?php
/**
 * GET /wp-json/fcom-mobile/api/v1/leaderboard/{me,levels,ranks}
 *
 * fcom-mobile's own /leader-board nests every range in one row, which no DataSource can bind.
 * Labels are composed here so strings are translated and the app never interprets numbers.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FluentCommunityLeaderboardController {

	/** FluentCommunity Pro's own range keys. */
	private const RANGES = array(
		'7_days'   => 7,
		'30_days'  => 30,
		'all_time' => 0,
	);

	private const PREVIEW_LEVELS = 3;

	/** FC Pro caches its board per limit; 10 matches the portal and hits its cache. */
	private const DEFAULT_LIMIT = 10;

	private const MEDALS = array( 1 => 'gold', 2 => 'silver', 3 => 'bronze' );

	public function permission_check() {
		return PortalGuard::reader();
	}

	/**
	 * A list of one: the preview proxy reads a bare object as zero rows.
	 * Zero rows (guest / module off) lets hide_when_empty drop the card.
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

		// FC recomputes totals at most hourly behind a cache; the portal renews on every render.
		$this->renew_points( $xprofile );

		$points = (int) $xprofile->total_points;
		$level  = $this->helper()::getLevelByPoint( $points );
		$levels = $this->levels();
		$last   = end( $levels );

		$is_max = is_array( $last ) && isset( $last['slug'], $level['slug'] ) && $last['slug'] === $level['slug'];

		// Next level starts one point past this one's ceiling (portal's arithmetic).
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

	/**
	 * scope=preview (default) returns the first few with has_more; scope=all returns every level.
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
				'is_unlocked'  => $my_ceil >= (int) ( $level['max_points'] ?? 0 ),
				'is_current'   => isset( $mine['slug'] ) && $mine['slug'] === ( $level['slug'] ?? '' ),
				// On every row: the renderer reads the card's verdict from the first row.
				'has_more'     => $has_more,
				'total_levels' => $total,
			);
		}

		return $this->ok( $rows );
	}

	/**
	 * A denied viewer gets an empty list, not a 403: the proxy turns upstream
	 * errors into a fixture fallback, which would render demo data.
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
			// Skipped members must not leave a gap in the numbering.
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
				// Blank at zero; no '+' on the all-time total.
				'points_label' => $points > 0
					? ( $all_time ? '' : '+' ) . number_format_i18n( $points )
					: '',
				'medal'        => self::MEDALS[ $rank ] ?? '',
				'is_me'        => $viewer > 0 && $viewer === $user_id,
			);
		}

		return $this->ok( $rows, array( 'range' => $range ) );
	}

	/**
	 * The settings flag survives deactivating Pro, and every call here goes through a Pro class.
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

	private function can_view_members(): bool {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return false;
		}

		return (bool) \FluentCommunity\App\Functions\Utility::canViewLeaderboardMembers();
	}

	private function privacy_setting(): string {
		if ( ! class_exists( '\\FluentCommunity\\App\\Functions\\Utility' ) ) {
			return '';
		}

		$value = \FluentCommunity\App\Functions\Utility::getPrivacySetting( 'leaderboard_members_visibility' );

		return is_string( $value ) ? $value : '';
	}

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

		// getDynamicLevels() preserves key order, not point order.
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
	 * Mirrors the portal: move the cached XProfile.total_points forward (never back)
	 * and fire user_points_updated, which drives level-up triggers.
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

	private function level_up_label( int $points ): string {
		return sprintf(
			/* translators: %s is the number of points needed, already formatted. */
			_n( '%s point to level up', '%s points to level up', $points, 'appza-core' ),
			number_format_i18n( $points )
		);
	}

	private function points_threshold_label( int $points ): string {
		return sprintf(
			/* translators: %s is a point threshold, already formatted. */
			_n( '%s Point', '%s Points', $points, 'appza-core' ),
			number_format_i18n( $points )
		);
	}

	/** Empty when there is no name — no stand-in glyph. */
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
