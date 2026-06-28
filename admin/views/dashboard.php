<?php
/**
 * APPZA Core admin dashboard view.
 *
 * Variables supplied by AdminMenu::render_dashboard():
 *   $meta               array — current row from wp_appza_catalog_meta
 *   $snapshots          array — rows from wp_appza_catalog_snapshot
 *   $default_template   string — pre-fills the pull form
 *   $pull_action_url    string — admin-post.php URL
 *   $pull_nonce         string — nonce for AdminController::PULL_ACTION
 *   $pull_action        string — admin-post action name
 *   $bootstrap_endpoint string — public REST URL clients hit on cold boot
 *   $core_api_url       string — APPZA_CORE_API_URL constant value
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap appza-core-dashboard">
	<h1><?php esc_html_e( 'APPZA Core', 'appza-core' ); ?></h1>

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
				<th><?php esc_html_e( 'Core API base URL', 'appza-core' ); ?></th>
				<td><code><?php echo esc_html( $core_api_url ); ?></code></td>
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
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Pull from Core', 'appza-core' ); ?></button>
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
</div>