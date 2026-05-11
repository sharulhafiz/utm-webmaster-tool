<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UTM News Import Module (Phase 1 Restructure)
 *
 * - Import is managed from WordPress Admin (department checkboxes)
 * - Import runs hourly in background via WP-Cron
 * - Shortcode remains for display only
 * - Display style is selected from admin screen: basic_list or card
 */

define('UTM_NEWS_SOURCE_HOST', 'news.utm.my');
define('UTM_NEWS_SOURCE_POSTS_ENDPOINT', 'https://news.utm.my/wp-json/wp/v2/posts');
define('UTM_NEWS_SOURCE_DEPT_ENDPOINT', 'https://news.utm.my/wp-json/wp/v2/department');
define('UTM_NEWS_SOURCE_MEDIA_ENDPOINT', 'https://news.utm.my/wp-json/wp/v2/media/');
define('UTM_NEWS_SOURCE_CAT_ENDPOINT', 'https://news.utm.my/wp-json/wp/v2/categories');
define('UTM_NEWS_SETTINGS_OPTION', 'utm_news_import_settings');
define('UTM_NEWS_DEPT_CACHE_OPTION', 'utm_news_department_cache');
define('UTM_NEWS_CAT_CACHE_OPTION', 'utm_news_category_cache');
define('UTM_NEWS_CRON_HOOK', 'utm_news_hourly_import_hook');

/**
 * Return true when current site is the source site news.utm.my.
 * On source site, importer is intentionally disabled.
 *
 * @return bool
 */
function utm_news_is_source_site() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (empty($host)) {
        return false;
    }

    return strtolower($host) === UTM_NEWS_SOURCE_HOST;
}

/**
 * Default settings.
 *
 * @return array
 */
function utm_news_get_default_settings() {
    return array(
        'selected_departments' => array(),
        'selected_categories' => array(),
        'display_style' => 'basic_list',
        'import_count' => 5,
        'retention_mode' => 'latest_only',
        'keep_latest_count' => 10,
        'redirect_users_to_source' => 1,
        'posts_per_page' => 5,
        'target' => '_blank',
    );
}

/**
 * Get merged settings.
 *
 * @return array
 */
function utm_news_get_settings() {
    $settings = get_option(UTM_NEWS_SETTINGS_OPTION, array());
    if (!is_array($settings)) {
        $settings = array();
    }

    $settings = wp_parse_args($settings, utm_news_get_default_settings());

    $settings['selected_departments'] = array_values(array_filter(array_map('sanitize_text_field', (array) $settings['selected_departments'])));
    $settings['selected_categories'] = array_values(array_filter(array_map('sanitize_text_field', (array) $settings['selected_categories'])));
    $settings['display_style'] = in_array($settings['display_style'], array('basic_list', 'card'), true) ? $settings['display_style'] : 'basic_list';
    $settings['import_count'] = max(1, (int) $settings['import_count']);
    $settings['retention_mode'] = in_array($settings['retention_mode'], array('latest_only', 'permanent'), true) ? $settings['retention_mode'] : 'latest_only';
    $settings['keep_latest_count'] = max(1, (int) $settings['keep_latest_count']);
    $settings['redirect_users_to_source'] = !empty($settings['redirect_users_to_source']) ? 1 : 0;
    $settings['posts_per_page'] = max(1, (int) $settings['posts_per_page']);
    $settings['target'] = in_array($settings['target'], array('_blank', '_self'), true) ? $settings['target'] : '_blank';

    return $settings;
}

/**
 * Save settings.
 *
 * @param array $settings Settings.
 * @return void
 */
function utm_news_import_save_settings($settings) {
    update_option(UTM_NEWS_SETTINGS_OPTION, $settings);
}

/**
 * Fetch available departments from source via REST API.
 *
 * @return array|WP_Error
 */
function utm_news_fetch_departments_from_source() {
    $all_departments = array();
    $per_page = 100;

    for ($page = 1; $page <= 10; $page++) {
        $url = add_query_arg(
            array(
                'per_page' => $per_page,
                'page' => $page,
                'orderby' => 'name',
                'order' => 'asc',
            ),
            UTM_NEWS_SOURCE_DEPT_ENDPOINT
        );

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('utm_news_dept_fetch_failed', 'Failed to fetch departments: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('utm_news_dept_bad_http', 'Failed to fetch departments (HTTP ' . $code . ').');
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items)) {
            return new WP_Error('utm_news_dept_bad_json', 'Invalid department response format from source.');
        }

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }

            $slug = !empty($item['slug']) ? sanitize_title($item['slug']) : '';
            $id = isset($item['id']) ? (string) (int) $item['id'] : '';
            $value = $slug !== '' ? $slug : $id;

            if ($value === '') {
                continue;
            }

            $all_departments[$value] = array(
                'value' => $value,
                'id' => $id,
                'slug' => $slug,
                'name' => sanitize_text_field($item['name']),
            );
        }

        if (count($items) < $per_page) {
            break;
        }
    }

    if (empty($all_departments)) {
        return new WP_Error('utm_news_dept_empty', 'No departments returned from source endpoint.');
    }

    uasort($all_departments, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    return array_values($all_departments);
}

/**
 * Get department cache. Optionally force refresh from source.
 *
 * @param bool $force_refresh Force refresh.
 * @return array|WP_Error
 */
function utm_news_get_departments($force_refresh = false) {
    if (!$force_refresh) {
        $cache = get_option(UTM_NEWS_DEPT_CACHE_OPTION, array());
        if (is_array($cache) && !empty($cache['departments']) && is_array($cache['departments'])) {
            return $cache['departments'];
        }
    }

    $departments = utm_news_fetch_departments_from_source();
    if (is_wp_error($departments)) {
        return $departments;
    }

    update_option(UTM_NEWS_DEPT_CACHE_OPTION, array(
        'departments' => $departments,
        'updated_at' => current_time('mysql'),
    ));

    return $departments;
}

/**
 * Fetch available categories from source via REST API.
 *
 * @return array|WP_Error
 */
