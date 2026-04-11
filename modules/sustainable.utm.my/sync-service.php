<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Find page ID by Google Doc mapping meta.
 *
 * @param string $google_id Google Doc ID.
 * @return int
 */
function utm_sustainable_find_page_by_google_id( $google_id ) {
    $google_id = sanitize_text_field( (string) $google_id );

    if ( '' === $google_id ) {
        return 0;
    }

    $query = new WP_Query(
        array(
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
            'posts_per_page' => 1,
            'meta_key'       => '_utm_google_doc_id',
            'meta_value'     => $google_id,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        )
    );

    return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
}

/**
 * Create or update page from Google payload.
 *
 * @param array $payload Sync payload.
 * @return int|WP_Error
 */
function utm_sustainable_upsert_page_from_google_doc( $payload ) {
    $google_id  = sanitize_text_field( $payload['google_id'] ?? '' );
    $title      = sanitize_text_field( $payload['title'] ?? '' );
    $status     = sanitize_key( $payload['status'] ?? 'publish' );
    $content    = (string) ( $payload['content'] ?? '' );
    $folder_path = $payload['folder_path'] ?? array();

    if ( '' === $google_id || '' === $title ) {
        return new WP_Error( 'utm_sustainable_invalid_payload', 'google_id and title are required.', array( 'status' => 400 ) );
    }

    if ( ! in_array( $status, array( 'publish', 'draft', 'private', 'pending' ), true ) ) {
        $status = 'publish';
    }

    $content = utm_sustainable_transform_bracketed_pdf_links( $content );
    $content = utm_sustainable_cleanup_google_doc_content( $content );
    $content = wp_kses_post( $content );

    $post_id = utm_sustainable_find_page_by_google_id( $google_id );

    $postarr = array(
        'post_type'    => 'page',
        'post_status'  => $status,
        'post_title'   => $title,
        'post_content' => $content,
    );

    if ( $post_id > 0 ) {
        $postarr['ID'] = $post_id;
        $result        = wp_update_post( $postarr, true );
    } else {
        $result  = wp_insert_post( $postarr, true );
        $post_id = is_wp_error( $result ) ? 0 : (int) $result;
    }

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    update_post_meta( $post_id, '_utm_google_doc_id', $google_id );

    if ( ! empty( $payload['google_modified'] ) ) {
        update_post_meta( $post_id, '_utm_google_doc_modified_at', sanitize_text_field( (string) $payload['google_modified'] ) );
    }

    if ( is_array( $folder_path ) && ! empty( $folder_path ) ) {
        $menu_result = utm_sustainable_ensure_menu_path( 'Main Menu', $folder_path, $post_id );

        if ( is_wp_error( $menu_result ) ) {
            return $menu_result;
        }
    }

    return (int) $post_id;
}
