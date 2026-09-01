<?php
/**
 * Static HTML Publisher — automated tests.
 *
 * Standalone runner: tests ZIP validation and extraction security.
 *
 * Run on any WordPress host with the plugin active:
 *   wp eval-file tests/test-shp.php
 *
 * Or directly with PHP (stubs ABSPATH for validation functions):
 *   php tests/test-shp.php
 */

// Minimal WordPress stubs if not running via wp-cli.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__ ) . '/../../' );
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

// Load module functions directly.
require_once dirname( __DIR__ ) . '/../modules/static-html-publisher/validation.php';
require_once dirname( __DIR__ ) . '/../modules/static-html-publisher/storage.php';

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

// valid-simple.zip — index.html at root.
$errors = utm_shp_validate_zip( "$fixtures_dir/valid-simple.zip" );
assert_test( empty( $errors ), 'valid-simple.zip passes', implode( '; ', $errors ) );

// valid-subdir.zip — single top-level dir with index.html.
$errors = utm_shp_validate_zip( "$fixtures_dir/valid-subdir.zip" );
assert_test( empty( $errors ), 'valid-subdir.zip passes', implode( '; ', $errors ) );

// traversal.zip — path traversal (../).
$errors = utm_shp_validate_zip( "$fixtures_dir/traversal.zip" );
assert_test( ! empty( $errors ), 'traversal.zip rejected', 'errors: ' . implode( '; ', $errors ) );

// symlink.zip — symlink entry.
$errors = utm_shp_validate_zip( "$fixtures_dir/symlink.zip" );
assert_test( ! empty( $errors ), 'symlink.zip rejected', 'errors: ' . implode( '; ', $errors ) );

// php-inside.zip — .php file inside.
$errors = utm_shp_validate_zip( "$fixtures_dir/php-inside.zip" );
assert_test( ! empty( $errors ), 'php-inside.zip rejected', 'errors: ' . implode( '; ', $errors ) );

// no-index.zip — no index.html.
$errors = utm_shp_validate_zip( "$fixtures_dir/no-index.zip" );
assert_test( ! empty( $errors ), 'no-index.zip rejected', 'errors: ' . implode( '; ', $errors ) );

// over500.zip — exceeds 500 file limit.
$errors = utm_shp_validate_zip( "$fixtures_dir/over500.zip" );
assert_test( ! empty( $errors ), 'over500.zip rejected (>500 files)', 'errors: ' . implode( '; ', $errors ) );

// oversized.zip — exceeds 50MB limit.
$errors = utm_shp_validate_zip( "$fixtures_dir/oversized.zip" );
assert_test( ! empty( $errors ), 'oversized.zip rejected (>50MB)', 'errors: ' . implode( '; ', $errors ) );

// Nonexistent file.
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

// Create a symlink.
symlink( "$tmp_dir/index.html", "$tmp_dir/evil.html" );
$errors = utm_shp_scan_symlinks( $tmp_dir );
assert_test( ! empty( $errors ), 'symlink detected after extraction', 'errors: ' . implode( '; ', $errors ) );
unlink( "$tmp_dir/evil.html" );

// Cleanup.
array_map( 'unlink', glob( "$tmp_dir/*" ) );
rmdir( $tmp_dir );

// ══════════════════════════════════════════════════════════════════
// 3. Extraction Tests
// ══════════════════════════════════════════════════════════════════
echo "\n=== Extraction ===\n";

$tmp_dir = sys_get_temp_dir() . '/shp-test-' . wp_generate_password( 8, false );
mkdir( $tmp_dir, 0755, true );

// Mock wp_mkdir_p and wp_tempnam if not available.
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) {
        return @mkdir( $dir, 0755, true );
    }
}

// Extract valid-simple.zip.
$page_id = 9999001;
$zip_path = "$fixtures_dir/valid-simple.zip";
$result = utm_shp_activate( $zip_path, $page_id, 'valid-simple.zip' );
assert_test( empty( $result ), 'valid-simple.zip extracts without errors', implode( '; ', $result ) );

$active_dir = utm_shp_package_dir( $page_id );
assert_test( is_dir( $active_dir ), 'active directory created' );
assert_test( is_file( "$active_dir/index.html" ), 'index.html exists in active dir' );

// Verify the HTML content was extracted.
$content = file_get_contents( "$active_dir/index.html" );
assert_test( false !== strpos( $content, 'Test' ), 'index.html content correct' );

// Verify metadata was stored.
$meta = utm_shp_get_meta( $page_id );
assert_test( $meta && 'active' === $meta->status, 'DB metadata status is active' );
assert_test( $meta && $meta->zip_name === 'valid-simple.zip', 'DB metadata zip_name correct' );

// Extract valid-subdir.zip to a different page ID.
$page_id2 = 9999002;
$errors = utm_shp_activate( "$fixtures_dir/valid-subdir.zip", $page_id2, 'valid-subdir.zip' );
assert_test( empty( $errors ), 'valid-subdir.zip extracts without errors', implode( '; ', $errors ) );

$active_dir2 = utm_shp_package_dir( $page_id2 );
assert_test( is_file( "$active_dir2/sub/index.html" ), 'index.html inside subdirectory' );

// Test re-publish (atomic replacement).
$errors = utm_shp_activate( "$fixtures_dir/valid-simple.zip", $page_id2, 'valid-simple.zip' );
assert_test( empty( $errors ), 're-publish replaces old package', implode( '; ', $errors ) );
assert_test( ! is_dir( "$active_dir2/sub" ), 'old subdirectory removed' );
assert_test( is_file( "$active_dir2/index.html" ), 'new index.html in place' );

// Test extraction failure cleanup.
$page_id3 = 9999003;
$errors = utm_shp_activate( "$fixtures_dir/traversal.zip", $page_id3, 'traversal.zip' );
assert_test( ! empty( $errors ), 'traversal.zip extraction fails', 'errors: ' . implode( '; ', $errors ) );
assert_test( ! is_dir( utm_shp_package_dir( $page_id3 ) ), 'no active dir after failed extraction' );

// ══════════════════════════════════════════════════════════════════
// 4. Deactivation Tests
// ══════════════════════════════════════════════════════════════════
echo "\n=== Deactivation ===\n";

utm_shp_deactivate( $page_id );
assert_test( ! is_dir( utm_shp_package_dir( $page_id ) ), 'active dir removed on deactivate' );
assert_test( utm_shp_get_meta( $page_id ) === null, 'DB metadata removed on deactivate' );

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
// Summary
// ══════════════════════════════════════════════════════════════════
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
