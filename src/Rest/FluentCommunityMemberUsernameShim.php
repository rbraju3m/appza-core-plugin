<?php
/**
 * fcom-mobile's /members/spaces requires `username` while every other member route takes
 * `member_id`; fill it from member_id so the catalog stays uniform.
 */

namespace AppzaCore\Plugin\Rest;

defined( 'ABSPATH' ) || exit;

class FluentCommunityMemberUsernameShim {

	const ROUTES = array( '/fcom-mobile/api/v1/members/spaces' );

	public function register_hooks() {
		// rest_request_before_callbacks is too late: WP has already 400'd the missing required arg.
		add_filter( 'rest_pre_dispatch', array( $this, 'fill_username' ), 10, 3 );
	}

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
