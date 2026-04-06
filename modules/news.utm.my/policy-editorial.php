<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin notice for edit block and special user capabilities
 */
add_action( 'admin_notices', function() {
    // Show block notice if redirected
    if ( isset( $_GET['edit_blocked'] ) && '1' === $_GET['edit_blocked'] ) {
        echo '<div class="notice notice-error is-dismissible"><p>You are not allowed to edit a post while it is pending review.</p></div>';
    }
} );

/**
 * Server-side block: Prevent authors from editing pending posts
 */
add_action( 'admin_init', function() {
    global $pagenow;
    if ( 'post.php' === $pagenow && isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['post'] ) ) {
        $post_id = intval( $_GET['post'] );
        $post = get_post( $post_id );
        if ( $post && 'pending' === $post->post_status ) {
            $current_user = wp_get_current_user();
            $is_author_role = in_array( 'author', (array) $current_user->roles );
            $is_post_author = ( $current_user->ID === (int) $post->post_author );
            if ( $is_author_role || $is_post_author ) {
                // Redirect to posts list with error
                $redirect_url = admin_url( 'edit.php?edit_blocked=1' );
                wp_redirect( $redirect_url );
                exit;
            }
        }
    }
} );

/**
 * Hide Edit link in post list for authors on pending posts
 */
add_filter( 'post_row_actions', function( $actions, $post ) {
    if ( 'pending' === $post->post_status ) {
        $current_user = wp_get_current_user();
        $is_author_role = in_array( 'author', (array) $current_user->roles );
        $is_post_author = ( $current_user->ID === (int) $post->post_author );
        if ( $is_author_role || $is_post_author ) {
            unset( $actions['edit'] );
        }
    }
    return $actions;
}, 10, 2 );

/**
 * Only run in admin area to grant capabilities to specific user
 */
add_action( 'admin_init', function() {
    // Only target the specific user
    $current_user = wp_get_current_user();
    if ( $current_user && 'officevc@utm.my' === $current_user->user_email ) {
        // Grant capabilities to the user to publish and edit posts
        $current_user->add_cap( 'edit_posts' );
        $current_user->add_cap( 'publish_posts' );
        // Optionally, ensure the user has the author role
        if ( ! in_array( 'author', $current_user->roles ) ) {
            $current_user->add_role( 'author' );
        }
    }
} );

/**
 * Append notice after posts by officevc@utm.my
 */
add_filter( 'the_content', function( $content ) {
    global $post;
    if ( is_admin() || ! is_singular() || ! isset( $post->post_author ) ) {
        return $content;
    }
    $author = get_userdata( $post->post_author );
    if ( $author && 'officevc@utm.my' === $author->user_email ) {
        $notice = '<div style="margin-top:2em;font-style:italic;color:#555;">Berita ini dikendalikan sepenuhnya oleh Pejabat Naib Canselor</div>';
        return $content . $notice;
    }
    return $content;
} );

/**
 * Shortcode to display analytics of posts by alumni category and department
 * 
 * Shortcode to display analytics of posts by alumni category and department who posted it
 * It will have 2 list:
 * 1. List of departments and their total posts (with category 'alumni')
 * 2. List of news with category 'alumni' and link to the post
 * 
 * This only limited to current year posts
 */
add_shortcode( 'utm_news_alumni_analytics', function( $atts ) {
    // Get posts by alumni-networking category, limited to current year
    $atts = shortcode_atts( array(
        'department' => '', // Optional department filter
    ), $atts );
    $posts = get_posts( array(
        'category_name' => 'alumni-networking',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'date_query' => array(
            array(
                'year' => date( 'Y' ),
            ),
        ),
    ) );

    if ( empty( $posts ) ) {
        return '<p>No alumni posts found.</p>';
    }

    // Build department post count
    $department_counts = array();
    foreach ( $posts as $post ) {
        $departments = wp_get_post_terms( $post->ID, 'department' );
        $department = ! empty( $departments ) ? $departments[0]->name : 'Unknown';
        if ( $atts['department'] && $atts['department'] !== $department ) {
            continue;
        }
        if ( ! isset( $department_counts[ $department ] ) ) {
            $department_counts[ $department ] = 0;
        }
        $department_counts[ $department ]++;
    }

    // Output department summary
    $output = '<div class="utm-news-alumni-analytics" style="margin: 20px 0;">';
    $output .= '<h3>Total Alumni News: ' . count( $posts ) . '</h3>';
    $output .= '<h4>Department (Total Post)</h4>';
    $output .= '<ul>';
    foreach ( $department_counts as $dept => $count ) {
        $output .= sprintf( '<li>%s (%d)</li>', esc_html( $dept ), intval( $count ) );
    }
    $output .= '</ul>';

    // Output post list
    $output .= '<h4>Alumni News List</h4>';
    $output .= '<ul>';
    foreach ( $posts as $post ) {
        $date = date( 'F j, Y', strtotime( $post->post_date ) );
        $departments = wp_get_post_terms( $post->ID, 'department' );
        $department = ! empty( $departments ) ? $departments[0]->name : '';
        if ( $atts['department'] && $atts['department'] !== $department ) {
            continue;
        }
        $output .= sprintf(
            '<li>
                %s - <a href="%s" target="_blank">%s</a> - %s
            </li>',
            esc_html( $date ),
            esc_url( get_permalink( $post->ID ) ),
            esc_html( $post->post_title ),
            esc_html( $department )
        );
    }
    $output .= '</ul>';
    $output .= '</div>';

    return $output;
} );

/**
 * Notice to login users about news submission blockage
 * 
 * Notice to login users that the news submission will be disabled from 15 Dec 2025 to 31 Dec 2025
 * All access to wp-admin/post-new.php will be blocked for all authors during this period
 * This notice cant be dismissed until the period is over
 * This notice will appear starting from now until 31 Dec 2025
 */
add_action( 'admin_notices', function() {
    global $pagenow;
    $start_date = strtotime( '2025-12-22 00:00:00' );
    $end_date = strtotime( '2025-12-31 23:59:59' );
    $current_date = current_time( 'timestamp' );
    $show_notice = false;
    $block_access = false;
    $notice_msg = '<div class="notice notice-warning"><p><strong>Notice:</strong> News submission is disabled from 22 Dec 2025 to 31 Dec 2025 for system maintenance. You will not be able to create new posts during this period.</p></div>';

    if ( $current_date <= $end_date ) {
        $show_notice = true;
    }

    if ( $current_date >= $start_date && $current_date <= $end_date ) {
        $show_notice = true;
        $block_access = true;
    }
    if ( $show_notice ) {
        echo $notice_msg;
    }
    // Block access to post-new.php or post.php for authors during the block period
    if ( $block_access && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
        $current_user = wp_get_current_user();
        $is_author_role = in_array( 'author', (array) $current_user->roles );
        if ( $is_author_role ) {
            wp_die( '<h1>Access Denied</h1><p>News submission is disabled from 22 Dec 2025 to 31 Dec 2025 for system maintenance. You cannot submit news during this period.</p>', 'Access Denied', array( 'back_link' => true ) );
        }
    }
} );
