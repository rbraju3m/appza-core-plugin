<?php
/**
 * Mount point for the React simulator.
 *
 * The script + style are enqueued by AssetLoader from the built bundle
 * in assets/admin/. This view just outputs the root element.
 *
 * Full-bleed wrapper (no WP `.wrap` margins) so the React app owns the
 * whole admin canvas — matches the legacy appza-builder UX.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<style>
	/* Full-bleed within the WP admin frame. The admin content column
	   has #wpcontent padding; counter it so the app sits flush against
	   the admin menu / top bar. */
	#wpcontent { padding-left: 0 !important; }
	.appza-core-app-host {
		margin: -10px 0 -65px -1px; /* top:remove notice gap; bottom:cover the footer; left:flush */
		height: calc(100vh - 32px);
	}
	@media (max-width: 782px) {
		.appza-core-app-host { height: calc(100vh - 46px); }
	}
</style>
<div class="appza-core-app-host">
	<div id="root"></div>
</div>
