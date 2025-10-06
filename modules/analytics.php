<?php
/**
 * Analytics Module - WordPress Custom REST API for CSV Export
 * Generates CSV data for posts from the past year with attachment counts
 * Compatible with Google Sheets import
 */
opcache_reset();
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom REST API endpoint for analytics CSV export
 */
add_action('rest_api_init', 'register_analytics_endpoint');

function register_analytics_endpoint() {
    register_rest_route('utm-webmaster/v1', '/analytics/data', array(
        'methods' => 'GET',
        'callback' => 'analytics_json_export_handler',
        'permission_callback' => '__return_true', // Public access, no authentication required
    ));
}

/**
 * Count and attach media files referenced in post content
 * Scans for media patterns in post content and automatically attaches unattached media
 */
function count_and_attach_media_in_content($post_content, $post_id) {
    if (empty($post_content)) {
        return 0;
    }
    
    $media_count = 0;
    $found_media = array();
    
    // Debug: Add optional debug parameter
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    
    // Pattern 1: WordPress attachment URLs in img tags (enhanced for multisite)
    // Matches: <img src="https://site.com/wp-content/uploads/sites/28/2025/08/image.jpg" or without sites/XX
    preg_match_all('/<img[^>]+src=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+)["\'][^>]*>/i', $post_content, $img_matches);
    if (!empty($img_matches[1])) {
        foreach ($img_matches[1] as $img_url) {
            // Handle both full URLs and relative paths
            $clean_url = $img_url;
            if (!preg_match('/^https?:\/\//', $img_url)) {
                $clean_url = home_url($img_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found image via src URL: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    // Pattern 2: Extract wp-image-XXXX class from img tags (enhanced)
    // Matches: class="wp-image-11972" or class="some-class wp-image-11972 another-class"
    preg_match_all('/<img[^>]+class=["\'][^"\']*wp-image-([0-9]+)[^"\']*["\'][^>]*>/i', $post_content, $wp_image_matches);
    if (!empty($wp_image_matches[1])) {
        foreach ($wp_image_matches[1] as $attachment_id) {
            if (get_post_type($attachment_id) === 'attachment' && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found image via wp-image class: ID $attachment_id");
            }
        }
    }
    
    // Pattern 2b: Alternative wp-image class matching (for edge cases)
    // Sometimes the class attribute formatting varies
    preg_match_all('/wp-image-([0-9]+)/i', $post_content, $alt_wp_image_matches);
    if (!empty($alt_wp_image_matches[1])) {
        foreach ($alt_wp_image_matches[1] as $attachment_id) {
            if (get_post_type($attachment_id) === 'attachment' && !in_array($attachment_id, $found_media)) {
                // Verify this ID is actually in an img tag context by checking surrounding content
                $search_pattern = '/wp-image-' . preg_quote($attachment_id) . '/i';
                if (preg_match($search_pattern, $post_content)) {
                    // Attach to post if not already attached
                    $attachment_parent = wp_get_post_parent_id($attachment_id);
                    if ($attachment_parent != $post_id) {
                        wp_update_post(array(
                            'ID' => $attachment_id,
                            'post_parent' => $post_id
                        ));
                    }
                    $found_media[] = $attachment_id;
                    $media_count++;
                    if ($debug) error_log("Found image via alternative wp-image class: ID $attachment_id");
                }
            }
        }
    }
    
    // Pattern 3: WordPress Gutenberg blocks (enhanced)
    // Matches: <!-- wp:image {"id":123} --> and other block types
    preg_match_all('/<!-- wp:(image|gallery|file|audio|video|media-text)[^>]*\{[^}]*"id":([0-9]+)[^}]*\}[^>]*-->/i', $post_content, $gutenberg_matches);
    if (!empty($gutenberg_matches[2])) {
        foreach ($gutenberg_matches[2] as $attachment_id) {
            if (get_post_type($attachment_id) === 'attachment' && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found media via Gutenberg block: ID $attachment_id");
            }
        }
    }
    
    // Pattern 4: Gallery shortcodes with specific IDs
    // Matches: [gallery ids="123,456,789"]
    preg_match_all('/\[gallery[^]]*ids=["\']([0-9,\s]+)["\'][^]]*\]/i', $post_content, $gallery_matches);
    if (!empty($gallery_matches[1])) {
        foreach ($gallery_matches[1] as $ids_string) {
            $ids = array_map('trim', explode(',', $ids_string));
            foreach ($ids as $id) {
                if (is_numeric($id) && get_post_type($id) === 'attachment' && !in_array($id, $found_media)) {
                    // Attach to post if not already attached
                    $attachment_parent = wp_get_post_parent_id($id);
                    if ($attachment_parent != $post_id) {
                        wp_update_post(array(
                            'ID' => $id,
                            'post_parent' => $post_id
                        ));
                    }
                    $found_media[] = $id;
                    $media_count++;
                    if ($debug) error_log("Found media via gallery shortcode: ID $id");
                }
            }
        }
    }
    
    // Pattern 5: Featured image (if not already counted)
    $featured_image_id = get_post_thumbnail_id($post_id);
    if ($featured_image_id && !in_array($featured_image_id, $found_media)) {
        $found_media[] = $featured_image_id;
        $media_count++;
        if ($debug) error_log("Found featured image: ID $featured_image_id");
    }
    
    // Pattern 6: Video/Audio shortcodes with media (updated for multisite)
    // Matches: [video src="..."], [audio src="..."]
    preg_match_all('/\[(video|audio)[^]]*src=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+)["\'][^]]*\]/i', $post_content, $media_shortcode_matches);
    if (!empty($media_shortcode_matches[2])) {
        foreach ($media_shortcode_matches[2] as $media_url) {
            $clean_url = $media_url;
            if (!preg_match('/^https?:\/\//', $media_url)) {
                $clean_url = home_url($media_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found media via video/audio shortcode: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    // Pattern 7: File download links (updated for multisite)
    // Matches: <a href="/wp-content/uploads/.../file.pdf">
    preg_match_all('/<a[^>]+href=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+\.(pdf|doc|docx|zip|xlsx|pptx|txt|rar|7z))["\'][^>]*>/i', $post_content, $file_matches);
    if (!empty($file_matches[1])) {
        foreach ($file_matches[1] as $file_url) {
            $clean_url = $file_url;
            if (!preg_match('/^https?:\/\//', $file_url)) {
                $clean_url = home_url($file_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found file via download link: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    // Pattern 8: Any attachment ID mentioned in content (last resort)
    // Matches: attachment_id="123" or data-id="123" in various contexts
    preg_match_all('/(attachment_id|data-id|data-attachment-id)=["\']([0-9]+)["\']/i', $post_content, $id_matches);
    if (!empty($id_matches[2])) {
        foreach ($id_matches[2] as $attachment_id) {
            if (get_post_type($attachment_id) === 'attachment' && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found media via ID attribute: ID $attachment_id");
            }
        }
    }
    
    // Pattern 9: Divi theme image modules (specific pattern)
    // Matches Divi's et_pb_image structure with wp-image class
    preg_match_all('/<div[^>]*et_pb_image[^>]*>.*?<img[^>]*wp-image-([0-9]+)[^>]*>.*?<\/div>/is', $post_content, $divi_matches);
    if (!empty($divi_matches[1])) {
        foreach ($divi_matches[1] as $attachment_id) {
            if (get_post_type($attachment_id) === 'attachment' && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found media via Divi theme structure: ID $attachment_id");
            }
        }
    }
    
    // Pattern 10: Divi Builder et_pb_image shortcodes
    // Matches: [et_pb_image src="https://site.com/wp-content/uploads/.../image.jpg" ...]
    preg_match_all('/\[et_pb_image[^\]]*src=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+)["\'][^\]]*\]/i', $post_content, $divi_image_matches);
    if (!empty($divi_image_matches[1])) {
        foreach ($divi_image_matches[1] as $img_url) {
            // Handle both full URLs and relative paths
            $clean_url = $img_url;
            if (!preg_match('/^https?:\/\//', $img_url)) {
                $clean_url = home_url($img_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found image via Divi et_pb_image shortcode: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    // Pattern 11: Divi Builder et_pb_gallery shortcodes
    // Matches: [et_pb_gallery gallery_ids="123,456,789" ...]
    preg_match_all('/\[et_pb_gallery[^\]]*gallery_ids=["\']([0-9,\s]+)["\'][^\]]*\]/i', $post_content, $divi_gallery_matches);
    if (!empty($divi_gallery_matches[1])) {
        foreach ($divi_gallery_matches[1] as $ids_string) {
            $ids = array_map('trim', explode(',', $ids_string));
            foreach ($ids as $id) {
                if (is_numeric($id) && get_post_type($id) === 'attachment' && !in_array($id, $found_media)) {
                    // Attach to post if not already attached
                    $attachment_parent = wp_get_post_parent_id($id);
                    if ($attachment_parent != $post_id) {
                        wp_update_post(array(
                            'ID' => $id,
                            'post_parent' => $post_id
                        ));
                    }
                    $found_media[] = $id;
                    $media_count++;
                    if ($debug) error_log("Found media via Divi et_pb_gallery shortcode: ID $id");
                }
            }
        }
    }
    
    // Pattern 12: Divi Builder et_pb_video shortcodes
    // Matches: [et_pb_video src="..." ...] or with other video attributes
    preg_match_all('/\[et_pb_video[^\]]*(?:src|mp4_src)=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+)["\'][^\]]*\]/i', $post_content, $divi_video_matches);
    if (!empty($divi_video_matches[1])) {
        foreach ($divi_video_matches[1] as $video_url) {
            $clean_url = $video_url;
            if (!preg_match('/^https?:\/\//', $video_url)) {
                $clean_url = home_url($video_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found video via Divi et_pb_video shortcode: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    // Pattern 13: Divi Builder background images in any et_pb module
    // Matches: background_image="https://site.com/wp-content/uploads/.../image.jpg"
    preg_match_all('/background_image=["\']([^"\']*\/wp-content\/uploads\/(?:sites\/[0-9]+\/)?[^"\']+)["\'/i', $post_content, $divi_bg_matches);
    if (!empty($divi_bg_matches[1])) {
        foreach ($divi_bg_matches[1] as $bg_url) {
            $clean_url = $bg_url;
            if (!preg_match('/^https?:\/\//', $bg_url)) {
                $clean_url = home_url($bg_url);
            }
            
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && !in_array($attachment_id, $found_media)) {
                // Attach to post if not already attached
                $attachment_parent = wp_get_post_parent_id($attachment_id);
                if ($attachment_parent != $post_id) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_parent' => $post_id
                    ));
                }
                $found_media[] = $attachment_id;
                $media_count++;
                if ($debug) error_log("Found background image via Divi shortcode: $clean_url (ID: $attachment_id)");
            }
        }
    }
    
    if ($debug) {
        error_log("Post ID $post_id: Total media found: $media_count");
        error_log("Post ID $post_id: Content length: " . strlen($post_content));
        // Log some sample content to help debug
        $content_sample = substr($post_content, 0, 500);
        error_log("Post ID $post_id: Content sample: " . $content_sample);
    }
    
    return $media_count;
}

/**
 * Main handler for analytics JSON export
 * Generates JSON data with post title, publish date, and attachment count
 * for posts published in the past year
 */
function analytics_json_export_handler(WP_REST_Request $request) {
    try {
        $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
        $single_post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : null;
        
        // Calculate date range (past 1 year from today)
        $one_year_ago = date('Y-m-d', strtotime('-1 year'));
        $today = date('Y-m-d');
        
        // Query arguments
        $query_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => $single_post_id ? 1 : -1, // Get all posts or just one
            'date_query' => array(
                array(
                    'after' => $one_year_ago,
                    'before' => $today,
                    'inclusive' => true,
                ),
            ),
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        // If debugging a specific post, override the query
        if ($single_post_id) {
            $query_args = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'p' => $single_post_id,
                'numberposts' => 1
            );
        }
        
        // Query posts
        $posts = get_posts($query_args);
        
        // Start building JSON data
        $posts_data = array();
        
        // Process each post
        foreach ($posts as $post) {
            // Count and auto-attach media that appears in the content
            $content_media_count = count_and_attach_media_in_content($post->post_content, $post->ID);
            
            // Format publish date in ISO 8601 format
            $publish_date = get_the_date('c', $post->ID); // ISO 8601 format (2025-08-27T10:30:00+00:00)
            
            // Add post data to array with simplified attachment info
            $post_data = array(
                'post_id' => $post->ID,
                'post_title' => $post->post_title,
                'post_publish_date' => $publish_date,
                'number_of_attachments' => $content_media_count,
                'post_url' => get_permalink($post->ID)
            );
            
            // Add debug information if requested
            if ($debug) {
                // Test specific patterns on this post content
                $pattern_tests = array();
                
                // Test wp-image patterns
                preg_match_all('/wp-image-([0-9]+)/', $post->post_content, $all_wp_image_matches);
                $pattern_tests['all_wp_image_ids_in_content'] = !empty($all_wp_image_matches[1]) ? $all_wp_image_matches[1] : array();
                
                // Test img src patterns  
                preg_match_all('/<img[^>]+src=["\']([^"\']*\/wp-content\/uploads\/[^"\']*)["\'][^>]*>/i', $post->post_content, $all_img_src_matches);
                $pattern_tests['all_img_src_urls'] = !empty($all_img_src_matches[1]) ? array_slice($all_img_src_matches[1], 0, 3) : array(); // Limit to 3 for brevity
                
                // Test for Divi classes and shortcodes
                $has_divi = strpos($post->post_content, 'et_pb_') !== false;
                $pattern_tests['has_divi_classes'] = $has_divi;
                
                // Test Divi-specific patterns
                preg_match_all('/\[et_pb_image[^\]]*src=["\']([^"\']*\/wp-content\/uploads\/[^"\']*)["\'][^\]]*\]/i', $post->post_content, $divi_img_matches);
                $pattern_tests['divi_image_urls_found'] = !empty($divi_img_matches[1]) ? array_slice($divi_img_matches[1], 0, 3) : array();
                
                preg_match_all('/\[et_pb_gallery[^\]]*gallery_ids=["\']([0-9,\s]+)["\'][^\]]*\]/i', $post->post_content, $divi_gallery_matches);
                $pattern_tests['divi_gallery_ids_found'] = !empty($divi_gallery_matches[1]) ? explode(',', str_replace(' ', '', implode(',', $divi_gallery_matches[1]))) : array();
                
                preg_match_all('/background_image=["\']([^"\']*\/wp-content\/uploads\/[^"\']*)["\'/i', $post->post_content, $divi_bg_matches);
                $pattern_tests['divi_background_urls_found'] = !empty($divi_bg_matches[1]) ? array_slice($divi_bg_matches[1], 0, 3) : array();
                
                $post_data['debug'] = array(
                    'content_length' => strlen($post->post_content),
                    'has_content' => !empty($post->post_content),
                    'content_preview' => substr($post->post_content, 0, 400) . '...',
                    'has_img_tags' => preg_match('/<img[^>]*>/i', $post->post_content) > 0,
                    'has_wp_blocks' => strpos($post->post_content, '<!-- wp:') !== false,
                    'has_shortcodes' => preg_match('/\[[^\]]+\]/', $post->post_content) > 0,
                    'featured_image_id' => get_post_thumbnail_id($post->ID),
                    'wp_image_classes_found' => preg_match_all('/wp-image-([0-9]+)/', $post->post_content, $matches) > 0 ? $matches[1] : array(),
                    'gutenberg_ids_found' => preg_match_all('/"id":([0-9]+)/', $post->post_content, $matches) > 0 ? $matches[1] : array(),
                    'pattern_tests' => $pattern_tests
                );
            }
            
            $posts_data[] = $post_data;
        }
        
        // Build response data
        $response_data = array(
            'status' => 'success',
            'meta' => array(
                'total_posts' => count($posts_data),
                'date_range' => array(
                    'from' => $one_year_ago,
                    'to' => $today
                ),
                'generated_at' => current_time('c'), // Current time in ISO 8601 format
                'api_version' => '1.1'
            ),
            'data' => $posts_data
        );
        
        if ($debug) {
            $response_data['debug_info'] = array(
                'debug_mode' => true,
                'query_args' => $query_args,
                'total_found' => count($posts)
            );
        }
        
        // Return JSON response
        return new WP_REST_Response($response_data, 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Failed to generate analytics data: ' . $e->getMessage(),
            'meta' => array(
                'generated_at' => current_time('c'),
                'api_version' => '1.0'
            )
        ), 500);
    }
}
?>
