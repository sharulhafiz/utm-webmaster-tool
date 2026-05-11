<?php
// delete orphan users
function delete_orphan_user()
{
	global $wpdb;
    $user_name = 'deleted';
    $user_email = 'deleted@utm.my';

    if (!username_exists($user_name) && !email_exists($user_email)) {
        $random_password = wp_generate_password(12, false);
        wp_create_user($user_name, $random_password, $user_email);
        echo "User deleted created<br>";
    } else {
        echo "User deleted exists<br>";
    }

	$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users WHERE user_login NOT IN ('deleteduser', 'webmaster')");
    echo count($users) . " users found<br>";

    $usersarray = array();
    foreach ($users as $user) {
        if (empty(get_blogs_of_user($user->ID))) {
            $usersarray[] = $user->ID;
        }
    }

	// Add a button to trigger the deletion
    echo '<button id="delete-orphan-users">Delete Orphan Users</button>';
	echo '<div id="status"></div>';
?>
	<script>
		$ = jQuery;
		let sec = 0;
		const pad = val => val > 9 ? val : "0" + val;
		setInterval(() => {
			$("#seconds").text(pad(++sec % 60));
			$("#minutes").text(pad(parseInt(sec / 60, 10)));
		}, 1000);

		const users_info = <?php echo json_encode($usersarray); ?>;
		let j = 0;

		const deleteOrphanUser = i => {
			const n = new Date($.now()); //set running time
			const minutes = n.getMinutes() < 10 ? "0" + n.getMinutes() : n.getMinutes();
			const m = `${n.getHours()}:${minutes}`;

			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: {
					'action': 'delete_orphan_users_ajax',
					'user_id': users_info[i]
				},
				success: response => {
					j++;
					$("#status").prepend(`${j}. ${m} ${response}`);
					$("#deletedusers").text(j);
					if (j < users_info.length) deleteOrphanUser(j);
				},
				error: () => {
					$("#status").prepend(`${j}. ${m} failed!<br>`);
					j++;
					if (j < users_info.length) deleteOrphanUser(j);
				}
			});
		}

		// Trigger the deletion when the button is clicked
        $('#delete-orphan-users').click(function() {
            if (users_info.length > 0) {
                deleteOrphanUser(j);
            } else {
                $("#status").prepend("No orphan user available");
            }
        });
	</script>
<?php
} // end utm webmaster user

add_action('wp_ajax_delete_orphan_users_ajax', 'delete_orphan_users_ajax');

function delete_orphan_users_ajax()
{
	// check_ajax_referer('delete_orphan_users_nonce', 'nonce');

	$user_id = intval($_POST['user_id']);
	$user_info = get_userdata($user_id);

	if (!$user_info) {
		echo "User not found<br>";
		wp_die();
	}

	$username = $user_info->user_login;
	$email = $user_info->user_email;

	$deleted_user = get_user_by('login', 'deleted');
	if (!$deleted_user) {
		echo "Deleted user not found<br>";
		wp_die();
	}

	wpmu_delete_user($user_id, $deleted_user->ID);
	echo $username . " - " . $email .  "<br>";

	wp_die();
}