<?php
function unarchiveblog()
{
	global $wpdb;
	if (current_user_can('administrator')) {
		echo '<strong>Unarchive site</strong><br>';
?>
		<form target="_blank">
			<label for="siteid">Site ID:</label>
			<input type="text" id="siteid" name="siteid" value="">
			<input type="submit" value="Submit">
		</form>
	<?php
	}
	if (isset($_GET['siteid'])) {
		$blog_id = $_GET['siteid'];
		if (get_blog_status($blog_id, 'archived') == 1) {
			$wpdb->update(
				$wpdb->blogs,
				array(
					'public' => 1,
					'archived' => 0,
				),
				array(
					'blog_id' => $blog_id,
				)
			);
			switch_to_blog($blog_id);
			echo '<a href="' . get_bloginfo('url') . '">' . get_bloginfo('url') . '</a> has been unarchived'; //https://developer.wordpress.org/reference/functions/get_bloginfo/
			echo 'Blog ' . $blog_id . ' was last updated ' . get_blog_status($blog_id, 'last updated') . '<br>';
		} else {
			switch_to_blog($blog_id);
			echo '<a href="' . get_bloginfo('url') . '">' . get_bloginfo('url') . '</a> was not archived';
		}
	}
}

function utmwebmaster_listblogs()
{
	unarchiveblog();
	// esc_html_e('UTM Webmaster Site Statistics', 'textdomain');
	echo "<script src='//cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js'></script>";
	// echo '<link rel="stylesheet" href="'.utm_webmaster_plugin_url.'style.css" type="text/css" media="all">';
	echo '<link rel="stylesheet" href="//cdn.datatables.net/1.13.1/css/jquery.dataTables.min.css" type="text/css" media="all">';
	global $wpdb;
	$total_sites_post_page = $i = 0;
	$blogquery = "SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` WHERE archived = 1 ORDER BY last_updated";
	$blogs = $wpdb->get_results($blogquery);
	if ($blogs) {
		$blogs_active = array();
		$blogs_archived = array();
		foreach ($blogs as $blog) {
			// count comments
			// set table prefix
			// if ($blog->blog_id == '1') {
			//     $table = $wpdb->base_prefix . 'comments';
			// } else {
			//     $table = $wpdb->base_prefix . $blog->blog_id . '_comments';
			// }
			// $sql = "SELECT comment_ID FROM " . $table;
			// $fivesdrafts = $wpdb->get_col($sql);
			// $blog->comments = count($fivesdrafts);
			// end count comments

			// count attachments
			// set table prefix
			// if ($blog->blog_id == '1') {
			//     $table = $wpdb->base_prefix . 'posts';
			// } else {
			//     $table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			// }

			// $sql = "SELECT ID FROM " . $table . " WHERE post_type='attachment'";
			// $fivesdrafts = $wpdb->get_col($sql);
			// $blog->attachments = count($fivesdrafts);
			// end count attachments

			// Set table prefix
			if ($blog->blog_id == '1') {
				$table = $wpdb->base_prefix . 'posts';
				continue;
			} else {
				$table = $wpdb->base_prefix . $blog->blog_id . '_posts';
			}
			
			// count post
			$sql = "SELECT ID FROM " . $table . " WHERE post_type='post'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->post = count($fivesdrafts);
			// end count post

			// count page
			$sql = "SELECT ID FROM " . $table . " WHERE post_type='page'";
			$fivesdrafts = $wpdb->get_col($sql);
			$blog->page = count($fivesdrafts);
			// end count page

			// get disk usage
			// Switch to a blog
			switch_to_blog($blog->blog_id);

			// blog title
			$blog->sitename = get_bloginfo('name');
			// blog admin email
			$blog->adminemail = get_bloginfo('admin_email');
			// blog URL
			$blog->url = get_bloginfo('url');

			// sum post + page
			$blog->postpage = $blog->post + $blog->page;
			$total_sites_post_page += $blog->postpage;

			// https://codex.wordpress.org/WPMU_Functions/get_blog_details
			// $blog->last_updated = date("Y-m-d", strtotime(get_blog_details($blog->blog_id)->last_updated));
			// $now = new DateTime();
			// $datediff = strtotime((string) $now) - strtotime($blog->last_updated);
			// $blog->daysdiff = round($datediff / (60 * 60 * 24));
			// if ($blog->daysdiff > 365) {
			// 	array_push($blogs_archived, $blog);
			// 	if (isset($_GET['archive'])) {
			// 		$wpdb->update(
			// 			$wpdb->blogs,
			// 			array(
			// 				'public' => 0,
			// 				'archived' => 1,
			// 			),
			// 			array(
			// 				'blog_id' => $blog->blog_id,
			// 			)
			// 		);
			// 	}
			// } else if ($blog->daysdiff < 365) {
			// 	array_push($blogs_active, $blog);
			// 	if (isset($_GET['publicactivesite'])) {
			// 		$wpdb->update(
			// 			$wpdb->blogs,
			// 			array(
			// 				'public' => 1,
			// 				'archived' => 0,
			// 			),
			// 			array(
			// 				'blog_id' => $blog->blog_id,
			// 			)
			// 		);
			// 	}
			// }
			$i++;
		} // close FOREACH
		switch_to_blog(1);
		echo "<br><a href='/blogs'>Active (" . count($blogs_active) . ")</a>";
		echo " | <a href='?archived'>Archived (" . count($blogs_archived) . ")</a>";

		if (isset($_GET['archived']) || isset($_GET['siteid'])) {
			$blogs = $blogs_archived;
		} else {
			$blogs = $blogs_active;
		}
		// echo "<br>All sites no. of pages: " . $total_sites_post_page . "<br>";

	} // close IF 
	?>
	<script>
		if (typeof jQuery == 'undefined') {
			var headTag = document.getElementsByTagName("head")[0];
			var jqTag = document.createElement('script');
			jqTag.type = 'text/javascript';
			jqTag.src = 'https://cdn.jsdelivr.net/npm/jquery@3.6.1/dist/jquery.min.js';
			jqTag.onload = myJQueryCode;
			headTag.appendChild(jqTag);
		}
		if ($ == undefined) {
			var $ = jQuery;
		}
		$(document).ready(function() {
			$('#sort').DataTable({
				"order": [
					[3, "desc"]
				]
			});
		});
	</script>

	<table id="sort" class="sort">
		<thead>
			<tr>
				<th class="sort-header">ID</th>
				<th class="sort-header">Name</th>
				<th class="sort-header">Admin Email</th>
				<th class="sort-header">No of Pages</th>
				<th class="sort-header">Last Updated</th>
			</tr>
		</thead>
		<tbody>
			<?php
			//   var_dump($blogs);
			foreach ($blogs as $row) {
				echo "<tr data-href='//" . $row->domain . $row->path . "'>
				<td>" . $row->blog_id . "</td>
				<td><a target='_blank' href='//" . $row->domain . $row->path . "'>" . $row->sitename . "</a></td>
				<td>" . $row->adminemail . "</td>
				<td>" . $row->postpage . "</td>
				<td>" . $row->last_updated . "</td> 
				</tr>";
			}
			?>
		</tbody>
	</table>
	<script>
		// $('#sort').on('click', 'tbody tr', function() {
		// 	window.location.href = $(this).data('href');
		// });
		$("tr").hover(function() {
			$(this).css("background-color", "#d9d9d9");
		}, function() {
			$(this).css("background-color", "white");
		});
	</script>
<?php
}
add_shortcode('utmwebmaster_listblogs', 'utmwebmaster_listblogs');
