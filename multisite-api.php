<?php
function my_api_custom_route_sites()
{
	$limit = $_GET['limit'];
	$offset = $_GET['offset'];
	if($limit < 1){
		$limit = 100;
	}
	if($offset < 1){
		$offset = 0;
	}
	
	$args = array(
		//'public'    => 1, // I only want the sites marked Public
		//'archived'  => 0,
		'mature'    => 0,
		'spam'      => 0,
		'deleted'   => 0,
		'number'	=> $limit,
		'offset'	=> $offset,
	);

	$sites = get_sites($args);
	foreach ($sites as $key => $site) {
		switch_to_blog($site->blog_id);
		// do something
		// blog admin email
		$sites[$key]->adminemail = get_bloginfo('admin_email');
		// $sites[$key]['blog_id'] = 10;
		// echo $key;
		// var_dump($site);
		restore_current_blog();
	}
	return $sites;
}

add_action('rest_api_init', function () {
	register_rest_route('wp/v2', 'sites', [
		'methods' => 'GET',
		'callback' => 'my_api_custom_route_sites'
	]);
});

// $blogAdminUsers = get_users('role=Administrator');
// foreach ($blogAdminUsers as $user) {
// 	$str .= '<span>' . esc_html($user->user_email) . ', </span>';
// }
// $str = rtrim($str, ", </span>");
// $str . +'</span>';
// echo '<td>' . $str . '</td>';
// $str = null;
