<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Magazine ZIP handler — upload, validate, extract, DB CRUD.
 */

define( 'UTM_MAGAZINE_TABLE', $GLOBALS['wpdb']->prefix . 'magazine_items' );

/**
 * Create the custom DB table on plugin activation / init flush.
 */
add_action( 'init', 'utm_magazine_ensure_table' );
function utm_magazine_ensure_table() {
    global $wpdb;
    $table   = UTM_MAGAZINE_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(191) NOT NULL,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        status      ENUM('published','draft') NOT NULL DEFAULT 'draft',
        zip_name    VARCHAR(255) NOT NULL DEFAULT '',
        updated_at  DATETIME DEFAULT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Base directory for published magazine publications on disk.
 *
 * Returns wp-content/magazine-publications  (absolute or content-relative).
 */
function utm_magazine_dir( $slug = '' ) {
    $base = WP_CONTENT_DIR . '/magazine-publications';
    return $slug ? rtrim( $base, '/' ) . '/' . $slug : $base;
}

/**
 * Get all magazine items from the DB.
 *
 * @param  string|null $status Optional status filter.
 * @return array
 */
function utm_magazine_get_items( $status = null ) {
    global $wpdb;
    $table = UTM_MAGAZINE_TABLE;
    $where = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
    return $wpdb->get_results( "SELECT * FROM $table $where ORDER BY created_at DESC" );
}

/**
 * Get a single magazine item by slug.
 *
 * @param  string $slug
 * @return object|null
 */
function utm_magazine_get_item( $slug ) {
    global $wpdb;
    $table = UTM_MAGAZINE_TABLE;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', $table, $slug ) );
}

/**
 * Upsert a magazine item.
 *
 * @param  string $slug
 * @param  string $title
 * @param  string $zip_name
 * @param  string $status
 * @return int|false  inserted/updated row ID on success.
 */
function utm_magazine_upsert( $slug, $title, $zip_name, $status = 'draft' ) {
    global $wpdb;
    $table = UTM_MAGAZINE_TABLE;
    $now   = current_time( 'mysql', true );

    $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $table, $slug ) );

    if ( $existing ) {
        $wpdb->update(
            $table,
            [ 'title' => $title, 'zip_name' => $zip_name, 'status' => $status, 'updated_at' => $now ],
            [ 'slug' => $slug ]
        );
        return (int) $existing;
    }

    $wpdb->insert(
        $table,
        [ 'slug' => $slug, 'title' => $title, 'zip_name' => $zip_name, 'status' => $status, 'created_at' => $now, 'updated_at' => $now ]
    );
    return $wpdb->insert_id;
}

/**
 * Delete a magazine item (DB row + directory).
 */
function utm_magazine_delete( $slug ) {
    global $wpdb;
    $wpdb->delete( UTM_MAGAZINE_TABLE, [ 'slug' => $slug ] );
    $dir = utm_magazine_dir( $slug );
    if ( is_dir( $dir ) ) {
        // Recursive delete (safe — contents are extracted ZIPs, not WP files).
        $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $f ) {
            if ( $f->isDir() ) {
                rmdir( $f->getRealPath() );
            } else {
                unlink( $f->getRealPath() );
            }
        }
        rmdir( $dir );
    }
}

/**
 * Safely extract a validated ZIP into the magazine publications directory.
 *
 * @param  string $zip_path  Absolute path to the validated ZIP file.
 * @param  string $slug      Magazine item slug.
 * @return string[]          Error strings; empty on success.
 */
function utm_magazine_extract( $zip_path, $slug ) {
    $target_dir = utm_magazine_dir( $slug );
    $temp_dir   = $target_dir . '__tmp_' . wp_generate_password( 8, false );
    $errors     = [];

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path ) ) {
        return [ 'Failed to open ZIP for extraction.' ];
    }

    $base = utm_magazine_dir();
    if ( ! is_dir( $base ) ) {
        wp_mkdir_p( $base );
    }

    mkdir( $temp_dir, 0755, true );

    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( false === $name ) {
            continue;
        }

        $norm = str_replace( '\\', '/', str_replace( "\0", '', $name ) );

        // Strip leading slash or traversal remnants.
        $norm = ltrim( $norm, '/' );
        if ( str_starts_with( $norm, '..' ) || str_starts_with( $norm, './' ) ) {
            $errors[] = "Skipped unsafe entry: $name";
            continue;
        }

        $dest = $temp_dir . '/' . $norm;
        $real = realpath( dirname( $dest ) );

        // Canonical containment check.
        $temp_real = realpath( $temp_dir );
        if ( false === $real || 0 !== strpos( $real, $temp_real ) ) {
            $errors[] = "Skipped entry outside target dir: $name";
            continue;
        }

        if ( str_ends_with( $norm, '/' ) ) {
            if ( ! is_dir( $dest ) ) {
                wp_mkdir_p( $dest );
            }
            continue;
        }

        $zip->extractTo( $temp_dir, $norm );
    }

    $zip->close();

    // Atomic swap: remove old dir, rename temp into place.
    if ( is_dir( $target_dir ) ) {
        $it = new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $f ) {
            if ( $f->isDir() ) {
                rmdir( $f->getRealPath() );
            } else {
                unlink( $f->getRealPath() );
            }
        }
        rmdir( $target_dir );
    }

    rename( $temp_dir, $target_dir );

    return $errors;
}

/**
 * AJAX handler — upload + extract a magazine ZIP.
 */
add_action( 'wp_ajax_magazine_upload', 'utm_magazine_ajax_upload' );
function utm_magazine_ajax_upload() {
    check_ajax_referer( 'magazine_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    if ( ! isset( $_FILES['magazine_zip'] ) ) {
        wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
    }

    $file = $_FILES['magazine_zip'];
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'Upload error ' . $file['error'] . '.' ] );
    }

    $slug   = sanitize_title( $_POST['slug'] ?? '' );
    $title  = sanitize_text_field( $_POST['title'] ?? '' );
    $status = in_array( $_POST['status'] ?? '', [ 'published', 'draft' ], true ) ? $_POST['status'] : 'draft';

    if ( empty( $slug ) ) {
        wp_send_json_error( [ 'message' => 'Slug is required.' ] );
    }

    // Save uploaded ZIP temporarily.
    $tmp_zip = wp_tempnam( 'magazine-' );
    move_uploaded_file( $file['tmp_name'], $tmp_zip );

    // Validate.
    $errors = utm_magazine_validate_zip( $tmp_zip );
    if ( ! empty( $errors ) ) {
        @unlink( $tmp_zip );
        wp_send_json_error( [ 'message' => 'Validation failed.', 'errors' => $errors ] );
    }

    // Extract.
    $extract_errs = utm_magazine_extract( $tmp_zip, $slug );
    @unlink( $tmp_zip );

    // Upsert DB.
    utm_magazine_upsert( $slug, $title, $file['name'], $status );

    wp_send_json_success( [
        'slug'    => $slug,
        'url'     => home_url( "/magazine/$slug/" ),
        'warnings' => $extract_errs,
    ] );
}

/**
 * AJAX handler — delete a magazine item.
 */
add_action( 'wp_ajax_magazine_delete', 'utm_magazine_ajax_delete' );
function utm_magazine_ajax_delete() {
    check_ajax_referer( 'magazine_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    $slug = sanitize_title( $_POST['slug'] ?? '' );
    if ( empty( $slug ) ) {
        wp_send_json_error( [ 'message' => 'Slug required.' ] );
    }

    utm_magazine_delete( $slug );
    wp_send_json_success( [ 'deleted' => $slug ] );
}
