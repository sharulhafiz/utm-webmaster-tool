<?php
function network_deactivation_page() {
    if (isset($_POST['plugin_name'])) {
        $plugin_name = sanitize_text_field($_POST['plugin_name']);
        deactivate_plugins($plugin_name, true, true);
        echo '<div class="notice notice-success"><p>Plugin deactivated successfully.</p></div>';
    }
	$network_active_plugins = array_keys(get_site_option('active_sitewide_plugins'));

    ?>
    <div class="wrap">
        <h1>Network Deactivation</h1>
			<form method="post" action="">
				<label for="plugin_name">Select Plugin to Deactivate:</label>
				<select name="plugin_name" id="plugin_name" required>
					<?php foreach($network_active_plugins as $plugin): ?>
						<option value="<?php echo $plugin; ?>"><?php echo $plugin; ?></option>
					<?php endforeach; ?>
				</select>
				<input type="submit" value="Deactivate Plugin" class="button button-primary">
			</form>
    </div>
    <?php
}