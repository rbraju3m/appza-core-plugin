<?php
/**
 * Makes fcom-mobile's `POST /members/update` behave like the PATCH every
 * caller already assumes it is.
 *
 * FluentCommunity's `ProfileController::updateProfile()` rebuilds the
 * profile's meta from the request rather than merging into it, so a
 * field the request omits is not left alone — it is ERASED:
 *
 *   headline     `Arr::get($data, 'headline', '')`      -> wiped
 *   is_verified  moderator branch: `... ? 1 : 0`        -> badge lost
 *   status       moderator branch: defaults to 'active' -> a deactivated
 *                                                          profile silently
 *                                                          comes back
 *   badge_slugs  moderator branch, user_badge on: `(array) Arr::get(...)`
 *                                                     -> every badge removed
 *   username     moderator: left alone when absent. But on a site with
 *                `can_customize_username` on, a MEMBER's save compares
 *                the stored username against null, sanitises that to '',
 *                and fails the whole request with "Invalid username".
 *
 * Our profile edit form carries five fields — first name, last name,
 * email, website, bio — because that is the form Noor's screen draws.
 * Without this, saving a bio would delete the member's headline, and on
 * some sites would not save at all.
 *
 * So the omitted keys are filled from the profile as it currently
 * stands, which makes the write a no-op for every field the form does
 * not show. A key the caller DID send is never touched.
 *
 * This lives here rather than in fcom-mobile because fcom-mobile is a
 * third-party plug-in we do not modify. `rest_request_before_callbacks`
 * fires for the outer HTTP request and for the internal
 * `rest_do_request` the preview proxy makes, so both shells get it.
 *
 * @package Appza_Core
 */

namespace AppzaCore\Plugin\Rest;

defined( 'ABSPATH' ) || exit;

class FluentCommunityMemberUpdateCompleter {

	const ROUTE = '/fcom-mobile/api/v1/members/update';

	/**
	 * Keys filled from the stored profile when the request omits them.
	 *
	 * `username` is included deliberately: sending the CURRENT username
	 * is a no-op on the moderator branch (the value is unchanged, so
	 * `$userNameChanged` stays false) and it is what keeps the member
	 * branch from rejecting the save on a site that allows username
	 * edits.
	 */
	const COMPLETED_KEYS = array( 'headline', 'is_verified', 'status', 'username', 'badge_slugs' );

	/** The inner FluentCommunity route fcom-mobile forwards to. */
	const FC_ROUTE_PATTERN = '#^/fluent-community/v2/profile/[^/]+$#';

	/**
	 * The last error FluentCommunity raised on the inner request, kept
	 * only for the microseconds between the inner response and the outer
	 * one within a single dispatch.
	 *
	 * @var string|null
	 */
	protected static $last_fc_error = null;

