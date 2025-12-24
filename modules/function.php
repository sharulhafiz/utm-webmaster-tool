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
 * FIXED: Using TreeWalker to preserve DOM references for Divi builder compatibility
 */
add_action('wp_footer', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Skip if Divi builder is active
        if (window.location.href.indexOf('et_fb=1') !== -1) {
            return;
        }
        
        // Wait a bit for detection to run first
        setTimeout(function() {
            // Function to replace text in text nodes only (preserves DOM structure)
            function replaceTextInNode(node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    var text = node.nodeValue;
                    // Replace phone numbers
                    text = text.replace(/\b553[ -]?3333\b/g, '533 3333');
                    text = text.replace(/\b07[ -]?553[ -]?3333\b/g, '07-533 3333');
                    text = text.replace(/\b\+?60[ -]?7[ -]?553[ -]?3333\b/g, '+60 7 533 3333');
                    node.nodeValue = text;
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    // Handle mailto links for corporate@utm.my
                    if (node.tagName === 'A' && node.getAttribute('href') === 'mailto:corporate@utm.my') {
                        node.setAttribute('href', 'https://support.utm.my');
                        node.setAttribute('target', '_blank');
                        node.setAttribute('rel', 'noopener');
                        if (node.textContent === 'corporate@utm.my') {
                            node.textContent = 'support.utm.my';
                        }
                        // Handle span inside link
                        var span = node.querySelector('span#et-info-email');
                        if (span && span.textContent === 'corporate@utm.my') {
                            span.textContent = 'support.utm.my';
                        }
                    }
                    // Recursively process child nodes
                    for (var i = 0; i < node.childNodes.length; i++) {
                        replaceTextInNode(node.childNodes[i]);
                    }
                }
            }
            
            // Start replacement from body
            replaceTextInNode(document.body);
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

