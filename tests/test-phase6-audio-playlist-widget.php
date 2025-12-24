<?php
/**
 * Phase 6 Tests - Audio Playlist Widget
 * Tests for the UTM_News_Audio_Playlist_Widget class
 */

// Exit if accessed directly (for security)
if (!defined('ABSPATH')) {
    exit;
}

// Set up test environment
require_once(ABSPATH . 'wp-load.php');

// Track test results
$tests_passed = 0;
$tests_failed = 0;
$test_results = array();

function run_test($test_name, $callback) {
    global $tests_passed, $tests_failed, $test_results;
    try {
        $result = $callback();
        if ($result === true) {
            $tests_passed++;
            $test_results[] = "✓ PASS: $test_name";
        } else {
            $tests_failed++;
            $test_results[] = "✗ FAIL: $test_name - $result";
        }
    } catch (Exception $e) {
        $tests_failed++;
        $test_results[] = "✗ ERROR: $test_name - " . $e->getMessage();
    }
}

// ============================================================================
// WIDGET CLASS EXISTENCE TESTS
// ============================================================================

run_test('UTM_News_Audio_Playlist_Widget class exists', function() {
    return class_exists('UTM_News_Audio_Playlist_Widget') ? true : "Class does not exist";
});

run_test('UTM_News_Audio_Playlist_Widget extends WP_Widget', function() {
    return is_subclass_of('UTM_News_Audio_Playlist_Widget', 'WP_Widget') ? true : "Does not extend WP_Widget";
});

// ============================================================================
// WIDGET REGISTRATION TESTS
// ============================================================================

run_test('Widget is registered in widgets_init hook', function() {
    $registered = false;
    $widgets = $GLOBALS['wp_widget_factory']->widgets;
    foreach ($widgets as $widget) {
        if (get_class($widget) === 'UTM_News_Audio_Playlist_Widget') {
            $registered = true;
            break;
        }
    }
    return $registered ? true : "Widget not found in widget factory";
});

// ============================================================================
// WIDGET CONSTRUCTOR TESTS
// ============================================================================

run_test('Widget constructor sets correct ID', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    return $widget->id_base === 'utm_news_audio_playlist' ? true : "ID is: " . $widget->id_base;
});

run_test('Widget constructor sets correct name', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    return $widget->name === 'Audio Shortcasts Playlist' ? true : "Name is: " . $widget->name;
});

run_test('Widget constructor sets description', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    return !empty($widget->widget_options['description']) ? true : "Description is empty";
});

// ============================================================================
// WIDGET QUERY TESTS
// ============================================================================

run_test('Widget queries posts with _audio_attachment_id meta', function() {
    // Create a test post with audio
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post With Audio',
        'post_content' => 'Test content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    
    update_post_meta($post_id, '_audio_attachment_id', 123);
    
    // Create a test post without audio
    $post_id_2 = wp_insert_post(array(
        'post_title' => 'Test Post Without Audio',
        'post_content' => 'Test content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    
    // Create widget and test output
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    // Clean up
    wp_delete_post($post_id, true);
    wp_delete_post($post_id_2, true);
    
    // Should contain the post with audio but not without
    return (strpos($output, 'Test Post With Audio') !== false && 
            strpos($output, 'Test Post Without Audio') === false) 
        ? true 
        : "Widget did not query posts correctly";
});

run_test('Widget displays only published posts', function() {
    // Create published post with audio
    $post_id_1 = wp_insert_post(array(
        'post_title' => 'Published Post',
        'post_content' => 'Published content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    update_post_meta($post_id_1, '_audio_attachment_id', 123);
    
    // Create draft post with audio
    $post_id_2 = wp_insert_post(array(
        'post_title' => 'Draft Post',
        'post_content' => 'Draft content',
        'post_status' => 'draft',
        'post_type' => 'post'
    ));
    update_post_meta($post_id_2, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id_1, true);
    wp_delete_post($post_id_2, true);
    
    return (strpos($output, 'Published Post') !== false && 
            strpos($output, 'Draft Post') === false) 
        ? true 
        : "Widget displayed non-published posts";
});

run_test('Widget respects count setting with default value 10', function() {
    // Create 15 posts with audio
    $post_ids = array();
    for ($i = 1; $i <= 15; $i++) {
        $post_id = wp_insert_post(array(
            'post_title' => 'Post ' . $i,
            'post_content' => 'Content ' . $i,
            'post_status' => 'publish',
            'post_type' => 'post'
        ));
        update_post_meta($post_id, '_audio_attachment_id', 123);
        $post_ids[] = $post_id;
    }
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    // Count how many post titles appear (look for "Post N" patterns)
    $count = 0;
    for ($i = 1; $i <= 15; $i++) {
        if (strpos($output, 'Post ' . $i) !== false) {
            $count++;
        }
    }
    
    // Clean up
    foreach ($post_ids as $id) {
        wp_delete_post($id, true);
    }
    
    return $count === 10 ? true : "Expected 10 posts, got $count";
});

run_test('Widget orders posts by date newest first', function() {
    // Create posts with specific dates
    $post_id_1 = wp_insert_post(array(
        'post_title' => 'Old Post',
        'post_content' => 'Old',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date' => '2025-01-01 10:00:00'
    ));
    update_post_meta($post_id_1, '_audio_attachment_id', 123);
    
    $post_id_2 = wp_insert_post(array(
        'post_title' => 'New Post',
        'post_content' => 'New',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date' => '2025-12-24 10:00:00'
    ));
    update_post_meta($post_id_2, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id_1, true);
    wp_delete_post($post_id_2, true);
    
    // New post should come before old post in output
    $new_pos = strpos($output, 'New Post');
    $old_pos = strpos($output, 'Old Post');
    
    return ($new_pos !== false && $old_pos !== false && $new_pos < $old_pos) 
        ? true 
        : "Posts not ordered correctly";
});

// ============================================================================
// WIDGET DISPLAY TESTS
// ============================================================================

run_test('Widget displays post titles as links', function() {
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post Title',
        'post_content' => 'Content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    update_post_meta($post_id, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id, true);
    
    return (strpos($output, '<a href=') !== false && 
            strpos($output, 'Test Post Title') !== false) 
        ? true 
        : "Post title not displayed as link";
});

run_test('Widget displays audio players with controls', function() {
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post',
        'post_content' => 'Content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    update_post_meta($post_id, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id, true);
    
    return (strpos($output, '<audio') !== false && 
            strpos($output, 'controls') !== false) 
        ? true 
        : "Audio player not found";
});

run_test('Widget displays post dates', function() {
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post',
        'post_content' => 'Content',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date' => '2025-12-24 10:00:00'
    ));
    update_post_meta($post_id, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id, true);
    
    return strpos($output, 'post-date') !== false ? true : "Post date not displayed";
});

run_test('Widget handles empty results gracefully', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    // Should output something, not be completely empty
    return !empty($output) ? true : "Widget output is empty";
});

run_test('Widget includes proper HTML structure', function() {
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post',
        'post_content' => 'Content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    update_post_meta($post_id, '_audio_attachment_id', 123);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div id="widget">',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id, true);
    
    return (strpos($output, 'utm-audio-playlist') !== false && 
            strpos($output, '<ul') !== false && 
            strpos($output, '<li') !== false) 
        ? true 
        : "HTML structure not correct";
});

