<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User meta key for the uploaded profile photo attachment ID.
 */
define( 'UTM_PROFILE_PHOTO_META_KEY', 'utm_profile_photo_id' );

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

    $notice = get_transient( 'utm_profile_photo_notice_' . get_current_user_id() );
    if ( $notice ) {
        delete_transient( 'utm_profile_photo_notice_' . get_current_user_id() );
        $class = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }

    $attachment_id = (int) get_user_meta( $user->ID, UTM_PROFILE_PHOTO_META_KEY, true );
    $image_url     = $attachment_id ? wp_get_attachment_image_url( $attachment_id, array( 192, 192 ) ) : '';

    if ( ! $image_url && $attachment_id ) {
        $image_url = wp_get_attachment_url( $attachment_id );
    }
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
                        style="display:block;width:96px;height:96px;object-fit:cover;border-radius:50%;margin-bottom:12px;"
                    />
                <?php else : ?>
                    <p><?php esc_html_e( 'No custom profile photo uploaded yet.', 'utm-webmaster' ); ?></p>
                <?php endif; ?>

                <input type="file" name="utm_profile_photo" id="utm_profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" />
                <p class="description">
                    <?php esc_html_e( 'Upload a JPG, PNG, GIF, or WebP image to use as your profile photo.', 'utm-webmaster' ); ?>
                </p>

                <?php if ( $attachment_id ) : ?>
                    <label for="utm_profile_photo_remove">
                        <input type="checkbox" name="utm_profile_photo_remove" id="utm_profile_photo_remove" value="1" />
                        <?php esc_html_e( 'Remove current profile photo', 'utm-webmaster' ); ?>
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

    if ( ! empty( $_POST['utm_profile_photo_remove'] ) ) {
        delete_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY );
        set_transient( 'utm_profile_photo_notice_' . get_current_user_id(), array(
            'type'    => 'success',
            'message' => __( 'Profile photo removed.', 'utm-webmaster' ),
        ), MINUTE_IN_SECONDS );
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
        set_transient( 'utm_profile_photo_notice_' . get_current_user_id(), array(
            'type'    => 'error',
            'message' => $attachment_id->get_error_message(),
        ), MINUTE_IN_SECONDS );
        return;
    }

    update_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY, (int) $attachment_id );

    set_transient( 'utm_profile_photo_notice_' . get_current_user_id(), array(
        'type'    => 'success',
        'message' => __( 'Profile photo updated.', 'utm-webmaster' ),
    ), MINUTE_IN_SECONDS );
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

    $attachment_id = (int) get_user_meta( $user_id, UTM_PROFILE_PHOTO_META_KEY, true );
    if ( ! $attachment_id ) {
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
