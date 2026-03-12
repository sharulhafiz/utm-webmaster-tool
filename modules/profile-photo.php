<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User meta key for the uploaded profile photo attachment ID.
 */
define( 'UTM_PROFILE_PHOTO_META_KEY', 'utm_profile_photo_id' );

/**
 * Attachment meta key used to mark files managed by this feature.
 */
define( 'UTM_PROFILE_PHOTO_MANAGED_META_KEY', 'utm_profile_photo_managed' );

/**
 * Build the transient key used for profile photo notices.
 *
 * @return string
 */
function utm_profile_photo_notice_key() {
    return 'utm_profile_photo_notice_' . get_current_user_id();
}

/**
 * Return the current profile photo attachment ID.
 *
 * @param int $user_id User ID.
 * @return int
 */
function utm_profile_photo_get_attachment_id( $user_id ) {
    return (int) get_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY, true );
}

/**
 * Return initials for placeholder output.
 *
 * @param WP_User $user User object.
 * @return string
 */
function utm_profile_photo_get_initials( $user ) {
    if ( empty( $user ) ) {
        return 'U';
    }

    $name  = trim( (string) $user->display_name );
    $parts = preg_split( '/\s+/', $name );
    $parts = array_filter( $parts );

    if ( empty( $parts ) && ! empty( $user->user_login ) ) {
        $parts = array( $user->user_login );
    }

    $initials = '';
    foreach ( array_slice( $parts, 0, 2 ) as $part ) {
        if ( function_exists( 'mb_substr' ) ) {
            $initials .= mb_substr( $part, 0, 1 );
        } else {
            $initials .= substr( $part, 0, 1 );
        }
    }

    if ( '' === $initials ) {
        $initials = 'U';
    }

    if ( function_exists( 'mb_strtoupper' ) ) {
        return mb_strtoupper( $initials );
    }

    return strtoupper( $initials );
}

/**
 * Return a deterministic fallback avatar data URI.
 *
 * @param int $user_id User ID.
 * @param int $size    Avatar size.
 * @return string
 */
function utm_profile_photo_get_fallback_avatar_url( $user_id, $size = 96 ) {
    $user     = get_userdata( $user_id );
    $initials = utm_profile_photo_get_initials( $user );
    $size     = max( 32, (int) $size );
    $colors   = array( '#1d4ed8', '#0f766e', '#7c3aed', '#be123c', '#0369a1', '#b45309' );
    $bg       = $colors[ $user_id % count( $colors ) ];
    $font     = max( 14, (int) round( $size * 0.34 ) );

    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="Avatar"><rect width="%1$d" height="%1$d" rx="%2$d" fill="%3$s"/><text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="%4$d" font-weight="700">%5$s</text></svg>',
        $size,
        (int) round( $size / 2 ),
        $bg,
        $font,
        esc_html( $initials )
    );

    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * Return the current profile image URL or an empty string.
 *
 * @param int $user_id User ID.
 * @param int $size    Preview size.
 * @return string
 */
function utm_profile_photo_get_image_url( $user_id, $size = 192 ) {
    $attachment_id = utm_profile_photo_get_attachment_id( $user_id );
    if ( ! $attachment_id ) {
        return '';
    }

    $image_url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );
    if ( ! $image_url ) {
        $image_url = wp_get_attachment_url( $attachment_id );
    }

    return $image_url ? $image_url : '';
}

/**
 * Store a one-time profile photo notice.
 *
 * @param string $message Notice message.
 * @param string $type    success|error.
 * @return void
 */
function utm_profile_photo_set_notice( $message, $type = 'success' ) {
    set_transient(
        utm_profile_photo_notice_key(),
        array(
            'type'    => $type,
            'message' => $message,
        ),
        MINUTE_IN_SECONDS
    );
}

/**
 * Delete a plugin-managed attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return void
 */
function utm_profile_photo_delete_managed_attachment( $attachment_id ) {
    $attachment_id = (int) $attachment_id;
    if ( ! $attachment_id ) {
        return;
    }

    if ( ! get_post_meta( $attachment_id, UTM_PROFILE_PHOTO_MANAGED_META_KEY, true ) ) {
        return;
    }

    wp_delete_attachment( $attachment_id, true );
}

