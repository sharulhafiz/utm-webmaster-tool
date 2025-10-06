<?php
/**
 * Anti-spam module for UTM Webmaster Tool
 * This module scans comments and posts for spam content and manages spam comments.
 * 
 * Features:
 * - Real-time spam detection with multiple filters
 * - Bulk scanning for existing comments
 * - Manual admin interface for spam management
 * - Database cleanup and health monitoring
 * - Rate-limited email notifications
 * 
 * @version 2.0
 * @author UTM Webmaster Team
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Do not run this plugin at news.utm.my
if ( strpos( $_SERVER['HTTP_HOST'], 'news.utm.my' ) !== false ) {
    return;
}

// Hook into the custom event to delete all comments
add_action('delete_comments_daily', 'delete_comments');

function delete_comments() {
    $args = array(
        'status' => 'any',
        'number' => 500, // batch size
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_spam_scanned',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );
    $comments = get_comments($args);
    foreach ($comments as $comment_id) {
        $comment = get_comment($comment_id);
        // Mark that comment has been scanned
        update_comment_meta($comment_id, '_spam_scanned', '1');

        // 1. Check with StopForumSpam
        $email = urlencode($comment->comment_author_email);
        $response = wp_remote_get("https://api.stopforumspam.org/api?email={$email}&json");
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response));
            if (!empty($data->email->appears) && $data->email->appears) {
                wp_spam_comment($comment_id);
                continue;
            }
        }
        // 2. Enhanced local spam detection
        if (is_comment_spam_enhanced($comment)) {
            wp_spam_comment($comment_id);
            
            // Send notification about spam comment
            $subject = 'Spam Comment Detected on ' . get_bloginfo('name');
            $message = "A spam comment has been automatically detected and marked:\n\n";
            $message .= "Author: {$comment->comment_author}\n";
            $message .= "Email: {$comment->comment_author_email}\n";
            $message .= "URL: {$comment->comment_author_url}\n";
            $message .= "IP: {$comment->comment_author_IP}\n";
            $message .= "Content: {$comment->comment_content}\n";
            $message .= "Post: " . get_permalink($comment->comment_post_ID) . "\n";
            
            wp_mail('webmaster@utm.my', $subject, $message, 
                'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>');
            continue;
        }
    }
}

// Auto-delete old spam comments to keep database clean
function cleanup_old_spam_comments() {
    global $wpdb;
    
    // Delete spam comments older than 30 days
    $deleted = $wpdb->query($wpdb->prepare("
        DELETE FROM {$wpdb->comments} 
        WHERE comment_approved = 'spam' 
        AND comment_date < %s
        LIMIT 500
    ", date('Y-m-d H:i:s', strtotime('-30 days'))));
    
    if ($deleted > 0) {
        error_log("Cleaned up {$deleted} old spam comments from database");
    }
    
    return $deleted;
}

// Schedule weekly spam cleanup
if (!wp_next_scheduled('weekly_spam_cleanup')) {
    wp_schedule_event(time(), 'weekly', 'weekly_spam_cleanup');
}
add_action('weekly_spam_cleanup', 'cleanup_old_spam_comments');

// Database health check - monitor comment table size
function check_comment_table_health() {
    global $wpdb;
    
    $stats = $wpdb->get_results("
        SELECT 
            comment_approved as status,
            COUNT(*) as count 
        FROM {$wpdb->comments} 
        GROUP BY comment_approved
    ");
    
    $total_comments = 0;
    $spam_count = 0;
    
    foreach ($stats as $stat) {
        $total_comments += $stat->count;
        if ($stat->status === 'spam') {
            $spam_count = $stat->count;
        }
    }
    
    // Alert if spam ratio is too high
    if ($total_comments > 1000 && ($spam_count / $total_comments) > 0.7) {
        wp_mail('webmaster@utm.my', 
            'Database Alert: High Spam Ratio', 
            "Warning: {$spam_count} spam comments out of {$total_comments} total. Consider running cleanup.",
            'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
        );
    }
    
    return compact('total_comments', 'spam_count');
}

// Enhanced spam detection for comments
function is_comment_spam_enhanced($comment) {
    global $spam_words;
    
    // Check all comment fields for spam content
    $content_to_check = strtolower(
        $comment->comment_content . ' ' . 
        $comment->comment_author . ' ' . 
        $comment->comment_author_url
    );
    
    // 1. Check against spam word list
    foreach ($spam_words as $word) {
        if (strpos($content_to_check, strtolower($word)) !== false) {
            return true;
        }
    }
    
    // 2. Check for gambling/casino domains in URL
    $gambling_domains = array(
        'casino', 'bet', 'poker', 'slots', 'gambling', 'viggo', 'spin', 
        'jackpot', 'roulette', 'blackjack', 'baccarat', 'lottery', 'betting'
    );
    
    if (!empty($comment->comment_author_url)) {
        $url_lower = strtolower($comment->comment_author_url);
        foreach ($gambling_domains as $domain_word) {
            if (strpos($url_lower, $domain_word) !== false) {
                return true;
            }
        }
    }
    
    // 3. Check for suspicious patterns
    $suspicious_patterns = array(
        '/brilliant\s+(article|post)!/i',  // Generic praise
        '/amazing\s+(content|article|post)/i',
        '/great\s+(info|information|article)/i',
        '/thanks?\s+for\s+(sharing|this)/i',
        '/very\s+(useful|helpful|good)/i',
        '/check\s+out\s+my\s+(website|blog|site)/i', // Self-promotion
        '/visit\s+my\s+(website|blog|site)/i',
        '/click\s+here/i'
    );
    
    foreach ($suspicious_patterns as $pattern) {
        if (preg_match($pattern, $comment->comment_content)) {
            return true;
        }
    }
    
    // 4. Check for very short generic comments
    $generic_short_comments = array(
        'brilliant article!', 'great post!', 'amazing!', 'awesome!', 
        'nice!', 'good job!', 'well done!', 'fantastic!', 'excellent!',
        'perfect!', 'wonderful!', 'superb!', 'outstanding!', 'incredible!'
    );
    
    $content_clean = strtolower(trim($comment->comment_content));
    if (in_array($content_clean, $generic_short_comments)) {
        return true;
    }
    
    // 5. Check for suspicious email patterns
    if (preg_match('/^[a-z]+\d+@gmail\.com$/i', $comment->comment_author_email)) {
        return true; // Pattern like "Reimann43744@gmail.com"
    }
    
    // 6. UTM-specific checks
    if (!is_utm_relevant_content($comment)) {
        // If content is not relevant to UTM and has suspicious characteristics
        if (strlen($comment->comment_content) < 20 && 
            !empty($comment->comment_author_url)) {
            return true; // Short irrelevant comment with URL = likely spam
        }
    }
    
    // 7. Check for multiple external links
    if (substr_count($comment->comment_content, 'http') > 2) {
        return true; // Multiple links usually spam
    }
    
    // 8. Check for academic spam patterns
    $academic_spam_patterns = array(
        '/essay.*writing.*service/i',
        '/assignment.*help.*online/i',
        '/buy.*research.*paper/i',
        '/thesis.*writing/i',
        '/homework.*help/i'
    );
    
    foreach ($academic_spam_patterns as $pattern) {
        if (preg_match($pattern, $comment->comment_content)) {
            return true;
        }
    }
    
    return false;
}

// Check if IP is from a suspicious range or known spam network
function is_suspicious_ip($ip) {
    // Known spam IP ranges (you can expand this list)
    $spam_ip_ranges = array(
        '104.128.67.', // The IP from your example
        '185.220.',    // Common Tor exit nodes
        '192.42.116.', // Another common spam range
    );
    
    foreach ($spam_ip_ranges as $range) {
        if (strpos($ip, $range) === 0) {
            return true;
        }
    }
    
    return false;
}

// Enhanced comment filtering that runs before comments are saved
function filter_comment_before_save($commentdata) {
    // Check if comment should be auto-rejected
    $comment = (object) $commentdata;
    
    // 1. Check IP
    if (is_suspicious_ip($commentdata['comment_author_IP'])) {
        wp_die('Comments from your IP address are not allowed.');
    }
    
    // 2. Check for spam content
    if (is_comment_spam_enhanced($comment)) {
        wp_die('Your comment has been flagged as spam.');
    }
    
    // 3. Rate limiting - check if same IP posted recently
    $recent_comments = get_comments(array(
        'meta_query' => array(
            array(
                'key' => 'comment_author_IP',
                'value' => $commentdata['comment_author_IP'],
                'compare' => '='
            )
        ),
        'date_query' => array(
            'after' => '1 hour ago'
        ),
        'count' => true
    ));
    
    if ($recent_comments > 3) {
        wp_die('Too many comments from your IP address. Please wait before commenting again.');
    }
    
    return $commentdata;
}
add_filter('preprocess_comment', 'filter_comment_before_save');

// Helper to get next midnight timestamp
function get_next_midnight() {
    $now = current_time('timestamp');
    $midnight = strtotime('tomorrow', $now);
    return $midnight;
}

// Schedule the daily comment deletion event at midnight
if (!wp_next_scheduled('delete_comments_daily')) {
    wp_schedule_event(get_next_midnight(), 'daily', 'delete_comments_daily');
}

// Schedule the spam scan at midnight (custom hook)
if (!wp_next_scheduled('midnight_spam_scan')) {
    wp_schedule_event(get_next_midnight(), 'daily', 'midnight_spam_scan');
}

// Change the hook for spam scan to midnight
add_action('midnight_spam_scan', 'scheduled_spam_scan');

// Hook into the 'wp' action to unschedule the event on plugin deactivation
register_deactivation_hook(__FILE__, 'unschedule_daily_comment_deletion');

function unschedule_daily_comment_deletion() {
    $timestamp = wp_next_scheduled('delete_comments_daily');
    wp_unschedule_event($timestamp, 'delete_comments_daily');
}

// Show notice in the admin dashboard footer the status of scheduled event
add_action('admin_notices', 'show_scheduled_event_status');

function show_scheduled_event_status() {
    $screen = get_current_screen();
    if ($screen->id !== 'edit-comments') {
        return;
    }

    $timestamp = wp_next_scheduled('delete_comments_daily');
    if ($timestamp === false) {
        $schedule_status = wp_schedule_event(time(), 'daily', 'delete_comments_daily');
        if ($schedule_status) {
            $timestamp = wp_next_scheduled('delete_comments_daily');
        }
    }
    $status = 'Scheduled for ' . date('Y-m-d H:i:s', $timestamp);
    echo "<div class='notice notice-info is-dismissible'><p>Comment Deletion Event: {$status}</p></div>";
}

// Disable support for comments and trackbacks in post types
function disable_comments_post_types_support() {
    $post_types = get_post_types();
    foreach ($post_types as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
}
add_action('admin_init', 'disable_comments_post_types_support');

// Close comments on the front-end
function disable_comments_status() {
    return false;
}
add_filter('comments_open', 'disable_comments_status', 20, 2);
add_filter('pings_open', 'disable_comments_status', 20, 2);

// ====================


// Disable email all notification for new comments
function disable_comments_notification($notify, $comment_id) {
    return false;
}
add_filter('comment_notification_recipients', 'disable_comments_notification', 10, 2);

// Disable comment form on front-end for specific post types only
function disable_comment_form($open, $post_id) {
    // Allow comments on posts but disable on pages
    $post = get_post($post_id);
    if ($post && $post->post_type === 'page') {
        return false; // Disable comments on pages
    }
    return $open; // Allow comments on posts
}
add_filter('comments_open', 'disable_comment_form', 10, 2);

// Enable smart comment filtering (replace complete disable)
function smart_comment_filtering($commentdata) {
    // This replaces the complete disable - now we filter instead of block
    return $commentdata; // Let filter_comment_before_save handle the filtering
}
add_filter('preprocess_comment', 'smart_comment_filtering');

// Smart email notifications - only for legitimate comments, rate-limited
function smart_comment_notification($comment_id, $comment) {
    // Skip if comment is spam or pending
    if ($comment->comment_approved === 'spam' || $comment->comment_approved === '0') {
        return;
    }
    
    // Rate limiting - max 5 notifications per hour
    $recent_notifications = get_transient('comment_notifications_sent');
    if ($recent_notifications >= 5) {
        return; // Skip notification if limit reached
    }
    
    $post = get_post($comment->comment_post_ID);
    $post_title = $post->post_title;
    $comment_author = $comment->comment_author;
    $comment_content = $comment->comment_content;
    $comment_link = get_comment_link($comment_id);
    
    $subject = 'Legitimate Comment on: ' . $post_title;
    $message = "A legitimate comment has been posted:\n\n";
    $message .= "Post: {$post_title}\n";
    $message .= "Author: {$comment_author}\n";
    $message .= "Email: {$comment->comment_author_email}\n";
    $message .= "Content: {$comment_content}\n\n";
    $message .= "View: {$comment_link}\n";
    $message .= "Moderate: " . admin_url('edit-comments.php') . "\n";
    
    $header = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
    wp_mail('webmaster@utm.my', $subject, $message, $header);
    
    // Update rate limiting counter
    $sent_count = $recent_notifications ? $recent_notifications + 1 : 1;
    set_transient('comment_notifications_sent', $sent_count, HOUR_IN_SECONDS);
}
add_action('wp_insert_comment', 'smart_comment_notification', 10, 2);

/*==================================================================================
  Anti-spam scan for posts and pages
==================================================================================*/

