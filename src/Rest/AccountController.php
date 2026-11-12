<?php
/**
 * Accounts from the app (v1 parity P2): forgot password, create account, delete account.
 * Routes in appza/v1/account; each one sets its own limits.
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\SignupService;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AccountController {

	/** Forgot password: requests per IP per window. */
	const FORGOT_LIMIT  = 5;
	const FORGOT_WINDOW = 15 * MINUTE_IN_SECONDS;

	/** Delete account: wrong passwords per account per window. */
	const DELETE_LIMIT  = 5;
	const DELETE_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Emails WordPress's own reset link. The answer never says whether the account exists, so the route
	 * can't be used to find accounts; only the rate limit answers differently.
	 */
	public function forgot_password( \WP_REST_Request $request ) {
		$login = trim( (string) $request->get_param( 'login' ) );
		if ( '' === $login ) {
			return new \WP_Error( 'appza_account_missing_login', __( 'Enter your email or username.', 'appza-core' ), array( 'status' => 400 ) );
		}

		if ( self::over_limit( 'forgot', self::FORGOT_LIMIT, self::FORGOT_WINDOW ) ) {
			return new \WP_Error( 'appza_account_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'appza-core' ), array( 'status' => 429 ) );
		}

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( $user ) {
			// WordPress's own mail and link, so the site's mail setup and reset page (or FluentAuth's) apply.
			// A failure (mail down, resets disabled for this user) is not shown: it would tell the account exists.
			retrieve_password( $user->user_login );
		}

		return new \WP_REST_Response(
			array(
				'message' => __( "If an account matches, we've emailed a link to reset the password. Check your inbox and spam folder.", 'appza-core' ),
			),
			200
		);
	}

	/** The create-account sheet: open or not, and the site's own wording and fields. */
	public function signup_form( \WP_REST_Request $request ) {
		return new \WP_REST_Response( SignupService::form(), 200 );
	}

	/** Create account, in FC's two steps (code, then create). The app signs in afterwards with the same login. */
	public function register( \WP_REST_Request $request ) {
		$input  = (array) $request->get_json_params() + (array) $request->get_body_params();
		$result = SignupService::register( $input );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete account (App Store rule: a user can delete their account from inside the app). The signed-in user only
	 * (application password), password asked again, refused for anyone who can manage the site. A real delete: the
	 * user's posts go with them (owner's call, 2026-11-12) and FluentCommunity's own clean-up runs on `deleted_user`.
	 */
	public function delete_account( \WP_REST_Request $request ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'appza_account_signed_out', __( 'Please sign in again, then try once more.', 'appza-core' ), array( 'status' => 401 ) );
		}
		if ( user_can( $user, 'manage_options' ) || is_super_admin( $user->ID ) ) {
			return new \WP_Error( 'appza_account_admin', __( "Site admins can't delete their account from the app. Do it from the WordPress dashboard.", 'appza-core' ), array( 'status' => 403 ) );
		}

		$bucket = 'delete_' . $user->ID;
		$key    = 'appza_acct_' . $bucket . '_tries';
		$tries  = (int) get_transient( $key );
		if ( $tries >= self::DELETE_LIMIT ) {
			return new \WP_Error( 'appza_account_rate_limited', __( 'Too many wrong passwords. Please wait a few minutes and try again.', 'appza-core' ), array( 'status' => 429 ) );
		}
		$password = (string) $request->get_param( 'password' );
		if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			set_transient( $key, $tries + 1, self::DELETE_WINDOW );
			$message = __( "That password isn't right.", 'appza-core' );
			return new \WP_Error( 'appza_account_wrong_password', $message, array( 'status' => 403, 'errors' => array( 'password' => $message ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		// On a network this removes the account from this site only; the network login stays (a network admin's call).
		if ( ! wp_delete_user( $user->ID ) ) {
			return new \WP_Error( 'appza_account_delete_failed', __( "We couldn't delete the account. Please try again later.", 'appza-core' ), array( 'status' => 500 ) );
		}
		delete_transient( $key );
		return new \WP_REST_Response( array( 'deleted' => true, 'message' => __( 'Your account has been deleted.', 'appza-core' ) ), 200 );
	}

	/** Counts this request; true once the IP has made more than $limit in the window. */
	private static function over_limit( string $bucket, int $limit, int $window ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'appza_acct_' . $bucket . '_' . md5( $ip );
		$hit = get_transient( $key );
		$now = time();
		if ( ! is_array( $hit ) || (int) $hit['until'] <= $now ) {
			$hit = array( 'count' => 0, 'until' => $now + $window );
		}
		$hit['count'] = (int) $hit['count'] + 1;
		set_transient( $key, $hit, max( 1, (int) $hit['until'] - $now ) );
		return $hit['count'] > $limit;
	}
}
