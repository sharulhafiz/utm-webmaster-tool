<?php
// This file is part of news.utm.my post export to csv feature

function generate_csv() {
    // Preserve existing admin POST behavior: determine year range from POST or defaults
    if (isset($_POST['start_year']) && isset($_POST['end_year'])) {
        $yearRangeStart = intval($_POST['start_year']);
        $yearRangeEnd = intval($_POST['end_year']);
    } else {
        $earliestYearInPosts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
        ));
        $currentYear = date('Y');
        $yearRangeStart = $earliestYearInPosts ? date('Y', strtotime($earliestYearInPosts[0]->post_date)) : $currentYear - 3;
        $yearRangeEnd = $currentYear;
    }

    $posts = get_posts_for_export($yearRangeStart, $yearRangeEnd);

    // save the file in the uploads directory (admin flow expects a saved file)
    $filename = 'posts-' . $yearRangeStart . '-' . $yearRangeEnd . '.csv';
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . $filename;

    stream_posts_csv($posts, $filename, $file_path);
}

/**
 * Return posts for given year range.
 */
function get_posts_for_export($start_year, $end_year) {
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => $start_year . '-01-01',
                'before' => $end_year . '-12-31',
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
function stream_posts_csv($posts, $filename = 'posts.csv', $to_file_path = null) {
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
    fputcsv($fh, array('ID', 'Title', 'Author', 'Category', 'Date', 'Department', 'URL', 'Page Path'));

    if ($posts) {
        foreach ($posts as $post) {
            $id = $post->ID;
            $title = $post->post_title;
            $author = get_the_author_meta('display_name', $post->post_author);
            $categories = get_the_category($post->ID);
            $category = $categories ? $categories[0]->name : '';
            $content = $post->post_content;
            $date = date('c', strtotime($post->post_date)); // convert to ISO format
            $page_path = parse_url(get_permalink($post->ID), PHP_URL_PATH);

            $departments = get_the_terms($post->ID, 'department');
            $department_names = array();
            if ($departments && !is_wp_error($departments)) {
                foreach ($departments as $department) {
                    $department_names[] = $department->name;
                }
            }
            $department_list = implode(', ', $department_names);

            $url = get_permalink($post->ID);

            fputcsv($fh, array($id, $title, $author, $category, $date, $department_list, $url, $page_path));
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
    $start = $request->get_param('start_year');
    $end = $request->get_param('end_year');

    if ($start && $end) {
        $start_year = intval($start);
        $end_year = intval($end);
    } else {
        $earliestYearInPosts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
        ));
        $currentYear = date('Y');
        $start_year = $earliestYearInPosts ? date('Y', strtotime($earliestYearInPosts[0]->post_date)) : $currentYear - 3;
        $end_year = $currentYear;
    }

    $posts = get_posts_for_export($start_year, $end_year);
    $filename = 'posts-' . $start_year . '-' . $end_year . '.csv';

    // Stream CSV and exit
    stream_posts_csv($posts, $filename, null);

    // Should never reach here because stream_posts_csv exits when streaming
    return new WP_REST_Response(array('status' => 'ok'));
}

// Add menu under Posts
function register_postExport_admin_menu(){
    add_submenu_page('edit.php', 'Export Posts', 'Export Posts', 'manage_options', 'post_export', 'post_export');
}
add_action('admin_menu', 'register_postExport_admin_menu');

function post_export() {
    ?>
    <div class="wrap">
        <style> .form-inline { display: inline-block; vertical-align: top; } </style>
        <h2>Export Posts</h2>
        <form method="post" action="" class="form-inline">
            <label for="start_year">Start Year:</label>
            <select name="start_year" id="start_year">
                <?php
                $currentYear = date('Y');
                $yearRangeStart = $currentYear - 3;
                $yearRangeEnd = $currentYear;
                for ($year = $yearRangeEnd; $year >= $yearRangeStart; $year--) {
                    $selected = ($year == $yearRangeStart) ? "selected" : "";
                    echo '<option value="' . $year . '" ' . $selected . '>' . $year . '</option>';
                }
                ?>
            </select>
            <label for="end_year">End Year:</label>
            <select name="end_year" id="end_year">
                <?php
                for ($year = $yearRangeEnd; $year >= $yearRangeStart; $year--) {
                    $selected = ($year == date("Y")) ? "selected" : "";
                    echo '<option value="' . $year . '" ' . $selected . '>' . $year . '</option>';
                }
                ?>
            </select>
            <input type="submit" name="export" value="Export" class="button button-primary" />
        </form>
        <?php
        if (isset($_POST['export'])) {
            generate_csv();
        }
        
        $upload_dir = wp_upload_dir();
        $files = glob($upload_dir['path'] . '/*.csv');

        // Button to delete all csv files
        if ($files) {
            echo '<form method="post" action="" class="form-inline">';
            echo '<input type="submit" name="delete_files" value="Delete All Exported Files" class="button button-secondary" />';
            echo '</form>';
        }

        if (isset($_POST['delete_files'])) {
            foreach ($files as $file) {
                unlink($file);
            }
            echo '<div class="notice notice-success is-dismissible"><p>All exported files have been deleted.</p></div>';
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
    <?php
}
