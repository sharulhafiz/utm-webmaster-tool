<?php
/**
 * Static HTML Publisher — automated tests.
 *
 * Standalone runner: tests ZIP validation, extraction security, and storage.
 *
 * Run on any WordPress host with the plugin active:
 *   wp eval-file tests/test-shp.php
 *
 * Or directly with PHP (stubs for validation/storage functions only):
 *   php tests/test-shp.php
 */

// ── Minimal WordPress stubs ──────────────────────────────────────
// These stubs allow the validation and basic storage functions to run
// outside WordPress. Functions requiring full WP (post meta, hooks)
// are skipped when WP is not available.

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__ ) . '/../../' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! function_exists( 'size_format' ) ) {
    function size_format( $bytes, $decimals = 0 ) {
        return $bytes . ' bytes';
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special_chars = true ) {
        return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) {
        return @mkdir( $dir, 0755, true );
    }
}

// Stubs for post meta functions (used by storage.php outside WordPress).
$_shp_post_meta = [];
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $id ) { return (object) [ 'ID' => $id, 'post_type' => 'page' ]; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = false ) {
        global $_shp_post_meta;
        $val = $_shp_post_meta[ $id ][ $key ] ?? '';
        return $single ? $val : [ $val ];
    }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $id, $key, $value ) {
        global $_shp_post_meta;
        $_shp_post_meta[ $id ][ $key ] = $value;
    }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $id, $key = '' ) {
        global $_shp_post_meta;
        if ( '' === $key ) {
            unset( $_shp_post_meta[ $id ] );
        } else {
            unset( $_shp_post_meta[ $id ][ $key ] );
        }
    }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id = 0 ) { return 'page'; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $format = 'mysql', $gmt = false ) {
        return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
    }
}

// Load module functions directly.
$repo_root = dirname( __DIR__ );
require_once $repo_root . '/modules/static-html-publisher/validation.php';
require_once $repo_root . '/modules/static-html-publisher/storage.php';

$fixtures_dir = dirname( __FILE__ ) . '/fixtures';
$pass = 0;
$fail = 0;

function assert_test( $condition, $label, $detail = '' ) {
    global $pass, $fail;
    if ( $condition ) {
        echo "  PASS: $label\n";
        $pass++;
    } else {
        echo "  FAIL: $label" . ( $detail ? " — $detail" : '' ) . "\n";
        $fail++;
    }
}

// ══════════════════════════════════════════════════════════════════
// 1. ZIP Validation Tests
// ══════════════════════════════════════════════════════════════════
echo "=== ZIP Validation ===\n";