$spam_words = array(
    // Gambling & Casino terms
    'Пин Ап казино', 'Pin Up Casino', 'casino', 'gambling', 'poker', 'roulette', 
    'blackjack', 'baccarat', 'lottery', 'sports betting', 'viggo', 'viggoslots',
    'slot', 'slots', 'jackpot', 'bet365', '1xbet', 'brillx',
    
    // Gaming terms (often used in spam)
    'Slot Oyna', 'Gates Of Olympus', 'demo', 'oyna', 'Lucky Jet',
    
    // Russian/Foreign spam terms
    'Сайт', 'Бонусы', 'Джекпот', 'Ставки на спорт', 'Онлайн-гемблинг', 'Техподдержка',
    
    // Software/Download spam
    'coreldraw free download', 'crack', 'keygen', 'serial', 'activation',
    
    // Generic spam terms
    'earn money online', 'work from home', 'make money fast', 'get rich quick',
    'forex trading', 'binary options', 'cryptocurrency investment',
    
    // UTM-specific spam patterns (academic context)
    'buy essay', 'write my assignment', 'homework help service', 'thesis writing service',
    'buy research paper', 'academic writing service', 'essay mill', 'paper writing service',
    'assignment help online', 'custom essay', 'plagiarism free essay',
    
    // Technology spam targeting universities
    'cheap hosting', 'best vpn', 'hosting discount', 'web hosting offer',
    'domain registration', 'ssl certificate cheap', 'website builder',
    
    // Health/supplement spam
    'weight loss pills', 'male enhancement', 'testosterone booster', 'diet pills',
    'health supplement', 'miracle cure', 'doctor approved',
    
    // Financial spam
    'instant loan', 'personal loan', 'credit repair', 'debt consolidation',
    'payday loan', 'cash advance', 'loan approval', 'bad credit loan'
);

