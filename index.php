<?php
/**
 * @package utm-webmaster-tool
 * @version 4
 */
/*
Plugin Name: UTM Webmaster Tool
Plugin URI: http://corporateaffairs.utm.my
Description: Tool for UTM Webmaster.
Author: UTM Webmaster
Network: true
Version: 4
Author URI: http://corporateaffairs.utm.my
*/
require_once ABSPATH . 'wp-admin/includes/ms.php';
include( plugin_dir_path( __FILE__ ) . 'shortcodes.php');
// include_once(ABSPATH . 'wp-includes/pluggable.php');

// UTM Central Script
function utm_query() {
	wp_enqueue_script ('utm_header_footer', '//www.utm.my/dev/utmQuery.js', array('jquery'), '1.0', true);
}
add_action('wp_enqueue_scripts','utm_query');

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

$url = plugin_dir_path(__FILE__);
define("utm_webmaster_plugin_path",plugin_dir_path(__FILE__));
define("utm_webmaster_plugin_url",WP_PLUGIN_URL . "/".basename($url)."/");

// register hook
register_activation_hook( __FILE__, 'MISTERPAH_CCM_activate' );
function MISTERPAH_CCM_activate() {
	if ( is_multisite() == False) {
		echo "FOR MULTISITE ONLY!";
	}
}

// add to menu in network
function utm_webmaster_register_custom_menu_page() {
	add_menu_page(
			__( 'UTM Webmaster Tool', 'textdomain' ),
			'UTM Webmaster Tool',
			'manage_options',
			'utm_webmaster_site_statistics',
			'utm_webmaster_site_statistics',
			'',
			25
		);
	add_submenu_page('utm_webmaster_site_statistics', 'Orphan Users', 'Orphan Users', 'manage_options', 'utm_webmaster_users', 'utm_webmaster_users');
	add_submenu_page('utm_webmaster_site_statistics', 'Add To Blogs', 'Add To Blogs', 'manage_options', 'utm_webmaster_add_to_blogs', 'utm_webmaster_add_to_blogs');
}
add_action( 'network_admin_menu', 'utm_webmaster_register_custom_menu_page' );

