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
    $google_id   = sanitize_text_field( $payload['google_id'] ?? '' );
    $title       = sanitize_text_field( $payload['title'] ?? '' );
    $status      = sanitize_key( $payload['status'] ?? 'publish' );
    $content     = (string) ( $payload['content'] ?? '' );
    $folder_path = utm_sustainable_normalize_folder_path( $payload['folder_path'] ?? array() );
    $post_parent = 0;

    if ( '' === $google_id || '' === $title ) {
        return new WP_Error( 'utm_sustainable_invalid_payload', 'google_id and title are required.', array( 'status' => 400 ) );
    }

    if ( ! in_array( $status, array( 'publish', 'draft', 'private', 'pending' ), true ) ) {
        $status = 'publish';
    }

    $content = utm_sustainable_transform_bracketed_pdf_links( $content );
    $content = utm_sustainable_inline_google_doc_class_styles( $content );
    $content = utm_sustainable_cleanup_google_doc_content( $content );
    $content = utm_sustainable_normalize_inline_base64_image_sources( $content );
    $content = utm_sustainable_convert_inline_base64_images_to_uploads( $content );
    $content = utm_sustainable_sanitize_synced_content( $content );

    if ( ! empty( $folder_path ) ) {
        $post_parent = utm_sustainable_get_or_create_folder_page_chain( $folder_path );

        if ( is_wp_error( $post_parent ) ) {
            return $post_parent;
        }
    }

    $post_id = utm_sustainable_find_page_by_google_id( $google_id );

    $postarr = array(
        'post_type'    => 'page',
        'post_status'  => $status,
        'post_title'   => $title,
        'post_content' => $content,
        'post_parent'  => (int) $post_parent,
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

    update_post_meta( $post_id, '_utm_sustainable_folder_path', wp_json_encode( $folder_path ) );

    if ( is_array( $folder_path ) && ! empty( $folder_path ) ) {
        $menu_result = utm_sustainable_ensure_menu_path( 'Main Menu', $folder_path, $post_id );

        if ( is_wp_error( $menu_result ) ) {
            return $menu_result;
        }
    }

    return (int) $post_id;
}

/**
 * Normalize incoming folder path segments.
 *
 * @param mixed $folder_path Folder path payload.
 * @return array
 */
function utm_sustainable_normalize_folder_path( $folder_path ) {
    if ( ! is_array( $folder_path ) ) {
        return array();
    }

    $normalized = array();

    foreach ( $folder_path as $segment ) {
        $clean_segment = sanitize_text_field( (string) $segment );

        if ( '' === $clean_segment ) {
            continue;
        }

        $normalized[] = $clean_segment;
    }

    return $normalized;
}

/**
 * Build or find folder pages and return the final parent page ID.
 *
 * @param array $folder_path Folder path segments.
 * @return int|WP_Error
 */
function utm_sustainable_get_or_create_folder_page_chain( $folder_path ) {
    $folder_path     = utm_sustainable_normalize_folder_path( $folder_path );
    $parent_post_id  = 0;
    $path_key_parts  = array();

    if ( empty( $folder_path ) ) {
        return 0;
    }

    foreach ( $folder_path as $segment ) {
        $path_key_parts[] = sanitize_title( $segment );
        $folder_key       = implode( '/', $path_key_parts );

        $folder_page_id = utm_sustainable_find_folder_page_by_key( $folder_key );

        if ( $folder_page_id > 0 ) {
            $existing_parent = (int) wp_get_post_parent_id( $folder_page_id );

            if ( $existing_parent !== (int) $parent_post_id ) {
                wp_update_post(
                    array(
                        'ID'          => $folder_page_id,
                        'post_parent' => (int) $parent_post_id,
                    )
                );
            }

            $parent_post_id = $folder_page_id;
            continue;
        }

        $folder_insert_result = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $segment,
                'post_content' => '',
                'post_parent'  => (int) $parent_post_id,
            ),
            true
        );

        if ( is_wp_error( $folder_insert_result ) ) {
            return $folder_insert_result;
        }

        $folder_page_id = (int) $folder_insert_result;

        update_post_meta( $folder_page_id, '_utm_sustainable_folder_key', $folder_key );
        update_post_meta( $folder_page_id, '_utm_sustainable_is_folder_page', '1' );

        $parent_post_id = $folder_page_id;
    }

    return (int) $parent_post_id;
}

/**
 * Find folder page by deterministic folder key.
 *
 * @param string $folder_key Folder key.
 * @return int
 */
function utm_sustainable_find_folder_page_by_key( $folder_key ) {
    $folder_key = sanitize_text_field( (string) $folder_key );

    if ( '' === $folder_key ) {
        return 0;
    }

    $query = new WP_Query(
        array(
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
            'posts_per_page' => 1,
            'meta_key'       => '_utm_sustainable_folder_key',
            'meta_value'     => $folder_key,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        )
    );

    return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
}

/**
 * Sanitize synced HTML while preserving safe data URI images.
 *
 * @param string $content Raw HTML content.
 * @return string
 */
function utm_sustainable_sanitize_synced_content( $content ) {
    $allowed_html      = wp_kses_allowed_html( 'post' );
    $allowed_protocols = wp_allowed_protocols();

    if ( ! in_array( 'data', $allowed_protocols, true ) ) {
        $allowed_protocols[] = 'data';
    }

    return wp_kses( (string) $content, $allowed_html, $allowed_protocols );
}
