<?php
/**
 * Magazine module tests — standalone runner.
 *
 * Run on any WordPress host with the plugin active:
 *   wp eval-file tests/test-magazine.php
 *
 * Or directly with PHP (requires ABSPATH defined):
 *   php tests/test-magazine.php
 */

// Minimal WordPress stub if not running via wp-cli.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__ ) . '/../../' );
}

// Load module security functions directly.
require_once dirname( __DIR__ ) . '/modules/magazine/security.php';
require_once dirname( __DIR__ ) . '/modules/magazine/context.php';

$fixtures_dir = dirname( __FILE__ ) . '/fixtures';
$pass = 0;
$fail = 0;

function assert_empty( $errors, $label ) {
    global $pass, $fail;
    if ( empty( $errors ) ) {
        echo "  PASS: $label\n";
        $pass++;
    } else {
        echo "  FAIL: $label — errors: " . implode( '; ', $errors ) . "\n";
        $fail++;
    }
}

function assert_nonempty( $errors, $label ) {
    global $pass, $fail;
    if ( ! empty( $errors ) ) {
        echo "  PASS: $label — got: " . implode( '; ', $errors ) . "\n";
        $pass++;
    } else {
        echo "  FAIL: $label — expected errors but got none\n";
        $fail++;
    }
}

echo "=== Magazine ZIP Validation Tests ===\n";

// valid-simple.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/valid-simple.zip" );
assert_empty( $errors, 'valid-simple.zip passes' );

// valid-subdir.zip (single top-level dir)
$errors = utm_magazine_validate_zip( "$fixtures_dir/valid-subdir.zip" );
assert_empty( $errors, 'valid-subdir.zip passes' );

// traversal.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/traversal.zip" );
assert_nonempty( $errors, 'traversal.zip rejected' );

// symlink.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/symlink.zip" );
assert_nonempty( $errors, 'symlink.zip rejected' );

// php-inside.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/php-inside.zip" );
assert_nonempty( $errors, 'php-inside.zip rejected' );

// no-index.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/no-index.zip" );
assert_nonempty( $errors, 'no-index.zip rejected' );

// over500.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/over500.zip" );
assert_nonempty( $errors, 'over500.zip rejected (>500 files)' );

// oversized.zip
$errors = utm_magazine_validate_zip( "$fixtures_dir/oversized.zip" );
assert_nonempty( $errors, 'oversized.zip rejected (>50MB)' );

// nonexistent file
$errors = utm_magazine_validate_zip( '/tmp/does-not-exist.zip' );
assert_nonempty( $errors, 'nonexistent.zip rejected' );

echo "\n=== Context Tests ===\n";

// Host gate
$_SERVER['HTTP_HOST'] = 'chancellery.utm.my';
assert( utm_magazine_is_allowed_context(), 'chancellery.utm.my allowed' );
echo "  PASS: chancellery.utm.my allowed\n";
$pass++;

$_SERVER['HTTP_HOST'] = 'news.utm.my';
$allowed = utm_magazine_is_allowed_context();
// news.utm.my is not admin/ajax — should be false
if ( ! $allowed ) {
    echo "  PASS: news.utm.my blocked on frontend\n";
    $pass++;
} else {
    echo "  FAIL: news.utm.my should be blocked on frontend\n";
    $fail++;
}

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
