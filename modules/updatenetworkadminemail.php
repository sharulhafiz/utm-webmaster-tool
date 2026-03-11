<?php
// This module will update the network admin email address by providing the new email address in a text field and clicking the submit button. It will also send an email to the webmaster email address webmaster@utm.my to inform the change. It will show the current email address used as network admin.

function change_network_admin_email() {
  if (isset($_POST['submit'])) {
    $email = sanitize_email($_POST['email']);
    if (is_email($email)) {
      update_site_option('admin_email', $email);
      $to = 'webmaster@utm.my';
      $subject = 'Network Admin Email Address Changed';
      $message = 'The network admin email address has been changed to ' . $email . '.';
      $headers = 'From: ' . $email . "\r\n" .
                 'Reply-To: ' . $email . "\r\n" .
                 'X-Mailer: PHP/' . phpversion();
      wp_mail($to, $subject, $message, $headers);
      echo '<div class="updated"><p>Email address updated successfully.</p></div>';
    } else {
      echo '<div class="error"><p>Invalid email address.</p></div>';
    }
  }

  $current_email = get_site_option('admin_email');
  ?>
  <h2>Update Network Admin Email</h2>
  <p>Current Network Admin Email Address: <?php echo esc_html($current_email); ?></p>
  <form action="" method="post">
    <label for="email">New Email Address</label>
    <input type="text" name="email" id="email" />
    <input type="submit" name="submit" value="Submit" class="button button-primary" />
  </form>
  <?php
}

function append_network_admin_email_form() {
  add_submenu_page(
    'utm-webmaster-dashboard', // Parent slug
    'Update Network Admin Email', // Page title
    'Update Network Admin Email', // Menu title
    'manage_network_options', // Capability
    'update-network-admin-email', // Menu slug
    'change_network_admin_email' // Function to display the page content
  );
}
add_action('network_admin_menu', 'append_network_admin_email_form');
?>
