<?php
// Disable all RSS, RDF, and Atom feeds
function disable_feeds() {
    wp_die( __('No feed available, please visit the homepage!') );
}
add_action('do_feed', 'disable_feeds', 1);
add_action('do_feed_rdf', 'disable_feeds', 1);
add_action('do_feed_rss', 'disable_feeds', 1);
add_action('do_feed_rss2', 'disable_feeds', 1);
add_action('do_feed_atom', 'disable_feeds', 1);

/*
 * Replace UTM phone and support (Updated to work with detection)
 */
add_action('wp_footer', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Wait a bit for detection to run first
        setTimeout(function() {
            // Replace specific phone number: 553 3333 (various formats) with 533 3333
            document.body.innerHTML = document.body.innerHTML.replace(/\b553[ -]?3333\b/g, '533 3333');
            // Replace 07-553 3333 with 07-533 3333 (specific format)  
            document.body.innerHTML = document.body.innerHTML.replace(/\b07[ -]?553[ -]?3333\b/g, '07-533 3333');
            // Replace international format +60 7 553 3333 with +60 7 533 3333
            document.body.innerHTML = document.body.innerHTML.replace(/\b\+?60[ -]?7[ -]?553[ -]?3333\b/g, '+60 7 533 3333');

            // Replace <a href="mailto:corporate@utm.my">corporate@utm.my</a>
            document.body.innerHTML = document.body.innerHTML.replace(
                /<a href="mailto:corporate@utm\.my">corporate@utm\.my<\/a>/gi,
                '<a href="https://support.utm.my" target="_blank" rel="noopener">support.utm.my</a>'
            );
            // Replace <a href="mailto:corporate@utm.my"><span id="et-info-email">corporate@utm.my</span></a>
            document.body.innerHTML = document.body.innerHTML.replace(
                /<a href="mailto:corporate@utm\.my"><span id="et-info-email">corporate@utm\.my<\/span><\/a>/gi,
                '<a href="https://support.utm.my" target="_blank" rel="noopener"><span id="et-info-email">support.utm.my</span></a>'
            );
        }, 1000); // 1 second delay to allow detection to complete
    });
    </script>
    <?php
});


/*
 * Clear opcache on core/plugin/theme update
 */
add_action('upgrader_process_complete', function() {
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}, 99);
// Register REST API endpoint for opcache reset
add_action('rest_api_init', function() {
    register_rest_route('utm/v1/', '/opcache-reset', array(
        'methods' => 'GET',
        'callback' => function() {
            if (function_exists('opcache_reset')) {
                opcache_reset();
                return new WP_REST_Response(['success' => true, 'message' => 'Opcache reset!'], 200);
            }
            return new WP_REST_Response(['error' => 'Opcache not available'], 500);
        },
        'permission_callback' => '__return_true',
    ));
});

