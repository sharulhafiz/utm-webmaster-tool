<?php
/**
 * Admission Module Compatibility Shim
 *
 * Keeps the historical file entrypoint available while the module is loaded
 * through the package bootstrap at modules/admission.utm.my/bootstrap.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admission.utm.my/bootstrap.php';