$errors = utm_shp_validate_zip( "$fixtures_dir/valid-simple.zip" );
assert_test( empty( $errors ), 'valid-simple.zip passes', implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/valid-subdir.zip" );
assert_test( empty( $errors ), 'valid-subdir.zip passes', implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/traversal.zip" );
assert_test( ! empty( $errors ), 'traversal.zip rejected', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/symlink.zip" );
assert_test( ! empty( $errors ), 'symlink.zip rejected', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/php-inside.zip" );
assert_test( ! empty( $errors ), 'php-inside.zip rejected', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/no-index.zip" );
assert_test( ! empty( $errors ), 'no-index.zip rejected', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/over500.zip" );
assert_test( ! empty( $errors ), 'over500.zip rejected (>500 files)', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( "$fixtures_dir/oversized.zip" );
assert_test( ! empty( $errors ), 'oversized.zip rejected (>50MB)', 'errors: ' . implode( '; ', $errors ) );

$errors = utm_shp_validate_zip( '/tmp/does-not-exist.zip' );
assert_test( ! empty( $errors ), 'nonexistent.zip rejected', 'errors: ' . implode( '; ', $errors ) );

// Custom limits override.
$errors = utm_shp_validate_zip( "$fixtures_dir/valid-simple.zip", [ 'max_bytes' => 100 ] );
assert_test( ! empty( $errors ), 'custom low max_bytes rejects valid ZIP', 'errors: ' . implode( '; ', $errors ) );

// ══════════════════════════════════════════════════════════════════
// 2. Symlink Scan Tests
// ══════════════════════════════════════════════════════════════════
echo "\n=== Symlink Scan ===\n";

$tmp_dir = sys_get_temp_dir() . '/shp-test-' . wp_generate_password( 8, false );
mkdir( $tmp_dir, 0755, true );
file_put_contents( "$tmp_dir/index.html", '<html></html>' );
$errors = utm_shp_scan_symlinks( $tmp_dir );
assert_test( empty( $errors ), 'clean directory: no symlinks', implode( '; ', $errors ) );

symlink( "$tmp_dir/index.html", "$tmp_dir/evil.html" );
$errors = utm_shp_scan_symlinks( $tmp_dir );
assert_test( ! empty( $errors ), 'symlink detected after extraction', 'errors: ' . implode( '; ', $errors ) );
unlink( "$tmp_dir/evil.html" );

array_map( 'unlink', glob( "$tmp_dir/*" ) );
rmdir( $tmp_dir );

// ══════════════════════════════════════════════════════════════════
// 3. Extraction Tests (filesystem only — no post meta)
// ══════════════════════════════════════════════════════════════════
echo "\n=== Extraction ===\n";

$page_id  = 9999001;
$page_id2 = 9999002;
$page_id3 = 9999003;

// Extract valid-simple.zip.
$result = utm_shp_activate( "$fixtures_dir/valid-simple.zip", $page_id, 'valid-simple.zip' );
assert_test( empty( $result ), 'valid-simple.zip extracts without errors', implode( '; ', $result ) );

$active_dir = utm_shp_package_dir( $page_id );
assert_test( is_dir( $active_dir ), 'active directory created' );
assert_test( is_file( "$active_dir/index.html" ), 'index.html exists in active dir' );

$content = file_get_contents( "$active_dir/index.html" );
assert_test( false !== strpos( $content, 'Test' ), 'index.html content correct' );

// Extract valid-subdir.zip.
$result = utm_shp_activate( "$fixtures_dir/valid-subdir.zip", $page_id2, 'valid-subdir.zip' );
assert_test( empty( $result ), 'valid-subdir.zip extracts without errors', implode( '; ', $result ) );

$active_dir2 = utm_shp_package_dir( $page_id2 );
assert_test( is_file( "$active_dir2/my-article/index.html" ), 'index.html inside subdirectory' );

// Test re-publish (atomic replacement).
$result = utm_shp_activate( "$fixtures_dir/valid-simple.zip", $page_id2, 'valid-simple.zip' );
assert_test( empty( $result ), 're-publish replaces old package', implode( '; ', $result ) );
assert_test( ! is_dir( "$active_dir2/my-article" ), 'old subdirectory removed' );
assert_test( is_file( "$active_dir2/index.html" ), 'new index.html in place' );

// Test extraction failure cleanup.
$errors = utm_shp_activate( "$fixtures_dir/traversal.zip", $page_id3, 'traversal.zip' );
assert_test( ! empty( $errors ), 'traversal.zip extraction fails', 'errors: ' . implode( '; ', $errors ) );
assert_test( ! is_dir( utm_shp_package_dir( $page_id3 ) ), 'no active dir after failed extraction' );

// ══════════════════════════════════════════════════════════════════
// 4. Deactivation Tests (filesystem only)
// ══════════════════════════════════════════════════════════════════
echo "\n=== Deactivation ===\n";

utm_shp_deactivate( $page_id );
assert_test( ! is_dir( utm_shp_package_dir( $page_id ) ), 'active dir removed on deactivate' );

// ══════════════════════════════════════════════════════════════════
// 5. Staging Cleanup Tests
// ══════════════════════════════════════════════════════════════════
echo "\n=== Staging Cleanup ===\n";

$staging_base = utm_shp_packages_dir();
$fake_staging = $staging_base . '/9999004__staging_faketest';
@mkdir( $staging_base, 0755, true );
@mkdir( $fake_staging, 0755, true );
file_put_contents( "$fake_staging/test.txt", 'stale' );
assert_test( is_dir( $fake_staging ), 'fake staging dir created' );

utm_shp_cleanup_staging( 9999004 );
assert_test( ! is_dir( $fake_staging ), 'stale staging cleaned up' );

// ══════════════════════════════════════════════════════════════════
// 6. Filesystem Containment Tests
// ══════════════════════════════════════════════════════════════════
echo "\n=== Filesystem Containment ===\n";

// Verify that package dirs are always under static-packages/.
$pkg_dir = utm_shp_package_dir( 42 );
$expected_base = utm_shp_packages_dir();
assert_test(
    0 === strpos( $pkg_dir, $expected_base ),
    'package dir is under static-packages base'
 );

// Verify staging dir contains page ID.
$staging = utm_shp_staging_dir( 42 );
assert_test(
    false !== strpos( $staging, '42__staging_' ),
    'staging dir contains page ID prefix'
 );

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
