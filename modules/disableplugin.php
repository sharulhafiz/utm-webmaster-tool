<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disable plugin UI for network-wide or site-specific deactivation.
 *
 * @return void
 */
function network_deactivation_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'utm-webmaster' ) );
	}

	$notice_type = '';
	$notice_message = '';

	// Network-wide deactivation.
	if ( isset( $_POST['utm_disable_action'] ) && 'network' === $_POST['utm_disable_action'] ) {
		check_admin_referer( 'utm_disable_plugin_network' );

		$plugin_name = isset( $_POST['plugin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_name'] ) ) : '';

		if ( '' === $plugin_name ) {
			$notice_type = 'error';
			$notice_message = 'Please select a plugin to deactivate network-wide.';
		} else {
			deactivate_plugins( $plugin_name, true, true );
			$notice_type = 'success';
			$notice_message = sprintf( 'Network plugin deactivated: %s', $plugin_name );
		}
	}

	// Site-specific deactivation.
	if ( isset( $_POST['utm_disable_action'] ) && 'site' === $_POST['utm_disable_action'] ) {
		check_admin_referer( 'utm_disable_plugin_site' );

		$blog_id = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
		$plugin_file = isset( $_POST['site_plugin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_plugin_name'] ) ) : '';

		if ( $blog_id <= 0 || '' === $plugin_file ) {
			$notice_type = 'error';
			$notice_message = 'Please provide both Site ID and plugin file.';
		} elseif ( ! is_multisite() ) {
			$notice_type = 'error';
			$notice_message = 'Site-specific deactivation is available in multisite only.';
		} else {
			$site = get_site( $blog_id );
			if ( ! $site ) {
				$notice_type = 'error';
				$notice_message = sprintf( 'Invalid Site ID: %d', $blog_id );
			} else {
				switch_to_blog( $blog_id );

				$active_plugins = (array) get_option( 'active_plugins', array() );
				$is_active_here = in_array( $plugin_file, $active_plugins, true );

				if ( $is_active_here ) {
					deactivate_plugins( $plugin_file, true, false );
					$notice_type = 'success';
					$notice_message = sprintf( 'Plugin deactivated on site ID %d: %s', $blog_id, $plugin_file );
				} else {
					$notice_type = 'warning';
					$notice_message = sprintf( 'Plugin is not active on site ID %d: %s', $blog_id, $plugin_file );
				}

				restore_current_blog();
			}
		}
	}

	$network_active_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );

	$selected_blog_id = isset( $_GET['blog_id'] ) ? absint( $_GET['blog_id'] ) : 0;
	$site_active_plugins = array();
	if ( is_multisite() && $selected_blog_id > 0 && get_site( $selected_blog_id ) ) {
		switch_to_blog( $selected_blog_id );
		$site_active_plugins = (array) get_option( 'active_plugins', array() );
		restore_current_blog();
	}

	?>
	<div class="wrap">
		<h1>Disable Plugin</h1>

		<?php if ( '' !== $notice_type && '' !== $notice_message ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice_message ); ?></p></div>
		<?php endif; ?>

		<h2>Network-wide Deactivation</h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'utm_disable_plugin_network' ); ?>
			<input type="hidden" name="utm_disable_action" value="network" />
			<label for="plugin_name">Select network-active plugin:</label>
			<select name="plugin_name" id="plugin_name" required>
				<option value="">-- Select Plugin --</option>
				<?php foreach ( $network_active_plugins as $plugin ) : ?>
					<option value="<?php echo esc_attr( $plugin ); ?>"><?php echo esc_html( $plugin ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="submit" value="Deactivate Network Plugin" class="button button-primary" />
		</form>

		<hr style="margin: 24px 0;" />

		<h2>Site-specific Deactivation</h2>
		<p>Deactivate a plugin for one site only (does not change network-active plugins).</p>

		<form method="get" action="" style="margin-bottom: 16px;">
			<input type="hidden" name="page" value="network_deactivation_page" />
			<label for="blog_id_lookup">Site ID:</label>
			<input type="number" min="1" id="blog_id_lookup" name="blog_id" value="<?php echo esc_attr( $selected_blog_id ); ?>" required />
			<input type="submit" class="button" value="Load Active Plugins" />
		</form>

		<form method="post" action="">
			<?php wp_nonce_field( 'utm_disable_plugin_site' ); ?>
			<input type="hidden" name="utm_disable_action" value="site" />

			<p>
				<label for="blog_id">Site ID:</label>
				<input type="number" min="1" id="blog_id" name="blog_id" value="<?php echo esc_attr( $selected_blog_id ); ?>" required />
			</p>

			<p>
				<label for="site_plugin_name">Plugin file (e.g. <code>hello-dolly/hello.php</code>):</label><br />
				<input type="text" id="site_plugin_name" name="site_plugin_name" style="width: 420px;" required />
			</p>

			<?php if ( ! empty( $site_active_plugins ) ) : ?>
				<p><strong>Active plugins on site ID <?php echo esc_html( $selected_blog_id ); ?>:</strong></p>
				<select id="site_plugin_select" style="width: 420px;">
					<option value="">-- Select active plugin to auto-fill --</option>
					<?php foreach ( $site_active_plugins as $plugin ) : ?>
						<option value="<?php echo esc_attr( $plugin ); ?>"><?php echo esc_html( $plugin ); ?></option>
					<?php endforeach; ?>
				</select>
				<script>
					(function() {
						var sel = document.getElementById('site_plugin_select');
						var input = document.getElementById('site_plugin_name');
						if (sel && input) {
							sel.addEventListener('change', function() {
								if (this.value) {
									input.value = this.value;
								}
							});
						}
					})();
				</script>
			<?php endif; ?>

			<p style="margin-top: 12px;">
				<input type="submit" value="Deactivate Plugin on Site" class="button button-primary" />
			</p>
		</form>
	</div>
	<?php
}