function utm_news_fetch_categories_from_source() {
    $all_categories = array();
    $per_page = 100;

    for ($page = 1; $page <= 10; $page++) {
        $url = add_query_arg(
            array(
                'per_page' => $per_page,
                'page' => $page,
                'orderby' => 'name',
                'order' => 'asc',
            ),
            UTM_NEWS_SOURCE_CAT_ENDPOINT
        );

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('utm_news_cat_fetch_failed', 'Failed to fetch categories: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('utm_news_cat_bad_http', 'Failed to fetch categories (HTTP ' . $code . ').');
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items)) {
            return new WP_Error('utm_news_cat_bad_json', 'Invalid category response format from source.');
        }

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }

            $slug = !empty($item['slug']) ? sanitize_title($item['slug']) : '';
            $id = isset($item['id']) ? (string) (int) $item['id'] : '';
            $value = $slug !== '' ? $slug : $id;

            if ($value === '') {
                continue;
            }

            $all_categories[$value] = array(
                'value' => $value,
                'id' => $id,
                'slug' => $slug,
                'name' => sanitize_text_field($item['name']),
            );
        }

        if (count($items) < $per_page) {
            break;
        }
    }

    if (empty($all_categories)) {
        return new WP_Error('utm_news_cat_empty', 'No categories returned from source endpoint.');
    }

    uasort($all_categories, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    return array_values($all_categories);
}

/**
 * Get category cache. Optionally force refresh from source.
 *
 * @param bool $force_refresh Force refresh.
 * @return array|WP_Error
 */
function utm_news_get_categories($force_refresh = false) {
    if (!$force_refresh) {
        $cache = get_option(UTM_NEWS_CAT_CACHE_OPTION, array());
        if (is_array($cache) && !empty($cache['categories']) && is_array($cache['categories'])) {
            return $cache['categories'];
        }
    }

    $categories = utm_news_fetch_categories_from_source();
    if (is_wp_error($categories)) {
        return $categories;
    }

    update_option(UTM_NEWS_CAT_CACHE_OPTION, array(
        'categories' => $categories,
        'updated_at' => current_time('mysql'),
    ));

    return $categories;
}

/**
 * Resolve department slug to numeric ID.
 *
 * @param string $slug_or_id Department identifier.
 * @return string
 */
function resolve_department_slug_to_id($slug_or_id) {
    if (is_numeric($slug_or_id)) {
        return (string) $slug_or_id;
    }

    $dept_url = add_query_arg(array('slug' => $slug_or_id), UTM_NEWS_SOURCE_DEPT_ENDPOINT);
    $dept_response = wp_remote_get($dept_url, array('timeout' => 10));

    if (is_wp_error($dept_response)) {
        error_log('UTM News Department Resolve Error: ' . $dept_response->get_error_message());
        return (string) $slug_or_id;
    }

    $departments = json_decode(wp_remote_retrieve_body($dept_response), true);
    if (!empty($departments) && is_array($departments) && isset($departments[0]['id'])) {
        return (string) (int) $departments[0]['id'];
    }

    return (string) $slug_or_id;
}

/**
 * Resolve category slug to numeric ID.
 *
 * @param string $slug_or_id Category identifier.
 * @return string
 */
function resolve_category_slug_to_id($slug_or_id) {
    if (is_numeric($slug_or_id)) {
        return (string) $slug_or_id;
    }

    $cat_url = add_query_arg(array('slug' => $slug_or_id), UTM_NEWS_SOURCE_CAT_ENDPOINT);
    $cat_response = wp_remote_get($cat_url, array('timeout' => 10));

    if (is_wp_error($cat_response)) {
        error_log('UTM News Category Resolve Error: ' . $cat_response->get_error_message());
        return (string) $slug_or_id;
    }

    $categories = json_decode(wp_remote_retrieve_body($cat_response), true);
    if (!empty($categories) && is_array($categories) && isset($categories[0]['id'])) {
        return (string) (int) $categories[0]['id'];
    }

    return (string) $slug_or_id;
}

/**
 * Fetch remote category IDs into map of id => name.
 *
 * @param array $category_ids Category IDs.
 * @return array
 */
function utm_news_fetch_remote_category_map($category_ids) {
    $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $category_ids))));
    if (empty($category_ids)) {
        return array();
    }

    $cache_key = 'utm_news_remote_cat_map_' . md5(wp_json_encode($category_ids));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(add_query_arg(array(
        'include' => implode(',', $category_ids),
        'per_page' => count($category_ids),
    ), UTM_NEWS_SOURCE_CAT_ENDPOINT), array(
        'timeout' => 15,
        'sslverify' => true,
    ));

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return array();
    }

    $items = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($items)) {
        return array();
    }

    $map = array();
    foreach ($items as $item) {
        if (!isset($item['id']) || empty($item['name'])) {
            continue;
        }
        $map[(int) $item['id']] = sanitize_text_field($item['name']);
    }

    set_transient($cache_key, $map, HOUR_IN_SECONDS);
    return $map;
}

/**
 * Ensure local categories based on remote category IDs and return local term IDs.
 *
 * @param array $remote_category_ids Remote category IDs.
 * @return array
 */
function utm_news_map_remote_categories_to_local($remote_category_ids) {
    $remote_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $remote_category_ids))));
    if (empty($remote_category_ids)) {
        return array();
    }

    $remote_map = utm_news_fetch_remote_category_map($remote_category_ids);
    if (empty($remote_map)) {
        return array();
    }

    $local_term_ids = array();

    foreach ($remote_category_ids as $remote_id) {
        if (empty($remote_map[$remote_id])) {
            continue;
        }

        $name = $remote_map[$remote_id];
        $slug = sanitize_title($name);
        $term = get_term_by('slug', $slug, 'category');

        if (!$term) {
            $created = wp_insert_term($name, 'category', array('slug' => $slug));
            if (is_wp_error($created) || empty($created['term_id'])) {
                continue;
            }
            $term = get_term_by('id', (int) $created['term_id'], 'category');
        }

        if ($term && !is_wp_error($term) && !empty($term->term_id)) {
            $local_term_ids[] = (int) $term->term_id;
        }
    }

    return array_values(array_unique($local_term_ids));
}

/**
 * Import featured image from source media endpoint.
 *
 * @param int $post_id Local post ID.
 * @param int $featured_media_id Remote media ID.
 * @param int $original_post_id Remote post ID.
 * @return int|false
 */
