<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<style>
	/* Pinned to the window: the admin menu can be taller than the screen and scroll the page. */
	.appza-core-app-host {
		position: fixed;
		top: 32px;
		left: 160px;
		right: 0;
		bottom: 0;
		z-index: 1;
		background: #fff;
	}
	.folded .appza-core-app-host { left: 36px; }
	@media (max-width: 960px) {
		.auto-fold .appza-core-app-host { left: 36px; }
	}
	@media (max-width: 782px) {
		.appza-core-app-host,
		.auto-fold .appza-core-app-host { top: 46px; left: 0; }
	}
</style>
<div class="appza-core-app-host">
	<div id="root"></div>
</div>