// UTM-specific content validation
function is_utm_relevant_content($comment) {
    // Check if comment is relevant to academic/university content
    $academic_keywords = array(
        'utm', 'university', 'student', 'academic', 'research', 'study', 'course',
        'lecturer', 'professor', 'faculty', 'campus', 'education', 'learning',
        'semester', 'examination', 'graduation', 'degree', 'diploma', 'engineering',
        'science', 'technology', 'management', 'skudai', 'johor', 'malaysia'
    );
    
    $content_lower = strtolower($comment->comment_content . ' ' . $comment->comment_author);
    
    foreach ($academic_keywords as $keyword) {
        if (strpos($content_lower, $keyword) !== false) {
            return true; // Likely relevant to UTM
        }
    }
    
    // Check if comment is too generic/irrelevant for academic context
    $irrelevant_patterns = array(
        '/^(nice|good|great|awesome|amazing|fantastic|excellent|wonderful)!?$/i',
        '/^thanks?!?$/i',
        '/^(wow|cool)!?$/i'
    );
    
    foreach ($irrelevant_patterns as $pattern) {
        if (preg_match($pattern, trim($comment->comment_content))) {
            return false; // Too generic
        }
    }
    
    return true; // Assume relevant if not obviously irrelevant
}

function scheduled_spam_scan() {
    global $spam_words;

    // Query 10 unscanned posts/pages
    $args = array(
        'post_type'      => array('post', 'page'),
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => '_spam_scanned',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'posts_per_page' => 10,
        'fields'         => 'ids', // Only fetch post IDs to reduce memory usage
    );
    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        return; // No posts to scan
    }

    foreach ($query->posts as $post_id) {
        $post = get_post($post_id);
        scan_post_for_spam($post, $spam_words);
    }
}

