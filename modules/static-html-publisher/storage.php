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
 * Metadata is stored as WordPress post meta (not a custom table) because:
 *   - All fields are simple scalars 1:1 with a Page
 *   - Automatic cleanup on page deletion (WP core handles trash/delete)
 *   - Multisite works natively (post meta is site-scoped)
 *   - No custom CREATE TABLE / dbDelta needed
 *   - No orphaned rows on module uninstall
 *   - Standard WordPress idiom — familiar to other developers
 *
 * Directory layout:
 *   wp-content/static-packages/{page_id}/        ← active (live) package
 *   wp-content/static-packages/{page_id}__staging ← staging (during extraction)
 */

// ── Meta keys ────────────────────────────────────────────────────
define( 'UTM_SHP_META_PREFIX', '_shp_' );
define( 'UTM_SHP_META_ACTIVE',          UTM_SHP_META_PREFIX . 'active' );
define( 'UTM_SHP_META_ZIP_NAME',        UTM_SHP_META_PREFIX . 'zip_name' );
define( 'UTM_SHP_META_FILE_COUNT',      UTM_SHP_META_PREFIX . 'file_count' );
define( 'UTM_SHP_META_TOTAL_BYTES',     UTM_SHP_META_PREFIX . 'total_bytes' );
define( 'UTM_SHP_META_WAS_ACTIVE_BEFORE_TRASH', UTM_SHP_META_PREFIX . 'was_active_before_trash' );

// ── Directory helpers ────────────────────────────────────────────

/**
 * Base directory for all static packages.
 *
 * @return string Absolute path.
 */
function utm_shp_packages_dir() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/static-packages';
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
    $dir   = utm_shp_package_dir( $page_id );
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
    if ( ! get_post( $page_id ) ) {
        return false;
    }
    return '1' === get_post_meta( $page_id, UTM_SHP_META_ACTIVE, true )
        && false !== utm_shp_index_html( $page_id );
}

// ── Post meta helpers ────────────────────────────────────────────

/**
 * Get package metadata for a Page.
 *
 * @param  int $page_id
 * @return object|null  Object with zip_name, file_count, total_bytes, updated_at or null.
 */
function utm_shp_get_meta( $page_id ) {
    $active = get_post_meta( $page_id, UTM_SHP_META_ACTIVE, true );
    if ( '1' !== $active ) {
        return null;
    }
    return (object) [
        'page_id'     => $page_id,
        'zip_name'    => get_post_meta( $page_id, UTM_SHP_META_ZIP_NAME, true ),
        'status'      => 'active',
        'file_count'  => (int) get_post_meta( $page_id, UTM_SHP_META_FILE_COUNT, true ),
        'total_bytes' => (int) get_post_meta( $page_id, UTM_SHP_META_TOTAL_BYTES, true ),
        'updated_at'  => get_post_meta( $page_id, UTM_SHP_META_PREFIX . 'updated_at', true ),
    ];
}

/**
 * Set package metadata on a Page.
 *
 * @param int    $page_id
 * @param string $zip_name
 * @param int    $file_count
 * @param int    $total_bytes
 * @param string $status      'active' or 'inactive'.
 */
function utm_shp_set_meta( $page_id, $zip_name, $file_count, $total_bytes, $status = 'active' ) {
    update_post_meta( $page_id, UTM_SHP_META_ACTIVE,     'active' === $status ? '1' : '0' );
    update_post_meta( $page_id, UTM_SHP_META_ZIP_NAME,   $zip_name );
    update_post_meta( $page_id, UTM_SHP_META_FILE_COUNT,  $file_count );
    update_post_meta( $page_id, UTM_SHP_META_TOTAL_BYTES, $total_bytes );
    update_post_meta( $page_id, UTM_SHP_META_PREFIX . 'updated_at', current_time( 'mysql', true ) );
}

/**
 * Delete all package metadata from a Page.
 *
 * @param int $page_id
 */
function utm_shp_delete_meta( $page_id ) {
    delete_post_meta( $page_id, UTM_SHP_META_ACTIVE );
    delete_post_meta( $page_id, UTM_SHP_META_ZIP_NAME );
    delete_post_meta( $page_id, UTM_SHP_META_FILE_COUNT );
    delete_post_meta( $page_id, UTM_SHP_META_TOTAL_BYTES );
    delete_post_meta( $page_id, UTM_SHP_META_PREFIX . 'updated_at' );
}

// ── Filesystem helpers ───────────────────────────────────────────

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
    $base   = utm_shp_packages_dir();
    $prefix = (int) $page_id . '__staging_';
    foreach ( glob( $base . '/' . $prefix . '*' ) as $stale ) {
        if ( is_dir( $stale ) ) {
            utm_shp_rmdir_recursive( $stale );
        }
    }
}

// ── Extraction & activation ──────────────────────────────────────

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
 *   7. Update post meta
 *
 * On any failure, staging is cleaned up and the old active directory
 * is left untouched.
 *
 * @param  string $zip_path Absolute path to validated ZIP.
 * @param  int    $page_id  WordPress Page ID.
 * @param  string $zip_name Original upload filename (for metadata).
 * @param  array  $limits   Optional overrides passed to validation.
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
    $scan_dir    = $staging;

    $candidates = [
        $staging . '/index.html',
        $staging . '/index.htm',
    ];

    // Check single-subdirectory fallback.
    $entries     = array_diff( scandir( $staging ), [ '.', '..' ] );
    $dirs        = array_filter( $entries, function ( $e ) use ( $staging ) {
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

    // Persist metadata via post meta.
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

// ── WordPress lifecycle hooks ────────────────────────────────────
// Guard with function_exists so the file can be required for testing
// outside WordPress (validation + filesystem functions still work).

if ( function_exists( 'add_action' ) ) {

/**
 * When a Page is moved to trash: mark package inactive but preserve files.
 * The files stay on disk so restoring the Page can reactivate them.
 */
add_action( 'wp_trash_post', function ( $post_id ) {
    if ( 'page' !== get_post_type( $post_id ) ) {
        return;
    }
    // Mark inactive — template_redirect checks 'active' meta before serving.
    if ( '1' === get_post_meta( $post_id, UTM_SHP_META_ACTIVE, true ) ) {
        update_post_meta( $post_id, UTM_SHP_META_WAS_ACTIVE_BEFORE_TRASH, '1' );
        update_post_meta( $post_id, UTM_SHP_META_ACTIVE, '0' );
    }
} );

/**
 * When a Page is restored from trash: reactivate if files still exist.
 */
add_action( 'untrash_post', function ( $post_id ) {
    if ( 'page' !== get_post_type( $post_id ) ) {
        return;
    }
    if ( '1' === get_post_meta( $post_id, UTM_SHP_META_WAS_ACTIVE_BEFORE_TRASH, true )
         && false !== utm_shp_index_html( $post_id )
    ) {
        update_post_meta( $post_id, UTM_SHP_META_ACTIVE, '1' );
    }
    delete_post_meta( $post_id, UTM_SHP_META_WAS_ACTIVE_BEFORE_TRASH );
} );

/**
 * When a Page is permanently deleted: remove all package files and metadata.
 */
add_action( 'delete_post', function ( $post_id ) {
    if ( 'page' !== get_post_type( $post_id ) ) {
        return;
    }
    $dir = utm_shp_package_dir( $post_id );
    if ( is_dir( $dir ) ) {
        utm_shp_rmdir_recursive( $dir );
    }
    utm_shp_cleanup_staging( $post_id );
    // Post meta is automatically cleaned up by WP core on delete.
} );

} // end function_exists( 'add_action' ) guard.
