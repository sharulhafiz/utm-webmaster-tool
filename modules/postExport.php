<?php
function generate_csv() {
    error_log('export function executed'); // Add this line

    if (isset($_POST['start_year']) && isset($_POST['end_year'])) {
        $yearRangeStart = $_POST['start_year'];
        $yearRangeEnd = $_POST['end_year'];
    } else {
        $currentYear = date('Y');
        $yearRangeStart = $currentYear - 3;
        $yearRangeEnd = $currentYear;
    }

    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => $yearRangeStart . '-01-01',
                'before' => $yearRangeEnd . '-12-31',
                'inclusive' => true,
            ),
        ),
    );

    $posts = get_posts($args);
    $output = '';
    if ($posts) {
        $output .= 'ID,Title,Category,Content,Date,Department,URL' . "\n";
        foreach ($posts as $post) {
            $id = str_replace('"', '""', $post->ID);
            $title = str_replace('"', '""', $post->post_title);
            $categories = get_the_category($post->ID);
            $category = $categories ? str_replace('"', '""', $categories[0]->name) : '';
            $content = str_replace('"', '""', $post->post_content);
            $date = str_replace('"', '""', $post->post_date);

            $departments = get_the_terms($post->ID, 'department');
            $department_names = array();
            if ($departments && !is_wp_error($departments)) {
                foreach ($departments as $department) {
                    $department_names[] = str_replace('"', '""', $department->name);
                }
            }
            $department_list = implode(', ', $department_names);

            $url = get_permalink($post->ID);

            $output .= "\"$id\",\"$title\",\"$category\",\"$content\",\"$date\",\"$department_list\",\"$url\"\n";
        }
    }

    // save the file in the uploads directory
    $filename = 'posts-' . $yearRangeStart . '-' . $yearRangeEnd . '.csv';
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . $filename;
    file_put_contents($file_path, $output);
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
