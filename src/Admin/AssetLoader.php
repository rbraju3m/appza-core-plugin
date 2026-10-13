<?php
namespace AppzaCore\Plugin\Admin;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AssetLoader {

	const HANDLE = 'appza-core-admin-app';

	public function enqueue( $hook_suffix ) {
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

		$entry = $manifest['index.html'] ?? null;
		if ( ! is_array( $entry ) ) {
			return;
		}

		$base_url = APPZA_CORE_PLUGIN_URL . 'assets/admin/';

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
						// Full URLs: the app appends params via the URL API, so pretty and ?rest_route= forms both work.
						'bootstrap'                => esc_url_raw( $this->endpoint_url( 'bootstrap' ) ),
						'customizations'           => esc_url_raw( $this->endpoint_url( 'customizations' ) ),
						'designIndex'              => esc_url_raw( $this->endpoint_url( 'design-index' ) ),
						'syncFromCore'             => esc_url_raw( $this->endpoint_url( 'sync-from-core' ) ),
						'sourceIntegrations'       => esc_url_raw( $this->endpoint_url( 'source-integrations' ) ),
						'activeSourceIntegrations' => esc_url_raw( $this->endpoint_url( 'active-source-integrations' ) ),
						'templates'                => esc_url_raw( $this->endpoint_url( 'templates' ) ),
							'buildRequests'            => esc_url_raw( $this->endpoint_url( 'build-requests' ) ),
					),
					'restNonce'       => wp_create_nonce( 'wp_rest' ),
					'defaultTemplate' => 'fluent-community-default',
					'assetsBase'      => esc_url_raw( $base_url ),
					'siteUrl'         => esc_url_raw( get_site_url() ),
				)
			);
		}

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

	/** Vite emits ES modules; WP doesn't add type="module" itself. */
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
