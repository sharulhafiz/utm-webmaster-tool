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
    $host = preg_replace( '/:\d+$/', '', $host );

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

// // if tribe_get_events or tribe_get_start_date is not defined, define a dummy function to avoid fatal error
// if (!function_exists('tribe_get_events')) {
//     function tribe_get_events($args) {
//         return array();
//     }
// }
// if (!function_exists('tribe_get_start_date')) {
//     function tribe_get_start_date($event, $format = false, $date_format = 'F j, Y') {
//         return date($date_format);
//     }
// }

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