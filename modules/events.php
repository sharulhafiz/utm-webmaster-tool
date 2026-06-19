<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check whether current request host targets events.utm.my.
 *
 * @return bool
 */
function utm_events_is_events_host() {
    if ( empty( $_SERVER['HTTP_HOST'] ) ) {
        return false;
    }

    $host = strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) );
    $host = preg_replace( '/:\\d+$/', '', $host );

    return ( false !== strpos( $host, 'events.utm.my' ) );
}

/**
 * Reduce Formidable addon API chatter on events admin pages.
 *
 * This avoids repeated addon update checks that contribute to admin latency
 * and noisy warnings in high-traffic periods.
 */
function utm_events_mitigate_formidable_addon_checks() {
    if ( ! is_admin() || ! utm_events_is_events_host() ) {
        return;
    }

    if ( class_exists( 'FrmAddonsController' ) ) {
        remove_filter( 'pre_set_site_transient_update_plugins', array( 'FrmAddonsController', 'check_update' ) );
    }
}
add_action( 'plugins_loaded', 'utm_events_mitigate_formidable_addon_checks', 20 );

// ============================================================
//  Community Events Submission Log
//  Captures form submissions to /events/community/add/ including
//  silent failures (spam, nonce) that normally leave no trace.
// ============================================================

/**
 * Get the submission log table name.
 */
function utm_ce_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'ce_submission_log';
}

/**
 * Create the submission log table if it doesn't exist.
 * Safe to call on every page load — dbDelta is a no-op if table exists.
 */
function utm_ce_create_log_table() {
    global $wpdb;
    $table = utm_ce_log_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id BIGINT UNSIGNED DEFAULT 0,
        user_id BIGINT UNSIGNED DEFAULT 0,
        user_ip VARCHAR(45) DEFAULT '',
        user_agent TEXT,
        submission_data LONGTEXT,
        messages LONGTEXT,
        status VARCHAR(30) DEFAULT 'unknown',
        logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_logged_at (logged_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'init', 'utm_ce_create_log_table' );

/**
 * Log a submission attempt.
 *
 * Fires early in the submission lifecycle — catches ALL POST submissions
 * to the community event form including silent failures (spam, nonce).
 */
function utm_ce_log_submission_attempt() {
    // Only act on community event form POST submissions.
    if ( empty( $_POST['_wpnonce'] ) || empty( $_POST['community-event'] ) ) {
        return;
    }

    global $wpdb;
    $table = utm_ce_log_table();

    // Sanitise a snapshot of what was submitted (no full post_content to keep it lean).
    $post_title   = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
    $post_content = isset( $_POST['post_content'] ) ? mb_substr( wp_kses_post( wp_unslash( $_POST['post_content'] ) ), 0, 300 ) : '';

    $data = array(
        'user_id'          => get_current_user_id(),
        'user_ip'          => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
        'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
        'submission_data'  => wp_json_encode( array(
            'post_title'     => $post_title,
            'post_content'   => $post_content,
            'event_category' => isset( $_POST['community-event']['tribe_events_cat'] ) ? array_map( 'intval', (array) $_POST['community-event']['tribe_events_cat'] ) : array(),
        ) ),
        'status'           => 'attempt',
    );

    $wpdb->insert( $table, $data );
    $log_id = $wpdb->insert_id;

    if ( $log_id ) {
        // Store the log ID in a static so update hooks can find it.
        $GLOBALS['utm_ce_current_log_id'] = $log_id;

        // Sniff for nonce failure right away so we don't have to wait.
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ecp_event_submission' ) ) {
            utm_ce_set_log_status( $log_id, 'nonce_fail', array(
                (object) array(
                    'message' => 'Nonce verification failed — session may have expired.',
                    'type'    => 'error',
                ),
            ) );
        }
    }
}
add_action( 'tribe_events_community_before_event_submission_page', 'utm_ce_log_submission_attempt', 1 );

/**
 * Update the log after form validation completes (success or validation errors).
 *
 * Fires AFTER validation & save inside process_submission(),
 * but only if the nonce check passed.
 */
