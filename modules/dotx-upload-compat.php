<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep DOTX explicitly present in allowed mimes.
 */
add_filter(
    'upload_mimes',
    static function ( $mimes ) {
        $mimes['dotx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template';
        return $mimes;
    },
    1000
);

/**
 * Normalize strict filetype checks for DOTX uploads.
 *
 * Some valid DOTX files are detected as generic ZIP or docx-like MIME by finfo.
 * In that case, keep extension/type aligned with .dotx.
 */
add_filter(
    'wp_check_filetype_and_ext',
    static function ( $data, $file, $filename, $mimes, $real_mime ) {
        if ( ! is_string( $filename ) || ! preg_match( '/\.dotx$/i', $filename ) ) {
            return $data;
        }

        $dotx_mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template';
        $docx_mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        $allowed_real_mimes = array(
            'application/zip',
            'application/octet-stream',
            $dotx_mime,
            $docx_mime,
        );

        $ext_missing  = empty( $data['ext'] ) || false === $data['ext'];
        $type_missing = empty( $data['type'] ) || false === $data['type'];
        $real_ok      = false === $real_mime || in_array( strtolower( (string) $real_mime ), $allowed_real_mimes, true );

        // Case 1: strict validation dropped type/ext even though file is plausibly valid DOTX.
        if ( ( $ext_missing || $type_missing ) && $real_ok ) {
            $data['ext']  = 'dotx';
            $data['type'] = $dotx_mime;
            if ( empty( $data['proper_filename'] ) ) {
                $data['proper_filename'] = false;
            }
            return $data;
        }

        // Case 2: detected as DOCX while filename is .dotx; keep extension/mime consistent.
        if ( isset( $data['type'] ) && $docx_mime === $data['type'] ) {
            $data['ext']  = 'dotx';
            $data['type'] = $dotx_mime;
        }

        return $data;
    },
    100,
    5
);
