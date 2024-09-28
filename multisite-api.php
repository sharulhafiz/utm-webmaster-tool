<?php
// To fetch list of websites using https://people.utm.my/wp-json/wp/v2/sites?per_page=9999
function my_api_custom_route_sites()
{
    $per_page = isset($_GET['per_page']) && $_GET['per_page'] > 0 ? $_GET['per_page'] : 100;
    $page = isset($_GET['page']) && $_GET['page'] > 0 ? $_GET['page'] : 0;

    $args = array(
        'public'    => 1,
        'archived'  => 0,
        'mature'    => 0,
        'spam'      => 0,
        'deleted'   => 0,
        'number'    => $per_page,
        'offset'    => $page * $per_page,
        'fields'    => array('blog_id', 'domain', 'path') // fetch only necessary fields
    );

    $sites = get_sites($args);
    $result = array();

    // loop sites to get site ID and admin_email
    foreach ($sites as $site) {
        $site_id = $site->blog_id;
        $admin_email = get_blog_option($site_id, 'admin_email');

        $result[] = array(
            'blog_id' => $site_id,
            'domain' => $site->domain,
            'path' => $site->path,
            'admin_email' => $admin_email
        );
    }

    // return results as json
    return $result;

	// save results as json file in site root/cache/sites.json
	// $upload_dir = wp_upload_dir();
	// $cache_dir = $upload_dir['basedir'] . '/cache';
	// if (!file_exists($cache_dir)) {
	// 	mkdir($cache_dir, 0755, true);
	// }
	// $cache_file = $cache_dir . '/sites.json';
	// file_put_contents($cache_file, json_encode($result, JSON_PRETTY_PRINT));

	// return 'Sites list saved to ' . $cache_file;
}

function my_api_custom_route_site_admin_email($data)
{
	$site_id = $data['id'];
	return get_blog_option($site_id, 'admin_email');
}

function my_api_custom_route_users_sites()
{
    $users = get_users();
    $result = array();

    foreach ($users as $user) {
        $sites = get_blogs_of_user($user->ID);

        foreach ($sites as $site) {
			if ($site->userblog_id == 1) {
				continue; // skip site with ID 1
			}
			$site_id = $site->userblog_id;
			break; // per_page to one site ID
		}

        $result[] = array(
            'user_id' => $user->ID,
            'site_ids' => $site_id,
			'email' => $user->user_email,
        );
    }

    return $result;
}

add_action('rest_api_init', function () {
	register_rest_route('wp/v2', 'sites', [
		'methods' => 'GET',
		'callback' => 'my_api_custom_route_sites'
	]);

	register_rest_route('wp/v2', 'sites/(?P<id>\d+)/admin_email', [
        'methods' => 'GET',
        'callback' => 'my_api_custom_route_site_admin_email'
    ]);

	register_rest_route('wp/v2', 'users', [
        'methods' => 'GET',
        'callback' => 'my_api_custom_route_users_sites'
    ]);
});