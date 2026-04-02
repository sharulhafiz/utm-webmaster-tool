<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Hook into WordPress head output.
add_action('wp_head', 'utm_seo', 5);

/**
 * Determine whether current request is Divi visual/builder context.
 *
 * @return bool
 */
function utm_is_divi_builder_request() {
    $divi_query_flags = array('et_fb', 'et_bfb', 'et_pb_preview');

    foreach ($divi_query_flags as $flag) {
        if (isset($_GET[$flag])) {
            return true;
        }
    }

    if (function_exists('et_core_is_fb_enabled') && et_core_is_fb_enabled()) {
        return true;
    }

    return false;
}

/**
 * Main SEO bootstrap for head output.
 */
function utm_seo() {
    if ((!is_admin())) {
        // Output Open Graph and Twitter Card metadata.
        utm_output_social_meta_tags();

        // Skip analytics/tracking in Divi builder contexts (top/app dual-window execution).
        if (utm_is_divi_builder_request()) {
            return;
        }

        global $post;

        // Ensure $post is an object
        if (!is_object($post) || !isset($post->ID)) {
            return; // Exit if $post is not valid
        }

        // Google Analytics tracking code
        $tracking_ids = [
            'news.utm.my'   => 'G-2PPE4JRE18',
            'space.utm.my'  => 'G-PJKMV7MRH0',
            'all'           => 'G-N3HJW8G3P7',
        ];

        $host = sanitize_text_field($_SERVER['HTTP_HOST']);
        $default_tracking_id = $tracking_ids['all'];
        $extra_tracking_id = isset($tracking_ids[$host]) ? $tracking_ids[$host] : null;

        // Enqueue Google Analytics scripts
        enqueue_google_analytics_scripts($default_tracking_id, $extra_tracking_id);
        
        // Enqueue outgoing link tracking script
        enqueue_outgoing_link_tracking();
    }
}

/**
 * Output Open Graph + Twitter Card metadata.
 *
 * This supports social previews (WhatsApp, Facebook, X/Twitter, etc.).
 * Skips output when popular SEO plugins are active to avoid duplicate tags.
 */
