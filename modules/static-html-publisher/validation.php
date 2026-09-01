<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — ZIP validation.
 *
 * Validates a ZIP archive before extraction. Returns an array of
 * human-readable error strings. Empty array = valid.
 *
 * Threat model:
 *   - ZIP Slip / path traversal (../ , absolute paths)
 *   - Symlink entries
 *   - Server-executable files (.php, .sh, .py, etc.)
 *   - Decompression bombs (file count, uncompressed size)
 *   - Missing required index.html
 *   - Oversized archive
 *
 * @param  string $zip_path Absolute filesystem path to the ZIP file.
 * @param  array  $limits   Optional overrides: max_bytes, max_files, max_uncompressed.
 * @return string[]          Error messages; empty on success.
 */
function utm_shp_validate_zip( $zip_path, $limits = [] ) {
    $errors = [];

    $max_bytes        = $limits['max_bytes']        ?? 50 * 1024 * 1024;   // 50 MB
    $max_files        = $limits['max_files']        ?? 500;
    $max_uncompressed = $limits['max_uncompressed'] ?? 200 * 1024 * 1024;  // 200 MB

    if ( ! file_exists( $zip_path ) ) {
        return [ 'ZIP file not found.' ];
    }

    $size = filesize( $zip_path );
    if ( 0 === $size ) {
        return [ 'ZIP file is empty.' ];
    }
    if ( $size > $max_bytes ) {
        return [ sprintf( 'ZIP exceeds size limit (%s).', size_format( $size ) ) ];
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path ) ) {
        return [ 'Could not open ZIP (error code: ' . $zip->getStatus() . ').' ];
    }

    $count     = $zip->numFiles;
    $total     = 0;
    $found     = [];
    $top_dirs  = [];
    $blocked   = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht',
        'phps', 'cgi', 'pl', 'py', 'pyc', 'pyo', 'sh', 'bash', 'csh',
        'asp', 'aspx', 'jsp', 'jspx', 'htaccess', 'htpasswd', 'ini',
        'exe', 'bat', 'cmd', 'com', 'msi', 'php2', 'phps',
    ];

    for ( $i = 0; $i < $count; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( false === $name ) {
            continue;
        }

        // Sanitise: strip null bytes, normalise separators.
        $name = str_replace( "\0", '', $name );
        $norm = str_replace( '\\', '/', $name );

        // Path traversal / absolute paths.
        if ( preg_match( '#(^|/)\\.\\./#', $norm )
             || preg_match( '#(^|/)\\.\\.$#', $norm )
             || str_starts_with( $norm, '/' )
             || preg_match( '#^[a-zA-Z]:#', $norm )
        ) {
            $errors[] = 'Path traversal or absolute path: ' . $norm;
            continue;
        }

        // Symlink detection via Unix mode bits.
        $info = $zip->statIndex( $i );
        if ( $info ) {
            $mode_val = 0;
            if ( isset( $info['attr'] ) ) {
                $mode_val = (int) $info['attr'];
            } elseif ( isset( $info['external']['unix'] ) ) {
                $ext = $info['external']['unix'];
                if ( is_array( $ext ) && isset( $ext[0] ) ) {
                    $mode_val = (int) $ext[0];
                }
            }
            // S_IFLNK = 0120000 = 40960.
            if ( 40960 === ( $mode_val & 0xF000 ) ) {
                $errors[] = 'Symlink entry: ' . $norm;
                continue;
            }
        }

        // Skip directories for file counting.
        if ( str_ends_with( $norm, '/' ) || '' === $norm ) {
            continue;
        }

        // Track top-level directories for index.html fallback detection.
        $parts = explode( '/', $norm );
        if ( count( $parts ) > 1 ) {
            $top_dirs[ $parts[0] ] = true;
        }

        // Blocked extension check.
        $ext = strtolower( pathinfo( $norm, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, $blocked, true ) ) {
            $errors[] = 'Blocked file type (.' . $ext . '): ' . $norm;
        }

        // Detect root-level index.html.
        if ( 'index.html' === strtolower( $norm ) || 'index.htm' === strtolower( $norm ) ) {
            $found['root_index'] = true;
        }

        $total += isset( $info['size'] ) ? (int) $info['size'] : 0;
    }

    if ( $count > $max_files ) {
        $errors[] = sprintf( 'ZIP contains too many files (%d, limit is %d).', $count, $max_files );
    }

    if ( $total > $max_uncompressed ) {
        $errors[] = sprintf(
            'Uncompressed total too large (%s, limit is %s).',
            size_format( $total ),
            size_format( $max_uncompressed )
        );
    }

    // Require index.html at root or inside a single top-level directory.
    if ( ! isset( $found['root_index'] ) ) {
        $has_index_in_subdir = false;
        if ( 1 === count( $top_dirs ) ) {
            $single_dir = array_key_first( $top_dirs );
            for ( $i = 0; $i < $count; $i++ ) {
                $n = $zip->getNameIndex( $i );
                if ( false === $n ) {
                    continue;
                }
                $n = strtolower( str_replace( '\\', '/', str_replace( "\0", '', $n ) ) );
                if ( $n === $single_dir . '/index.html' || $n === $single_dir . '/index.htm' ) {
                    $has_index_in_subdir = true;
                    break;
                }
            }
        }
        if ( ! $has_index_in_subdir ) {
            $errors[] = 'ZIP must contain index.html at root or inside a single top-level directory.';
        }
    }

    $zip->close();

    return $errors;
}