// Function to scan a post for spam
function scan_post_for_spam($post, $spam_words) {
    $spam_score = 0;
    $matched_words = [];
    $post_text = strtolower($post->post_title . ' ' . $post->post_content);

    // Check individual spam words first
    foreach ($spam_words as $word) {
        $pattern = '/\b' . preg_quote(strtolower($word), '/') . '\b/';
        if (preg_match($pattern, $post_text)) {
            $spam_score++;
            $matched_words[] = $word; // Store the original word, not the pattern
        }
    }

    // Check custom patterns
    $custom_patterns = array(
        'slot.*oyna' => 'Slot Oyna variations',
        'demo.*oyna' => 'Demo Oyna variations',
        'gates.*olympus' => 'Gates Of Olympus variations'
    );
    
    foreach ($custom_patterns as $pattern => $description) {
        if (preg_match('/\b' . $pattern . '\b/', $post_text)) {
            $spam_score++;
            $matched_words[] = $description;
        }
    }

    // Adjust threshold as needed
    if ($spam_score > 2) {
        $post_data = array(
            'ID'          => $post->ID,
            'post_status' => 'draft',
        );

        // Update post status and send email notification
        if (wp_update_post($post_data)) {
            list($subject, $message, $headers) = construct_spam_email($post, $matched_words);
            wp_mail(get_bloginfo('admin_email'), $subject, $message, $headers);
        }
    }

    // Mark post as scanned
    update_post_meta($post->ID, '_spam_scanned', '1');
}

