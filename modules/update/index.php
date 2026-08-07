<?php

/**
 * UTM Webmaster Tool — update module (NEUTRALIZED for swarm lean stack)
 *
 * The plugin is deployed from a shared NFS folder (rw for PHP) / baked into
 * images. Updates flow through the deploy method, NOT GitHub self-update.
 * This module is kept as a no-op so its REST route exists but it never
 * writes into the plugin tree (plugin folder is read-only via :ro mount on
 * swarm lean stacks). Any state lives in wp-content (writable).
 */

// No-op: deploy method manages updates. State dir for future use.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( "rest_api_init", "webmaster_update_register_routes" );

function webmaster_update_register_routes()
{
    register_rest_route( "utm-webmaster-update/v1", "cron", array(
        array(
            "methods" => WP_REST_Server::READABLE,
            "callback" => "webmaster_update_cron",
            "permission_callback" => "__return_true"
        )
    ));
}

function webmaster_update_cron( $request )
{
    // Update mechanism moved to deploy method (www6 checkout + deploy script).
    // Nothing to do here on the lean stack.
    return new WP_REST_Response( array( "status" => "noop", "reason" => "deploy-method" ), 200 );
}
