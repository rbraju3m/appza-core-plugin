<?php
/** Variables supplied by AdminMenu::render_builds(). */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$status_labels = array(
	'queued'    => __( 'Queued', 'appza-core' ),
	'building'  => __( 'Building', 'appza-core' ),
	'uploading' => __( 'Uploading', 'appza-core' ),
	'done'      => __( 'Ready', 'appza-core' ),
	'failed'    => __( 'Failed', 'appza-core' ),
);
?>
<div class="wrap appza-core-builds">
	<h1><img src="<?php echo esc_url( APPZA_CORE_PLUGIN_URL . 'assets/admin/images/appza-logo.png' ); ?>" alt="<?php esc_attr_e( 'APPZA Core', 'appza-core' ); ?>" style="height: 36px; width: auto; vertical-align: middle;" /></h1>

	<h2><?php esc_html_e( 'Build History', 'appza-core' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Builds requested with Build App from this site, newest first. Download links are made fresh each time this page loads.', 'appza-core' ); ?></p>

	<?php if ( $error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( __( 'Could not reach Core: %s', 'appza-core' ), $error ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $log ) : ?>
		<p><?php esc_html_e( 'No builds yet. Use Build App on the App page to request one.', 'appza-core' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="margin-top: 1em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Requested', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'Template', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'Site URL', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'Version', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'Size', 'appza-core' ); ?></th>
					<th><?php esc_html_e( 'APK', 'appza-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $row ) : ?>
					<?php
					$build = $core[ $row['id'] ] ?? null;
					$user  = get_userdata( (int) ( $row['requested_by'] ?? 0 ) );
					$when  = strtotime( (string) ( $row['requested_at'] ?? '' ) );
					?>
					<tr>
						<td>
							<?php echo esc_html( $when ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) : '—' ); ?>
							<?php if ( $user ) : ?>
								<br /><span class="description"><?php echo esc_html( $user->display_name ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $row['template_slug'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $row['site_url'] ?? '' ); ?></td>
						<?php if ( $build ) : ?>
							<td><?php echo esc_html( $status_labels[ $build['status'] ] ?? $build['status'] ); ?></td>
							<td><?php echo $build['version_name'] ? esc_html( $build['version_name'] . ' (' . $build['version_code'] . ')' ) : '—'; ?></td>
							<td><?php echo $build['apk_size'] ? esc_html( size_format( (int) $build['apk_size'], 1 ) ) : '—'; ?></td>
							<td>
								<?php if ( $build['download_url'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $build['download_url'] ); ?>"><?php esc_html_e( 'Download', 'appza-core' ); ?></a>
								<?php elseif ( $build['error'] ) : ?>
									<span style="color: #b32d2e;"><?php echo esc_html( $build['error'] ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						<?php elseif ( $error ) : ?>
							<td colspan="4">—</td>
						<?php else : ?>
							<td colspan="4" class="description"><?php esc_html_e( 'Removed on Core', 'appza-core' ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