function import_featured_image_from_url($post_id, $featured_media_id, $original_post_id) {
    $media_url = UTM_NEWS_SOURCE_MEDIA_ENDPOINT . (int) $featured_media_id;
    $media_response = wp_remote_get($media_url, array('timeout' => 15));

    if (is_wp_error($media_response)) {
        return false;
    }

    $media_data = json_decode(wp_remote_retrieve_body($media_response), true);
    if (empty($media_data['source_url'])) {
        return false;
    }

    $image_url = $media_data['source_url'];

    $existing_attachment = get_posts(array(
        'post_type' => 'attachment',
        'meta_key' => 'utm_news_original_media_id',
        'meta_value' => $featured_media_id,
        'posts_per_page' => 1,
    ));

    if (!empty($existing_attachment)) {
        set_post_thumbnail($post_id, $existing_attachment[0]->ID);
        return (int) $existing_attachment[0]->ID;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return false;
    }

    $file_array = array(
        'name' => basename($image_url),
        'tmp_name' => $tmp,
    );

    $title = !empty($media_data['title']['rendered']) ? wp_strip_all_tags($media_data['title']['rendered']) : '';
    $attachment_id = media_handle_sideload($file_array, $post_id, $title);

    if (is_wp_error($attachment_id)) {
        @unlink($file_array['tmp_name']);
        return false;
    }

    update_post_meta($attachment_id, 'utm_news_original_media_id', (int) $featured_media_id);
    update_post_meta($attachment_id, 'utm_news_original_post_id', (int) $original_post_id);

    set_post_thumbnail($post_id, $attachment_id);
    return (int) $attachment_id;
}

/**
 * Cleanup old imported posts per department.
 *
 * @param string $department_identifier Department slug/id.
 * @param int    $keep_count Number to keep (+buffer).
 * @return void
 */
function cleanup_old_imported_posts($department_identifier, $keep_count = 5) {
    $keep_with_buffer = max(1, (int) $keep_count) + 2;

    $all_post_ids = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => 'utm_news_department_id',
                'value' => (string) $department_identifier,
                'compare' => '=',
            ),
        ),
    ));

    if (count($all_post_ids) <= $keep_with_buffer) {
        return;
    }

    $posts_to_trash = array_slice($all_post_ids, $keep_with_buffer);
    foreach ($posts_to_trash as $post_id) {
        wp_trash_post($post_id);
    }
}

/**
 * Cleanup old imported posts per category.
 *
 * @param string $category_identifier Category slug/id.
 * @param int    $keep_count Number to keep (+buffer).
 * @return void
 */
function cleanup_old_imported_posts_by_category($category_identifier, $keep_count = 5) {
    $keep_with_buffer = max(1, (int) $keep_count) + 2;

    $all_post_ids = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => 'utm_news_category_id',
                'value' => (string) $category_identifier,
                'compare' => '=',
            ),
        ),
    ));

    if (count($all_post_ids) <= $keep_with_buffer) {
        return;
    }

    $posts_to_trash = array_slice($all_post_ids, $keep_with_buffer);
    foreach ($posts_to_trash as $post_id) {
        wp_trash_post($post_id);
    }
}

/**
 * Import posts by one department.
 *
 * @param string $department_identifier Department slug or ID.
 * @param int    $import_count Number of posts.
 * @return bool
 */
function import_utm_news_posts_flexible($department_identifier = '', $import_count = 5) {
    if (empty($department_identifier)) {
        return false;
    }

    $resolved_department_id = resolve_department_slug_to_id($department_identifier);
    $remote_per_page = max(1, (int) $import_count);

    $transient_key = 'utm_news_dept_' . sanitize_key($department_identifier) . '_count_' . (int) $remote_per_page;
    if (get_transient($transient_key) !== false) {
        return true;
    }

    $response = wp_remote_get(add_query_arg(array(
        'per_page' => $remote_per_page,
        'department' => $resolved_department_id,
        '_embed' => 1,
    ), UTM_NEWS_SOURCE_POSTS_ENDPOINT), array(
        'timeout' => 20,
        'sslverify' => true,
    ));

    if (is_wp_error($response)) {
        error_log('UTM News Import Error: ' . $response->get_error_message());
        return false;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('UTM News Import Error: HTTP ' . $response_code . ' for department ' . $department_identifier);
        return false;
    }

    $posts = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($posts) || !is_array($posts)) {
        return false;
    }

    $imported_count = 0;

    foreach ($posts as $post) {
        $remote_id = isset($post['id']) ? (int) $post['id'] : 0;
        $remote_url = isset($post['link']) ? esc_url_raw($post['link']) : '';
        $remote_title = isset($post['title']['rendered']) ? wp_strip_all_tags($post['title']['rendered']) : '';
        $remote_content = isset($post['content']['rendered']) ? $post['content']['rendered'] : '';
        $remote_date = isset($post['date']) ? $post['date'] : current_time('mysql');
        $remote_modified = isset($post['modified_gmt']) ? $post['modified_gmt'] : '';
        $remote_excerpt = isset($post['excerpt']['rendered']) ? wp_strip_all_tags($post['excerpt']['rendered']) : '';
        $remote_categories = isset($post['categories']) ? (array) $post['categories'] : array();

        if (!$remote_id || empty($remote_url) || empty($remote_title)) {
            continue;
        }

        $existing_post = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'utm_news_original_id',
                    'value' => $remote_id,
                    'compare' => '=',
                ),
                array(
                    'key' => 'utm_news_original_url',
                    'value' => $remote_url,
                    'compare' => '=',
                ),
            ),
        ));

        $local_category_ids = utm_news_map_remote_categories_to_local($remote_categories);
        if (empty($local_category_ids)) {
            $fallback = get_term_by('slug', 'news', 'category');
            if (!$fallback) {
                $created = wp_insert_term('News', 'category', array('slug' => 'news'));
                if (!is_wp_error($created) && !empty($created['term_id'])) {
                    $fallback = get_term_by('id', (int) $created['term_id'], 'category');
                }
            }
            if ($fallback && !is_wp_error($fallback)) {
                $local_category_ids = array((int) $fallback->term_id);
            }
        }

        if (!empty($existing_post)) {
            $local = $existing_post[0];
            $local_modified = !empty($local->post_modified_gmt) ? $local->post_modified_gmt : $local->post_modified;

            if (empty($remote_modified) || strtotime($remote_modified) > strtotime($local_modified)) {
                wp_update_post(array(
                    'ID' => $local->ID,
                    'post_title' => $remote_title,
                    'post_content' => $remote_content,
                    'post_excerpt' => $remote_excerpt,
                    'post_date' => $remote_date,
                    'post_status' => 'publish',
                ));

                if (!empty($local_category_ids)) {
                    wp_set_post_categories($local->ID, $local_category_ids);
                }

                if (!empty($post['featured_media'])) {
                    $stored_media_id = (int) get_post_meta($local->ID, 'utm_news_original_media_id', true);
                    if ($stored_media_id !== (int) $post['featured_media']) {
                        import_featured_image_from_url($local->ID, (int) $post['featured_media'], $remote_id);
                    }
                }

                update_post_meta($local->ID, 'utm_news_remote_modified', $remote_modified);
                update_post_meta($local->ID, 'utm_news_original_url', $remote_url);
                update_post_meta($local->ID, 'utm_news_department_id', (string) $department_identifier);
                $imported_count++;
            }

            continue;
        }

        $post_id = wp_insert_post(array(
            'post_title' => $remote_title,
            'post_content' => $remote_content,
            'post_excerpt' => $remote_excerpt,
            'post_status' => 'publish',
            'post_date' => $remote_date,
            'post_author' => 1,
            'post_category' => $local_category_ids,
        ));

        if ($post_id && !is_wp_error($post_id)) {
            if (!empty($post['featured_media'])) {
                import_featured_image_from_url($post_id, (int) $post['featured_media'], $remote_id);
            }

            update_post_meta($post_id, 'utm_news_original_id', $remote_id);
            update_post_meta($post_id, 'utm_news_original_url', $remote_url);
            update_post_meta($post_id, 'utm_news_department_id', (string) $department_identifier);
            update_post_meta($post_id, 'utm_news_imported_date', current_time('mysql'));
            update_post_meta($post_id, 'utm_news_remote_modified', $remote_modified);

            $imported_count++;
        }
    }

    set_transient($transient_key, array(
        'last_import' => current_time('mysql'),
        'imported_count' => $imported_count,
    ), HOUR_IN_SECONDS);

    error_log('UTM News Import: Imported/updated ' . $imported_count . ' post(s) for department ' . $department_identifier);
    return true;
}