	public function register_hooks() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'complete' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'recover_error_message' ), 10, 3 );
	}

	/**
	 * Puts FluentCommunity's own words back on a rejected save.
	 *
	 * fcom-mobile builds its envelope with
	 * `$response->get_data()['message'] ?? 'Success'`. A validation
	 * failure carries its messages under `errors` and has no top-level
	 * `message`, so the fallback wins and a 422 is reported to the user
	 * as the word **"Success"** — in a red toast, on a form that did not
	 * save. That is worse than no message.
	 *
	 * The real sentence exists on the INNER response, which passes
	 * through this same filter moments earlier. It is captured there and
	 * substituted here.
	 *
	 * Worth knowing while reading this: FluentCommunity only RAISES that
	 * validation over HTTP. `Controller::validate()` rethrows the
	 * ValidationException when `REST_REQUEST` is defined and otherwise
	 * swallows it and carries on, so an in-process `rest_do_request`
	 * probe skips validation entirely and writes the empty value it
	 * should have rejected.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
	 * @param array                                              $handler
	 * @param \WP_REST_Request                                   $request
	 * @return mixed
	 */
	public function recover_error_message( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		$route = (string) $request->get_route();

		if ( preg_match( self::FC_ROUTE_PATTERN, $route ) ) {
			self::$last_fc_error = $this->extract_message( $response );
			return $response;
		}

		if ( self::ROUTE !== $route ) {
			return $response;
		}

		$captured = self::$last_fc_error;
		self::$last_fc_error = null;

		if ( ! $captured || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['response']['status'] ) ) {
			return $response;
		}
		if ( (int) $data['response']['status'] < 400 ) {
			return $response;
		}

		$data['response']['message'] = $captured;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * The most specific sentence a rejected response carries.
	 *
	 * MEASURED, because the shape is not the one you would guess: the
	 * framework returns the validation errors AS the response body, keyed
	 * by field and then by rule, with no `message` and no `errors`
	 * wrapper —
	 *
	 *     {"first_name":{"required":"First name is required"}}
	 *
	 * — so the first string leaf is the sentence to show. An explicit
	 * `message` is preferred when one exists, since other failures
	 * (permissions, a taken username) answer in that shape instead.
	 *
	 * @param mixed $response
	 * @return string|null
	 */
	protected function extract_message( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message() ?: null;
		}
		if ( ! $response instanceof \WP_REST_Response || $response->get_status() < 400 ) {
			return null;
		}
		$data = $response->get_data();
		if ( is_string( $data ) ) {
			return '' !== trim( $data ) ? trim( $data ) : null;
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( isset( $data['message'] ) && is_string( $data['message'] ) && '' !== trim( $data['message'] ) ) {
			return trim( $data['message'] );
		}

		return $this->first_string_leaf( isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : $data );
	}

	/**
	 * First non-empty string anywhere in a small nested array. Depth is
	 * bounded because this runs on a response body we do not control.
	 *
	 * @param mixed $value
	 * @param int   $depth
	 * @return string|null
	 */
	protected function first_string_leaf( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value ) ? trim( $value ) : null;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		foreach ( $value as $item ) {
			$found = $this->first_string_leaf( $item, $depth + 1 );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|null|mixed $response
	 * @param array                                                   $handler
	 * @param \WP_REST_Request                                        $request
	 * @return mixed The response, untouched. Only the REQUEST is changed.
	 */
	public function complete( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		// A short-circuited request (an auth failure upstream) is left
		// alone — there is nothing to complete and no profile to read.
		if ( null !== $response ) {
			return $response;
		}
		if ( self::ROUTE !== (string) $request->get_route() ) {
			return $response;
		}
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return $response;
		}

		$profile = $this->resolve_profile( $request );
		if ( ! $profile ) {
			return $response;
		}

		$current = array(
			'headline'    => (string) ( is_array( $profile->meta ) ? ( $profile->meta['headline'] ?? '' ) : '' ),
			'is_verified' => (int) $profile->is_verified,
			'status'      => (string) $profile->status,
			'username'    => (string) $profile->username,
			// Sent as `badge_slugs`, stored as `badge_slug`. Same wipe as
			// the headline: the moderator branch assigns
			// `(array) Arr::get($data, 'badge_slugs', [])` unconditionally
			// when the user_badge feature is on, so a save that does not
			// mention badges removes every one.
			'badge_slugs' => is_array( $profile->meta ) ? (array) ( $profile->meta['badge_slug'] ?? array() ) : array(),
		);

		foreach ( self::COMPLETED_KEYS as $key ) {
			// `null === get_param()` is the test, not `empty()`: an
			// intentionally emptied headline is a value, and must not be
			// overwritten with the stored one.
			if ( null === $request->get_param( $key ) ) {
				$request->set_param( $key, $current[ $key ] );
			}
		}

		return $response;
	}

	/**
	 * The profile this request is about — by member_id (an id OR a
	 * username, the way fcom-mobile's own resolver takes it), falling
	 * back to the signed-in viewer.
	 *
	 * @return object|null An XProfile, or null when FluentCommunity is
	 *                     not loaded or the member cannot be resolved.
	 */
	protected function resolve_profile( \WP_REST_Request $request ) {
		$model = '\FluentCommunity\App\Models\XProfile';
		if ( ! class_exists( $model ) ) {
			return null;
		}

		$member = $request->get_param( 'member_id' );
		if ( null === $member || '' === $member ) {
			$member = $request->get_param( 'username' );
		}
		if ( null === $member || '' === $member ) {
			$viewer = get_current_user_id();
			return $viewer ? $model::where( 'user_id', $viewer )->first() : null;
		}

		if ( is_numeric( $member ) ) {
			return $model::where( 'user_id', (int) $member )->first();
		}
		return $model::where( 'username', (string) $member )->first();
	}
}
