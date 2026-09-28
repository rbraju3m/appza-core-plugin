<?php
/**
 * FC's ProfileController::updateProfile() rebuilds meta from the request, so omitted keys are ERASED
 * (headline, verified badge, badges; status resets to active). Fill them from the stored profile.
 */

namespace AppzaCore\Plugin\Rest;

defined( 'ABSPATH' ) || exit;

class FluentCommunityMemberUpdateCompleter {

	const ROUTE = '/fcom-mobile/api/v1/members/update';

	// `username` is required: without it FC rejects a member's save as "Invalid username" when usernames are editable.
	const COMPLETED_KEYS = array( 'headline', 'is_verified', 'status', 'username', 'badge_slugs' );

	const FC_ROUTE_PATTERN = '#^/fluent-community/v2/profile/[^/]+$#';

	/**
	 * @var string|null
	 */
	protected static $last_fc_error = null;

	public function register_hooks() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'complete' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'recover_error_message' ), 10, 3 );
	}

	/**
	 * fcom-mobile reports an FC validation failure as "Success"; capture the inner FC error and substitute it.
	 * FC only validates when REST_REQUEST is defined, so in-process probes skip validation.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
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
	 * FC validation errors ARE the body, e.g. {"first_name":{"required":"..."}}, with no message key.
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
	 * @param mixed $value
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
	 * @return mixed The response, untouched; only the request is changed.
	 */
	public function complete( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
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
			// Sent as `badge_slugs`, stored as `badge_slug`.
			'badge_slugs' => is_array( $profile->meta ) ? (array) ( $profile->meta['badge_slug'] ?? array() ) : array(),
		);

		foreach ( self::COMPLETED_KEYS as $key ) {
			// Not empty(): an intentionally emptied field must not be overwritten.
			if ( null === $request->get_param( $key ) ) {
				$request->set_param( $key, $current[ $key ] );
			}
		}

		return $response;
	}

	/**
	 * @return object|null XProfile
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