// ============================================================================
// WIDGET FORM TESTS
// ============================================================================

run_test('Widget form has count input field', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->form(array('count' => 10));
    $output = ob_get_clean();
    
    return (strpos($output, 'type="number"') !== false || 
            strpos($output, 'input') !== false) 
        ? true 
        : "Count input field not found";
});

run_test('Widget form displays current count value', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->form(array('count' => 5));
    $output = ob_get_clean();
    
    return strpos($output, '5') !== false ? true : "Count value not displayed";
});

run_test('Widget form has label for count field', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->form(array('count' => 10));
    $output = ob_get_clean();
    
    return (strpos($output, '<label') !== false || 
            strpos($output, 'Number') !== false) 
        ? true 
        : "Label not found";
});

// ============================================================================
// WIDGET UPDATE TESTS
// ============================================================================

run_test('Widget update sanitizes count value', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    $result = $widget->update(array('count' => '15'), array('count' => 10));
    
    return $result['count'] === 15 ? true : "Count not properly updated";
});

run_test('Widget update returns default count when empty', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    $result = $widget->update(array('count' => ''), array('count' => 10));
    
    return $result['count'] === 10 ? true : "Default count not applied";
});

run_test('Widget update converts count to integer', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    $result = $widget->update(array('count' => '25'), array('count' => 10));
    
    return is_int($result['count']) ? true : "Count is not an integer";
});

run_test('Widget update sanitizes non-numeric count to default', function() {
    $widget = new UTM_News_Audio_Playlist_Widget();
    $result = $widget->update(array('count' => 'abc'), array('count' => 10));
    
    return $result['count'] === 10 ? true : "Non-numeric value not sanitized";
});

// ============================================================================
// AUDIO SOURCE TEST
// ============================================================================

run_test('Widget includes audio source with proper type', function() {
    // Create attachment
    $attachment_id = wp_insert_attachment(array(
        'post_title' => 'Test Audio',
        'post_mime_type' => 'audio/mpeg',
        'post_status' => 'publish',
        'post_type' => 'attachment'
    ));
    
    $post_id = wp_insert_post(array(
        'post_title' => 'Test Post',
        'post_content' => 'Content',
        'post_status' => 'publish',
        'post_type' => 'post'
    ));
    update_post_meta($post_id, '_audio_attachment_id', $attachment_id);
    
    $widget = new UTM_News_Audio_Playlist_Widget();
    ob_start();
    $widget->widget(array(
        'before_widget' => '<div>',
        'after_widget' => '</div>',
        'before_title' => '<h3>',
        'after_title' => '</h3>'
    ), array('count' => 10));
    $output = ob_get_clean();
    
    wp_delete_post($post_id, true);
    wp_delete_post($attachment_id, true);
    
    return (strpos($output, '<source') !== false && 
            strpos($output, 'type=') !== false) 
        ? true 
        : "Audio source not properly included";
});

// ============================================================================
// OUTPUT RESULTS
// ============================================================================

echo "\n==============================================\n";
echo "       PHASE 6 - AUDIO PLAYLIST WIDGET TESTS\n";
echo "==============================================\n\n";

foreach ($test_results as $result) {
    echo $result . "\n";
}

echo "\n==============================================\n";
echo "Total: " . ($tests_passed + $tests_failed) . " | ";
echo "Passed: " . $tests_passed . " | ";
echo "Failed: " . $tests_failed . "\n";
echo "==============================================\n\n";

if ($tests_failed === 0) {
    echo "✓ ALL TESTS PASSED!\n\n";
} else {
    echo "✗ SOME TESTS FAILED\n\n";
}

// Output in WP-appropriate format if in admin
if (defined('WP_ADMIN') && WP_ADMIN) {
    echo '<pre>';
    foreach ($test_results as $result) {
        echo htmlspecialchars($result) . "\n";
    }
    echo '</pre>';
}
