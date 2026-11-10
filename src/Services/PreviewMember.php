<?php
/**
 * The builder's "Viewing as: Member" account (P5). A plain subscriber, made on first use, never signed in to:
 * the preview proxy reads as it for a site admin who asks (X-Appza-View-As: member), and nothing writes as it.
 * It joins no FluentCommunity space (owner, 2026-11-10), so it sees what a brand-new member sees. It does get an
 * FC member profile (fcom-mobile refuses a user without one), so it is listed as a member on the community;
 * the owner chose that over hiding it (2026-11-10).
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PreviewMember {

	const OPTION = 'appza_preview_member_id';
	const LOGIN  = 'appza-preview-member';
	const HEADER = 'x_appza_view_as';

	/** The account's id, made (or remade, if someone deleted it) when missing. 0 when it can't be made. */
	public static function id() {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 && get_userdata( $id ) ) {
			return self::with_fc_profile( $id );
		}
		$existing = get_user_by( 'login', self::LOGIN );
		if ( $existing ) {
			update_option( self::OPTION, (int) $existing->ID, false );
			return self::with_fc_profile( (int) $existing->ID );
		}
		// wp_insert_user sends no email; only wp_new_user_notification does.
		$id = wp_insert_user(
			array(
				'user_login'   => self::LOGIN,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => self::LOGIN . '@preview.invalid',
				'display_name' => __( 'Sample member', 'appza-core' ),
				'role'         => 'subscriber',
				'description'  => __( 'Used by the APPZA builder to show your app as a member sees it. Nobody signs in with it.', 'appza-core' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_option( self::OPTION, (int) $id, false );
		return self::with_fc_profile( (int) $id );
	}

	/** FC's own sign-up path (User::syncXProfile), so the profile is exactly what a new member gets. */
	protected static function with_fc_profile( $id ) {
		if ( ! class_exists( '\\FluentCommunity\\App\\Models\\User' ) || ! class_exists( '\\FluentCommunity\\App\\Models\\XProfile' ) ) {
			return $id;
		}
		if ( \FluentCommunity\App\Models\XProfile::where( 'user_id', $id )->exists() ) {
			return $id;
		}
		$user = \FluentCommunity\App\Models\User::find( $id );
		if ( $user ) {
			$user->syncXProfile();
		}
		return $id;
	}

	/** FC memoises the signed-in member's profile per request; re-read it after the user changes. */
	public static function refresh_fc_current() {
		if ( class_exists( '\\FluentCommunity\\App\\Services\\Helper' ) ) {
			\FluentCommunity\App\Services\Helper::getCurrentProfile( false );
		}
	}

	/**
	 * True when this request asks to read as the sample member AND may: a signed-in site admin whose
	 * wp-admin session sent a valid REST nonce. An app password or a bearer token never qualifies.
	 */
	public static function requested( \WP_REST_Request $request ) {
		if ( 'member' !== strtolower( (string) $request->get_header( self::HEADER ) ) ) {
			return false;
		}
		$nonce = (string) $request->get_header( 'x_wp_nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/** Name, id and avatar for the switch and `state.viewer.user_id`. */
	public static function describe( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return null;
		}
		return array(
			'user_id'      => (int) $user->ID,
			'display_name' => (string) $user->display_name,
			'avatar'       => (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		);
	}
}
