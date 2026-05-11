<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create or reuse menu path and attach page item under final parent.
 *
 * @param string $menu_name       Menu name.
 * @param array  $folder_path     Ordered folder segments.
 * @param int    $target_post_id  Page post ID.
 * @return int|WP_Error Menu term ID or WP_Error.
 */
function utm_sustainable_ensure_menu_path( $menu_name, $folder_path, $target_post_id ) {
    $menu_name      = sanitize_text_field( (string) $menu_name );
    $folder_path    = is_array( $folder_path ) ? $folder_path : array();
    $target_post_id = (int) $target_post_id;

    if ( '' === $menu_name || $target_post_id <= 0 ) {
        return new WP_Error( 'utm_sustainable_menu_invalid_args', 'Invalid menu sync arguments.', array( 'status' => 400 ) );
    }

    $menu = wp_get_nav_menu_object( $menu_name );
    if ( ! $menu ) {
        $menu_id = wp_create_nav_menu( $menu_name );
        if ( is_wp_error( $menu_id ) ) {
            return $menu_id;
        }
    } else {
        $menu_id = (int) $menu->term_id;
    }

    $parent_item_id = 0;

    foreach ( $folder_path as $segment ) {
        $segment        = sanitize_text_field( (string) $segment );
        $parent_item_id = utm_sustainable_find_or_create_menu_folder_item( $menu_id, $segment, $parent_item_id );
    }

    utm_sustainable_find_or_create_menu_page_item( $menu_id, $target_post_id, $parent_item_id );

    return (int) $menu_id;
}

/**
 * Ensure a custom menu "folder" item exists under parent.
 *
 * @param int    $menu_id    Menu ID.
 * @param string $label      Folder label.
 * @param int    $parent_id  Parent nav item ID.
 * @return int
 */
function utm_sustainable_find_or_create_menu_folder_item( $menu_id, $label, $parent_id ) {
    $menu_id   = (int) $menu_id;
    $label     = sanitize_text_field( (string) $label );
    $parent_id = (int) $parent_id;

    if ( '' === $label ) {
        return $parent_id;
    }

    $items = wp_get_nav_menu_items( $menu_id );
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( (int) $item->menu_item_parent === $parent_id && $item->title === $label && 'custom' === $item->type ) {
                return (int) $item->ID;
            }
        }
    }

    $new_item_id = wp_update_nav_menu_item(
        $menu_id,
        0,
        array(
            'menu-item-title'     => $label,
            'menu-item-url'       => '#',
            'menu-item-status'    => 'publish',
            'menu-item-type'      => 'custom',
            'menu-item-parent-id' => $parent_id,
        )
    );

    return is_wp_error( $new_item_id ) ? $parent_id : (int) $new_item_id;
}

/**
 * Ensure a page menu item exists under parent.
 *
 * @param int $menu_id       Menu ID.
 * @param int $target_post_id Target page post ID.
 * @param int $parent_id     Parent nav item ID.
 * @return int
 */
function utm_sustainable_find_or_create_menu_page_item( $menu_id, $target_post_id, $parent_id ) {
    $menu_id        = (int) $menu_id;
    $target_post_id = (int) $target_post_id;
    $parent_id      = (int) $parent_id;

    $items = wp_get_nav_menu_items( $menu_id );
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( 'post_type' === $item->type && 'page' === $item->object && (int) $item->object_id === $target_post_id ) {
                if ( (int) $item->menu_item_parent !== $parent_id ) {
                    wp_update_nav_menu_item(
                        $menu_id,
                        (int) $item->ID,
                        array(
                            'menu-item-parent-id' => $parent_id,
                        )
                    );
                }

                return (int) $item->ID;
            }
        }
    }

    $new_item_id = wp_update_nav_menu_item(
        $menu_id,
        0,
        array(
            'menu-item-object-id' => $target_post_id,
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => $parent_id,
        )
    );

    return is_wp_error( $new_item_id ) ? 0 : (int) $new_item_id;
}
