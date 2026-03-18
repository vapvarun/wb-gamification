<?php
/**
 * PHP auto_prepend_file for PHPUnit.
 *
 * Defines ABSPATH and other WordPress constants that must exist before
 * Composer's autoloader runs (src/Extensions/functions.php guards on ABSPATH).
 *
 * Usage (via phpunit.xml.dist <php> ini block, or CLI):
 *   php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit
 *
 * @package WB_Gamification
 */

$_wb_gam_root = dirname( __DIR__ );

defined( 'ABSPATH' )        || define( 'ABSPATH',        $_wb_gam_root . '/' );
defined( 'WB_GAM_VERSION' ) || define( 'WB_GAM_VERSION', '0.1.0' );
defined( 'WB_GAM_PATH' )    || define( 'WB_GAM_PATH',    $_wb_gam_root . '/' );
defined( 'WB_GAM_URL' )     || define( 'WB_GAM_URL',     'http://example.com/wp-content/plugins/wb-gamification/' );

// WordPress DB result-type constants.
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'OBJECT' )  || define( 'OBJECT',  'OBJECT' );

unset( $_wb_gam_root );
