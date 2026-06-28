=== APPZA Core 2.0 ===
Contributors: lazycoders
Tags: mobile-app, sdui, server-driven-ui, app-builder
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

APPZA 2.0 base WordPress plug-in. Serves runtime JSON to the APPZA mobile app and stores the Lazycoders catalog snapshot locally.

== Description ==

APPZA Core 2.0 is the base WordPress plug-in for APPZA 2.0 — a no-code mobile app builder that turns any WordPress site into a native mobile app.

The plug-in:

* Pulls the Lazycoders component catalog (Primitives, Superstructures, AppZets, Templates) from the central APPZA Core SaaS and caches it locally as a snapshot.
* Serves a single bootstrap endpoint (`GET /wp-json/appza/v1/bootstrap?template=<X>`) that the APPZA mobile app reads on cold boot.
* Hosts customer-side customizations layered on top of the Lazycoders catalog.
* Requires a Source Integration add-on plug-in (FluentCommunity, WooCommerce, TutorLMS, etc.) to expose per-Source data endpoints.

== Installation ==

1. Upload the `appza-core-2.0` folder to `/wp-content/plugins/`.
2. Activate the plug-in through the WordPress Plugins screen.
3. Visit **APPZA Core** in the WP admin menu.
4. Click **Pull from Core** to fetch the latest catalog snapshot.

== Changelog ==

= 2.0.0 =
* Initial scaffold (Phase 1B.1).