/**
 * Import posts by one category.
 *
 * @param string $category_identifier Category slug or ID.
 * @param int    $import_count Number of posts.
 * @return bool
 */
function import_utm_news_posts_by_category_flexible($category_identifier = '', $import_count = 5) {
    if (empty($category_identifier)) {
        return false;
    }

    $resolved_category_id = resolve_category_slug_to_id($category_identifier);
    $remote_per_page = max(1, (int) $import_count);

    $transient_key = 'utm_news_cat_' . sanitize_key($category_identifier) . '_count_' . (int) $remote_per_page;
    if (get_transient($transient_key) !== false) {
        return true;
    }

    $response = wp_remote_get(add_query_arg(array(
        'per_page' => $remote_per_page,
        'categories' => $resolved_category_id,
        '_embed' => 1,
    ), UTM_NEWS_SOURCE_POSTS_ENDPOINT), array(
        'timeout' => 20,
        'sslverify' => true,
    ));

    if (is_wp_error($response)) {
        error_log('UTM News Category Import Error: ' . $response->get_error_message());
        return false;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('UTM News Category Import Error: HTTP ' . $response_code . ' for category ' . $category_identifier);
        return false;
    }

    $posts = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($posts) || !is_array($posts)) {
        return false;
    }

    $imported_count = 0;

    foreach ($posts as $post) {
        $remote_id = isset($post['id']) ? (int) $post['id'] : 0;
        $remote_url = isset($post['link']) ? esc_url_raw($post['link']) : '';
        $remote_title = isset($post['title']['rendered']) ? wp_strip_all_tags($post['title']['rendered']) : '';
        $remote_content = isset($post['content']['rendered']) ? $post['content']['rendered'] : '';
        $remote_date = isset($post['date']) ? $post['date'] : current_time('mysql');
        $remote_modified = isset($post['modified_gmt']) ? $post['modified_gmt'] : '';
        $remote_excerpt = isset($post['excerpt']['rendered']) ? wp_strip_all_tags($post['excerpt']['rendered']) : '';
        $remote_categories = isset($post['categories']) ? (array) $post['categories'] : array();

        if (!$remote_id || empty($remote_url) || empty($remote_title)) {
            continue;
        }

        $existing_post = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'utm_news_original_id',
                    'value' => $remote_id,
                    'compare' => '=',
                ),
                array(
                    'key' => 'utm_news_original_url',
                    'value' => $remote_url,
                    'compare' => '=',
                ),
            ),
        ));

        $local_category_ids = utm_news_map_remote_categories_to_local($remote_categories);
        if (empty($local_category_ids)) {
            $fallback = get_term_by('slug', 'news', 'category');
            if (!$fallback) {
                $created = wp_insert_term('News', 'category', array('slug' => 'news'));
                if (!is_wp_error($created) && !empty($created['term_id'])) {
                    $fallback = get_term_by('id', (int) $created['term_id'], 'category');
                }
            }
            if ($fallback && !is_wp_error($fallback)) {
                $local_category_ids = array((int) $fallback->term_id);
            }
        }

        if (!empty($existing_post)) {
            $local = $existing_post[0];
            $local_modified = !empty($local->post_modified_gmt) ? $local->post_modified_gmt : $local->post_modified;

            if (empty($remote_modified) || strtotime($remote_modified) > strtotime($local_modified)) {
                wp_update_post(array(
                    'ID' => $local->ID,
                    'post_title' => $remote_title,
                    'post_content' => $remote_content,
                    'post_excerpt' => $remote_excerpt,
                    'post_date' => $remote_date,
                    'post_status' => 'publish',
                ));

                if (!empty($local_category_ids)) {
                    wp_set_post_categories($local->ID, $local_category_ids);
                }

                if (!empty($post['featured_media'])) {
                    $stored_media_id = (int) get_post_meta($local->ID, 'utm_news_original_media_id', true);
                    if ($stored_media_id !== (int) $post['featured_media']) {
                        import_featured_image_from_url($local->ID, (int) $post['featured_media'], $remote_id);
                    }
                }

                update_post_meta($local->ID, 'utm_news_remote_modified', $remote_modified);
                update_post_meta($local->ID, 'utm_news_original_url', $remote_url);
                update_post_meta($local->ID, 'utm_news_category_id', (string) $category_identifier);
                $imported_count++;
            }

            continue;
        }

        $post_id = wp_insert_post(array(
            'post_title' => $remote_title,
            'post_content' => $remote_content,
            'post_excerpt' => $remote_excerpt,
            'post_status' => 'publish',
            'post_date' => $remote_date,
            'post_author' => 1,
            'post_category' => $local_category_ids,
        ));

        if ($post_id && !is_wp_error($post_id)) {
            if (!empty($post['featured_media'])) {
                import_featured_image_from_url($post_id, (int) $post['featured_media'], $remote_id);
            }

            update_post_meta($post_id, 'utm_news_original_id', $remote_id);
            update_post_meta($post_id, 'utm_news_original_url', $remote_url);
            update_post_meta($post_id, 'utm_news_category_id', (string) $category_identifier);
            update_post_meta($post_id, 'utm_news_imported_date', current_time('mysql'));
            update_post_meta($post_id, 'utm_news_remote_modified', $remote_modified);

            $imported_count++;
        }
    }

    set_transient($transient_key, array(
        'last_import' => current_time('mysql'),
        'imported_count' => $imported_count,
    ), HOUR_IN_SECONDS);

    error_log('UTM News Import: Imported/updated ' . $imported_count . ' post(s) for category ' . $category_identifier);
    return true;
}

