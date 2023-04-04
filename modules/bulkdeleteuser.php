<?php


// delete orphan users
function delete_orphan_user()
{
	global $wpdb;
	$user_name = 'deleted';
	$user_email = 'deleted@utm.my';
	$user_id = username_exists($user_name);
	if (!$user_id && email_exists($user_email) == false) {
		$random_password = wp_generate_password($length = 12, $include_standard_special_chars = false);
		$user_id = wp_create_user($user_name, $random_password, $user_email);
		echo "User deleted created<br>";
	} else {
		echo "User deleted exists<br>";
	}
	$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
	echo count($users) . " users found<br>";
	$usersarray = array();
	$i = 0;
	foreach ($users as $user) :

		$user_login = $user->user_login; // get login
		$user_id = $user->ID; // get ID

		// check for name
		if (($user_login != 'deleteduser') && ($i < count($users))) {
			$user_blogs = get_blogs_of_user($user_id); // get related sites
			// check if empty
			if (empty($user_blogs)) {
				array_push($usersarray, $user_id);
				$i += 1;
			}
		}
	endforeach;
?>
	<script>
		var sec = 0;

		function pad(val) {
			return val > 9 ? val : "0" + val;
		}
		setInterval(function() {
			jQuery("#seconds").html(pad(++sec % 60));
			jQuery("#minutes").html(pad(parseInt(sec / 60, 10)));
		}, 1000);
	</script>
	Deletion in process<br>Elapsed time: <span id="minutes"></span>:<span id="seconds"></span><br>
	No. of users delete: <span id="deletedusers">0</span>/<?php echo count($users); ?><br>
	Deleted users: <br>
	<span id="status"></span>
	<script>
		ajaxtimeout = setTimeout(function() {
			location.reload();
		}, 300000); //set timeout
		var users_info = <?php echo json_encode($usersarray); ?>;
		console.log(users_info);
		$ = jQuery;
		var each = '';
		j = 0;

		function nextAjax(i) {
			clearTimeout(ajaxtimeout); //reset timeout
			n = new Date($.now()); //set running time
			if (n.getMinutes() < 10) {
				minutes = "" + 0 + n.getMinutes();
			} else {
				minutes = n.getMinutes();
			}
			m = n.getHours() + ':' + minutes;

			var data = {
				'action': 'delete_orphan_users_ajax',
				'user_id': users_info[i]
			};
			$.ajax({
				type: 'POST',
				url: ajaxurl,
				// dataType: "json",
				async: true,
				data: data,
				success: function(response) {
					j++;
					$("#status").prepend(j + '. ' + m + ' ' + response);
					$("#deletedusers").html(j);
					nextAjax(j);
				},
				error: function(xhr, textStatus, errorThrown) {
					$("#status").prepend(j + '. ' + m + ' failed!<br>');
					j++;
					nextAjax(j);
				}
			});
		}
		if (users_info.length > 0) {
			console.log('Start')
			nextAjax(j);
		} else {
			$("#status").prepend("No orphan user available");
		}
	</script>
<?php
} // end utm webmaster user

add_action('wp_ajax_delete_orphan_users_ajax', 'delete_orphan_users_ajax');
// ajax delete orphan users
function delete_orphan_users_ajax()
{
	global $wpdb; // this is how you get access to the database

	$user_id = intval($_POST['user_id']);
	$user_info = get_userdata($user_id);
	$username = $user_info->user_login;
	$email = $user_info->user_email;
	$user = get_user_by('login', 'deleted');
	wpmu_delete_user($user_id, $user->ID); // delete user
	echo $username . " - " . $email .  "<br>";

	wp_die(); // this is required to terminate immediately and return a proper response
}