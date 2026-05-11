<?php
/**
 * Module: news.utm.my editor/runtime stability
 *
 * Purpose:
 * - Reduce admin editor connection-loss events by lowering heartbeat frequency.
 * - Contain Action Scheduler async pressure during interactive admin/editor requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensure this module only runs on news.utm.my.
 *
 * @return bool
 */
function utm_news_stability_is_news_host() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    return is_string( $host ) && 'news.utm.my' === strtolower( $host );
}

if ( ! utm_news_stability_is_news_host() ) {
    return;
}

/**
 * Detect whether request is interactive editor/admin activity.
 *
 * @return bool
 */
function utm_news_stability_is_interactive_request() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

    if ( is_admin() && ( false !== strpos( (string) $request_uri, '/wp-admin/post.php' ) || false !== strpos( (string) $request_uri, '/wp-admin/post-new.php' ) ) ) {
        return true;
    }

    if ( wp_doing_ajax() && in_array( $ajax_action, array( 'heartbeat', 'autosave' ), true ) ) {
        return true;
    }

    return false;
}

/**
 * Reduce heartbeat frequency to lower admin-ajax DB pressure.
 */
add_filter(
    'heartbeat_settings',
    function( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        if ( is_admin() ) {
            $settings['interval'] = 45;
        }

        return $settings;
    },
    20
);

/**
 * Keep Action Scheduler queue runners conservative on news.
 */
add_filter(
    'action_scheduler_queue_runner_concurrent_batches',
    function( $batches ) {
        return 1;
    },
    20
);

add_filter(
    'action_scheduler_queue_runner_batch_size',
    function( $batch_size ) {
        return 10;
    },
    20
);

/**
 * Disable async queue-runner path on news to avoid admin-ajax contention.
 * Queue processing should happen via scheduled/system-driven runs, not
 * on interactive request paths.
 */
add_filter(
    'action_scheduler_allow_async_request_runner',
    function( $allow_async_runner ) {
        return false;
    },
    20
);

/**
 * If MailPoet exposes this filter, disable its cron trigger during editor activity.
 */
add_filter(
    'mailpoet_cron_trigger_enabled',
    function( $enabled ) {
        if ( utm_news_stability_is_interactive_request() ) {
            return false;
        }

        return $enabled;
    },
    20
);
