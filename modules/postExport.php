<?php
// This file is part of news.utm.my post export to csv feature

function post_export_get_common_fields() {
    return array(
        'id' => 'ID',
        'title' => 'Title',
        'author' => 'Author',
        'category' => 'Category',
        'date' => 'Date',
        'department' => 'Department',
        'url' => 'URL',
        'page_path' => 'Page Path',
        'content' => 'Content/Body',
        'excerpt' => 'Excerpt',
        'slug' => 'Slug',
        'status' => 'Status',
        'tags' => 'Tags',
    );
}

function post_export_get_default_fields() {
    return array('id', 'title', 'author', 'category', 'date', 'department', 'url', 'page_path');
}

function post_export_get_default_date_range() {
    $earliest_post = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'orderby' => 'date',
        'order' => 'ASC',
    ));

    $today = current_time('Y-m-d');
    $start_date = $earliest_post ? date('Y-m-d', strtotime($earliest_post[0]->post_date)) : date('Y-m-d', strtotime('-3 years'));

    return array(
        'start_date' => $start_date,
        'end_date' => $today,
    );
}

function post_export_normalize_date_range($start_date = '', $end_date = '', $start_year = '', $end_year = '') {
    $defaults = post_export_get_default_date_range();

    if (!empty($start_date) && !empty($end_date)) {
        return array(
            'start_date' => sanitize_text_field($start_date),
            'end_date' => sanitize_text_field($end_date),
        );
    }

    if (!empty($start_year) && !empty($end_year)) {
        return array(
            'start_date' => intval($start_year) . '-01-01',
            'end_date' => intval($end_year) . '-12-31',
        );
    }

    return $defaults;
}

function post_export_sanitize_selected_fields($fields) {
    $available = post_export_get_common_fields();
    $sanitized = array();

    if (is_string($fields)) {
        $fields = array_filter(array_map('trim', explode(',', $fields)));
    }

    if (!is_array($fields)) {
        $fields = array();
    }

    foreach ($fields as $field_key) {
        $key = sanitize_key($field_key);
        if (isset($available[$key])) {
            $sanitized[] = $key;
        }
    }

    $sanitized = array_values(array_unique($sanitized));

    if (empty($sanitized)) {
        return post_export_get_default_fields();
    }

    return $sanitized;
}

function post_export_get_post_value($post, $field_key) {
    switch ($field_key) {
        case 'id':
            return $post->ID;
        case 'title':
            return $post->post_title;
        case 'author':
            return get_the_author_meta('display_name', $post->post_author);
        case 'category':
            $categories = get_the_category($post->ID);
            return $categories ? $categories[0]->name : '';
        case 'date':
            return date('c', strtotime($post->post_date));
        case 'department':
            $departments = get_the_terms($post->ID, 'department');
            $department_names = array();
            if ($departments && !is_wp_error($departments)) {
                foreach ($departments as $department) {
                    $department_names[] = $department->name;
                }
            }
            return implode(', ', $department_names);
        case 'url':
            return get_permalink($post->ID);
        case 'page_path':
            return parse_url(get_permalink($post->ID), PHP_URL_PATH);
        case 'content':
            return wp_strip_all_tags($post->post_content);
        case 'excerpt':
            return wp_strip_all_tags(get_the_excerpt($post));
        case 'slug':
            return $post->post_name;
        case 'status':
            return $post->post_status;
        case 'tags':
            $tags = get_the_terms($post->ID, 'post_tag');
            if (!$tags || is_wp_error($tags)) {
                return '';
            }
            return implode(', ', wp_list_pluck($tags, 'name'));
        default:
            return '';
    }
}

function post_export_get_rows($posts, $selected_fields) {
    $rows = array();
    foreach ($posts as $post) {
        $row = array();
        foreach ($selected_fields as $field_key) {
            $row[] = post_export_get_post_value($post, $field_key);
        }
        $rows[] = $row;
    }

    return $rows;
}

function generate_csv($start_date = '', $end_date = '', $selected_fields = array()) {
    $date_range = post_export_normalize_date_range(
        $start_date,
        $end_date,
        isset($_POST['start_year']) ? wp_unslash($_POST['start_year']) : '',
        isset($_POST['end_year']) ? wp_unslash($_POST['end_year']) : ''
    );

    if (empty($selected_fields) && isset($_POST['fields'])) {
        $selected_fields = wp_unslash($_POST['fields']);
    }

    $selected_fields = post_export_sanitize_selected_fields($selected_fields);
    $posts = get_posts_for_export($date_range['start_date'], $date_range['end_date']);

    // save the file in the uploads directory (admin flow expects a saved file)
    $filename = 'posts-' . date('Ymd', strtotime($date_range['start_date'])) . '-' . date('Ymd', strtotime($date_range['end_date'])) . '.csv';
    $upload_dir = wp_upload_dir();
    $file_path = trailingslashit($upload_dir['path']) . $filename;

    stream_posts_csv($posts, $filename, $file_path, $selected_fields);
}

