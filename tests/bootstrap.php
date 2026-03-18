<?php
/**
 * PHPUnit bootstrap for WB Gamification.
 *
 * Uses Brain\Monkey for WordPress function mocking.
 * Does NOT load a full WordPress environment — all WP functions
 * are mocked via Mockery / Brain\Monkey stubs.
 *
 * @package WB_Gamification
 */

// Define WordPress constants BEFORE autoload so that autoloaded files
// (e.g. src/Extensions/functions.php) don't hit their `defined('ABSPATH') || exit` guard.
defined( 'ABSPATH' )        || define( 'ABSPATH',        dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_VERSION' ) || define( 'WB_GAM_VERSION', '0.1.0' );
defined( 'WB_GAM_PATH' )    || define( 'WB_GAM_PATH',    dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_URL' )     || define( 'WB_GAM_URL',     'http://example.com/wp-content/plugins/wb-gamification/' );

// WordPress DB result-type constants expected by PointsEngine and other classes.
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'OBJECT' )  || define( 'OBJECT',  'OBJECT' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
