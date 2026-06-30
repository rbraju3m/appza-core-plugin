# appza-core-plugin

APPZA 2.0 base WordPress plug-in. Serves runtime JSON to the APPZA mobile app
and stores the Lazycoders catalog snapshot locally; also embeds the React
simulator that runs inside `/wp-admin/`.

Part of APPZA 2.0 — a no-code mobile app builder that turns any WordPress
site into a native mobile app via a single shared renderer.

## What it does

- Pulls the Lazycoders component catalog (Primitives, Superstructures, AppZets,
  Templates) from the central APPZA Core SaaS and caches it locally as a
  snapshot in `wp_appza_catalog_snapshot`.
- Serves the bootstrap endpoint `GET /wp-json/appza/v1/bootstrap?template=<X>`
  that the APPZA mobile app reads on cold boot — returns a DC#13 Q5 envelope
  (catalog + customizations + runtime_config).
- Hosts customer-side **customizations** in `wp_appza_customizations`, layered
  on top of the Lazycoders catalog at the renderer's cascade stage (token
  cascade + per-leaf REPLACE per P26 Part 4).
- Exposes REST CRUD for customizations at `appza/v1/customizations*`
  (`manage_options` + cookie nonce).
- Embeds the React simulator under **APPZA Core** in the admin menu — built
  bundle ships from the [appza-core-mobile](https://github.com/rbraju3m/appza-core-mobile)
  monorepo via `pnpm build:plugin`.

## Companion repos

| Repo | Role |
|---|---|
| [APPZA-2-0](https://github.com/nmkhan/APPZA-2-0) | Laravel core — catalog master library + snapshot export endpoint (`/api/v1/catalog/snapshot?template=<slug>`) |
| [appza-core-mobile](https://github.com/rbraju3m/appza-core-mobile) | TypeScript monorepo — `@appza/schemas`, `@appza/renderer`, `@appza/plugin-admin` (the React simulator that ships into this plug-in's `assets/admin/`) |

A Source Integration add-on plug-in (FluentCommunity, WooCommerce, TutorLMS,
etc.) is required at runtime to expose per-Source data endpoints.

## Install

1. Drop the `appza-core-2.0` folder into `wp-content/plugins/`.
2. Activate via the **Plugins** screen.
3. Visit **APPZA Core → Sync** in the admin menu.
4. Click **Pull from Core** to fetch the latest catalog snapshot.
5. Visit **APPZA Core → App** to open the React simulator.

Optional `wp-config.php` overrides:

```php
define( 'APPZA_CORE_API_URL', 'http://your-core-host/api/v1' );
define( 'APPZA_CORE_FORCE_QUERY_REST', true ); // ?rest_route= form for hosts without pretty-permalink rewrites
```

## REST surface

| Verb | Path | Auth |
|---|---|---|
| GET | `/wp-json/appza/v1/bootstrap?template=<slug>` | public |
| GET | `/wp-json/appza/v1/customizations` | `manage_options` + nonce |
| POST | `/wp-json/appza/v1/customizations` | `manage_options` + nonce |
| DELETE | `/wp-json/appza/v1/customizations/{id}` | `manage_options` + nonce |

## Admin pages

- `?page=appza-core` — React simulator (App)
- `?page=appza-core-sync` — server-rendered Sync (version registry, Pull from Core, snapshot list)

## License

GPL-2.0+ — see `readme.txt` for the WordPress plug-in repo manifest.