/**
 * Crop and compress a managed profile photo to a square image.
 *
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function utm_profile_photo_process_attachment( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        return new WP_Error( 'utm_profile_photo_missing_file', __( 'Uploaded profile photo could not be processed.', 'utm-webmaster' ) );
    }

    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) {
        return $editor;
    }

    $size = $editor->get_size();
    if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
        return new WP_Error( 'utm_profile_photo_invalid_size', __( 'Uploaded profile photo has invalid dimensions.', 'utm-webmaster' ) );
    }

    $crop_size = min( (int) $size['width'], (int) $size['height'] );
    $x         = (int) floor( ( (int) $size['width'] - $crop_size ) / 2 );
    $y         = (int) floor( ( (int) $size['height'] - $crop_size ) / 2 );

    $cropped = $editor->crop( $x, $y, $crop_size, $crop_size );
    if ( is_wp_error( $cropped ) ) {
        return $cropped;
    }

    if ( method_exists( $editor, 'set_quality' ) ) {
        $editor->set_quality( 82 );
    }

    $saved = $editor->save( $file );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }

    $metadata = wp_generate_attachment_metadata( $attachment_id, $file );
    if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    return true;
}

/**
 * Ensure the user profile form accepts file uploads.
 */
add_action( 'user_edit_form_tag', 'utm_profile_photo_form_tag' );
function utm_profile_photo_form_tag() {
    echo ' enctype="multipart/form-data"';
}

/**
 * Display profile photo controls on the user profile screen.
 *
 * @param WP_User $user User object.
 * @return void
 */
add_action( 'show_user_profile', 'utm_profile_photo_profile_fields' );
add_action( 'edit_user_profile', 'utm_profile_photo_profile_fields' );
function utm_profile_photo_profile_fields( $user ) {
    if ( empty( $user ) || empty( $user->ID ) ) {
        return;
    }

    if ( ! current_user_can( 'edit_user', $user->ID ) ) {
        return;
    }

    $notice = get_transient( utm_profile_photo_notice_key() );
    if ( $notice ) {
        delete_transient( utm_profile_photo_notice_key() );
        $class = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }

    $attachment_id = utm_profile_photo_get_attachment_id( $user->ID );
    $image_url     = utm_profile_photo_get_image_url( $user->ID, 192 );
    $initials      = utm_profile_photo_get_initials( $user );
    ?>
    <h2><?php esc_html_e( 'Profile Photo', 'utm-webmaster' ); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="utm_profile_photo"><?php esc_html_e( 'Custom Profile Photo', 'utm-webmaster' ); ?></label></th>
            <td>
                <?php wp_nonce_field( 'utm_profile_photo_update', 'utm_profile_photo_nonce' ); ?>

                <?php if ( $image_url ) : ?>
                    <img
                        src="<?php echo esc_url( $image_url ); ?>"
                        alt="<?php esc_attr_e( 'Current profile photo', 'utm-webmaster' ); ?>"
                        style="display:block;width:112px;height:112px;object-fit:cover;border-radius:50%;margin-bottom:12px;border:3px solid #dcdcde;box-shadow:0 2px 8px rgba(0,0,0,.08);"
                    />
                <?php else : ?>
                    <div style="display:flex;align-items:center;gap:16px;margin:0 0 12px;">
                        <div style="width:112px;height:112px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#0f766e);color:#fff;display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.08);">
                            <?php echo esc_html( $initials ); ?>
                        </div>
                        <div>
                            <strong><?php esc_html_e( 'No custom photo yet', 'utm-webmaster' ); ?></strong>
                            <p style="margin:6px 0 0;color:#50575e;">
                                <?php esc_html_e( 'Upload a square image to personalize your profile. If you leave it empty, a generated fallback avatar will be used.', 'utm-webmaster' ); ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <input type="file" name="utm_profile_photo" id="utm_profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" />
                <p class="description">
                    <?php esc_html_e( 'Upload a JPG, PNG, GIF, or WebP image. The photo will be center-cropped to a square and compressed automatically on save.', 'utm-webmaster' ); ?>
                </p>

                <?php if ( $attachment_id ) : ?>
                    <label for="utm_profile_photo_remove">
                        <input type="checkbox" name="utm_profile_photo_remove" id="utm_profile_photo_remove" value="1" />
                        <?php esc_html_e( 'Remove current profile photo and delete its managed file', 'utm-webmaster' ); ?>
                    </label>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Save the uploaded profile photo.
 *
 * @param int $user_id User ID.
 * @return void
 */
add_action( 'personal_options_update', 'utm_profile_photo_save_profile_fields' );
add_action( 'edit_user_profile_update', 'utm_profile_photo_save_profile_fields' );
function utm_profile_photo_save_profile_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }

    if ( ! isset( $_POST['utm_profile_photo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['utm_profile_photo_nonce'] ) ), 'utm_profile_photo_update' ) ) {
        return;
    }

    $existing_attachment_id = utm_profile_photo_get_attachment_id( $user_id );

    if ( ! empty( $_POST['utm_profile_photo_remove'] ) ) {
        delete_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY );
        utm_profile_photo_delete_managed_attachment( $existing_attachment_id );
        utm_profile_photo_set_notice( __( 'Profile photo removed.', 'utm-webmaster' ) );
        return;
    }

    if ( empty( $_FILES['utm_profile_photo']['name'] ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_handle_upload(
        'utm_profile_photo',
        0,
        array(),
        array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg' => 'image/jpeg',
                'png'      => 'image/png',
                'gif'      => 'image/gif',
                'webp'     => 'image/webp',
            ),
        )
    );

    if ( is_wp_error( $attachment_id ) ) {
        utm_profile_photo_set_notice( $attachment_id->get_error_message(), 'error' );
        return;
    }

    update_post_meta( $attachment_id, UTM_PROFILE_PHOTO_MANAGED_META_KEY, 1 );

    $processed = utm_profile_photo_process_attachment( $attachment_id );
    if ( is_wp_error( $processed ) ) {
        utm_profile_photo_delete_managed_attachment( $attachment_id );
        utm_profile_photo_set_notice( $processed->get_error_message(), 'error' );
        return;
    }

    update_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY, (int) $attachment_id );
    if ( $existing_attachment_id && $existing_attachment_id !== (int) $attachment_id ) {
        utm_profile_photo_delete_managed_attachment( $existing_attachment_id );
    }

    utm_profile_photo_set_notice( __( 'Profile photo updated.', 'utm-webmaster' ) );
}