/**
 * Validate a single HTML file upload.
 *
 * Checks file size and scans for server-side code or dangerous patterns.
 * Returns an array of human-readable error strings. Empty array = valid.
 *
 * @param  string $html_path Absolute filesystem path to the HTML file.
 * @param  array  $limits    Optional overrides: max_bytes.
 * @return string[]           Error messages; empty on success.
 */
function utm_shp_validate_html( $html_path, $limits = [] ) {
    $errors   = [];
    $max_bytes = $limits['max_bytes'] ?? 10 * 1024 * 1024; // 10 MB

    if ( ! file_exists( $html_path ) ) {
        return [ 'HTML file not found.' ];
    }

    $size = filesize( $html_path );
    if ( 0 === $size ) {
        return [ 'HTML file is empty.' ];
    }
    if ( $size > $max_bytes ) {
        return [ sprintf( 'HTML file exceeds size limit (%s).', size_format( $size ) ) ];
    }

    $content = file_get_contents( $html_path );
    if ( false === $content ) {
        return [ 'Could not read HTML file.' ];
    }

    // Block server-executable code.
    $blocked = [
        [ '#<\?php#i',                          'PHP code' ],
        [ '#<\?=#i',                             'PHP short echo' ],
        [ '#<\?[^h]#i',                          'PHP tag' ],
    ];
    foreach ( $blocked as [$pat, $label] ) {
        if ( preg_match( $pat, $content ) ) {
            $errors[] = 'Blocked content (' . $label . ').';
        }
    }

    return $errors;
}

/**
 * Post-extraction symlink scan.
 *
 * Walks the extracted directory and detects any symlinks that slipped
 * through ZIP extraction (ZIPArchive doesn't always honour mode bits).
 *
 * @param  string $dir Absolute path to extracted directory.
 * @return string[]     Error messages; empty if no symlinks found.
 */
function utm_shp_scan_symlinks( $dir ) {
    $errors = [];
    if ( ! is_dir( $dir ) ) {
        return [ 'Directory not found for symlink scan: ' . $dir ];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $it as $file ) {
        if ( is_link( $file->getPathname() ) ) {
            $relative = str_replace( $dir . '/', '', $file->getPathname() );
            $errors[] = 'Symlink found after extraction: ' . $relative;
        }
    }

    return $errors;
}