function utm_ce_log_after_validation() {
    $log_id = $GLOBALS['utm_ce_current_log_id'] ?? 0;
    if ( ! $log_id ) {
        return;
    }

    // If status is already 'nonce_fail' from the early check, don't overwrite.
    global $wpdb;
    $current_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM " . utm_ce_log_table() . " WHERE id = %d", $log_id
    ) );
    if ( 'nonce_fail' === $current_status ) {
        return;
    }

    // Grab messages from the submission handler singleton.
    $messages = \TEC\Events_Community\Submission\Messages::get_instance()->get_messages();
    $has_error = false;
    foreach ( $messages as $msg ) {
        if ( 'error' === $msg->type ) {
            $has_error = true;
            break;
        }
    }

    $status = $has_error ? 'validation_error' : 'success';

    // Capture event_id if available.
    $event_id = 0;
    if ( isset( $GLOBALS['Events_Community_Event_ID'] ) ) {
        $event_id = (int) $GLOBALS['Events_Community_Event_ID'];
    }

    $wpdb->update(
        utm_ce_log_table(),
        array(
            'status'   => $status,
            'messages' => wp_json_encode( $messages ),
            'event_id' => $event_id,
        ),
        array( 'id' => $log_id )
    );
}
add_action( 'tribe_events_community_after_form_validation', 'utm_ce_log_after_validation' );

/**
 * Update the log on explicit save failure.
 */
function utm_ce_log_save_failure( $event_id ) {
    $log_id = $GLOBALS['utm_ce_current_log_id'] ?? 0;
    if ( ! $log_id ) {
        return;
    }
    utm_ce_set_log_status( $log_id, 'save_failure', null, $event_id );
}
add_action( 'tribe_community_event_save_failure', 'utm_ce_log_save_failure' );

/**
 * Update the log on successful save.
 */
function utm_ce_log_save_updated( $event_id ) {
    $log_id = $GLOBALS['utm_ce_current_log_id'] ?? 0;
    if ( ! $log_id ) {
        return;
    }

    global $wpdb;
    // Don't downgrade from 'validation_error' to 'success' — the message
    // queue may already tell the story, but the save still happened.
    $current_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM " . utm_ce_log_table() . " WHERE id = %d", $log_id
    ) );

    if ( 'validation_error' === $current_status ) {
        // Save succeeded despite validation warnings — keep "validation_error"
        // so admin knows something was amiss, but also store the event_id.
        $wpdb->update(
            utm_ce_log_table(),
            array( 'event_id' => (int) $event_id ),
            array( 'id' => $log_id )
        );
        return;
    }

    utm_ce_set_log_status( $log_id, 'success', null, $event_id );
}
add_action( 'tribe_community_event_save_updated', 'utm_ce_log_save_updated' );

/**
 * Shutdown handler — catches silent failures (spam redirect, unexpected exit)
 * that bypass all the hooks above.
 */
function utm_ce_log_shutdown() {
    $log_id = $GLOBALS['utm_ce_current_log_id'] ?? 0;
    if ( ! $log_id ) {
        return;
    }

    global $wpdb;
    $current_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM " . utm_ce_log_table() . " WHERE id = %d", $log_id
    ) );

    // If still 'attempt', something went wrong silently — spam or early exit.
    if ( 'attempt' === $current_status ) {
        utm_ce_set_log_status( $log_id, 'spam_or_exit', array(
            (object) array(
                'message' => 'Request ended without completing processing (spam check, expired session, or other silent exit).',
                'type'    => 'error',
            ),
        ) );
    }
}
register_shutdown_function( 'utm_ce_log_shutdown' );

/**
 * Helper: update log status and messages for a given log ID.
 */
function utm_ce_set_log_status( $log_id, $status, $messages = null, $event_id = 0 ) {
    global $wpdb;
    $data = array( 'status' => $status );
    if ( null !== $messages ) {
        $data['messages'] = wp_json_encode( $messages );
    }
    if ( $event_id ) {
        $data['event_id'] = (int) $event_id;
    }
    $wpdb->update( utm_ce_log_table(), $data, array( 'id' => $log_id ) );
}

/**
 * Admin page: view submission log.
 */
function utm_ce_admin_menu() {
    if ( ! utm_events_is_events_host() ) {
        return;
    }
    add_submenu_page(
        'edit.php?post_type=tribe_events',
        'Submission Log',
        'Submission Log',
        'manage_options',
        'utm-ce-submission-log',
        'utm_ce_admin_page'
    );
}
add_action( 'admin_menu', 'utm_ce_admin_menu' );

/**
 * Render the admin log viewer.
 */
