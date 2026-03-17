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

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants expected by the plugin.
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_VERSION' ) || define( 'WB_GAM_VERSION', '0.1.0' );
defined( 'WB_GAM_PATH' )    || define( 'WB_GAM_PATH',    dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_URL' )     || define( 'WB_GAM_URL',     'http://example.com/wp-content/plugins/wb-gamification/' );
