<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — Page editor metabox.
 *
 * Adds a "Static HTML Package" panel to the Page editor where editors
 * can upload, validate, and manage ZIP packages. Uses the WordPress
 * Media Upload API for the file selector.
 */

/**
 * Register the metabox on Page post type only.
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'shp_static_package',
        'Static HTML Package',
        'utm_shp_metabox_render',
        UTM_SHP_POST_TYPES,
        'normal',
        'high'
    );
} );

/**
 * Metabox render callback.
 *
 * @param WP_Post $post The current Page.
 */
function utm_shp_metabox_render( $post ) {
    wp_nonce_field( 'shp_save_package', 'shp_nonce' );

    $meta = utm_shp_get_meta( $post->ID );
    $has  = utm_shp_has_package( $post->ID );
    ?>
    <div id="shp-metabox">
        <?php if ( $has && $meta ) : ?>
            <div class="shp-status shp-status--active">
                <span class="dashicons dashicons-yes-alt"></span>
                Active package: <strong><?php echo esc_html( $meta->zip_name ); ?></strong>
                &mdash; <?php echo (int) $meta->file_count; ?> files,
                <?php echo size_format( $meta->total_bytes ); ?>
                &mdash; updated <?php echo esc_html( $meta->updated_at ?: $meta->created_at ); ?>
            </div>
        <?php else : ?>
            <div class="shp-status shp-status--none">
                No static package uploaded for this page.
            </div>
        <?php endif; ?>

        <div id="shp-upload-area" class="shp-upload-area">
            <input type="file" id="shp-zip-input" name="shp_zip" accept=".zip,.html,.htm" class="shp-file-input">
            <label for="shp-zip-input" class="shp-file-label">
                <span class="dashicons dashicons-upload"></span>
                Choose file or drag here
            </label>
        </div>

        <div id="shp-msg" class="shp-msg" style="display:none;"></div>

        <div class="shp-actions">
            <button type="button" id="shp-upload-btn" class="button button-primary" <?php echo $has ? '' : 'disabled'; ?>>
                Upload &amp; Publish
            </button>
            <?php if ( $has ) : ?>
                <button type="button" id="shp-unpublish-btn" class="button">
                    Unpublish Package
                </button>
            <?php endif; ?>
        </div>
    </div>

    <style>
        #shp-metabox { padding: 12px 0; }
        .shp-status { padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
        .shp-status--active { background: #d4edda; border: 1px solid #28a745; color: #155724; }
        .shp-status--active .dashicons { color: #28a745; vertical-align: middle; }
        .shp-status--none { background: #f8f9fa; border: 1px solid #dee2e6; color: #6c757d; }
        .shp-upload-area {
            border: 2px dashed #ccc; border-radius: 6px; padding: 20px;
            text-align: center; margin: 12px 0; position: relative; transition: border-color .2s;
        }
        .shp-upload-area:hover, .shp-upload-area.shp-dragover { border-color: #0073aa; }
        .shp-file-input {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; z-index: 2;
        }
        .shp-file-label {
            display: inline-flex; align-items: center; gap: 6px;
            color: #555; font-size: 14px; pointer-events: none; position: relative; z-index: 1;
        }
        .shp-msg { padding: 8px 12px; border-radius: 4px; margin: 10px 0; font-size: 13px; }
        .shp-msg--ok { background: #d4edda; border: 1px solid #28a745; color: #155724; }
        .shp-msg--err { background: #f8d7da; border: 1px solid #dc3545; color: #721c24; }
        .shp-msg--info { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .shp-actions { margin-top: 10px; display: flex; gap: 8px; }
    </style>
    <?php
}

/**
 * Save metabox data on Page save.
 *
 * Handles "unpublish" action (removing the active package).
 */
add_action( 'save_post_page', function ( $post_id ) {
    // Verify nonce.
    if ( ! isset( $_POST['shp_nonce'] )
         || ! wp_verify_nonce( $_POST['shp_nonce'], 'shp_save_package' )
    ) {
        return;
    }

    // Capability check.
    if ( ! current_user_can( 'edit_page', $post_id ) ) {
        return;
    }

    // Autosave check.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Handle unpublish action.
    if ( isset( $_POST['shp_action'] ) && 'unpublish' === $_POST['shp_action'] ) {
        utm_shp_deactivate( $post_id );
    }
} );
