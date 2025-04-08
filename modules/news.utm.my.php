<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// if website domain is not news.utm.my, exit
if ($_SERVER['HTTP_HOST'] !== 'news.utm.my') {
    return; // Uncomment this line to disable the script
}

// Hook into WordPress init to check for the export parameter
add_action('init', function () {
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        die('Exporting CSV...'); // Debugging line to check if the script is running
        export_taxonomy_to_csv();
    }
});

/**
 * Export the current taxonomy archive to a CSV file.
 */
function export_taxonomy_to_csv()
{
    global $wp;

    // Get the current URL
    $current_url = home_url($_SERVER['REQUEST_URI']);

    // Parse the URL to extract the taxonomy and term
    $url_parts = explode('/', trim(parse_url($current_url, PHP_URL_PATH), '/'));
    $taxonomy = $url_parts[0] ?? null; // First part of the URL (e.g., 'department')
    $term_slug = $url_parts[1] ?? null; // Second part of the URL (e.g., 'malaysia-japan-international-institute-of-technology')

    if (!$taxonomy || !$term_slug) {
        wp_die('Invalid taxonomy or term.');
    }

    // Get the term object to validate it exists
    $term = get_term_by('slug', $term_slug, $taxonomy);
    var_dump($term); // Debugging line to check the term object
    if (!$term) {
        wp_die('Invalid term.');
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $taxonomy . '-' . $term_slug . '-export.csv"');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write the CSV header row
    fputcsv($output, ['Post ID', 'Post Title', 'Post Content', 'Post URL', 'Featured Image URL', 'Meta Key', 'Meta Value']);

    // Query posts in the current taxonomy and term
    $query = new WP_Query([
        'post_type' => 'any',
        'tax_query' => [
            [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $term_slug,
            ],
        ],
        'posts_per_page' => 10, // Retrieve all posts
    ]);

    // Loop through posts and write data to CSV
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $post_title = get_the_title();
            $post_content = get_the_content();
            $post_url = get_permalink();
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'full') ?: '';

            // Get all post meta
            $post_meta = get_post_meta($post_id);

            // Write each meta key-value pair to the CSV
            foreach ($post_meta as $meta_key => $meta_values) {
                foreach ($meta_values as $meta_value) {
                    fputcsv($output, [$post_id, $post_title, $post_content, $post_url, $featured_image_url, $meta_key, $meta_value]);
                }
            }
        }
    }

    // Reset post data
    wp_reset_postdata();

    // Close output stream
    fclose($output);

    // Terminate script to prevent further output
    exit;
}