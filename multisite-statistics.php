<?php
// ini_set("memory_limit","512M");
// multisite statistics
function multisite_statistics()
{
	$total_sites_post_page = $total_sites_diskusage = 0;
	esc_html_e('UTM Webmaster Site Statistics', 'textdomain');
	echo "<script src='//cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js'></script>";
	// echo '<link rel="stylesheet" href="'.utm_webmaster_plugin_url.'style.css" type="text/css" media="all">';
	echo '<link rel="stylesheet" href="//cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css" type="text/css" media="all">';
	global $wpdb;
	$blogs = $wpdb->get_results("SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` ORDER BY blog_id DESC");
	if ($blogs) {
		$blogs_info = array();
		foreach ($blogs as $blog) {
			// count comments
			// set table prefix
			if ($blog->blog_id == '1') {
				$table = $wpdb->base_prefix . 'comments';
				allinonemigration_updatepath();
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_comments';
			}
			$sql = "SELECT comment_ID FROM " . $table;
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->comments = count($fivesdrafts);
			// end count comments

			// count attachments
			// set table prefix
			if ($blog->blog_id == '1') {
				$table = $wpdb->base_prefix . 'posts';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}

			$sql = "SELECT ID FROM " . $table . " WHERE post_type='attachment'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->attachments = count($fivesdrafts);
			// end count attachments

			// count post
			if ($blog->blog_id == '1') {
				$table = $wpdb->base_prefix . 'posts';
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}

			$sql = "SELECT ID FROM " . $table . " WHERE post_type='post'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->post = count($fivesdrafts);
			// end count post

			// count page
			if ($blog->blog_id == '1') {
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
			switch_to_blog($blog->blog_id);

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
			$last_updated = date("Y-m-d", strtotime(get_blog_details($blog->blog_id)->last_updated));
			// date in last 3 years
			$last3years = date("Y-m-d", strtotime("-3 years"));
			if ($last_updated < $last3years) {
				$last_updated = '<span style="color:red;">' . $last_updated . '</span>';
				// archive current blog by updating database
				$wpdb->update($wpdb->blogs, array('archived' => 1), array('blog_id' => $blog->blog_id));
			}
			$blog->last_updated = $last_updated;

			// list of plugins - 8 dec 2020
			// https://gist.github.com/damiencarbery/16b329aa67c801356d6b2a35513cc09d
			$the_plugs = get_blog_option($blog->blog_id, 'active_plugins');
			// printf('<hr /><h4><strong>SITE</strong>: <a href="%s" title="Go to the Dashboard for %s">%s</a></h4>', get_admin_url( $blog->blog_id), get_blog_option($blog->blog_id, 'blogname'), get_blog_option($blog->blog_id, 'blogname'));
			$pluginlist = '<ul>';
			foreach ($the_plugs as $key => $value) {
				$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $value);
				$pluginlist .= '<li>' . $plugin_data['Name'] . ' ' . $value . '</li>';
				// disable a plugin
				// if($blog->blog_id == 256){
				// 	switch_to_blog( $blog->blog_id );
				// 	// deactivate_plugins('wordpress-seo-premium/wp-seo-premium.php');
				// 	restore_current_blog();
				// }

			}
			$pluginlist .= '</ul>';
			$blog->pluginlist = $pluginlist;

			// get upload path - 13 sep 2023
			$upload_path = get_option('upload_path');
			if($upload_path != ''){
				update_option('upload_path', '');
				// if upload path contain 'files' string, run migration
				if(strpos($upload_path, 'files') !== false){
					run_migration($blog->blog_id);
				}
			}
			$blog->upload_path = 'Upload: ' . get_option('upload_path');

			// get list of user roles available to be selected
			

			array_push($blogs_info, $blog);
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
		foreach ($users as $user) :

			$user_login = $user->user_login; // get login
			$user_id = $user->ID; // get ID

			// check for name
			if (($user_login != 'deleteduser')) {
				$user_blogs = get_blogs_of_user($user_id); // get related sites
				// check if empty
				if (empty($user_blogs)) {
					array_push($usersarray, $user_id);
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
		$ = jQuery;
		$(document).ready(function() {
			var each = '';
			for (i = 0; i < blogs_info.length; i++) {
				each = blogs_info[i];
				$("#sort tbody").append("<tr><td>" + each['blog_id'] + "</td> <td><a target='_blank' href='http://" + each['domain'] + each['path'] + "'>" + each['domain'] + each['path'] + "</a> <a class='row-actions' target='_blank' href='/wp-admin/network/site-info.php?id=" + each['blog_id'] + "'>Edit</a><br />" + each['pluginlist'] + "<br />" + each['upload_path'] + "</td> <td>" + each['comments'] + "</td> <td>" + each['attachments'] + "</td> <td>" + each['post'] + "</td> <td>" + each['page'] + "</td> <td>" + each['postpage'] + "</td> <td>" + each['diskusage'] + "</td><td>" + Number(each['usercount']) + "</td><td>" + each['last_updated'] + "</td> </tr>");
			}
			// new Tablesort(document.getElementById('sort'));
			$(document).ready(function() {
				$('#sort').DataTable();
			});
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
