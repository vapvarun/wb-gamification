<?php
/**
 * Minimal WP_REST_Controller so a REST controller class can load in a unit test.
 *
 * @package WB_Gamification
 */

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/**
	 * Test double for core's WP_REST_Controller.
	 */
	abstract class WP_REST_Controller {

		/**
		 * Route namespace.
		 *
		 * @var string
		 */
		protected $namespace;

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base;
	}
}