function utm_ce_admin_page() {
    global $wpdb;
    $table = utm_ce_log_table();

    // Pagination.
    $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $per_page = 50;
    $offset   = ( $page - 1 ) * $per_page;

    // Filter by status.
    $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    $where = '';
    $params = array();
    if ( $status_filter ) {
        $where  = 'WHERE status = %s';
        $params[] = $status_filter;
    }

    if ( $params ) {
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table $where", $params
        ) );
    } else {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }
    $total_pages = ceil( $total / $per_page );

    if ( $params ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY logged_at DESC LIMIT %d OFFSET %d",
            array_merge( $params, array( $per_page, $offset ) )
        ) );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY logged_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
    }

    $statuses = $wpdb->get_col( "SELECT DISTINCT status FROM $table ORDER BY status" );

    ?>
    <div class="wrap">
        <h1>Community Events Submission Log</h1>

        <form method="get">
            <input type="hidden" name="post_type" value="tribe_events" />
            <input type="hidden" name="page" value="utm-ce-submission-log" />
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ( $statuses as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
                        <?php echo esc_html( $s ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Filter</button>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>User</th>
                    <th>IP</th>
                    <th>Title</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7">No submissions logged yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $sub_data = json_decode( $row->submission_data, true );
                        $msgs     = json_decode( $row->messages, true );
                        $title    = $sub_data['post_title'] ?? '(no title)';
                        $errors   = array();
                        if ( is_array( $msgs ) ) {
                            foreach ( $msgs as $m ) {
                                $m = (array) $m;
                                if ( isset( $m['type'] ) && 'error' === $m['type'] ) {
                                    $errors[] = $m['message'];
                                }
                            }
                        }
                        $user_info = $row->user_id ? get_userdata( $row->user_id ) : null;
                        $user_name = $user_info ? $user_info->display_name : ( $row->user_id ? "#{$row->user_id}" : 'Anonymous' );
                        ?>
                        <tr>
                            <td><?php echo (int) $row->id; ?></td>
                            <td><?php echo esc_html( $row->logged_at ); ?></td>
                            <td>
                                <span style="color: <?php echo 'success' === $row->status ? 'green' : ( 'attempt' === $row->status ? 'orange' : 'red' ); ?>;">
                                    <?php echo esc_html( $row->status ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $user_name ); ?></td>
                            <td><code><?php echo esc_html( $row->user_ip ?: '-' ); ?></code></td>
                            <td><?php echo esc_html( $title ); ?></td>
                            <td>
                                <?php if ( empty( $errors ) ) : ?>
                                    <span style="color:#999">-</span>
                                <?php else : ?>
                                    <?php foreach ( $errors as $e ) : ?>
                                        <div style="color:#c00;font-size:12px;">• <?php echo esc_html( $e ); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================
//  Shortcode: utm_upcoming_events
// ============================================================

function utm_get_upcoming_events_shortcode($atts) {
    // Check if The Events Calendar is active
    if (!class_exists('Tribe__Events__Main')) {
        return '<p>The Events Calendar plugin is not active.</p>';
    }

    $atts = shortcode_atts(array(
        'limit' => 6,
        'thumbnail_size' => 'medium'
    ), $atts, 'utm_upcoming_events');

    $events = tribe_get_events(array(
        'posts_per_page' => $atts['limit'],
        'start_date' => 'now',
        'orderby' => 'event_date',
        'order' => 'ASC'
    ));

    // If number of events is less than the limit, get the latest 4 events including past events
    if (count($events) < $atts['limit']) {
        $past_events = tribe_get_events(array(
            'posts_per_page' => $atts['limit'] - count($events),
            'end_date' => 'now',
            'orderby' => 'event_date',
            'order' => 'DESC'
        ));
        $events = array_merge($events, $past_events);
    }

    if (empty($events)) {
        return '<p>No upcoming events found.</p>';
    }

    $output = '<ul class="utm-upcoming-events">';

    foreach ($events as $event) {
        $event_link = get_permalink($event->ID);
        $thumbnail = sprintf(
            '<a href="%s" class="utm-event-thumbnail" style="background-image: url(%s);"></a>',
            esc_url($event_link),
            esc_url(get_the_post_thumbnail_url($event->ID, $atts['thumbnail_size']))
        );

        $output .= sprintf(
            '<li class="utm-event-item">%s</li>',
            $thumbnail
        );
    }

    $output .= '</ul>';

    // Updated CSS styles
    $output .= '
    <style>
        .utm-upcoming-events {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 24px;
        }
        .utm-event-item {
            aspect-ratio: 2/3; /* Changed from 1/1 to 2/3 for portrait orientation */
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s ease;
        }
        .utm-event-item:hover {
            transform: translateY(-5px);
        }
        .utm-event-thumbnail {
            display: block;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        @media (min-width: 768px) {
            .utm-upcoming-events {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 767px) {
            .utm-upcoming-events {
                grid-template-columns: 1fr;
            }
        }
    </style>';

    return $output;
}
add_shortcode('utm_upcoming_events', 'utm_get_upcoming_events_shortcode');