function utm_webmaster_site_statistics() {
	esc_html_e( 'UTM Webmaster Site Statistics', 'textdomain' );
	echo "<script src='".utm_webmaster_plugin_url."tablesort.min.js'></script>";
	echo '<link rel="stylesheet" href="'.utm_webmaster_plugin_url.'style.css" type="text/css" media="all">';
	global $wpdb;
	$blogs = $wpdb->get_results( "SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` ORDER BY blog_id DESC" );
	if ($blogs) {
		$blogs_info = array();
		foreach ( $blogs as $blog ) {
			// count comments
			// set table prefix
			if ( $blog->blog_id == '1' ) {
				$table = $wpdb->base_prefix . 'comments';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_comments';
			}
			$sql = "SELECT comment_ID FROM " . $table;
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->comments = count($fivesdrafts);
			// end count comments

			// count attachments
			// set table prefix
			if ( $blog->blog_id == '1' ) {
				$table = $wpdb->base_prefix . 'posts';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}

			$sql = "SELECT ID FROM " . $table . " WHERE post_type='attachment'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->attachments = count($fivesdrafts);
			// end count attachments

			// count post
			if ( $blog->blog_id == '1' ) {
				$table = $wpdb->base_prefix . 'posts';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}

			$sql = "SELECT ID FROM " . $table . " WHERE post_type='post'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->post = count($fivesdrafts);
			// end count post

			// count page
			if ( $blog->blog_id == '1' ) {
				$table = $wpdb->base_prefix . 'posts';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}

			$sql = "SELECT ID FROM " . $table . " WHERE post_type='page'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->page = count($fivesdrafts);
      // end count page

      // get disk usage
			// Switch to a blog
			switch_to_blog( $blog->blog_id );

			// Get this site's upload folder
			$dir = wp_upload_dir();
			$directory = $dir['basedir'];

			// get the size of this site's uploads folder
			// $size = custom_dirSize( $directory );
			$size = get_space_used();
			$blog->diskusage = number_format(round($size));
			$blog->diskusagetotal = round($size);

			// sum post + page
			$blog->postpage = $blog->post + $blog->page;
			$total_sites_post_page += $blog->postpage;
			$total_sites_diskusage += $blog->diskusagetotal;

			// user count
			$usercount = count_users();
			$blog->usercount = (int)$usercount['total_users'];


			// https://codex.wordpress.org/WPMU_Functions/get_blog_details
			$blog->last_updated = date("Y-m-d", strtotime(get_blog_details($blog->blog_id)->last_updated));

			array_push($blogs_info,$blog);
		} // close FOREACH
		echo "<br>Total sites : " . count($blogs_info);
		$blogs_info = json_encode($blogs_info);
		echo "<br>Total sites posts + pages: " . $total_sites_post_page;
		echo "<br>Total sites disk usage: " . number_format($total_sites_diskusage) . "MB<br>";

		// Count orphan users
		$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
		echo count($users) . " users found<br>";
		$usersarray = array();
		$i = 0;
		$j = 0;
		foreach ( $users as $user ) :

			$user_login = $user->user_login; // get login
			$user_id = $user->ID; // get ID

			// check for name
			if ( ($user_login != 'deleteduser') ) {
				$user_blogs = get_blogs_of_user( $user_id ); // get related sites
				// check if empty
				if ( empty($user_blogs) ) {
					array_push($usersarray,$user_id);
					$i += 1;
				} else {
					$j += 1;
				}
			}
		endforeach;
		echo "Total users without blog: " . $i;
		echo "<br>Total users with blog: " . $j;
	} // close IF
	?>

	<script>
	var blogs_info = <?php echo $blogs_info; ?>;
	console.log(blogs_info);
	$=jQuery;
	$(document).ready(function() {
		var each = '';
		for ( i=0; i < blogs_info.length; i++) {
			each = blogs_info[i];
			$("#sort tbody").append("<tr><td>"+each['blog_id']+"</td> <td><a target='_blank' href='http://"+each['domain']+each['path']+"'>"+each['domain']+each['path']+"</a> <a class='row-actions' target='_blank' href='/wp-admin/network/site-info.php?id="+each['blog_id']+"'>Edit</a></td> <td>"+each['comments']+"</td> <td>"+each['attachments']+"</td> <td>"+each['post']+"</td> <td>"+each['page']+"</td> <td>"+each['postpage']+"</td> <td>"+each['diskusage']+"</td><td>"+Number(each['usercount'])+"</td><td>"+each['last_updated']+"</td> </tr>");
	}
	new Tablesort(document.getElementById('sort'));
	});
	</script>

	<table id="sort" class="sort">
	  <thead>
	    <tr>
	      <th class="sort-header">Blog ID</th>
	  		<th class="sort-header">URL</th>
	  		<th class="sort-header">Comment Count</th>
	  		<th class="sort-header">Attachment Count</th>
	  		<th class="sort-header">Post Count</th>
	  		<th class="sort-header">Page Count</th>
	  		<th class="sort-header">Post + Page</th>
	  		<th class="sort-header">Disk Usage (MB)</th>
	  		<th class="sort-header">Users</th>
	  		<th class="sort-header">Last Updated</th>
	    </tr>
	  </thead>
	  <tbody>
	  </tbody>
	</table>
<?php
}