/**
 * Return posts for given date range.
 */
function get_posts_for_export($start_date, $end_date) {
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => $start_date,
                'before' => $end_date,
                'inclusive' => true,
            ),
        ),
    );

    return get_posts($args);
}

/**
 * Stream posts as CSV. If $to_file_path is provided, writes to that file path.
 * Otherwise streams to php://output and exits (for direct download via REST/browser).
 */
function stream_posts_csv($posts, $filename = 'posts.csv', $to_file_path = null, $selected_fields = array()) {
    $available_fields = post_export_get_common_fields();
    $selected_fields = post_export_sanitize_selected_fields($selected_fields);

    if ($to_file_path) {
        $fh = fopen($to_file_path, 'w');
    } else {
        // Stream to browser / client
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // helpful for long exports
        @set_time_limit(0);
        $fh = fopen('php://output', 'w');
        // write UTF-8 BOM to help Excel/Sheets detect UTF-8
        fwrite($fh, "\xEF\xBB\xBF");
    }

    // header row
    $headers = array();
    foreach ($selected_fields as $field_key) {
        $headers[] = $available_fields[$field_key];
    }
    fputcsv($fh, $headers);

    if ($posts) {
        $rows = post_export_get_rows($posts, $selected_fields);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
    }

    fclose($fh);

    if (!$to_file_path) {
        // when streaming directly, end execution to avoid extra output
        exit;
    }
}

/**
 * REST route registration for public CSV export
 */
function register_post_export_route() {
    if (function_exists('register_rest_route')) {
        register_rest_route('utm-webmaster/v1', '/export/posts', array(
            'methods' => 'GET',
            'callback' => 'post_export_rest_handler',
            'permission_callback' => '__return_true',
        ));
    }
}
add_action('rest_api_init', 'register_post_export_route');

/**
 * REST handler: streams CSV for given start_year and end_year (GET parameters).
 */
function post_export_rest_handler($request) {
    $start_year = $request->get_param('start_year');
    $end_year = $request->get_param('end_year');
    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');
    $fields = $request->get_param('fields');

    $date_range = post_export_normalize_date_range($start_date, $end_date, $start_year, $end_year);
    $selected_fields = post_export_sanitize_selected_fields($fields);

    $posts = get_posts_for_export($date_range['start_date'], $date_range['end_date']);
    $filename = 'posts-' . date('Ymd', strtotime($date_range['start_date'])) . '-' . date('Ymd', strtotime($date_range['end_date'])) . '.csv';

    // Stream CSV and exit
    stream_posts_csv($posts, $filename, null, $selected_fields);

    // Should never reach here because stream_posts_csv exits when streaming
    return new WP_REST_Response(array('status' => 'ok'));
}

// Add menu under Posts
function register_postExport_admin_menu(){
    add_submenu_page('utm-webmaster-dashboard', 'Export Posts', 'Export Posts', 'manage_options', 'post_export', 'post_export');
}
add_action('admin_menu', 'register_postExport_admin_menu');

function post_export_enqueue_admin_assets($hook) {
    if (strpos($hook, 'post_export') === false) {
        return;
    }

    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-datepicker-base', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', array(), '1.13.2');
}
add_action('admin_enqueue_scripts', 'post_export_enqueue_admin_assets');

