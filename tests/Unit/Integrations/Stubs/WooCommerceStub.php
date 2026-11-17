<?php
/**
 * Presence marker so the WooCommerce manifest loads in a unit test.
 *
 * @package WB_Gamification
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Test double: the manifest only checks that the class exists.
	 */
	final class WooCommerce {}
}
