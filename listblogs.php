<?php
function unarchiveblog(){
	global $wpdb;
	if(isset($_GET['unarchive'])){
		$wpdb->update(
			$wpdb->blogs,
			array( 
				'public' => 0,
				'archived' => 0,
			),
			array(
				'blog_id' => $_GET['unarchive'],
			)
		);
	}
	echo $_GET['unarchive'] . " unarchived.";
	die();
}
function utmwebmaster_listblogs()
{
	unarchiveblog();
    // esc_html_e('UTM Webmaster Site Statistics', 'textdomain');
    echo "<script src='//cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js'></script>";
    // echo '<link rel="stylesheet" href="'.utm_webmaster_plugin_url.'style.css" type="text/css" media="all">';
    echo '<link rel="stylesheet" href="//cdn.datatables.net/1.10.22/css/jquery.dataTables.min.css" type="text/css" media="all">';
	global $wpdb;
	$blogquery = "SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "`";
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

            // count post
            if ($blog->blog_id == '1') {
                $table = $wpdb->base_prefix . 'posts';
            	continue; 
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

            // blog title
            $blog->sitename = get_bloginfo('name');
            // blog admin email
            $blog->adminemail = get_bloginfo('admin_email');
            // blog URL
            $blog->url = get_bloginfo('url');

            // sum post + page
            $blog->postpage = $blog->post + $blog->page;
            @$total_sites_post_page += $blog->postpage;

            // https://codex.wordpress.org/WPMU_Functions/get_blog_details
            $blog->last_updated = date("Y-m-d", strtotime(get_blog_details($blog->blog_id)->last_updated));
			$datediff = $now - strtotime($blog->last_updated);
          	$blog->daysdiff = round($datediff / (60 * 60 * 24));
            if ($blog->daysdiff > 365) {
				array_push($blogs_archived, $blog);
				if(isset($_GET['archive'])){
					$wpdb->update(
						$wpdb->blogs,
						array( 
							'public' => 0,
							'archived' => 1,
						),
						array(
							'blog_id' => $blog->blog_id,
						)
					);
				}
				
            } else if ($blog->daysdiff < 365) {
				array_push($blogs_active, $blog);
				if(isset($_GET['publicactivesite'])){
					$wpdb->update(
						$wpdb->blogs,
						array( 
							'public' => 1,
							'archived' => 0,
						),
						array(
							'blog_id' => $blog->blog_id,
						)
					);
				}
			}
			$i++;
		} // close FOREACH
        echo "<br><a href='/blogs'>Active (" . count($blogs_active) . ")</a>";
		echo " | <a href='?archived'>Archived (" . count($blogs_archived) . ")</a>";
		
		if(isset($_GET['archived'])){
			$blogs = $blogs_archived;
		} else {
			$blogs = $blogs_active;
		}
		// echo "<br>All sites no. of pages: " . $total_sites_post_page . "<br>";
	
    } // close IF ?>

	<script>
	$=jQuery;
	$(document).ready(function() {
		var each = '';
		$(document).ready( function () {
			$('#sort').DataTable( {
				"order": [[ 3, "desc" ]]
			} );
		} );
	});
	</script>

	<table id="sort" class="sort">
	  <thead>
	    <tr>
	  		<th class="sort-header">Name</th>
	  		<th class="sort-header">Admin Email</th>
	  		<th class="sort-header">No of Pages</th>
	  		<th class="sort-header">Last Updated</th>
	    </tr>
	  </thead>
	  <tbody>
	  <?php
	//   var_dump($blogs);
		foreach($blogs as $row){
			echo "<tr data-href='//".$row->domain.$row->path."'>
				<td><a target='_blank' href='//".$row->domain.$row->path."'>".$row->sitename."</a></td>
				<td>".$row->adminemail."</td>
				<td>".$row->postpage."</td>
				<td>".$row->last_updated."</td> 
				</tr>";
		}
	  ?>
	  </tbody>
	</table>
	<script>
	$('#sort').on('click', 'tbody tr', function() {
		window.location.href = $(this).data('href');
		});
	$("tr").hover(function(){
		$(this).css("background-color", "#d9d9d9");
		}, function(){
		$(this).css("background-color", "white");
	});
	</script>
<?php
}
add_shortcode('utmwebmaster_listblogs', 'utmwebmaster_listblogs');