/**
 * Schedule cron if not already scheduled.
 *
 * @return void
 */
function utm_news_maybe_schedule_cron() {
    if (!wp_next_scheduled(UTM_NEWS_CRON_HOOK)) {
        wp_schedule_event(time() + 300, 'hourly', UTM_NEWS_CRON_HOOK);
    }
}
add_action('init', 'utm_news_maybe_schedule_cron');

/**
 * Hourly cron worker.
 *
 * @return void
 */
function utm_news_run_hourly_import() {
    if (utm_news_is_source_site()) {
        error_log('UTM News Import: Skipped on source site news.utm.my');
        return;
    }

    if (get_transient('utm_news_import_lock')) {
        return;
    }

    set_transient('utm_news_import_lock', 1, 15 * MINUTE_IN_SECONDS);

    $settings = utm_news_get_settings();
    $selected = (array) $settings['selected_departments'];
    $selected_categories = (array) $settings['selected_categories'];
    $import_count = (int) $settings['import_count'];
    $retention_mode = $settings['retention_mode'];
    $keep_latest_count = max(1, (int) $settings['keep_latest_count']);

    if (!empty($selected)) {
        foreach ($selected as $department_identifier) {
            import_utm_news_posts_flexible($department_identifier, $import_count);

            if ($retention_mode === 'latest_only') {
                cleanup_old_imported_posts($department_identifier, $keep_latest_count);
            }
        }
    }

    if (!empty($selected_categories)) {
        foreach ($selected_categories as $category_identifier) {
            import_utm_news_posts_by_category_flexible($category_identifier, $import_count);

            if ($retention_mode === 'latest_only') {
                cleanup_old_imported_posts_by_category($category_identifier, $keep_latest_count);
            }
        }
    }

    update_option('utm_news_last_hourly_import', current_time('mysql'));
    delete_transient('utm_news_import_lock');
}
add_action(UTM_NEWS_CRON_HOOK, 'utm_news_run_hourly_import');

/**
 * Register admin menu.
 *
 * @return void
 */
function utm_news_register_admin_menu() {
    add_submenu_page(
        'edit.php',
        'UTM News Import',
        'UTM News Import',
        'manage_options',
        'utm-news-import-settings',
        'utm_news_render_admin_page'
    );
}
add_action('admin_menu', 'utm_news_register_admin_menu');

/**
 * Save settings handler.
 *
 * @return void
 */
