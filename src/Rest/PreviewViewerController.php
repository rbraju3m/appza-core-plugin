<?php
/**
 * The builder's "Viewing as" switch (P5). Site admins only.
 *
 * GET  /appza/v1/preview/viewer     — who "Me" and "Member" are (makes the sample member on first use).
 * POST /appza/v1/preview/viewer/me  — signs the simulator in as the admin: a fresh application password on the
 *      admin's own account, the same credential the simulator's sign-in form gets, so every tap works unchanged.
 *      The simulator revokes it when the switch moves off Me (core's /wp/v2/users/me/application-passwords).
 */

namespace AppzaCore\Plugin\Rest;

use AppzaCore\Plugin\Services\PreviewMember;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewViewerController {

	const APP_NAME = 'APPZA builder — Viewing as Me';

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public function show() {
		return rest_ensure_response(
			array(
				'me'     => PreviewMember::describe( get_current_user_id() ),
				'member' => PreviewMember::describe( PreviewMember::id() ),
			)
		);
	}

	public function sign_in_me() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error( 'appza_preview_no_user', __( 'Sign in to WordPress first.', 'appza-core' ), array( 'status' => 401 ) );
		}
		if ( ! class_exists( '\WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error(
				'appza_preview_app_passwords_off',
				__( 'This site has application passwords turned off, so the builder can’t sign in as you.', 'appza-core' ),
				array( 'status' => 409 )
			);
		}
		// One at a time: an earlier one the builder didn't get to revoke (tab closed) is replaced, not piled up.
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $existing ) {
			if ( isset( $existing['name'], $existing['uuid'] ) && self::APP_NAME === $existing['name'] ) {
				\WP_Application_Passwords::delete_application_password( $user->ID, $existing['uuid'] );
			}
		}
		$created = \WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => self::APP_NAME ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		list( $password, $item ) = $created;
		$who = PreviewMember::describe( $user->ID );
		return rest_ensure_response(
			array(
				'username'     => $user->user_login,
				'token'        => $password,
				'uuid'         => $item['uuid'],
				'user_id'      => (int) $user->ID,
				'display_name' => $who ? $who['display_name'] : $user->display_name,
				'avatar'       => $who ? $who['avatar'] : '',
			)
		);
	}
}