function utm_output_social_meta_tags() {
    if (is_admin() || is_feed()) {
        return;
    }

    // Avoid duplicate tags if another SEO plugin already handles Open Graph.
    if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION')) {
        return;
    }

    $site_name = wp_strip_all_tags(get_bloginfo('name'));
    $locale = get_locale();
    $type = 'website';
    $title = wp_strip_all_tags(wp_get_document_title());
    $description = wp_strip_all_tags(get_bloginfo('description'));
    $url = home_url('/');
    $image = '';
    $image_width = 0;
    $image_height = 0;

    if (is_singular()) {
        global $post;

        if (is_object($post) && isset($post->ID)) {
            $type = 'article';
            $title = wp_strip_all_tags(get_the_title($post->ID));
            $url = get_permalink($post->ID);

            $excerpt = trim((string) get_the_excerpt($post->ID));
            if (!empty($excerpt)) {
                $description = wp_strip_all_tags($excerpt);
            } else {
                $content = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
                $description = wp_trim_words($content, 35, '...');
            }

            if (has_post_thumbnail($post->ID)) {
                $thumbnail_id = get_post_thumbnail_id($post->ID);
                $preferred_sizes = array('medium_large', 'large', 'full');

                foreach ($preferred_sizes as $size) {
                    $image_data = wp_get_attachment_image_src($thumbnail_id, $size);
                    if (is_array($image_data) && !empty($image_data[0])) {
                        $image = $image_data[0];
                        $image_width = isset($image_data[1]) ? (int) $image_data[1] : 0;
                        $image_height = isset($image_data[2]) ? (int) $image_data[2] : 0;
                        break;
                    }
                }
            }
        }
    }

    // Fallback image: site icon.
    if (empty($image)) {
        $site_icon = get_site_icon_url(512);
        if (!empty($site_icon)) {
            $image = $site_icon;
            $image_width = 512;
            $image_height = 512;
        }
    }

    if (empty($description)) {
        $description = $site_name;
    }

    // Keep metadata within common platform limits.
    $title = trim(wp_html_excerpt($title, 200, ''));
    $description = trim(wp_html_excerpt($description, 300, ''));

    echo "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";

    if (!empty($image)) {
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta property="og:image:secure_url" content="' . esc_url($image) . '">' . "\n";
        if ($image_width > 0) {
            echo '<meta property="og:image:width" content="' . esc_attr($image_width) . '">' . "\n";
        }
        if ($image_height > 0) {
            echo '<meta property="og:image:height" content="' . esc_attr($image_height) . '">' . "\n";
        }
    }

    echo '<meta name="twitter:card" content="' . esc_attr(!empty($image) ? 'summary_large_image' : 'summary') . '">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";

    if (!empty($image)) {
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
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
 * Enqueue script to track outgoing links, subdomain navigation, and other link metrics in Google Analytics.
 */
function enqueue_outgoing_link_tracking() {
    wp_add_inline_script('google-analytics', "
        // Track all link clicks with comprehensive metrics
        document.addEventListener('DOMContentLoaded', function() {
            var currentDomain = window.location.hostname;
            var currentBaseDomain = currentDomain.split('.').slice(-2).join('.'); // e.g., utm.my
            
            // Get all links on the page
            var links = document.querySelectorAll('a');
            
            links.forEach(function(link) {
                var href = link.getAttribute('href');
                
                // Skip empty, javascript:, mailto:, tel: links
                if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#')) {
                    return;
                }
                
                // Check if it's an external link (http/https)
                if (href.startsWith('http://') || href.startsWith('https://')) {
                    var linkDomain = '';
                    var linkBaseDomain = '';
                    var linkUrl = null;
                    
                    try {
                        linkUrl = new URL(href);
                        linkDomain = linkUrl.hostname;
                        linkBaseDomain = linkDomain.split('.').slice(-2).join('.');
                    } catch (e) {
                        return; // Invalid URL, skip
                    }
                    
                    link.addEventListener('click', function(event) {
                        if (typeof gtag === 'undefined') return;
                        
                        var linkText = link.textContent.trim().substring(0, 100) || 'No text';
                        var linkPosition = link.getBoundingClientRect();
                        var isVisibleOnLoad = linkPosition.top < window.innerHeight;
                        
                        // Determine link type and track accordingly
                        if (linkDomain === currentDomain) {
                            // Same subdomain - internal link
                            gtag('event', 'click', {
                                'event_category': 'internal',
                                'event_label': href,
                                'link_text': linkText,
                                'link_domain': linkDomain,
                                'visible_on_load': isVisibleOnLoad,
                                'transport_type': 'beacon'
                            });
                            console.log('Internal link tracked:', href);
                            
                        } else if (linkBaseDomain === currentBaseDomain) {
                            // Different subdomain, same base domain
                            gtag('event', 'click', {
                                'event_category': 'subdomain',
                                'event_label': href,
                                'link_text': linkText,
                                'link_domain': linkDomain,
                                'from_subdomain': currentDomain,
                                'to_subdomain': linkDomain,
                                'visible_on_load': isVisibleOnLoad,
                                'transport_type': 'beacon'
                            });
                            console.log('Subdomain navigation tracked:', currentDomain, '→', linkDomain);
                            
                        } else {
                            // External domain - outbound link
                            gtag('event', 'click', {
                                'event_category': 'outbound',
                                'event_label': href,
                                'link_text': linkText,
                                'link_domain': linkDomain,
                                'visible_on_load': isVisibleOnLoad,
                                'transport_type': 'beacon'
                            });
                            console.log('Outbound link tracked:', href);
                        }
                    });
                } else {
                    // Relative or root-relative internal link
                    link.addEventListener('click', function(event) {
                        if (typeof gtag === 'undefined') return;
                        
                        var linkText = link.textContent.trim().substring(0, 100) || 'No text';
                        var fullUrl = link.href; // Gets the full resolved URL
                        
                        gtag('event', 'click', {
                            'event_category': 'internal',
                            'event_label': fullUrl,
                            'link_text': linkText,
                            'link_type': 'relative',
                            'transport_type': 'beacon'
                        });
                        console.log('Internal relative link tracked:', fullUrl);
                    });
                }
            });
            
            // Track download links
            var downloadLinks = document.querySelectorAll('a[href$=\".pdf\"], a[href$=\".zip\"], a[href$=\".doc\"], a[href$=\".docx\"], a[href$=\".xls\"], a[href$=\".xlsx\"], a[href$=\".ppt\"], a[href$=\".pptx\"], a[href*=\"download\"]');
            downloadLinks.forEach(function(link) {
                link.addEventListener('click', function(event) {
                    if (typeof gtag === 'undefined') return;
                    
                    var href = link.getAttribute('href') || link.href;
                    var fileName = href.split('/').pop().split('?')[0];
                    var fileExtension = fileName.split('.').pop().toLowerCase();
                    
                    gtag('event', 'file_download', {
                        'event_category': 'download',
                        'event_label': href,
                        'file_name': fileName,
                        'file_extension': fileExtension,
                        'transport_type': 'beacon'
                    });
                    console.log('Download tracked:', fileName);
                });
            });
            
            // Track email and phone links
            var contactLinks = document.querySelectorAll('a[href^=\"mailto:\"], a[href^=\"tel:\"]');
            contactLinks.forEach(function(link) {
                link.addEventListener('click', function(event) {
                    if (typeof gtag === 'undefined') return;
                    
                    var href = link.getAttribute('href');
                    var contactType = href.startsWith('mailto:') ? 'email' : 'phone';
                    var contactValue = href.replace(/^(mailto:|tel:)/, '');
                    
                    gtag('event', 'contact', {
                        'event_category': 'contact_' + contactType,
                        'event_label': contactValue,
                        'transport_type': 'beacon'
                    });
                    console.log('Contact link tracked:', contactType, contactValue);
                });
            });
        });
    ");
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



