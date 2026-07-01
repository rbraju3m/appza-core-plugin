<?php
/**
 * Loads the built @appza/plugin-admin React bundle into the WP admin.
 *
 * Vite emits `assets/admin/manifest.json` next to the hashed bundle
 * files. We read it to discover the entry JS + CSS filenames; their
 * hashes change every build and we don't want to grep the directory.
 *
 * The bundle is only enqueued on the APPZA Core "App" page — every
 * other admin screen stays untouched.
 *
 * Cross-cuts WP REST + JS:
 *   - `apiUrl` is `rest_url()` so the React app uses whatever URL form
 *     this install's permalinks produce (pretty `/wp-json/...` or the
 *     query-form `?rest_route=/...`). Same code works on every install.
 *   - The entry script needs type="module" because Vite emits ES
 *     modules; WP's wp_enqueue_script doesn't add that attribute by
 *     default, so we hook script_loader_tag.
 */

namespace AppzaCore\Plugin\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AssetLoader {

	const HANDLE = 'appza-core-admin-app';

	public function enqueue( $hook_suffix ) {
		// Only on the APPZA Core "App" page.
		if ( 'toplevel_page_' . AdminMenu::SLUG !== $hook_suffix ) {
			return;
		}

		$manifest_path = APPZA_CORE_PLUGIN_DIR . 'assets/admin/.vite/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
			return;
		}

		// Vite manifests key by source-relative input path. plugin-admin's
		// entry is `index.html`.
		$entry = $manifest['index.html'] ?? null;
		if ( ! is_array( $entry ) ) {
			return;
		}

		$base_url = APPZA_CORE_PLUGIN_URL . 'assets/admin/';

		// Entry JS.
		if ( ! empty( $entry['file'] ) ) {
			wp_enqueue_script(
				self::HANDLE,
				$base_url . $entry['file'],
				array(),
				APPZA_CORE_VERSION,
				true
			);
			wp_localize_script(
				self::HANDLE,
				'appzaCoreConfig',
				array(
					'endpoints'       => array(
						// Full URL per endpoint. The React app appends query
						// params via the URL API — that works whether the
						// host returns pretty `/wp-json/...` or query-form
						// `/?rest_route=/...` URLs. Sidestep: hosts where
						// Apache rewrites are broken can define
						// APPZA_CORE_FORCE_QUERY_REST so we hand the query
						// form regardless of permalink settings.
						'bootstrap'      => esc_url_raw( $this->endpoint_url( 'bootstrap' ) ),
						'customizations' => esc_url_raw( $this->endpoint_url( 'customizations' ) ),
					),
					// Cookie-auth'd REST calls need this header (X-WP-Nonce).
					// The mutating customizations endpoints check it via WP's
					// rest_cookie_check_errors filter.
					'restNonce'       => wp_create_nonce( 'wp_rest' ),
					'defaultTemplate' => 'fluent-community-default',
					'assetsBase'      => esc_url_raw( $base_url ),
				)
			);
		}

		// Entry CSS (Vite emits CSS as a sibling asset; index.html's
		// "css" key lists every CSS file the entry chunk imports).
		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $i => $css_file ) {
				wp_enqueue_style(
					self::HANDLE . '-' . $i,
					$base_url . $css_file,
					array(),
					APPZA_CORE_VERSION
				);
			}
		}
	}

	/**
	 * WordPress's wp_enqueue_script emits classic <script src> by default.
	 * Vite output is ESM, so we have to flip the entry handle's tag to
	 * <script type="module" src>.
	 */
	public function add_module_type( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'type="module"' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	protected function endpoint_url( $route ) {
		if ( defined( 'APPZA_CORE_FORCE_QUERY_REST' ) && APPZA_CORE_FORCE_QUERY_REST ) {
			return home_url( '/?rest_route=/' . APPZA_CORE_REST_NAMESPACE . '/' . ltrim( $route, '/' ) );
		}
		return rest_url( APPZA_CORE_REST_NAMESPACE . '/' . ltrim( $route, '/' ) );
	}

	public function render_missing_build_notice() {
		?>
		<div class="notice notice-warning">
			<p>
				<strong>APPZA Core:</strong>
				<?php esc_html_e( 'The plug-in admin React build is missing. Run', 'appza-core' ); ?>
				<code>pnpm --filter @appza/plugin-admin build:plugin</code>
				<?php esc_html_e( 'in the appza-mobile repo to generate it.', 'appza-core' ); ?>
			</p>
		</div>
		<?php
	}
}
