<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Hook into WordPress to add Open Graph meta tags
add_action('wp_head', 'utm_seo', 5);

/**
 * Add Open Graph meta tags to ensure WhatsApp displays the thumbnail correctly.
 */
function utm_seo() {
    if ((!is_admin())) {
        global $post;

        // Ensure $post is an object
        if (!is_object($post) || !isset($post->ID)) {
            return; // Exit if $post is not valid
        }

        // Google Analytics tracking code
        $tracking_ids = [
            'news.utm.my' => 'G-2PPE4JRE18',
            'all' => 'G-N3HJW8G3P7',
        ];

        $host = sanitize_text_field($_SERVER['HTTP_HOST']);
        $default_tracking_id = $tracking_ids['all'];
        $extra_tracking_id = isset($tracking_ids[$host]) ? $tracking_ids[$host] : null;

        // Enqueue Google Analytics scripts
        enqueue_google_analytics_scripts($default_tracking_id, $extra_tracking_id);
    }
}

/**
 * Enqueue Google Analytics scripts.
 *
 * @param string $default_tracking_id Default tracking ID.
 * @param string|null $extra_tracking_id Extra tracking ID for specific domains.
 */
function enqueue_google_analytics_scripts($default_tracking_id, $extra_tracking_id = null) {
    wp_enqueue_script('google-analytics', 'https://www.googletagmanager.com/gtag/js?id=' . esc_attr($default_tracking_id), [], null, true);
    wp_add_inline_script('google-analytics', "
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '" . esc_js($default_tracking_id) . "');
        console.log('Google Analytics loaded with ID: " . esc_js($default_tracking_id) . "');
    ");
    if ($extra_tracking_id && $extra_tracking_id !== $default_tracking_id) {
        wp_enqueue_script('google-analytics-extra', 'https://www.googletagmanager.com/gtag/js?id=' . esc_attr($extra_tracking_id), [], null, true);
        wp_add_inline_script('google-analytics-extra', "
            gtag('config', '" . esc_js($extra_tracking_id) . "');
            console.log('Extra tracking ID: " . esc_js($extra_tracking_id) . "');
        ");
    }
}

/**
 * Enqueue script to detect Google Analytics blocking and show a notice.
 */
function enqueue_adblock_notice_script() {
    // Check if Google Analytics is blocked
    wp_add_inline_script('adblock-notice', "
        (function() {
            var ga = window.ga || window['GoogleAnalyticsObject'];
            if (typeof ga === 'undefined') {
                console.log('Google Analytics is blocked by your browser or ad blocker.');
                var notice = document.createElement('div');
                notice.innerHTML = `
                    <div style='background-color: #ff4444; color: white; padding: 15px; text-align: center; position: fixed; top: 30px; left: 0; right: 0; z-index: 9999; box-shadow: 0 2px 5px rgba(0,0,0,0.2);'>
                        <p style='margin: 0; font-size: 16px;'>
                            <strong>⚠️ Google Analytics is blocked by your browser or ad blocker</strong>
                        </p>
                        <p style='margin: 5px 0; font-size: 14px;'>
                            To help us improve your experience, please consider:<br>
                            1. Disabling your ad blocker for this site<br>
                            2. Allowing analytics tracking in your browser settings<br>
                            3. Adding this site to your ad blocker's whitelist
                        </p>
                        <p style='margin: 5px 0; font-size: 12px; color: #ffe6e6;'>
                            We respect your privacy and only collect anonymous usage data to improve our services.
                        </p>
                        <button onclick='this.parentElement.remove()' style='background: white; color: #ff4444; border: none; padding: 5px 15px; border-radius: 3px; cursor: pointer; margin-top: 5px;'>Dismiss</button>
                    </div>`;
                document.body.appendChild(notice);
            }
        })();
    ");
}
add_action('wp_footer', 'enqueue_adblock_notice_script');



