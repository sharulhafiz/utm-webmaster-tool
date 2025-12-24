#!/usr/bin/env php
<?php
/**
 * Phase 6 - Audio Playlist Widget Implementation Verification
 * Comprehensive test and verification script
 */

// Simple verification without WordPress
$tests_passed = 0;
$tests_failed = 0;
$test_results = array();

// ============================================================================
// BASIC CLASS CHECKS (Without WordPress)
// ============================================================================

// Test 1: Check if widget class definition exists in the file
$test_name = "Widget class defined in news.utm.my.php";
$widget_file = __DIR__ . '/../modules/news.utm.my.php';
if (file_exists($widget_file)) {
    $content = file_get_contents($widget_file);
    if (strpos($content, 'class UTM_News_Audio_Playlist_Widget extends WP_Widget') !== false) {
        $tests_passed++;
        $test_results[] = "✓ PASS: $test_name";
    } else {
        $tests_failed++;
        $test_results[] = "✗ FAIL: $test_name - Class definition not found";
    }
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name - File not found";
}

// Test 2: Check for __construct method
$test_name = "__construct method exists";
if (strpos($content, 'public function __construct()') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 3: Check for widget method
$test_name = "widget() method exists";
if (strpos($content, 'public function widget( $args, $instance )') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 4: Check for form method
$test_name = "form() method exists";
if (strpos($content, 'public function form( $instance )') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 5: Check for update method
$test_name = "update() method exists";
if (strpos($content, 'public function update( $new_instance, $old_instance )') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 6: Check for widget registration hook
$test_name = "Widget registration hook present";
if (strpos($content, 'add_action( \'widgets_init\'') !== false && 
    strpos($content, 'register_widget( \'UTM_News_Audio_Playlist_Widget\'' ) !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 7: Check for correct widget ID
$test_name = "Widget has correct ID 'utm_news_audio_playlist'";
if (strpos($content, "'utm_news_audio_playlist'") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 8: Check for correct widget name
$test_name = "Widget has correct name 'Audio Shortcasts Playlist'";
if (strpos($content, "'Audio Shortcasts Playlist'") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 9: Check for WP_Query usage
$test_name = "Widget uses WP_Query to fetch posts";
if (preg_match('/new\s+WP_Query\s*\(/', $content)) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 10: Check for meta_key with _audio_attachment_id
$test_name = "Widget queries for _audio_attachment_id meta";
if (strpos($content, "'meta_key'") !== false && 
    strpos($content, "'_audio_attachment_id'") !== false &&
    strpos($content, "'meta_compare'") !== false &&
    strpos($content, "'EXISTS'") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 11: Check for post status publish filter
$test_name = "Widget filters for published posts only";
if (strpos($content, "'post_status'") !== false && 
    strpos($content, "'publish'") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 12: Check for orderby date DESC
$test_name = "Widget orders by date descending (newest first)";
if (strpos($content, "'orderby'") !== false && 
    strpos($content, "'date'") !== false &&
    strpos($content, "'order'") !== false &&
    strpos($content, "'DESC'") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 13: Check for audio player HTML
$test_name = "Widget includes audio controls HTML";
if (strpos($content, '<audio') !== false && 
    strpos($content, 'controls') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 14: Check for proper list structure
$test_name = "Widget includes UL/LI list structure";
if (strpos($content, 'utm-audio-playlist') !== false && 
    strpos($content, '<ul') !== false &&
    strpos($content, '<li') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 15: Check for proper escaping
$test_name = "Widget uses proper escaping (esc_url, esc_html, esc_attr)";
$escaping_count = substr_count($content, 'esc_url') + 
                  substr_count($content, 'esc_html') + 
                  substr_count($content, 'esc_attr');
if ($escaping_count >= 5) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name - Found only $escaping_count escaping calls";
}

// Test 16: Check for get_field_id and get_field_name
$test_name = "Widget form uses get_field_id() and get_field_name()";
if (strpos($content, 'get_field_id') !== false && 
    strpos($content, 'get_field_name') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 17: Check for absint sanitization
$test_name = "Widget update sanitizes count with absint()";
if (strpos($content, 'absint') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 18: Check for default count value
$test_name = "Widget has default count of 10";
if (preg_match('/\:\s*10\s*[);,]/', $content)) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 19: Check for wp_reset_postdata
$test_name = "Widget calls wp_reset_postdata() after loop";
if (strpos($content, 'wp_reset_postdata()') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 20: Check for empty result handling
$test_name = "Widget handles empty results gracefully";
if (strpos($content, 'No audio shortcasts found') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 21: Check for inline CSS styling
$test_name = "Widget includes inline CSS styling";
if (strpos($content, '<style>') !== false && 
    strpos($content, '.utm-audio-playlist') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 22: Check test file exists
$test_name = "Phase 6 test file created";
if (file_exists(__DIR__ . '/../tests/test-phase6-audio-playlist-widget.php')) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 23: Check test runner file exists
$test_name = "Phase 6 test runner created";
if (file_exists(__DIR__ . '/../tests/run-phase6-tests.php')) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 24: Verify posts_per_page is set correctly
$test_name = "Widget sets posts_per_page based on count parameter";
if (strpos($content, "'posts_per_page' => \$count") !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// Test 25: Check for get_post_mime_type usage
$test_name = "Widget retrieves MIME type for audio files";
if (strpos($content, 'get_post_mime_type') !== false) {
    $tests_passed++;
    $test_results[] = "✓ PASS: $test_name";
} else {
    $tests_failed++;
    $test_results[] = "✗ FAIL: $test_name";
}

// ============================================================================
// OUTPUT RESULTS
// ============================================================================

echo "\n" . str_repeat("=", 60) . "\n";
echo "    PHASE 6 - AUDIO PLAYLIST WIDGET VERIFICATION\n";
echo str_repeat("=", 60) . "\n\n";

foreach ($test_results as $result) {
    echo $result . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Total: " . ($tests_passed + $tests_failed) . " | ";
echo "Passed: " . $tests_passed . " | ";
echo "Failed: " . $tests_failed . "\n";
echo str_repeat("=", 60) . "\n\n";

if ($tests_failed === 0) {
    echo "✓ ALL VERIFICATION CHECKS PASSED!\n\n";
    exit(0);
} else {
    echo "✗ SOME VERIFICATION CHECKS FAILED\n\n";
    exit(1);
}
