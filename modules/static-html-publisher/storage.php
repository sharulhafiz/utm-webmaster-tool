<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — storage operations.
 *
 * Page ID is the stable identity for packages. Changing a Page's title,
 * slug or parent does not affect the stored files.
 *
 * Directory layout:
 *   wp-content/static-packages/{page_id}/        ← active (live) package
 *   wp-content/static-packages/{page_id}__staging ← staging (during extraction)
 */

/**
 * Base directory for all static packages.
 *
 * @return string Absolute path.
 */
function utm_shp_packages_dir() {
    return WP_CONTENT_DIR . '/static-packages';
}

/**
 * Active package directory for a specific Page.
 *
 * @param  int $page_id WordPress Page ID.
 * @return string        Absolute path.
 */
function utm_shp_package_dir( $page_id ) {
    return utm_shp_packages_dir() . '/' . (int) $page_id;
}

/**
 * Staging directory for a Page (during extraction).
 *
 * @param  int $page_id WordPress Page ID.
 * @return string        Absolute path.
 */
function utm_shp_staging_dir( $page_id ) {
    return utm_shp_packages_dir() . '/' . (int) $page_id . '__staging_' . wp_generate_password( 8, false );
}

/**
 * Path to the index.html inside the active package.
 *
 * @param  int $page_id WordPress Page ID.
 * @return string|false  Absolute path or false if not found.
 */
function utm_shp_index_html( $page_id ) {
    $dir = utm_shp_package_dir( $page_id );
    $index = $dir . '/index.html';
    if ( is_file( $index ) ) {
        return $index;
    }
    return false;
}

/**
 * Check whether a Page has an active static package.
 *
 * @param  int $page_id WordPress Page ID.
 * @return bool
 */
function utm_shp_has_package( $page_id ) {
    return false !== utm_shp_index_html( $page_id );
}

// ── Database helpers ──────────────────────────────────────────────

/**
 * Ensure the packages metadata table exists.
 *
 * Uses Page ID as the stable key. Slug/title/parent are informational only
 * and updated on each publish for display purposes.
 */
function utm_shp_ensure_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'shp_packages';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        page_id       BIGINT(20) UNSIGNED NOT NULL,
        zip_name      VARCHAR(255) NOT NULL DEFAULT '',
        status        ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
        file_count    INT UNSIGNED NOT NULL DEFAULT 0,
        total_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT NULL,
        PRIMARY KEY (page_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'init', 'utm_shp_ensure_table' );

/**
 * Get package metadata row.
 *
 * @param  int $page_id
 * @return object|null
 */
function utm_shp_get_meta( $page_id ) {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'shp_packages WHERE page_id = %d', $page_id )
    );
}

/**
 * Upsert package metadata.
 *
 * @param int    $page_id
 * @param string $zip_name
 * @param int    $file_count
 * @param int    $total_bytes
 * @param string $status
 */
function utm_shp_set_meta( $page_id, $zip_name, $file_count, $total_bytes, $status = 'active' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'shp_packages';
    $now   = current_time( 'mysql', true );

    $existing = $wpdb->get_var(
        $wpdb->prepare( 'SELECT page_id FROM ' . $table . ' WHERE page_id = %d', $page_id )
    );

    $data = [
        'zip_name'    => $zip_name,
        'status'      => $status,
        'file_count'  => $file_count,
        'total_bytes' => $total_bytes,
        'updated_at'  => $now,
    ];

    if ( $existing ) {
        $wpdb->update( $table, $data, [ 'page_id' => $page_id ] );
    } else {
        $data['page_id']    = $page_id;
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );
    }
}

/**
 * Delete package metadata row.
 *
 * @param int $page_id
 */
function utm_shp_delete_meta( $page_id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'shp_packages', [ 'page_id' => $page_id ] );
}

// ── Extraction ────────────────────────────────────────────────────

/**
 * Recursively calculate total size of all files in a directory.
 *
 * @param  string $dir
 * @return int     Total size in bytes.
 */
function utm_shp_dir_size( $dir ) {
    $total = 0;
    if ( ! is_dir( $dir ) ) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
    );
    foreach ( $it as $file ) {
        if ( $file->isFile() ) {
            $total += $file->getSize();
        }
    }
    return $total;
}

/**
 * Recursively remove a directory and all contents.
 *
 * @param string $dir Absolute path.
 */
