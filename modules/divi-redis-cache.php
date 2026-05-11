<?php
/**
 * Divi Redis Cache Optimizer
 *
 * Caches Divi attachment ID lookups in object cache (Redis-backed when available)
 * to reduce repeated expensive lookups on image-heavy pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UTM_Divi_Redis_Cache {

    /**
     * Cache group for attachment lookups.
     */
    const CACHE_GROUP = 'divi_attachments';

    /**
     * Cache expiration in seconds (24h).
     */
    const CACHE_EXPIRATION = 86400;

    /**
     * Initialize optimizer hooks.
     *
     * @return void
     */
    public static function init() {
        if ( ! function_exists( 'et_get_attachment_id_by_url' ) ) {
            return;
        }

        add_filter( 'et_get_attachment_id_by_url_pre', array( __CLASS__, 'get_cached_attachment_id' ), 10, 2 );

        if ( ! defined( 'ET_DISABLE_FILE_BASED_CACHE' ) ) {
            define( 'ET_DISABLE_FILE_BASED_CACHE', true );
        }
    }

    /**
     * Resolve attachment ID from object cache first.
     *
     * @param bool|int $attachment_id_pre Pre-filtered value.
     * @param string   $url               Image URL.
     * @return bool|int
     */
    public static function get_cached_attachment_id( $attachment_id_pre, $url ) {
        if ( false !== $attachment_id_pre ) {
            return $attachment_id_pre;
        }

        if ( ! function_exists( 'et_attachment_normalize_url' ) ) {
            return false;
        }

        $normalized_url = et_attachment_normalize_url( $url );

        if ( ! $normalized_url ) {
            return false;
        }

        $cache_key = 'url_' . md5( $normalized_url );
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        add_action(
            'shutdown',
            function() use ( $normalized_url, $cache_key ) {
                if ( ! function_exists( 'et_get_attachment_id_by_url' ) ) {
                    return;
                }

                remove_filter( 'et_get_attachment_id_by_url_pre', array( 'UTM_Divi_Redis_Cache', 'get_cached_attachment_id' ), 10 );

                $attachment_id = et_get_attachment_id_by_url( $normalized_url );

                add_filter( 'et_get_attachment_id_by_url_pre', array( 'UTM_Divi_Redis_Cache', 'get_cached_attachment_id' ), 10, 2 );

                if ( $attachment_id ) {
                    wp_cache_set( $cache_key, $attachment_id, self::CACHE_GROUP, self::CACHE_EXPIRATION );
                }
            },
            999
        );

        return false;
    }

    /**
     * Flush attachment cache group.
     *
     * @return void
     */
    public static function flush_cache() {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
            return;
        }

        wp_cache_flush();
    }
}

add_action( 'init', array( 'UTM_Divi_Redis_Cache', 'init' ) );
add_action( 'delete_attachment', array( 'UTM_Divi_Redis_Cache', 'flush_cache' ) );