function post_export() {
    $date_defaults = post_export_get_default_date_range();
    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : $date_defaults['start_date'];
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : $date_defaults['end_date'];

    // Backward compatibility for legacy year selectors if values are posted.
    if (isset($_POST['start_year']) && isset($_POST['end_year']) && (!isset($_POST['start_date']) || !isset($_POST['end_date']))) {
        $legacy_range = post_export_normalize_date_range('', '', wp_unslash($_POST['start_year']), wp_unslash($_POST['end_year']));
        $start_date = $legacy_range['start_date'];
        $end_date = $legacy_range['end_date'];
    }

    $selected_fields = isset($_POST['fields'])
        ? post_export_sanitize_selected_fields(wp_unslash($_POST['fields']))
        : post_export_get_default_fields();

    $preview_posts = array();
    $notice_message = '';
    $notice_class = 'notice-success';

    if (isset($_POST['export'])) {
        if (isset($_POST['post_export_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['post_export_nonce'])), 'post_export_action')) {
            generate_csv($start_date, $end_date, $selected_fields);
            $notice_message = 'Export completed and file saved to uploads directory.';
            $notice_class = 'notice-success';
        } else {
            $notice_message = 'Security check failed. Please try again.';
            $notice_class = 'notice-error';
        }
    }

    if (isset($_POST['preview'])) {
        if (isset($_POST['post_export_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['post_export_nonce'])), 'post_export_action')) {
            $preview_posts = array_slice(get_posts_for_export($start_date, $end_date), 0, 5);
        } else {
            $notice_message = 'Security check failed. Please try again.';
            $notice_class = 'notice-error';
        }
    }

    ?>
    <div class="wrap">
        <style>
            .form-inline { display: inline-block; vertical-align: top; }
            .post-export-field-grid { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 8px 12px; margin: 12px 0; }
            .post-export-actions { margin-top: 12px; display: flex; gap: 8px; align-items: center; }
            .post-export-preview { margin-top: 20px; }
        </style>
        <h2>Export Posts</h2>
        <form method="post" action="" class="form-inline">
            <?php wp_nonce_field('post_export_action', 'post_export_nonce'); ?>
            <label for="start_date">Start Date:</label>
            <input type="text" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" class="regular-text" autocomplete="off" />

            <label for="end_date" style="margin-left:12px;">End Date:</label>
            <input type="text" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" class="regular-text" autocomplete="off" />

            <h3 style="margin-top:16px;">Choose Common Metadata</h3>
            <div class="post-export-field-grid">
                <?php
                foreach (post_export_get_common_fields() as $field_key => $field_label) {
                    echo '<label><input type="checkbox" name="fields[]" value="' . esc_attr($field_key) . '" ' . checked(in_array($field_key, $selected_fields, true), true, false) . ' /> ' . esc_html($field_label) . '</label>';
                }
                ?>
            </div>

            <div class="post-export-actions">
                <input type="submit" name="preview" value="Preview 5 Rows" class="button" />
                <input type="submit" name="export" value="Export" class="button button-primary" />
            </div>
        </form>
        <?php
        if (!empty($notice_message)) {
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($notice_message) . '</p></div>';
        }

        if (!empty($preview_posts)) {
            $preview_rows = post_export_get_rows($preview_posts, $selected_fields);
            $available_fields = post_export_get_common_fields();
            echo '<div class="post-export-preview">';
            echo '<h3>Preview (First ' . count($preview_rows) . ' Rows)</h3>';
            echo '<table class="widefat striped"><thead><tr>';
            foreach ($selected_fields as $field_key) {
                echo '<th>' . esc_html($available_fields[$field_key]) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($preview_rows as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . esc_html($cell) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        } elseif (isset($_POST['preview'])) {
            echo '<div class="notice notice-info is-dismissible"><p>No posts found for selected date range.</p></div>';
        }
        
        $upload_dir = wp_upload_dir();
        $files = glob($upload_dir['path'] . '/*.csv');

        // Button to delete all csv files
        if ($files) {
            echo '<form method="post" action="" class="form-inline">';
            wp_nonce_field('post_export_delete_files_action', 'post_export_delete_files_nonce');
            echo '<input type="submit" name="delete_files" value="Delete All Exported Files" class="button button-secondary" />';
            echo '</form>';
        }

        if (isset($_POST['delete_files'])) {
            if (isset($_POST['post_export_delete_files_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['post_export_delete_files_nonce'])), 'post_export_delete_files_action')) {
                foreach ($files as $file) {
                    unlink($file);
                }
                echo '<div class="notice notice-success is-dismissible"><p>All exported files have been deleted.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Security check failed while deleting files.</p></div>';
            }
        } else {
            if ($files) {
                echo '<h3>Exported Files</h3>';

                foreach ($files as $file) {
                    $file_url = $upload_dir['url'] . '/' . basename($file);
                    echo '<a href="' . $file_url . '">' . basename($file) . '</a><br>';
                }
            }
        }

        ?>
    </div>
    <script>
        jQuery(function($) {
            $('#start_date, #end_date').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                yearRange: '2000:+5'
            });
        });
    </script>
    <?php
}