function utm_shp_rmdir_recursive( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
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

/**
 * Remove any stale staging directories for a Page.
 *
 * @param int $page_id
 */
function utm_shp_cleanup_staging( $page_id ) {
    $base = utm_shp_packages_dir();
    $prefix = (int) $page_id . '__staging_';
    foreach ( glob( $base . '/' . $prefix . '*' ) as $stale ) {
        if ( is_dir( $stale ) ) {
            utm_shp_rmdir_recursive( $stale );
        }
    }
}

/**
 * Extract a validated ZIP into a staging directory, then activate.
 *
 * Flow:
 *   1. Create staging dir
 *   2. Extract ZIP entries into staging
 *   3. Validate extracted content (containment, index.html location, symlinks)
 *   4. Enforce size/count limits on extracted files
 *   5. Remove old active directory (if any)
 *   6. Rename staging → active
 *   7. Upsert DB metadata
 *
 * On any failure, staging is cleaned up and the old active directory
 * is left untouched.
 *
 * @param  string $zip_path Absolute path to validated ZIP.
 * @param  int    $page_id  WordPress Page ID.
 * @param  string $zip_name Original upload filename (for metadata).
 * @param  array  $limits   Optional overrides passed to utm_shp_validate_zip.
 * @return string[]          Error messages; empty on success.
 */
function utm_shp_activate( $zip_path, $page_id, $zip_name, $limits = [] ) {
    $max_uncompressed = $limits['max_uncompressed'] ?? 200 * 1024 * 1024;
    $max_files        = $limits['max_files']        ?? 500;

    // Clean up any stale staging directories from previous failed attempts.
    utm_shp_cleanup_staging( $page_id );

    $staging = utm_shp_staging_dir( $page_id );
    $errors  = [];

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path ) ) {
        return [ 'Failed to open ZIP for extraction.' ];
    }

    $base = utm_shp_packages_dir();
    if ( ! is_dir( $base ) ) {
        wp_mkdir_p( $base );
    }

    if ( ! @mkdir( $staging, 0755, true ) ) {
        $zip->close();
        return [ 'Failed to create staging directory.' ];
    }

    // Extract each entry, enforcing containment.
    $file_count = 0;
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( false === $name ) {
            continue;
        }

        $norm = str_replace( '\\', '/', str_replace( "\0", '', $name ) );
        $norm = ltrim( $norm, '/' );

        if ( str_starts_with( $norm, '..' ) || str_starts_with( $norm, './' ) ) {
            $errors[] = "Skipped unsafe entry: $name";
            continue;
        }

        $dest = $staging . '/' . $norm;

        // Canonical containment check before extraction.
        $dest_dir = dirname( $dest );
        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }
        $real_dest_dir = realpath( $dest_dir );
        $real_staging  = realpath( $staging );

        if ( false === $real_dest_dir
             || false === $real_staging
             || 0 !== strpos( $real_dest_dir, $real_staging )
        ) {
            $errors[] = "Entry escapes staging dir: $name";
            continue;
        }

        if ( str_ends_with( $norm, '/' ) ) {
            continue; // Directory already created above.
        }

        $zip->extractTo( $staging, $norm );
        $file_count++;
    }
    $zip->close();

    // ── Post-extraction validation ──────────────────────────────

    // 1. Verify index.html is inside the final publication root.
    $index_found = false;
    $index_rel   = '';
    $scan_dir    = $staging;

    $candidates = [
        $staging . '/index.html',
        $staging . '/index.htm',
    ];

    // Check single-subdirectory fallback.
    $entries = array_diff( scandir( $staging ), [ '.', '..' ] );
    $dirs    = array_filter( $entries, function ( $e ) use ( $staging ) {
        return is_dir( $staging . '/' . $e );
    } );
    $files_at_root = array_diff( $entries, $dirs );

    if ( empty( $files_at_root ) && 1 === count( $dirs ) ) {
        $subdir = array_values( $dirs )[0];
        $candidates[] = $staging . '/' . $subdir . '/index.html';
        $candidates[] = $staging . '/' . $subdir . '/index.htm';
        $scan_dir     = $staging . '/' . $subdir;
    }

    foreach ( $candidates as $cand ) {
        if ( is_file( $cand ) ) {
            $real_cand = realpath( $cand );
            $real_stg  = realpath( $staging );
            if ( false !== $real_cand && 0 === strpos( $real_cand, $real_stg ) ) {
                $index_found = true;
                $index_rel   = str_replace( $staging . '/', '', $real_cand );
                break;
            }
        }
    }

    if ( ! $index_found ) {
        utm_shp_rmdir_recursive( $staging );
        return [ 'index.html not found inside extracted package root.' ];
    }

    // 2. Post-extraction symlink scan.
    $symlink_errors = utm_shp_scan_symlinks( $staging );
    if ( ! empty( $symlink_errors ) ) {
        utm_shp_rmdir_recursive( $staging );
        return array_merge( $errors, $symlink_errors );
    }

    // 3. Enforce extracted size and file count limits.
    $total_size = utm_shp_dir_size( $staging );
    if ( $total_size > $max_uncompressed ) {
        utm_shp_rmdir_recursive( $staging );
        return [ sprintf(
            'Extracted content too large (%s, limit is %s).',
            size_format( $total_size ),
            size_format( $max_uncompressed )
        ) ];
    }
    if ( $file_count > $max_files ) {
        utm_shp_rmdir_recursive( $staging );
        return [ sprintf(
            'Too many files in package (%d, limit is %d).',
            $file_count,
            $max_files
        ) ];
    }

    // ── Activation ──────────────────────────────────────────────

    // Remove old active directory (if any).
    $active = utm_shp_package_dir( $page_id );
    if ( is_dir( $active ) ) {
        utm_shp_rmdir_recursive( $active );
    }

    // Atomic swap: rename staging → active.
    if ( ! rename( $staging, $active ) ) {
        // rename() can fail across filesystems — fall back to copy.
        if ( ! copy( $staging, $active ) ) {
            utm_shp_rmdir_recursive( $staging );
            return [ 'Failed to move staging directory into place.' ];
        }
        utm_shp_rmdir_recursive( $staging );
    }

    // Persist metadata.
    utm_shp_set_meta( $page_id, $zip_name, $file_count, $total_size, 'active' );

    return $errors; // Non-empty = warnings only (extraction succeeded).
}

/**
 * Deactivate and delete a Page's package.
 *
 * @param int $page_id
 */
function utm_shp_deactivate( $page_id ) {
    $dir = utm_shp_package_dir( $page_id );
    if ( is_dir( $dir ) ) {
        utm_shp_rmdir_recursive( $dir );
    }
    utm_shp_delete_meta( $page_id );
    utm_shp_cleanup_staging( $page_id );
}
