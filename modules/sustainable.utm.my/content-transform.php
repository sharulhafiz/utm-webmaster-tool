<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transform bracketed Google Drive file links to embeddable iframe HTML.
 *
 * Example input: [https://drive.google.com/file/d/<id>/view?usp=sharing]
 *
 * @param string $content Raw content.
 * @return string
 */
function utm_sustainable_transform_bracketed_pdf_links( $content ) {
    return preg_replace_callback(
        '/\[(https:\/\/drive\.google\.com\/file\/d\/([^\/\]]+)\/[^\]]*)\]/i',
        function ( $matches ) {
            $file_id = sanitize_text_field( $matches[2] );
            $src     = 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/preview';

            return '<div class="utm-gdoc-pdf-embed"><iframe src="' . esc_url( $src ) . '" width="100%" height="800" loading="lazy" allow="autoplay"></iframe></div>';
        },
        (string) $content
    );
}

/**
 * Normalize Google Docs HTML content before storing in WordPress.
 *
 * @param string $content Raw content.
 * @return string
 */
function utm_sustainable_cleanup_google_doc_content( $content ) {
    $content = (string) $content;

    $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );

    return (string) $content;
}