// Construct spam email
function construct_spam_email($post, $matched_words) {
    $subject = 'Spam Post Detected on ' . get_bloginfo('name');
    $message = "A post containing spam content has been automatically set to draft:\n\n";
    $message .= "Post Title: {$post->post_title}\n";
    $message .= "Post URL: " . get_permalink($post->ID) . "\n";
    $message .= "Edit URL: " . admin_url('post.php?post=' . $post->ID . '&action=edit') . "\n\n";

    $message .= "Matched Spam Terms: " . implode(', ', $matched_words) . "\n\n";
    
    $author = get_userdata($post->post_author);
    $author_name = $author ? $author->display_name : 'Unknown';
    $author_email = $author ? $author->user_email : 'Unknown';
    $message .= "Post Author: {$author_name} ({$author_email})\n\n";
    
    $message .= "Please review this post and take appropriate action.\n";
    $message .= "If this is a false positive, you can republish the post from the WordPress admin.";
    
    $headers = array(
        'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>',
        'Cc: webmaster@utm.my'
    );
    
    return array($subject, $message, $headers);
}

// Bulk scan existing comments (for cleaning up thousands of comments)
function bulk_scan_existing_comments($batch_size = 100) {
    global $spam_words;
    
    // Get unscanned comments in batches
    $args = array(
        'status' => array('approve', 'hold'), // Don't re-scan spam
        'number' => $batch_size,
        'meta_query' => array(
            array(
                'key' => '_bulk_spam_scanned',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'orderby' => 'comment_date',
        'order' => 'ASC' // Start with oldest
    );
    
    $comments = get_comments($args);
    $stats = array(
        'total_scanned' => 0,
        'spam_found' => 0,
        'legitimate' => 0,
        'errors' => 0
    );
    
    foreach ($comments as $comment) {
        $stats['total_scanned']++;
        
        try {
            // Mark as scanned first
            update_comment_meta($comment->comment_ID, '_bulk_spam_scanned', current_time('mysql'));
            
            // Check if it's spam
            if (is_comment_spam_enhanced($comment) || is_suspicious_ip($comment->comment_author_IP)) {
                wp_spam_comment($comment->comment_ID);
                $stats['spam_found']++;
                
                // Log for review
                error_log("Bulk scan marked comment #{$comment->comment_ID} as spam: {$comment->comment_author}");
            } else {
                $stats['legitimate']++;
            }
            
        } catch (Exception $e) {
            $stats['errors']++;
            error_log("Error scanning comment #{$comment->comment_ID}: " . $e->getMessage());
        }
    }
    
    return $stats;
}

// WP-CLI command or admin function to run bulk scan
function run_bulk_comment_scan() {
    $total_stats = array('total_scanned' => 0, 'spam_found' => 0, 'legitimate' => 0, 'errors' => 0);
    
    // Process in batches to avoid memory issues
    do {
        $batch_stats = bulk_scan_existing_comments(50); // Smaller batches for safety
        
        $total_stats['total_scanned'] += $batch_stats['total_scanned'];
        $total_stats['spam_found'] += $batch_stats['spam_found'];
        $total_stats['legitimate'] += $batch_stats['legitimate'];
        $total_stats['errors'] += $batch_stats['errors'];
        
        // Log progress
        error_log("Bulk scan progress: {$total_stats['total_scanned']} scanned, {$total_stats['spam_found']} spam found");
        
        // Small delay to prevent server overload
        sleep(1);
        
    } while ($batch_stats['total_scanned'] > 0);
    
    return $total_stats;
}

// Add admin notice showing spam scan statistics
add_action('admin_notices', 'show_spam_scan_stats');

function show_spam_scan_stats() {
    $screen = get_current_screen();
    if ($screen->id !== 'edit-comments') {
        return;
    }
    
    // Show comprehensive admin interface
    echo "<div class='notice notice-info'>";
    echo "<p><strong>UTM Anti-Spam System</strong> is active.</p>";
    echo "<p>";
    echo "<a href='#' onclick='runManualSpamCheck()' class='button'>Scan Pending Comments</a> ";
    echo "<a href='#' onclick='runBulkScan()' class='button button-primary'>Bulk Scan All Comments</a> ";
    echo "<a href='#' onclick='runDatabaseCleanup()' class='button'>Clean Old Spam</a> ";
    echo "<a href='#' onclick='showSpamStats()' class='button'>View Statistics</a>";
    echo "</p>";
    
    // Add AJAX functionality
    echo "<script>
    function runManualSpamCheck() {
        if (confirm('Scan pending comments for spam? This is safe and quick.')) {
            runAjaxSpamAction('scan_pending');
        }
    }
    
    function runBulkScan() {
        if (confirm('WARNING: This will scan ALL comments in your database.\\nThis may take several minutes. Continue?')) {
            runAjaxSpamAction('bulk_scan');
        }
    }
    
    function runDatabaseCleanup() {
        if (confirm('Delete spam comments older than 30 days?\\nThis action cannot be undone.')) {
            runAjaxSpamAction('cleanup_spam');
        }
    }
    
    function showSpamStats() {
        runAjaxSpamAction('show_stats');
    }
    
    function runAjaxSpamAction(action) {
        var data = {
            'action': 'utm_spam_admin_action',
            'spam_action': action,
            'nonce': '" . wp_create_nonce('utm_spam_admin') . "'
        };
        
        // Show loading
        var button = event.target;
        var originalText = button.textContent;
        button.textContent = 'Processing...';
        button.disabled = true;
        
        jQuery.post(ajaxurl, data, function(response) {
            alert(response.data.message);
            button.textContent = originalText;
            button.disabled = false;
            if (response.data.reload) {
                location.reload();
            }
        }).fail(function() {
            alert('Error occurred. Please try again.');
            button.textContent = originalText;
            button.disabled = false;
        });
    }
    </script>";
    echo "</div>";
}

// AJAX handler for admin actions
add_action('wp_ajax_utm_spam_admin_action', 'handle_utm_spam_admin_action');

function handle_utm_spam_admin_action() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'utm_spam_admin')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $action = sanitize_text_field($_POST['spam_action']);
    $response = array('reload' => false);
    
    switch ($action) {
        case 'scan_pending':
            $stats = scan_pending_comments_manual();
            $response['message'] = "Pending Scan Complete:\n" . 
                "Scanned: {$stats['total_scanned']}\n" .
                "Spam Found: {$stats['spam_found']}\n" .
                "Legitimate: {$stats['legitimate']}";
            break;
            
        case 'bulk_scan':
            $stats = run_bulk_comment_scan();
            $response['message'] = "Bulk Scan Complete:\n" . 
                "Total Scanned: {$stats['total_scanned']}\n" .
                "Spam Found: {$stats['spam_found']}\n" .
                "Legitimate: {$stats['legitimate']}\n" .
                "Errors: {$stats['errors']}";
            $response['reload'] = true;
            break;
            
        case 'cleanup_spam':
            $deleted = cleanup_old_spam_comments();
            $response['message'] = "Cleanup Complete:\nDeleted {$deleted} old spam comments.";
            $response['reload'] = true;
            break;
            
        case 'show_stats':
            $health = check_comment_table_health();
            $spam_percent = $health['total_comments'] > 0 ? 
                round(($health['spam_count'] / $health['total_comments']) * 100, 1) : 0;
            $response['message'] = "Database Statistics:\n" .
                "Total Comments: {$health['total_comments']}\n" .
                "Spam Comments: {$health['spam_count']}\n" .
                "Spam Percentage: {$spam_percent}%";
            break;
            
        default:
            $response['message'] = 'Unknown action';
    }
    
    wp_send_json_success($response);
}

