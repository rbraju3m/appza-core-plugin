<?php
/**
 * Lets `/members/spaces` be addressed the way every other member route
 * is — by `member_id`.
 *
 * fcom-mobile's member routes take `member_id` and resolve an id OR a
 * username from it, except this one: it declares `username` as a
 * REQUIRED arg, so a request carrying only `member_id` is refused by
 * WordPress before the callback ever runs —
 *
 *     {"code":"rest_missing_callback_param",
 *      "message":"Missing parameter(s): username"}
 *
 * The profile screens publish ONE number into `profile_controls`, and
 * every data source on those screens binds it. Teaching the catalog that
 * one of the five tabs is addressed differently would put a special case
 * in the one place that should stay uniform, so the id is translated
 * here instead — the same seam that already completes /members/update.
 *
 * @package Appza_Core
 */

namespace AppzaCore\Plugin\Rest;

defined( 'ABSPATH' ) || exit;

class FluentCommunityMemberUsernameShim {

	/** Routes that want a username but are addressed by member_id. */
	const ROUTES = array( '/fcom-mobile/api/v1/members/spaces' );

	public function register_hooks() {
		// `rest_request_before_callbacks` runs AFTER WordPress validates
		// required args, so it is too late — the request is already a 400
		// by then. `rest_pre_dispatch` is the last hook before that check.
		add_filter( 'rest_pre_dispatch', array( $this, 'fill_username' ), 10, 3 );
	}

	/**
	 * @param mixed             $result  Short-circuit response, if any.
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return mixed The result, untouched. Only the REQUEST is changed.
	 */
	public function fill_username( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! in_array( (string) $request->get_route(), self::ROUTES, true ) ) {
			return $result;
		}

		$username = $request->get_param( 'username' );
		if ( is_string( $username ) && '' !== $username ) {
			return $result;
		}

		$member = $request->get_param( 'member_id' );
		if ( null === $member || '' === $member ) {
			return $result;
		}

		$model = '\FluentCommunity\App\Models\XProfile';
		if ( ! class_exists( $model ) ) {
			return $result;
		}

		$profile = is_numeric( $member )
			? $model::where( 'user_id', (int) $member )->first()
			: $model::where( 'username', (string) $member )->first();

		if ( $profile && ! empty( $profile->username ) ) {
			$request->set_param( 'username', (string) $profile->username );
		}

		return $result;
	}
}
