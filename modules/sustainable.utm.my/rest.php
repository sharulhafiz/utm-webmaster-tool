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
    $payload = $request->get_json_params();

    if ( ! is_array( $payload ) ) {
        $payload = array();
    }

    $post_id = utm_sustainable_upsert_page_from_google_doc( $payload );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    return rest_ensure_response(
        array(
            'ok'      => true,
            'post_id' => (int) $post_id,
        )
    );
}
