<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Magazine ZIP security validation.
 *
 * Returns an array of human-readable error strings. Empty = valid.
 *
 * @param  string $zip_path Full filesystem path to the ZIP to validate.
 * @return string[]         Error messages; empty on success.
 */
function utm_magazine_validate_zip( $zip_path ) {
    $errors = [];

    if ( ! file_exists( $zip_path ) ) {
        return [ 'ZIP file not found.' ];
    }

    $size = filesize( $zip_path );
    if ( $size > 50 * 1024 * 1024 ) {
        $errors[] = sprintf( 'ZIP exceeds 50 MB limit (%d bytes).', $size );
    }

    if ( 0 === $size ) {
        return [ 'ZIP file is empty.' ];
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path ) ) {
        return [ 'Could not open ZIP (error code: ' . $zip->getStatus() . ').' ];
    }

    $count    = $zip->numFiles;
    $total    = 0;
    $found    = [];
    $top_dirs = [];
    $blocked  = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht',
        'phps', 'cgi', 'pl', 'py', 'pyc', 'pyo', 'sh', 'bash', 'csh',
        'asp', 'aspx', 'jsp', 'jspx', 'htaccess', 'htpasswd', 'ini',
        'exe', 'bat', 'cmd', 'com', 'msi',
    ];

    for ( $i = 0; $i < $count; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( false === $name ) {
            continue;
        }

        // Sanitise — strip null bytes and normalise separators.
        $name = str_replace( "\0", '', $name );
        $norm = str_replace( '\\', '/', $name );

        // Path traversal / absolute paths.
        if ( preg_match( '#(^|/)\.\./#', $norm )
             || preg_match( '#(^|/)\.\.$#', $norm )
             || str_starts_with( $norm, '/' )
             || preg_match( '#^[a-zA-Z]:#', $norm )
        ) {
            $errors[] = 'Path traversal or absolute path detected: ' . $norm;
            continue;
        }

        // Symlink detection.
        $info = $zip->statIndex( $i );
        if ( $info && isset( $info['raw'] ) ) {
            $mode = $info['external']['unix'];
            if ( is_array( $mode ) && isset( $mode[0] ) ) {
                $mode_val = $mode[0];
            } elseif ( isset( $info['attr'] ) ) {
                $mode_val = $info['attr'];
            } else {
                $mode_val = 0;
            }
            // S_IFLNK = 0120000.
            if ( 0120000 === ( $mode_val & 0xF000 ) ) {
                $errors[] = 'Symlink entry detected: ' . $norm;
                continue;
            }
        }

        // Directory count tracking.
        if ( str_ends_with( $norm, '/' ) || '' === $norm ) {
            continue;
        }

        $parts = explode( '/', $norm );
        if ( count( $parts ) > 1 ) {
            $top_dirs[ $parts[0] ] = true;
        }

        // Blocked extension check.
        $ext = strtolower( pathinfo( $norm, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, $blocked, true ) ) {
            $errors[] = 'Blocked file type (.' . $ext . '): ' . $norm;
        }

        // index.html detection.
        $is_root_index = ( 'index.html' === $norm || 'index.htm' === $norm );
        if ( $is_root_index ) {
            $found['root_index'] = true;
        }

        $total += $info['size'] ?? 0;
    }

    if ( $count > 500 ) {
        $errors[] = sprintf( 'ZIP contains too many files (%d, limit is 500).', $count );
    }

    if ( $total > 200 * 1024 * 1024 ) {
        $errors[] = sprintf(
            'Uncompressed total too large (%d bytes, limit is 200 MB).',
            $total
        );
    }

    if ( ! isset( $found['root_index'] ) ) {
        $has_index_in_subdir = false;
        if ( count( $top_dirs ) === 1 ) {
            $single_dir = array_key_first( $top_dirs );
            for ( $i = 0; $i < $count; $i++ ) {
                $name = str_replace( "\0", '', (string) $zip->getNameIndex( $i ) );
                if ( strtolower( $name ) === $single_dir . '/index.html'
                     || strtolower( $name ) === $single_dir . '/index.htm'
                ) {
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