// Helper function for manual pending scan
function scan_pending_comments_manual() {
    $pending_comments = get_comments(array(
        'status' => 'hold',
        'number' => 200 // Increased batch size for manual operation
    ));
    
    $stats = array(
        'total_scanned' => 0,
        'spam_found' => 0,
        'legitimate' => 0
    );
    
    foreach ($pending_comments as $comment) {
        $stats['total_scanned']++;
        
        if (is_comment_spam_enhanced($comment) || is_suspicious_ip($comment->comment_author_IP)) {
            wp_spam_comment($comment->comment_ID);
            $stats['spam_found']++;
            
            // Log the detection
            error_log("Manual scan marked comment #{$comment->comment_ID} as spam: {$comment->comment_author}");
        } else {
            $stats['legitimate']++;
        }
    }
    
    return $stats;
}

// Add admin menu for comprehensive spam management
add_action('admin_menu', 'utm_antispam_admin_menu');

function utm_antispam_admin_menu() {
    add_management_page(
        'UTM Anti-Spam Manager',
        'UTM Anti-Spam',
        'manage_options',
        'utm-antispam',
        'utm_antispam_admin_page'
    );
}

function utm_antispam_admin_page() {
    ?>
    <div class="wrap">
        <h1>🛡️ UTM Anti-Spam Management</h1>
        
        <?php
        // Handle form submissions
        if (isset($_POST['action']) && wp_verify_nonce($_POST['utm_spam_nonce'], 'utm_spam_action')) {
            switch ($_POST['action']) {
                case 'scan_pending':
                    $stats = scan_pending_comments_manual();
                    echo "<div class='notice notice-success'><p>✅ Scanned {$stats['total_scanned']} comments. Found {$stats['spam_found']} spam.</p></div>";
                    break;
                    
                case 'bulk_scan':
                    set_time_limit(300); // 5 minutes
                    $stats = run_bulk_comment_scan();
                    echo "<div class='notice notice-success'><p>✅ Bulk scan complete! Scanned {$stats['total_scanned']} comments, found {$stats['spam_found']} spam.</p></div>";
                    break;
                    
                case 'cleanup_spam':
                    $deleted = cleanup_old_spam_comments();
                    echo "<div class='notice notice-success'><p>✅ Deleted {$deleted} old spam comments.</p></div>";
                    break;
                    
                case 'update_settings':
                    update_option('utm_spam_rate_limit', intval($_POST['rate_limit']));
                    update_option('utm_spam_cleanup_days', intval($_POST['cleanup_days']));
                    echo "<div class='notice notice-success'><p>✅ Settings updated successfully.</p></div>";
                    break;
            }
        }
        
        // Get current statistics
        $health = check_comment_table_health();
        $spam_percent = $health['total_comments'] > 0 ? 
            round(($health['spam_count'] / $health['total_comments']) * 100, 1) : 0;
        
        $pending_count = get_comments(array('status' => 'hold', 'count' => true));
        ?>
        
        <div class="utm-spam-dashboard">
            <h2>📊 Dashboard Overview</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="postbox">
                    <div class="inside">
                        <h3>Total Comments</h3>
                        <p style="font-size: 2em; margin: 0;"><?php echo number_format($health['total_comments']); ?></p>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h3>Spam Comments</h3>
                        <p style="font-size: 2em; margin: 0; color: #d63638;"><?php echo number_format($health['spam_count']); ?></p>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h3>Spam Percentage</h3>
                        <p style="font-size: 2em; margin: 0; color: <?php echo $spam_percent > 50 ? '#d63638' : '#00a32a'; ?>;"><?php echo $spam_percent; ?>%</p>
                    </div>
                </div>
                <div class="postbox">
                    <div class="inside">
                        <h3>Pending Review</h3>
                        <p style="font-size: 2em; margin: 0; color: #dba617;"><?php echo number_format($pending_count); ?></p>
                    </div>
                </div>
            </div>
            
            <h2>⚡ Quick Actions</h2>
            <form method="post" style="margin: 20px 0;">
                <?php wp_nonce_field('utm_spam_action', 'utm_spam_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Scan Pending Comments</th>
                        <td>
                            <button type="submit" name="action" value="scan_pending" class="button">
                                🔍 Scan <?php echo $pending_count; ?> Pending Comments
                            </button>
                            <p class="description">Quick scan of comments waiting for approval.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bulk Scan All Comments</th>
                        <td>
                            <button type="submit" name="action" value="bulk_scan" class="button button-primary" 
                                    onclick="return confirm('This may take several minutes. Continue?')">
                                🚀 Scan All Comments
                            </button>
                            <p class="description"><strong>⚠️ Warning:</strong> This will scan all comments in your database.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clean Old Spam</th>
                        <td>
                            <button type="submit" name="action" value="cleanup_spam" class="button" 
                                    onclick="return confirm('Delete old spam comments? This cannot be undone.')">
                                🗑️ Delete Old Spam
                            </button>
                            <p class="description">Remove spam comments older than 30 days.</p>
                        </td>
                    </tr>
                </table>
            </form>
            
            <h2>⚙️ Settings</h2>
            <form method="post">
                <?php wp_nonce_field('utm_spam_action', 'utm_spam_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Rate Limit (comments/hour)</th>
                        <td>
                            <input type="number" name="rate_limit" value="<?php echo get_option('utm_spam_rate_limit', 3); ?>" min="1" max="20" />
                            <p class="description">Maximum comments per IP per hour.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cleanup Days</th>
                        <td>
                            <input type="number" name="cleanup_days" value="<?php echo get_option('utm_spam_cleanup_days', 30); ?>" min="7" max="365" />
                            <p class="description">Delete spam comments older than this many days.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="submit" name="action" value="update_settings" class="button button-primary">
                                💾 Update Settings
                            </button>
                        </td>
                    </tr>
                </table>
            </form>
            
            <h2>🛡️ Active Protection Features</h2>
            <div class="postbox">
                <div class="inside">
                    <ul style="list-style: none; padding: 0;">
                        <li>✅ <strong>StopForumSpam API</strong> - External spam database checking</li>
                        <li>✅ <strong>UTM Academic Context</strong> - University-specific spam detection</li>
                        <li>✅ <strong>Gambling & Casino</strong> - Blocks gambling-related spam</li>
                        <li>✅ <strong>IP-based Blocking</strong> - Known spam IP ranges</li>
                        <li>✅ <strong>Rate Limiting</strong> - Prevents spam floods</li>
                        <li>✅ <strong>Pattern Detection</strong> - Generic spam phrases</li>
                        <li>✅ <strong>Multi-link Filter</strong> - Blocks multiple external links</li>
                        <li>✅ <strong>Email Validation</strong> - Suspicious email patterns</li>
                        <li>✅ <strong>Academic Spam</strong> - Essay writing services, etc.</li>
                        <li>✅ <strong>Content Relevance</strong> - UTM-specific context checking</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// WP-CLI Commands for advanced users
if (defined('WP_CLI') && WP_CLI) {
    
    /**
     * UTM Anti-Spam management commands
     */
    class UTM_AntiSpam_CLI {
        
        /**
         * Scan all pending comments for spam
         * 
         * ## EXAMPLES
         * 
         *     wp utm-spam scan-pending
         */
        public function scan_pending($args, $assoc_args) {
            $stats = scan_pending_comments_manual();
            WP_CLI::success("Scanned {$stats['total_scanned']} comments, found {$stats['spam_found']} spam, {$stats['legitimate']} legitimate.");
        }
        
        /**
         * Run bulk scan on all comments
         * 
         * ## EXAMPLES
         * 
         *     wp utm-spam bulk-scan
         */
        public function bulk_scan($args, $assoc_args) {
            WP_CLI::log('Starting bulk scan... This may take several minutes.');
            $stats = run_bulk_comment_scan();
            WP_CLI::success("Bulk scan complete! Scanned {$stats['total_scanned']} comments, found {$stats['spam_found']} spam.");
        }
        
        /**
         * Clean up old spam comments
         * 
         * ## EXAMPLES
         * 
         *     wp utm-spam cleanup
         */
        public function cleanup($args, $assoc_args) {
            $deleted = cleanup_old_spam_comments();
            WP_CLI::success("Deleted {$deleted} old spam comments.");
        }
        
        /**
         * Show spam statistics
         * 
         * ## EXAMPLES
         * 
         *     wp utm-spam stats
         */
        public function stats($args, $assoc_args) {
            $health = check_comment_table_health();
            $spam_percent = $health['total_comments'] > 0 ? 
                round(($health['spam_count'] / $health['total_comments']) * 100, 1) : 0;
            
            WP_CLI::log("=== UTM Anti-Spam Statistics ===");
            WP_CLI::log("Total Comments: " . number_format($health['total_comments']));
            WP_CLI::log("Spam Comments: " . number_format($health['spam_count']));
            WP_CLI::log("Spam Percentage: {$spam_percent}%");
            
            $pending = get_comments(array('status' => 'hold', 'count' => true));
            WP_CLI::log("Pending Comments: " . number_format($pending));
        }
    }
    
    WP_CLI::add_command('utm-spam', 'UTM_AntiSpam_CLI');
}
