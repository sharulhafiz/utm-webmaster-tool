<?php
function clean($string)
{
    if (empty($string)) {
        $string = "No title";
    }
    return trim($string); // Removes special chars.
}

function torque_hello_world_shortcode($atts)
{
    $a = shortcode_atts(array(
    'name' => 'world'
  ), $atts);
    // return "Hello ' . $a['name'] . !";
  $user_id = get_current_user_id(); // get ID
  $user_blogs = get_blogs_of_user($user_id);
    if (!is_user_logged_in()) {
        echo do_shortcode('[frm-login show_lost_password="1"]');
    } else if (count($user_blogs) == 1) {
        foreach ($user_blogs as $blog) {
            wp_redirect($blog->siteurl);
        }
    } else if (count($user_blogs) > 0) {
        foreach ($user_blogs as $blog) {
            echo "<a href='" . $blog->siteurl . "'> . $blog->siteurl . </a><br>";
        }
    }
    if (count($user_blogs) == 0) {
        // echo "<a href='".site_url('/wp-login.php?action=register')."'>Click here to register a site</a>";
        echo "You have no registered website. ";
        echo "<a href='".site_url('/register')."'>Click here to register a site</a>";
    }
}
add_shortcode('helloworld', 'torque_hello_world_shortcode');

function utmwebmaster_checkuserexist_shortcode($atts)
{
    $email = "";
    if (isset($_POST['submit'])) {
        $email = $_POST['email'];
        echo "This form will check if staff already registered and have a website. Please list out email address here:</b>";
    } ?>
  <form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
    <textarea name="email" rows="10" cols="30">
    <?php echo $email; ?>
    </textarea><br>
    <input type="submit" name="submit" value="Submit Form"><br>
  </form>
  <?php
  $email_array = explode(PHP_EOL, $email);
    $email_array = array_map('trim', $email_array);
    $email_array = array_filter($email_array);
    foreach ($email_array as $useremail) {
        $user = get_user_by('email', $useremail); // get ID
        if ($user != false) {
            $user_blogs = get_blogs_of_user($user->ID);
            if ($user_blogs==false) {
                $userblog = "User has registered but no website<br>";
                $icon = "⚠️ ";
            } else {
                $icon = "✅ ";
                foreach ($user_blogs as $blog) {
                    $userblog .= "<a href='$blog->siteurl'>$blog->siteurl</a><br>";
                }
            }
            echo $icon . $useremail . " - " . $userblog;
        } else {
            echo "❗️ " . $useremail . " - No user has been registered<br>";
        }
        $userblog = "";
    }
}
add_shortcode('utmwebmaster_checkuserexist', 'utmwebmaster_checkuserexist_shortcode');

function utmwebmaster_sitenouser_shortcode($atts)
{
    echo "<script src='".utm_webmaster_plugin_url."tablesort.min.js'></script>";
    echo '<link rel="stylesheet" href="'.utm_webmaster_plugin_url.'style.css" type="text/css" media="all">';
    global $wpdb;
    $blogs = $wpdb->get_results("SELECT * FROM `" . $wpdb->blogs . "` WHERE `deleted`!=1 ORDER BY blog_id DESC");
    $k = 0;
    if ($blogs) {
        $blogs_info = array();
        foreach ($blogs as $blog) {
            // count comments
            // set table prefix
            if ($blog->blog_id == '1') {
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
            $blog->usercount = $usercount['total_users'];


            // https://codex.wordpress.org/WPMU_Functions/get_blog_details
            $blog->last_updated = date("Y-m-d", strtotime(get_blog_details($blog->blog_id)->last_updated));

            if ($usercount['total_users']==0) {
                array_push($blogs_info, $blog);
                $k++;
            }
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
        echo "<br>Total sites without user: " . $k;
    } // close IF ?>

  <script>
  var blogs_info = <?php echo $blogs_info; ?>;
  console.log(blogs_info);
  $=jQuery;
  $(document).ready(function() {
    var each = '';
    for ( i=0; i < blogs_info.length; i++) {
      each = blogs_info[i];
      $("#sort tbody").append("<tr><td>"+each['blog_id']+"</td> <td><a target='_blank' href='http://"+each['domain']+each['path']+"'>"+each['domain']+each['path']+"</a> <a class='row-actions' target='_blank' href='/wp-admin/network/site-info.php?id="+each['blog_id']+"'>Edit</a></td> <td>"+each['comments']+"</td> <td>"+each['attachments']+"</td> <td>"+each['post']+"</td> <td>"+each['page']+"</td> <td>"+each['postpage']+"</td> <td>"+each['diskusage']+"</td><td>"+each['usercount']+"</td><td>"+each['last_updated']+"</td> </tr>");
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
add_shortcode('utmwebmaster_sitenouser', 'utmwebmaster_sitenouser_shortcode');