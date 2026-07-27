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

// Define WordPress constants BEFORE anything else, so the guard in
// src/Extensions/functions.php (required at the bottom of this file) is satisfied.
defined( 'ABSPATH' )        || define( 'ABSPATH',        dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_VERSION' ) || define( 'WB_GAM_VERSION', '0.1.0' );
defined( 'WB_GAM_PATH' )    || define( 'WB_GAM_PATH',    dirname( __DIR__ ) . '/' );
defined( 'WB_GAM_URL' )     || define( 'WB_GAM_URL',     'http://example.com/wp-content/plugins/wb-gamification/' );

// WordPress DB result-type constants expected by PointsEngine and other classes.
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'OBJECT' )  || define( 'OBJECT',  'OBJECT' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The global wb_gam_* helpers. These used to arrive via Composer's `files` autoload, which is what
// forced that file to carry a CLI escape hatch in its direct-access guard -- and that hatch is a
// shape the WP.org Plugin Check does not recognise, so the plugin failed the submission bar. The
// `files` entry was redundant (the plugin bootstrap require_once's the same file), so it is gone and
// the tests load it here, explicitly, after ABSPATH is defined above.
require_once dirname( __DIR__ ) . '/src/Extensions/functions.php';
