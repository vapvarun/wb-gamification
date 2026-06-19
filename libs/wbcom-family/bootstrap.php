<?php
/**
 * Wbcom Family Kit — portable bootstrap.
 *
 * Self-contained: own namespace (Wbcom\Family), own requires. NOT registered
 * in any host plugin's composer autoloader, so the directory drops into any
 * plugin unchanged. If two plugins bundle different Kit versions on one site,
 * the highest version wins (load-once-highest guard).
 *
 * @package Wbcom\Family
 */

defined( 'ABSPATH' ) || exit;

$wbcom_family_version = '1.0.0';

if ( defined( 'WBCOM_FAMILY_KIT_VERSION' ) ) {
	// Already loaded by this or a higher version — do nothing.
	if ( version_compare( WBCOM_FAMILY_KIT_VERSION, $wbcom_family_version, '>=' ) ) {
		return;
	}
	// A lower version loaded first cannot be un-declared; bail to avoid redeclare fatals.
	return;
}

define( 'WBCOM_FAMILY_KIT_VERSION', $wbcom_family_version );
define( 'WBCOM_FAMILY_KIT_DIR', __DIR__ );

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/class-state.php';
require_once __DIR__ . '/class-installer.php';
require_once __DIR__ . '/class-page.php';
require_once __DIR__ . '/class-kit.php';
