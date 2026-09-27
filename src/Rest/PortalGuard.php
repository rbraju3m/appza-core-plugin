<?php
/**
 * FluentCommunity's portal rule (PortalPolicy::verifyRequest) for our own routes.
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PortalGuard {

	/** Signed-in member whose profile is active and who may use the portal. */
	public static function member() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! class_exists( '\FluentCommunity\App\Services\Helper' ) ) {
			return true;
		}
		$profile = \FluentCommunity\App\Services\Helper::getCurrentProfile( true );
		if ( $profile && 'active' !== $profile->status ) {
			return false;
		}
		return (bool) \FluentCommunity\App\Services\Helper::canAccessPortal( get_current_user_id() );
	}

	/** A member, or a guest when the portal is public. */
	public static function reader() {
		if ( is_user_logged_in() ) {
			return self::member();
		}
		if ( ! class_exists( '\FluentCommunity\App\Services\Helper' ) ) {
			return true;
		}
		return (bool) \FluentCommunity\App\Services\Helper::canAccessPortal( 0 );
	}
}
