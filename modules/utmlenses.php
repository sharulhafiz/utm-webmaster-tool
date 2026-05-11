<?php
// Return if domain is not utmlenses.utm.my
if (strpos($_SERVER['HTTP_HOST'], 'utmlenses.utm.my') === false) {
    return;
}

class UTMLenses
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_backup_media_page'));
        add_action('wp_ajax_backup_media', array($this, 'backup_media'));
    }

    public function add_backup_media_page()
    {
        add_media_page(
            'Backup Media',
            'Backup Media',
            'manage_options',
            'backup-media',
            array($this, 'backup_media_page')
        );
    }

    public function backup_media_page()
    {
        $media_dir = ABSPATH . 'media';
        $this->generate_index_html($media_dir);
?>
        <div class="wrap">
            <h1>Backup Media</h1>
            <button id="start-backup" class="button button-primary">Start Backup</button>
            <div id="backup-status">
                <div style="display: flex;">
                    <div style="flex: 1; padding: 10px;">
                        <h2>Existing Files</h2>
                        <ul id="existing-files"></ul>
                    </div>
                    <div style="flex: 1; padding: 10px;">
                        <h2>Current Files Being Copied</h2>
                        <ul id="current-files"></ul>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Fetch existing files
                $.post(ajaxurl, {
                    action: 'get_existing_files'
                }, function(response) {
                    if (response.success) {
                        $('#existing-files').empty();
                        response.data.files.forEach(function(file) {
                            $('#existing-files').append('<li>' + file + '</li>');
                        });
                    } else {
                        alert('An error occurred while fetching existing files.');
                    }
                });
                $('#start-backup').on('click', function() {
                    var offset = 0;
                    var batch_size = 10;

                    $('#current-files').html('<li>Starting backup...</li>');

                    function backupMedia() {
                        $.post(ajaxurl, {
                            action: 'backup_media',
                            offset: offset
                        }, function(response) {
                            if (response.success) {
                                $('#current-files').empty();
                                response.data.files.forEach(function(file) {
                                    $('#current-files').append('<li>' + file + '</li>');
                                });

                                if (response.data.continue) {
                                    offset += batch_size;
                                    backupMedia();
                                } else {
                                    alert('Backup completed.');
                                }
                            } else {
                                alert('An error occurred.');
                            }
                        });
                    }

                    backupMedia();
                });
            });
        </script>
<?php
    }

    public function backup_media()
    {
        global $wpdb;

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10; // Number of files to process per request

        $media_dir = ABSPATH . 'media';

        if (!file_exists($media_dir)) {
            mkdir($media_dir, 0755, true);
        }

        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
        ));

        $total_files = $query->found_posts;

        if ($offset >= $total_files) {
            $this->generate_index_html($media_dir);
            wp_send_json_success(array('message' => 'Backup completed.', 'continue' => false));
        }

        $copied_files = array();
        foreach ($query->posts as $post) {
            $file = get_attached_file($post->ID);
            $destination = $media_dir . '/' . basename($file);
            if (!file_exists($destination)) {
                copy($file, $destination);
                $copied_files[] = basename($file);
            }
        }

        $new_offset = $offset + $batch_size;
        $continue = $new_offset < $total_files;

        wp_send_json_success(array('message' => 'Processed ' . $new_offset . ' of ' . $total_files . ' files.', 'offset' => $new_offset, 'continue' => $continue, 'files' => $copied_files));
    }

    private function generate_index_html($media_dir)
    {
        $files = scandir($media_dir);
        $html_content = '<html><body><ul>';

        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $file_url = site_url('/media/' . $file);
                $html_content .= '<li><a href="' . $file_url . '">' . $file_url . '</a></li>';
            }
        }

        $html_content .= '</ul></body></html>';

        file_put_contents($media_dir . '/index.html', $html_content);
        echo 'Generated index.html file.';
    }
}

new UTMLenses();

add_action('wp_ajax_get_existing_files', 'get_existing_files');

function get_existing_files()
{
    $media_dir = ABSPATH . 'media';
    $existing_files = array();

    if (file_exists($media_dir)) {
        $files = scandir($media_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $existing_files[] = $file;
            }
        }
    }

    wp_send_json_success(array('files' => $existing_files));
}
?>