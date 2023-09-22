<?php
// this module will update the network admin email address by providing the new email address in a text field and clicking the submit button. it will also send an email to the webmaster email address webmaster@utm.my to inform the change. it will show currently email address used as network admin.

function change_network_admin_email() {
  $current_email = get_site_option('admin_email');
  echo 'Current Network Admin Email Address: ' . $current_email;
  ?>
  <form action="" method="post">
	<label for="email">New Email Address</label>
	<input type="text" name="email" id="email" />
	<input type="submit" name="submit" value="Submit" />
  </form>
  <?php
  if (isset($_POST['submit'])) {
	$email = $_POST['email'];
	update_site_option('admin_email', $email);
	$to = 'webmaster@utm.my';
	$subject = 'Network Admin Email Address Changed';
	$message = 'The network admin email address has been changed to ' . $email . '.';
	$headers = 'From:	' . $email . "\r\n" .
		'Reply-To: ' . $email . "\r\n" .
		'X-Mailer: PHP/' . phpversion();
	mail($to, $subject, $message, $headers);
  }
}
add_action('network_admin_menu', 'change_network_admin_email');