function utm_news_handle_settings_save() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('utm_news_save_settings');

    $settings = utm_news_get_settings();

    $selected_departments = isset($_POST['selected_departments']) ? (array) $_POST['selected_departments'] : array();
    $settings['selected_departments'] = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_departments))));

    $selected_categories = isset($_POST['selected_categories']) ? (array) $_POST['selected_categories'] : array();
    $settings['selected_categories'] = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_categories))));

    $display_style = isset($_POST['display_style']) ? sanitize_text_field($_POST['display_style']) : 'basic_list';
    $settings['display_style'] = in_array($display_style, array('basic_list', 'card'), true) ? $display_style : 'basic_list';

    $settings['import_count'] = isset($_POST['import_count']) ? max(1, (int) $_POST['import_count']) : 5;
    $retention_mode = isset($_POST['retention_mode']) ? sanitize_text_field($_POST['retention_mode']) : 'latest_only';
    $settings['retention_mode'] = in_array($retention_mode, array('latest_only', 'permanent'), true) ? $retention_mode : 'latest_only';
    $settings['keep_latest_count'] = isset($_POST['keep_latest_count']) ? max(1, (int) $_POST['keep_latest_count']) : 10;
    $settings['redirect_users_to_source'] = isset($_POST['redirect_users_to_source']) ? 1 : 0;
    $settings['posts_per_page'] = isset($_POST['posts_per_page']) ? max(1, (int) $_POST['posts_per_page']) : 5;

    $target = isset($_POST['target']) ? sanitize_text_field($_POST['target']) : '_blank';
    $settings['target'] = in_array($target, array('_blank', '_self'), true) ? $target : '_blank';

    utm_news_import_save_settings($settings);

    wp_redirect(add_query_arg(array(
        'page' => 'utm-news-import-settings',
        'utm_news_saved' => 1,
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_utm_news_save_settings', 'utm_news_handle_settings_save');

/**
 * Refresh department list handler.
 *
 * @return void
 */
function utm_news_handle_refresh_departments() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('utm_news_refresh_departments');

    $result = utm_news_get_departments(true);
    $ok = !is_wp_error($result) ? 1 : 0;

    wp_redirect(add_query_arg(array(
        'page' => 'utm-news-import-settings',
        'utm_news_refresh' => $ok,
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_utm_news_refresh_departments', 'utm_news_handle_refresh_departments');

/**
 * Refresh category list handler.
 *
 * @return void
 */
function utm_news_handle_refresh_categories() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('utm_news_refresh_categories');

    $result = utm_news_get_categories(true);
    $ok = !is_wp_error($result) ? 1 : 0;

    wp_redirect(add_query_arg(array(
        'page' => 'utm-news-import-settings',
        'utm_news_cat_refresh' => $ok,
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_utm_news_refresh_categories', 'utm_news_handle_refresh_categories');

/**
 * Manual run handler.
 *
 * @return void
 */
function utm_news_handle_run_now() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('utm_news_run_now');

    if (!utm_news_is_source_site()) {
        utm_news_run_hourly_import();
        $result = 1;
    } else {
        $result = 0;
    }

    wp_redirect(add_query_arg(array(
        'page' => 'utm-news-import-settings',
        'utm_news_run_now' => $result,
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_utm_news_run_now', 'utm_news_handle_run_now');

/**
 * Render helper warning for department endpoint fallback requirement.
 *
 * @return string
 */
function utm_news_get_helper_function_prompt_html() {
    $example = '{"departments":[{"id":3058,"slug":"school-of-professional-continuing-education","name":"School of Professional and Continuing Education"}]}';

    return '<div class="notice notice-warning"><p><strong>Department list fetch failed.</strong> Unable to fetch departments from <code>' . esc_html(UTM_NEWS_SOURCE_DEPT_ENDPOINT) . '</code>. If this endpoint is unavailable/restricted, please create a helper endpoint on news.utm.my that returns department list JSON (id, slug, name), then we will integrate it in this module.</p><p><em>Expected response shape:</em> <code>' . esc_html($example) . '</code></p></div>';
}

/**
 * Render admin settings page.
 *
 * @return void
 */
function utm_news_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = utm_news_get_settings();
    $selected_departments = (array) $settings['selected_departments'];
    $selected_categories = (array) $settings['selected_categories'];
    $departments = utm_news_get_departments(false);
    $categories = utm_news_get_categories(false);
    $dept_cache = get_option(UTM_NEWS_DEPT_CACHE_OPTION, array());
    $cat_cache = get_option(UTM_NEWS_CAT_CACHE_OPTION, array());
    $last_cached = (!empty($dept_cache['updated_at'])) ? $dept_cache['updated_at'] : 'Never';
    $last_cached_categories = (!empty($cat_cache['updated_at'])) ? $cat_cache['updated_at'] : 'Never';
    $last_import = get_option('utm_news_last_hourly_import', 'Never');
    $next_cron = wp_next_scheduled(UTM_NEWS_CRON_HOOK);

    echo '<div class="wrap">';
    echo '<h1>UTM News Import</h1>';

    if (!empty($_GET['utm_news_saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    if (isset($_GET['utm_news_refresh'])) {
        if ((int) $_GET['utm_news_refresh'] === 1) {
            echo '<div class="notice notice-success is-dismissible"><p>Department list refreshed successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to refresh department list.</p></div>';
        }
    }
    if (isset($_GET['utm_news_cat_refresh'])) {
        if ((int) $_GET['utm_news_cat_refresh'] === 1) {
            echo '<div class="notice notice-success is-dismissible"><p>Category list refreshed successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to refresh category list.</p></div>';
        }
    }
    if (isset($_GET['utm_news_run_now'])) {
        if ((int) $_GET['utm_news_run_now'] === 1) {
            echo '<div class="notice notice-success is-dismissible"><p>Import run triggered.</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>Import skipped (source site detected).</p></div>';
        }
    }

    if (utm_news_is_source_site()) {
        echo '<div class="notice notice-info"><p><strong>Importer disabled on news.utm.my</strong>. This site is the content source. Shortcode display remains available.</p></div>';
    }

    echo '<p><strong>Department cache updated:</strong> ' . esc_html($last_cached) . '</p>';
    echo '<p><strong>Category cache updated:</strong> ' . esc_html($last_cached_categories) . '</p>';
    echo '<p><strong>Last hourly import:</strong> ' . esc_html($last_import) . '</p>';
    echo '<p><strong>Next scheduled run:</strong> ' . esc_html($next_cron ? date_i18n('Y-m-d H:i:s', $next_cron) : 'Not scheduled') . '</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
    wp_nonce_field('utm_news_refresh_departments');
    echo '<input type="hidden" name="action" value="utm_news_refresh_departments" />';
    submit_button('Sync Departments from news.utm.my', 'secondary', 'submit', false);
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
    wp_nonce_field('utm_news_refresh_categories');
    echo '<input type="hidden" name="action" value="utm_news_refresh_categories" />';
    submit_button('Sync Categories from news.utm.my', 'secondary', 'submit', false);
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 24px;">';
    wp_nonce_field('utm_news_run_now');
    echo '<input type="hidden" name="action" value="utm_news_run_now" />';
    submit_button('Run Import Now', 'secondary', 'submit', false);
    echo '</form>';

    if (is_wp_error($departments)) {
        echo utm_news_get_helper_function_prompt_html();
        $departments = array();
    }

    if (is_wp_error($categories)) {
        echo '<div class="notice notice-warning"><p><strong>Category list fetch failed.</strong> Unable to fetch categories from <code>' . esc_html(UTM_NEWS_SOURCE_CAT_ENDPOINT) . '</code>.</p></div>';
        $categories = array();
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('utm_news_save_settings');
    echo '<input type="hidden" name="action" value="utm_news_save_settings" />';

    echo '<h2>Select Departments to Import</h2>';

    if (empty($departments)) {
        echo '<p>No department data available yet. Please click "Sync Departments from news.utm.my".</p>';
    } else {
        echo '<div style="max-height:280px; overflow:auto; border:1px solid #ccd0d4; padding:12px; background:#fff;">';
        foreach ($departments as $dept) {
            $value = isset($dept['value']) ? $dept['value'] : '';
            $label = isset($dept['name']) ? $dept['name'] : $value;
            if ($value === '') {
                continue;
            }

            $meta = array();
            if (!empty($dept['slug'])) {
                $meta[] = 'slug: ' . $dept['slug'];
            }
            if (!empty($dept['id'])) {
                $meta[] = 'id: ' . $dept['id'];
            }

            echo '<label style="display:block; margin-bottom:8px;">';
            echo '<input type="checkbox" name="selected_departments[]" value="' . esc_attr($value) . '" ' . checked(in_array($value, $selected_departments, true), true, false) . ' /> ';
            echo esc_html($label);
            if (!empty($meta)) {
                echo ' <span style="color:#666;">(' . esc_html(implode(', ', $meta)) . ')</span>';
            }
            echo '</label>';
        }
        echo '</div>';
    }

    echo '<h2 style="margin-top:24px;">Select Categories to Import</h2>';

    if (empty($categories)) {
        echo '<p>No category data available yet. Please click "Sync Categories from news.utm.my".</p>';
    } else {
        echo '<div style="max-height:280px; overflow:auto; border:1px solid #ccd0d4; padding:12px; background:#fff;">';
        foreach ($categories as $cat) {
            $value = isset($cat['value']) ? $cat['value'] : '';
            $label = isset($cat['name']) ? $cat['name'] : $value;
            if ($value === '') {
                continue;
            }

            $meta = array();
            if (!empty($cat['slug'])) {
                $meta[] = 'slug: ' . $cat['slug'];
            }
            if (!empty($cat['id'])) {
                $meta[] = 'id: ' . $cat['id'];
            }

            echo '<label style="display:block; margin-bottom:8px;">';
            echo '<input type="checkbox" name="selected_categories[]" value="' . esc_attr($value) . '" ' . checked(in_array($value, $selected_categories, true), true, false) . ' /> ';
            echo esc_html($label);
            if (!empty($meta)) {
                echo ' <span style="color:#666;">(' . esc_html(implode(', ', $meta)) . ')</span>';
            }
            echo '</label>';
        }
        echo '</div>';
    }

    echo '<h2 style="margin-top:24px;">Display & Import Settings</h2>';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="display_style">Display Style</label></th><td>';
    echo '<select id="display_style" name="display_style">';
    echo '<option value="basic_list" ' . selected($settings['display_style'], 'basic_list', false) . '>Basic list (title only)</option>';
    echo '<option value="card" ' . selected($settings['display_style'], 'card', false) . '>Card (title, date, featured image)</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="import_count">Import count (per department/category / run)</label></th><td>';
    echo '<input type="number" min="1" max="20" id="import_count" name="import_count" value="' . esc_attr((string) $settings['import_count']) . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="retention_mode">Retention Mode</label></th><td>';
    echo '<select id="retention_mode" name="retention_mode">';
    echo '<option value="latest_only" ' . selected($settings['retention_mode'], 'latest_only', false) . '>Keep latest only (auto-clean old imported posts)</option>';
    echo '<option value="permanent" ' . selected($settings['retention_mode'], 'permanent', false) . '>Keep all imported posts permanently</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="keep_latest_count">Keep latest count (per department/category)</label></th><td>';
    echo '<input type="number" min="1" max="200" id="keep_latest_count" name="keep_latest_count" value="' . esc_attr((string) $settings['keep_latest_count']) . '" />';
    echo '<p class="description">Used only when retention mode is set to <strong>Keep latest only</strong>.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="posts_per_page">Shortcode posts per page</label></th><td>';
    echo '<input type="number" min="1" max="20" id="posts_per_page" name="posts_per_page" value="' . esc_attr((string) $settings['posts_per_page']) . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="target">Link target</label></th><td>';
    echo '<select id="target" name="target">';
    echo '<option value="_blank" ' . selected($settings['target'], '_blank', false) . '>Open in new tab</option>';
    echo '<option value="_self" ' . selected($settings['target'], '_self', false) . '>Open in same tab</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Redirect to source site</th><td>';
    echo '<label>';
    echo '<input type="checkbox" name="redirect_users_to_source" value="1" ' . checked((int) $settings['redirect_users_to_source'], 1, false) . ' /> ';
    echo 'Redirect normal users to source URL (news.utm.my)';
    echo '</label>';
    echo '<p class="description">Crawler/bot/AI user-agents are always redirected for SEO consistency.</p>';
    echo '</td></tr>';
    echo '</table>';

    submit_button('Save UTM News Settings');

    echo '</form>';

    echo '<hr style="margin:30px 0;" />';
    $shortcode_basic = '[utm_news_department]';
    $shortcode_with_style = '[utm_news_department style="' . esc_attr($settings['display_style']) . '"]';
    $shortcode_with_ppp = '[utm_news_department posts_per_page="' . (int) $settings['posts_per_page'] . '"]';

    echo '<h2>Shortcode</h2>';
    echo '<p>Use this shortcode on any page/post to display imported UTM news:</p>';
    echo '<div style="display:flex; gap:8px; align-items:center; max-width:760px;">';
    echo '<input type="text" id="utm-news-shortcode" readonly value="' . esc_attr($shortcode_basic) . '" class="regular-text" style="max-width:520px;" />';
    echo '<button type="button" class="button button-secondary" onclick="utmNewsCopyActiveShortcode()">Copy</button>';
    echo '</div>';
    echo '<p style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">';
    echo '<button type="button" class="button" onclick="utmNewsSetShortcode(\'' . esc_js($shortcode_basic) . '\')">Use Basic</button>';
    echo '<button type="button" class="button" onclick="utmNewsSetShortcode(\'' . esc_js($shortcode_with_style) . '\')">Use Current Style</button>';
    echo '<button type="button" class="button" onclick="utmNewsSetShortcode(\'' . esc_js($shortcode_with_ppp) . '\')">Use Current Posts/Page</button>';
    echo '</p>';
    echo '<p class="description">You can combine attributes, e.g. <code>[utm_news_department style="card" posts_per_page="6"]</code></p>';

    echo '<h2 style="margin-top:20px;">Shortcode Preview</h2>';
    echo '<p class="description">Preview reflects current saved display settings and imported posts.</p>';
    echo '<div style="border:1px solid #ccd0d4; background:#fff; padding:16px; border-radius:4px;">';
    echo do_shortcode('[utm_news_department]');
    echo '</div>';

    echo '<script>';
    echo 'function utmNewsSetShortcode(val){';
    echo 'var input=document.getElementById("utm-news-shortcode");';
    echo 'if(!input){return;}';
    echo 'input.value=val;';
    echo '}';
    echo 'function utmNewsCopyActiveShortcode(){';
    echo 'var input=document.getElementById("utm-news-shortcode");';
    echo 'if(!input){return;}';
    echo 'input.select(); input.setSelectionRange(0,99999);';
    echo 'try{document.execCommand("copy");}catch(e){}';
    echo '}';
    echo '</script>';

    echo '</div>';
}

/**
 * Shortcode output renderer.
 * Display-only: no import work is done here.
 */
add_shortcode('utm_news_department', function($atts) {
    $settings = utm_news_get_settings();
    $is_crawler = utm_news_import_is_crawler_request();
    $redirect_users = !empty($settings['redirect_users_to_source']);

    $atts = shortcode_atts(array(
        'posts_per_page' => $settings['posts_per_page'],
        'target' => $settings['target'],
        'style' => $settings['display_style'],
    ), $atts);

    $posts_per_page = max(1, (int) $atts['posts_per_page']);
    $target = in_array($atts['target'], array('_blank', '_self'), true) ? $atts['target'] : $settings['target'];
    $style = in_array($atts['style'], array('basic_list', 'card'), true) ? $atts['style'] : $settings['display_style'];

    $posts = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_page,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(
            array(
                'key' => 'utm_news_original_id',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    if (empty($posts)) {
        return '<p class="utm-news-no-posts">No imported news available yet.</p>';
    }

    $output = '<div class="utm-news-department utm-news-style-' . esc_attr($style) . '">';

    foreach ($posts as $post) {
        $date = date_i18n('F j, Y', strtotime($post->post_date));
        $original_url = get_post_meta($post->ID, 'utm_news_original_url', true);
        $local_url = get_permalink($post->ID);
        if (($redirect_users || $is_crawler) && !empty($original_url)) {
            $link = $original_url;
        } else {
            $link = $local_url;
        }

        if ($style === 'basic_list') {
            $output .= '<div class="news-item news-item-basic">';
            $output .= sprintf(
                '<h3><a href="%s" target="%s">%s</a></h3>',
                esc_url($link),
                esc_attr($target),
                esc_html($post->post_title)
            );
            $output .= '</div>';
            continue;
        }

        $thumb = get_the_post_thumbnail($post->ID, 'medium', array('loading' => 'lazy', 'class' => 'utm-news-thumb'));

        $output .= '<article class="news-item news-item-card">';
        if (!empty($thumb)) {
            $output .= '<div class="news-thumb-wrap">' . $thumb . '</div>';
        }
        $output .= '<div class="news-card-content">';
        $output .= sprintf(
            '<h3><a href="%s" target="%s">%s</a></h3>',
            esc_url($link),
            esc_attr($target),
            esc_html($post->post_title)
        );
        $output .= '<div class="news-meta"><span class="date">' . esc_html($date) . '</span></div>';
        $output .= '</div>';
        $output .= '</article>';
    }

    $output .= '</div>';

    $output .= '
    <style>
        .utm-news-department { margin: 20px 0; }
        .utm-news-department .news-item { margin-bottom: 16px; }
        .utm-news-style-basic_list .news-item h3 { margin: 0; font-size: 1.05em; line-height: 1.45; }
        .utm-news-style-card .news-item-card {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 14px;
            align-items: start;
            padding: 12px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #fff;
        }
        .utm-news-style-card .news-thumb-wrap img {
            width: 100%;
            height: auto;
            border-radius: 6px;
            object-fit: cover;
        }
        .utm-news-department h3 { margin: 0 0 6px; font-size: 1.05em; }
        .utm-news-department h3 a {
            color: #009154;
            text-decoration: none;
            transition: color .2s ease;
        }
        .utm-news-department h3 a:hover { color: #007a45; text-decoration: underline; }
        .utm-news-department .news-meta { font-size: .9em; color: #666; }
        .utm-news-no-posts {
            color: #666;
            font-style: italic;
            padding: 16px;
            background: #f8f8f8;
            border-radius: 6px;
            text-align: center;
        }
        @media (max-width: 768px) {
            .utm-news-style-card .news-item-card {
                grid-template-columns: 1fr;
            }
        }
    </style>';

    return $output;
});

/**
 * Detect crawler/bot/AI user agents.
 *
 * @return bool
 */
function utm_news_import_is_crawler_request() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';
    if ($ua === '') {
        return false;
    }

    $patterns = array(
        'bot',
        'spider',
        'crawler',
        'slurp',
        'bingpreview',
        'facebookexternalhit',
        'facebot',
        'linkedinbot',
        'twitterbot',
        'whatsapp',
        'telegrambot',
        'applebot',
        'yandex',
        'baidu',
        'semrush',
        'ahrefs',
        'mj12bot',
        'bytespider',
        'gptbot',
        'chatgpt-user',
        'claudebot',
        'ccbot',
        'perplexitybot',
        'amazonbot',
    );

    foreach ($patterns as $pattern) {
        if (strpos($ua, $pattern) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Redirect imported local post to original URL on source site.
 */
add_action('template_redirect', function() {
    if (!is_single()) {
        return;
    }

    $post_id = get_the_ID();
    $original_url = get_post_meta($post_id, 'utm_news_original_url', true);

    if (empty($original_url)) {
        return;
    }

    $settings = utm_news_get_settings();
    $redirect_users = !empty($settings['redirect_users_to_source']);
    $is_crawler = utm_news_import_is_crawler_request();

    if ($is_crawler || $redirect_users) {
        wp_redirect($original_url, 301);
        exit;
    }
});