/**
 * Resolve a user ID from the avatar context.
 *
 * @param mixed $id_or_email Avatar lookup input.
 * @return int
 */
function utm_profile_photo_resolve_user_id( $id_or_email ) {
    if ( is_numeric( $id_or_email ) ) {
        return (int) $id_or_email;
    }

    if ( $id_or_email instanceof WP_User ) {
        return (int) $id_or_email->ID;
    }

    if ( $id_or_email instanceof WP_Comment ) {
        if ( ! empty( $id_or_email->user_id ) ) {
            return (int) $id_or_email->user_id;
        }

        if ( ! empty( $id_or_email->comment_author_email ) ) {
            $user = get_user_by( 'email', $id_or_email->comment_author_email );
            return $user ? (int) $user->ID : 0;
        }
    }

    if ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
        return (int) $id_or_email->user_id;
    }

    if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
        $user = get_user_by( 'email', $id_or_email );
        return $user ? (int) $user->ID : 0;
    }

    return 0;
}

/**
 * Override avatar data when a custom profile photo exists.
 *
 * @param array $args        Avatar arguments.
 * @param mixed $id_or_email Avatar lookup input.
 * @return array
 */
add_filter( 'get_avatar_data', 'utm_profile_photo_get_avatar_data', 10, 2 );
function utm_profile_photo_get_avatar_data( $args, $id_or_email ) {
    $user_id = utm_profile_photo_resolve_user_id( $id_or_email );
    if ( ! $user_id ) {
        return $args;
    }

    $attachment_id = utm_profile_photo_get_attachment_id( $user_id );
    if ( ! $attachment_id ) {
        $args['url']          = utm_profile_photo_get_fallback_avatar_url( $user_id, isset( $args['size'] ) ? (int) $args['size'] : 96 );
        $args['found_avatar'] = true;
        return $args;
    }

    $image_url = wp_get_attachment_image_url( $attachment_id, array( (int) $args['size'], (int) $args['size'] ) );
    if ( ! $image_url ) {
        $image_url = wp_get_attachment_url( $attachment_id );
    }

    if ( ! $image_url ) {
        return $args;
    }

    $args['url']          = $image_url;
    $args['found_avatar'] = true;

    return $args;
}
