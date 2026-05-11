<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Function to import posts
 */
function import_utm_news_posts( $department_id ) {
    $remote_api_url = 'https://news.utm.my/wp-json/wp/v2/posts?department=' . $department_id . '&per_page=25';
    $response = wp_remote_get( $remote_api_url );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $posts = json_decode( wp_remote_retrieve_body( $response ), true );
    
    // Create category if it doesn't exist
    $cat_name = 'MJIIT News';
    $cat_slug = 'mjiit-news';
    $category = get_term_by( 'slug', $cat_slug, 'category' );
    
    if ( ! $category ) {
        wp_insert_term( $cat_name, 'category', array( 'slug' => $cat_slug ) );
        $category = get_term_by( 'slug', $cat_slug, 'category' );
    }

    foreach ( $posts as $post ) {
        // Check if post already exists
        $existing_post = get_posts( array(
            'meta_key' => 'utm_news_original_id',
            'meta_value' => $post['id'],
            'post_type' => 'post',
            'post_status' => 'any'
        ) );

        if ( empty( $existing_post ) ) {
            // Prepare post data
            $post_data = array(
                'post_title' => wp_strip_all_tags( $post['title']['rendered'] ),
                'post_content' => $post['content']['rendered'] . '<p><a href="' . esc_url( $post['link'] ) . '" target="_blank">Read more on UTM Newshub</a></p>',
                'post_excerpt' => wp_strip_all_tags( $post['excerpt']['rendered'] ),
                'post_status' => 'publish',
                'post_date' => $post['date'],
                'post_category' => array( $category->term_id )
            );

            // Insert post
            $post_id = wp_insert_post( $post_data );

            if ( $post_id ) {
                // Add source metadata
                update_post_meta( $post_id, 'utm_news_original_id', $post['id'] );
                update_post_meta( $post_id, 'utm_news_original_url', $post['link'] );
            }
        }
    }

    return true;
}

// [utm_news_department] is registered in modules/utm-news-import.php as the
// canonical implementation to avoid duplicate shortcode overrides.
