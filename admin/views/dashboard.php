<?php
/** Variables supplied by AdminMenu::render_sync(). Friendly v2 P2: plain order, technical tables under Advanced. */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap appza-core-dashboard">
	<h1><img src="<?php echo esc_url( APPZA_CORE_PLUGIN_URL . 'assets/admin/images/appza-logo.png' ); ?>" alt="<?php esc_attr_e( 'APPZA Core', 'appza-core' ); ?>" style="height: 36px; width: auto; vertical-align: middle;" /></h1>

	<h2><?php esc_html_e( 'Connection to APPZA', 'appza-core' ); ?></h2>
	<p class="description" style="max-width: 720px;"><?php esc_html_e( 'Your app\'s designs come from APPZA. This is the address this site gets them from.', 'appza-core' ); ?></p>
	<?php if ( $core_url_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $core_url_error ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $pull_action_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $url_action ); ?>" />
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $url_nonce ); ?>" />
		<p>
			<label for="appza-core-api-url"><?php esc_html_e( 'APPZA address', 'appza-core' ); ?></label><br />
			<input type="url" id="appza-core-api-url" name="core_api_url" value="<?php echo esc_attr( $core_api_url ); ?>" class="large-text code" placeholder="https://…/api/v1" <?php disabled( 'constant' === $core_url_source ); ?> />
		</p>
		<?php if ( 'constant' === $core_url_source ) : ?>
			<p class="description"><?php esc_html_e( 'Set in wp-config.php (APPZA_CORE_API_URL).', 'appza-core' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Starts with https:// (http:// only on a local test site).', 'appza-core' ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'appza-core' ); ?></button></p>
		<?php endif; ?>
	</form>
	<form method="post" action="<?php echo esc_url( $pull_action_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $test_action ); ?>" />
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $test_nonce ); ?>" />
		<p><button type="submit" class="button" <?php disabled( 'none' === $core_url_source ); ?>><?php esc_html_e( 'Check connection', 'appza-core' ); ?></button></p>
	</form>

	<h2 style="margin-top: 2em;"><?php esc_html_e( 'Your app\'s designs', 'appza-core' ); ?></h2>
	<table class="widefat striped" style="max-width: 720px;">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Look', 'appza-core' ); ?></th>
				<td><?php echo esc_html( $site_template_name ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last updated', 'appza-core' ); ?></th>
				<td><?php echo $site_template_at ? esc_html( $site_template_at . ' UTC' ) : esc_html__( 'Not yet: get the designs below.', 'appza-core' ); ?></td>
			</tr>
		</tbody>
	</table>
	<form method="post" action="<?php echo esc_url( $pull_action_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $pull_action ); ?>" />
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $pull_nonce ); ?>" />
		<input type="hidden" name="template" value="<?php echo esc_attr( $site_template ); ?>" />
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Get latest designs', 'appza-core' ); ?></button></p>
	</form>
	<p class="description"><?php esc_html_e( 'To choose another look, open APPZA Core → App and use the "Apps" button.', 'appza-core' ); ?></p>

	<h2 style="margin-top: 2em;"><?php esc_html_e( 'App builds', 'appza-core' ); ?></h2>
	<form method="post" action="<?php echo esc_url( $pull_action_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $token_action ); ?>" />
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $token_nonce ); ?>" />
		<p>
			<label for="appza-core-build-token"><?php esc_html_e( 'Build token', 'appza-core' ); ?></label><br />
			<input type="password" id="appza-core-build-token" name="build_token" value="" class="regular-text code" autocomplete="off"
				placeholder="<?php echo esc_attr( 'none' === $build_token_source ? __( 'Not set', 'appza-core' ) : __( 'Set (hidden). Type a new one to replace it.', 'appza-core' ) ); ?>"
				<?php disabled( 'constant' === $build_token_source ); ?> />
		</p>
		<?php if ( 'constant' === $build_token_source ) : ?>
			<p class="description"><?php esc_html_e( 'Set in wp-config.php (APPZA_CORE_BUILD_TOKEN).', 'appza-core' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'From the Core admin. Build App sends it with each build request. Save it empty to remove it.', 'appza-core' ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'appza-core' ); ?></button></p>
		<?php endif; ?>
	</form>

	<details style="margin-top: 2em; max-width: 920px;">
		<summary style="cursor: pointer; font-size: 1.1em; font-weight: 600;"><?php esc_html_e( 'Advanced', 'appza-core' ); ?></summary>

		<h2><?php esc_html_e( 'Version registry', 'appza-core' ); ?></h2>
		<table class="widefat striped" style="max-width: 720px;">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Catalog snapshot version', 'appza-core' ); ?></th>
					<td><?php echo esc_html( (int) $meta['catalog_snapshot_version'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Customizations version', 'appza-core' ); ?></th>
					<td><?php echo esc_html( (int) $meta['customizations_version'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Schema version (contracts)', 'appza-core' ); ?></th>
					<td><code><?php echo esc_html( $meta['schema_version'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last synced at', 'appza-core' ); ?></th>
					<td><?php echo esc_html( $meta['last_synced_at'] ?: '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Bootstrap endpoint (mobile reads this)', 'appza-core' ); ?></th>
					<td><code><?php echo esc_html( $bootstrap_endpoint ); ?>?template=&lt;slug&gt;</code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last pulled from', 'appza-core' ); ?></th>
					<td><?php echo $last_pulled_from ? '<code>' . esc_html( $last_pulled_from ) . '</code>' : '—'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Site template', 'appza-core' ); ?></th>
					<td><code><?php echo esc_html( $site_template ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<h2 style="margin-top: 2em;"><?php esc_html_e( 'Pull catalog from Core', 'appza-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $pull_action_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $pull_action ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $pull_nonce ); ?>" />
			<p>
				<label for="appza-core-template-slug"><?php esc_html_e( 'Template slug', 'appza-core' ); ?></label>
				<input type="text" id="appza-core-template-slug" name="template" value="<?php echo esc_attr( $default_template ); ?>" class="regular-text" />
			</p>
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Pull from Core', 'appza-core' ); ?></button>
			</p>
		</form>

		<h2 style="margin-top: 2em;"><?php esc_html_e( 'Local snapshots', 'appza-core' ); ?></h2>
		<?php if ( empty( $snapshots ) ) : ?>
			<p><em><?php esc_html_e( 'No snapshots stored yet. Run a pull to populate.', 'appza-core' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 920px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Template slug', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'Version', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'Core API version', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'Fetched at', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'Updated at', 'appza-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshots as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['template_slug'] ); ?></code></td>
							<td><?php echo esc_html( (int) $row['catalog_snapshot_version'] ); ?></td>
							<td><code><?php echo esc_html( $row['core_api_version'] ); ?></code></td>
							<td><?php echo esc_html( $row['fetched_at'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $row['updated_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</details>
</div>
