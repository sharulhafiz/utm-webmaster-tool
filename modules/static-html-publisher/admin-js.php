<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static HTML Publisher — admin JavaScript and AJAX handlers.
 *
 * Enqueues inline JS for the Page editor metabox and registers
 * AJAX endpoints for ZIP upload and unpublish.
 */

/**
 * Enqueue admin scripts on Page editor screens only.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        return;
    }

    wp_register_script( 'shp-admin', false, [], UTM_PLUGIN_VERSION, true );
    wp_enqueue_script( 'shp-admin' );
    wp_add_inline_script( 'shp-admin', '
    (function(){
        function init() {
            var btn    = document.getElementById("shp-upload-btn");
            var unpBtn = document.getElementById("shp-unpublish-btn");
            var fileIn = document.getElementById("shp-zip-input");
            var msg    = document.getElementById("shp-msg");
            var area   = document.getElementById("shp-upload-area");
            var postId = "' . (int) get_the_ID() . '";

            if ( ! btn || ! fileIn || ! msg ) return;

            function showMsg(text, type) {
                msg.style.display = "block";
                msg.className = "shp-msg shp-msg--" + type;
                msg.textContent = text;
            }

            fileIn.addEventListener("change", function() {
                btn.disabled = !fileIn.files.length;
                if (fileIn.files.length) {
                    showMsg("Selected: " + fileIn.files[0].name, "info");
                }
            });

            if (area) {
                ["dragenter","dragover"].forEach(function(e) {
                    area.addEventListener(e, function(ev) { ev.preventDefault(); area.classList.add("shp-dragover"); });
                });
                ["dragleave","drop"].forEach(function(e) {
                    area.addEventListener(e, function(ev) { ev.preventDefault(); area.classList.remove("shp-dragover"); });
                });
            }

            btn.addEventListener("click", function() {
                if (!fileIn || !fileIn.files.length) return;

                var fd = new FormData();
                fd.append("action", "shp_upload");
                fd.append("nonce", "' . wp_create_nonce( 'shp_upload' ) . '");
                fd.append("post_id", postId);
                fd.append("shp_zip", fileIn.files[0]);

                btn.disabled = true;
                btn.textContent = "Uploading\u2026";
                showMsg("Validating and extracting package\u2026", "info");

                fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                    method: "POST",
                    body: fd
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        var warnings = d.data.warnings && d.data.warnings.length
                            ? " Warnings: " + d.data.warnings.join("; ")
                            : "";
                        showMsg("\u2713 Package published." + warnings, "ok");
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        var errs = d.data.errors
                            ? d.data.errors.join("\\n")
                            : d.data.message;
                        showMsg("\u2717 " + errs, "err");
                        btn.disabled = false;
                        btn.textContent = "Upload & Publish";
                    }
                })
                .catch(function() {
                    showMsg("Network error.", "err");
                    btn.disabled = false;
                    btn.textContent = "Upload & Publish";
                });
            });

            if (unpBtn) {
                unpBtn.addEventListener("click", function() {
                    if (!confirm("Remove the static package from this page? The page will revert to normal WordPress content.")) return;

                    var fd = new FormData();
                    fd.append("action", "shp_unpublish");
                    fd.append("nonce", "' . wp_create_nonce( 'shp_unpublish' ) . '");
                    fd.append("post_id", postId);

                    unpBtn.disabled = true;
                    unpBtn.textContent = "Removing\u2026";

                    fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                        method: "POST",
                        body: fd
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) {
                            showMsg("Package removed. Page reverts to normal content.", "ok");
                            setTimeout(function() { location.reload(); }, 1200);
                        } else {
                            showMsg("\u2717 " + (d.data.message || "Failed."), "err");
                            unpBtn.disabled = false;
                            unpBtn.textContent = "Unpublish Package";
                        }
                    })
                    .catch(function() {
                        showMsg("Network error.", "err");
                        unpBtn.disabled = false;
                        unpBtn.textContent = "Unpublish Package";
                    });
                });
            }
        }

        // Try immediately, then retry on DOMContentLoaded and after a delay
        // to handle the block editor rendering classic metaboxes asynchronously.
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", init);
        } else {
            init();
        }
        setTimeout(init, 500);
        setTimeout(init, 1500);
    })();
    ' );
} );

// ── AJAX handlers ─────────────────────────────────────────────────

/**
 * AJAX: upload, validate, extract, and activate a static ZIP package.
 */
add_action( 'wp_ajax_shp_upload', 'utm_shp_ajax_upload' );
function utm_shp_ajax_upload() {
    check_ajax_referer( 'shp_upload', 'nonce' );

    if ( ! current_user_can( 'edit_others_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    if ( ! $post_id || ! utm_shp_is_supported_type( get_post_type( $post_id ) ) ) {
        wp_send_json_error( [ 'message' => 'Invalid post type.' ] );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Cannot edit this page.' ], 403 );
    }

    if ( ! isset( $_FILES['shp_zip'] ) ) {
        wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
    }
    $file = $_FILES['shp_zip'];
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'Upload error ' . $file['error'] . '.' ] );
    }

    // 1. Save to temp file.
    $tmp = wp_tempnam( 'shp-' );
    if ( ! move_uploaded_file( $file['tmp_name'], $tmp ) ) {
        @unlink( $tmp );
        wp_send_json_error( [ 'message' => 'Failed to save uploaded file.' ] );
    }

    // Detect file type by extension.
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

    if ( in_array( $ext, [ 'html', 'htm' ], true ) ) {
        // ── HTML file path ──
        $errors = utm_shp_validate_html( $tmp );
        if ( ! empty( $errors ) ) {
            @unlink( $tmp );
            wp_send_json_error( [ 'message' => 'Validation failed.', 'errors' => $errors ] );
        }
        $warnings = utm_shp_activate_html( $tmp, $post_id, $file['name'] );
        @unlink( $tmp );
    } else {
        // ── ZIP file path ──
        $errors = utm_shp_validate_zip( $tmp );
        if ( ! empty( $errors ) ) {
            @unlink( $tmp );
            wp_send_json_error( [ 'message' => 'Validation failed.', 'errors' => $errors ] );
        }
        $warnings = utm_shp_activate( $tmp, $post_id, $file['name'] );
        @unlink( $tmp );
    }

    if ( ! empty( $warnings ) ) {
        // Warnings are non-fatal (extraction succeeded).
        wp_send_json_success( [
            'slug'     => get_post_field( 'post_name', $post_id ),
            'url'      => get_permalink( $post_id ),
            'warnings' => $warnings,
        ] );
    }

    wp_send_json_success( [
        'slug' => get_post_field( 'post_name', $post_id ),
        'url'  => get_permalink( $post_id ),
    ] );
}

/**
 * AJAX: unpublish (remove) a static package from a Page.
 */
add_action( 'wp_ajax_shp_unpublish', 'utm_shp_ajax_unpublish' );
function utm_shp_ajax_unpublish() {
    check_ajax_referer( 'shp_unpublish', 'nonce' );

    if ( ! current_user_can( 'edit_others_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    if ( ! $post_id || ! utm_shp_is_supported_type( get_post_type( $post_id ) ) ) {
        wp_send_json_error( [ 'message' => 'Invalid post type.' ] );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Cannot edit this page.' ], 403 );
    }

    utm_shp_deactivate( $post_id );

    wp_send_json_success( [ 'unpublished' => $post_id ] );
}
