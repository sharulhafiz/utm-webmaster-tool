<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register sustainable sync endpoints.
 */
function utm_sustainable_register_rest_routes() {
    register_rest_route(
        'utm-sustainable/v1',
        '/post-by-google-id',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'utm_sustainable_rest_post_by_google_id',
            'permission_callback' => 'utm_sustainable_rest_can_sync',
        )
    );

    register_rest_route(
        'utm-sustainable/v1',
        '/sync-page',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'utm_sustainable_rest_sync_page',
            'permission_callback' => 'utm_sustainable_rest_can_sync',
        )
    );
}
add_action( 'rest_api_init', 'utm_sustainable_register_rest_routes' );

/**
 * Permission callback for sync endpoints.
 *
 * @return true|WP_Error
 */
function utm_sustainable_rest_can_sync() {
    if ( ! is_user_logged_in() ) {
        return new WP_Error( 'utm_sustainable_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
    }

    if ( ! current_user_can( 'edit_pages' ) ) {
        return new WP_Error( 'utm_sustainable_forbidden', 'Insufficient capability.', array( 'status' => 403 ) );
    }

    return true;
}

/**
 * Get mapped page by google_id.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function utm_sustainable_rest_post_by_google_id( WP_REST_Request $request ) {
    $google_id = sanitize_text_field( (string) $request->get_param( 'google_id' ) );

    if ( '' === $google_id ) {
        return new WP_Error( 'utm_sustainable_missing_google_id', 'google_id is required.', array( 'status' => 400 ) );
    }

    $post_id = utm_sustainable_find_page_by_google_id( $google_id );

    if ( $post_id <= 0 ) {
        return rest_ensure_response(
            array(
                'id' => null,
            )
        );
    }

    return rest_ensure_response(
        array(
            'id'       => $post_id,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
        )
    );
}

/**
 * Upsert page from sync payload.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function utm_sustainable_rest_sync_page( WP_REST_Request $request ) {
    $started_at = microtime( true );
    $payload = $request->get_json_params();

    if ( ! is_array( $payload ) ) {
        $payload = array();
    }

    $google_id       = sanitize_text_field( (string) ( $payload['google_id'] ?? '' ) );
    $google_modified = sanitize_text_field( (string) ( $payload['google_modified'] ?? '' ) );
    $lock_key        = utm_sustainable_rest_get_sync_lock_key( $payload, $google_id );

    if ( get_transient( $lock_key ) ) {
        $elapsed = microtime( true ) - $started_at;
        utm_sustainable_rest_log_sync_timing( $google_id, $elapsed, 'skipped', 'locked' );

        return new WP_Error(
            'utm_sustainable_sync_locked',
            'A sync request for this document is already in progress.',
            array( 'status' => 429 )
        );
    }

    set_transient( $lock_key, 1, 120 );

    try {
        if ( '' !== $google_id ) {
            $cooldown_key    = utm_sustainable_rest_get_sync_cooldown_key( $google_id );
            $cooldown_window = (int) apply_filters( 'utm_sustainable_sync_cooldown_seconds', 90 );
            if ( $cooldown_window < 1 ) {
                $cooldown_window = 90;
            }

            $last_success = get_transient( $cooldown_key );

            if (
                is_array( $last_success )
                && ! empty( $last_success['google_modified'] )
                && '' !== $google_modified
                && hash_equals( (string) $last_success['google_modified'], $google_modified )
            ) {
                $elapsed          = microtime( true ) - $started_at;
                $cooldown_post_id = 0;

                if ( ! empty( $last_success['post_id'] ) ) {
                    $cooldown_post_id = (int) $last_success['post_id'];
                }

                if ( $cooldown_post_id <= 0 ) {
                    $cooldown_post_id = utm_sustainable_find_page_by_google_id( $google_id );
                }

                utm_sustainable_rest_log_sync_timing( $google_id, $elapsed, 'skipped', 'cooldown' );

                return rest_ensure_response(
                    array(
                        'ok'      => true,
                        'post_id' => (int) $cooldown_post_id,
                        'skipped' => true,
                        'reason'  => 'cooldown',
                    )
                );
            }
        }

        $post_id = utm_sustainable_upsert_page_from_google_doc( $payload );

        if ( is_wp_error( $post_id ) ) {
            $elapsed = microtime( true ) - $started_at;
            utm_sustainable_rest_log_sync_timing( $google_id, $elapsed, 'error', $post_id->get_error_code() );

            return $post_id;
        }

        if ( '' !== $google_id && '' !== $google_modified ) {
            set_transient(
                utm_sustainable_rest_get_sync_cooldown_key( $google_id ),
                array(
                    'google_modified' => $google_modified,
                    'post_id'         => (int) $post_id,
                    'at'              => time(),
                ),
                max( 1, (int) apply_filters( 'utm_sustainable_sync_cooldown_seconds', 90 ) )
            );
        }

        $action = 'updated';
        $reason = '';

        if ( function_exists( 'utm_sustainable_get_last_upsert_result_meta' ) ) {
            $last_upsert_meta = utm_sustainable_get_last_upsert_result_meta();

            if ( is_array( $last_upsert_meta ) ) {
                if ( ! empty( $last_upsert_meta['action'] ) ) {
                    $action = sanitize_key( (string) $last_upsert_meta['action'] );
                }

                if ( ! empty( $last_upsert_meta['reason'] ) ) {
                    $reason = sanitize_key( (string) $last_upsert_meta['reason'] );
                }
            }
        }

        $elapsed = microtime( true ) - $started_at;
        utm_sustainable_rest_log_sync_timing( $google_id, $elapsed, $action, $reason );

        return rest_ensure_response(
            array(
                'ok'      => true,
                'post_id' => (int) $post_id,
            )
        );
    } finally {
        delete_transient( $lock_key );
    }
}

/**
 * Build transient key for per-document in-flight lock.
 *
 * @param array  $payload   Sync payload.
 * @param string $google_id Google Doc ID.
 * @return string
 */
function utm_sustainable_rest_get_sync_lock_key( $payload, $google_id ) {
    $google_id = sanitize_text_field( (string) $google_id );

    if ( '' !== $google_id ) {
        return 'utm_sust_sync_lock_' . md5( $google_id );
    }

    $payload_hash = md5( wp_json_encode( (array) $payload ) );

    return 'utm_sust_sync_lock_' . $payload_hash;
}

/**
 * Build transient key for short-term successful sync cooldown.
 *
 * @param string $google_id Google Doc ID.
 * @return string
 */
function utm_sustainable_rest_get_sync_cooldown_key( $google_id ) {
    return 'utm_sust_sync_cd_' . md5( sanitize_text_field( (string) $google_id ) );
}

/**
 * Log sync timing/action details.
 *
 * @param string $google_id Google Doc ID.
 * @param float  $elapsed   Elapsed seconds.
 * @param string $action    skipped|updated|inserted|error.
 * @param string $reason    Optional reason.
 * @return void
 */
function utm_sustainable_rest_log_sync_timing( $google_id, $elapsed, $action, $reason = '' ) {
    $payload = array(
        'component' => 'sustainable.sync',
        'google_id' => sanitize_text_field( (string) $google_id ),
        'elapsed'   => round( max( 0, (float) $elapsed ), 4 ),
        'action'    => sanitize_key( (string) $action ),
    );

    if ( '' !== $reason ) {
        $payload['reason'] = sanitize_key( (string) $reason );
    }

    error_log( '[utm-sustainable-sync] ' . wp_json_encode( $payload ) );
}