function utm_webmaster_users() {
	global $wpdb;
	$user_name = 'deleted';
	$user_email = 'deleted@utm.my';
	$user_id = username_exists( $user_name );
	if ( !$user_id && email_exists($user_email) == false ) {
		$random_password = wp_generate_password( $length=12, $include_standard_special_chars=false );
		$user_id = wp_create_user( $user_name, $random_password, $user_email );
		echo "User deleted created<br>";
	} else {
		echo "User deleted exists<br>";
	}
	$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
	echo count($users) . " users found<br>";
	$usersarray = array();
	$i = 0;
	foreach ( $users as $user ) :

		$user_login = $user->user_login; // get login
		$user_id = $user->ID; // get ID

		// check for name
		if ( ($user_login != 'deleteduser') && ($i < count($users)) ) {
			$user_blogs = get_blogs_of_user( $user_id ); // get related sites
			// check if empty
			if ( empty($user_blogs) ) {
				array_push($usersarray,$user_id);
				$i += 1;
			}
		}
	endforeach;
	?>
	<script>
		var sec = 0;
		function pad ( val ) { return val > 9 ? val : "0" + val; }
		setInterval( function(){
				jQuery("#seconds").html(pad(++sec%60));
				jQuery("#minutes").html(pad(parseInt(sec/60,10)));
		}, 1000);
	</script>
	Deletion in process<br>Elapsed time: <span id="minutes"></span>:<span id="seconds"></span><br>
	No. of users delete: <span id="deletedusers">0</span>/<?php echo count($users); ?><br>
	Deleted users: <br>
	<span id="status"></span>
	<script>
    ajaxtimeout = setTimeout(function(){location.reload();}, 300000); //set timeout
		var users_info = <?php echo json_encode($usersarray); ?>;
		console.log(users_info);
		$=jQuery;
		var each = '';
		j = 0;
		function nextAjax(i) {
      clearTimeout(ajaxtimeout); //reset timeout
      n = new Date($.now()); //set running time
      if ( n.getMinutes() < 10) {
        minutes = "" + 0 + n.getMinutes();
      } else {
        minutes = n.getMinutes();
      }
      m = n.getHours()+':'+minutes;

			var data = {
				'action': 'delete_orphan_users',
				'user_id': users_info[i]
			};
      $.ajax({
        type: 'POST',
        url: ajaxurl,
        // dataType: "json",
        async: true,
        data: data,
        success: function (response) {
          j++;
  				$("#status").prepend(j+'. '+m+' '+response);
  				$("#deletedusers").html(j);
          nextAjax(j);
        },
        error: function (xhr, textStatus, errorThrown) {
          $("#status").prepend(j+'. '+m+' failed!<br>');
          j++;
  				nextAjax(j);
        }
      });
		}
    if ( users_info.length > 0 ) {
      console.log('Start')
      nextAjax(j);
    } else {
      $("#status").prepend("No orphan user available");
    }

	</script>
	<?php
} // end utm webmaster user

add_action( 'wp_ajax_delete_orphan_users', 'delete_orphan_users' );

function delete_orphan_users() {
	global $wpdb; // this is how you get access to the database

	$user_id = intval( $_POST['user_id'] );
	$user_info = get_userdata($user_id);
	$username = $user_info->user_login;
	$email = $user_info->user_email;
	$user = get_user_by( 'login', 'deleted' );
	wpmu_delete_user( $user_id, $user->ID ); // delete user
  echo $username . " - " . $email .  "<br>";

	wp_die(); // this is required to terminate immediately and return a proper response
}

function utm_webmaster_add_to_blogs() {
	global $wpdb;

	echo "<div class='wrap'>";
	echo "<h1>Add User to blogs</h1>";

	if (isset($_POST['username']) && isset($_POST['blogpath'])){
		$user = get_user_by( 'login', $_POST['username'] );
		if ($user == false){
			echo $_POST['username'] . " not found";
		} else {
			$blogpath = $_POST['blogpath'];
			$blogs = $wpdb->get_results( "SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` ORDER BY blog_id DESC" );
			if ($blogs) {
				$blogs_info = array();
				foreach ( $blogs as $blog ) {
					if (stripos($blog->path, $blogpath) !== false){
						$slug = $blog->path;
						$id = get_id_from_blogname($slug);
						//ADD USER ID TO BLOG ID AS AN ADMINISTRATOR
						$blog_id = $blog->blog_id;
						$role = 'administrator';
						add_user_to_blog( $blog_id, $user->ID, $role );
						$url = get_site_url($blog->blog_id);
						echo $user->user_login . " added to " . $url . "<br>";
					}
				}
			}
		}
	}
	?>
	<form action="" id="adduser" method="post" novalidate="novalidate">
	<table class="form-table">
		<tbody><tr class="form-field form-required">
			<th scope="row"><label for="username">Username</label></th>
			<td><input type="text" class="regular-text" name="username" id="username" autocapitalize="none" autocorrect="off" maxlength="60"></td>
		</tr>
		<tr class="form-field form-required">
			<th scope="row"><label for="blogpath">Blog Path</label></th>
			<td><input type="text" class="regular-text" name="blogpath" id="blogpath"></td>
		</tr>
		<tr class="form-field">
			<td colspan="2">User will be added to the founded blogs.</td>
		</tr>
	</tbody></table>
	<p class="submit"><input type="submit" name="add-user" id="add-user" class="button button-primary" value="Add User"></p>	</form>
	<?php
	echo "</div>";
}

add_action('init','redirectnonadmin');
function redirectnonadmin(){
	$currentblogid = get_current_blog_id();
	if($currentblogid == 1){
		if(is_admin()){
			$current_user = wp_get_current_user();
			$currentusername = $current_user->user_login;
			if( is_super_admin() != true ){
				wp_redirect( "https://people.utm.my/login" );
			}
		}
	}
}
