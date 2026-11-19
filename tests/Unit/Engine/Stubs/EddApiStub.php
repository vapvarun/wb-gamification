<?php
/**
 * Stand-in for the bundled EDD SDK request class, so LicenseActivator::request() can be tested without a store.
 *
 * Answers through $GLOBALS['wbgam_test_licence'] and records the host and fallback it was given.
 *
 * @package WB_Gamification
 */

namespace EasyDigitalDownloads\Updater\Requests;

if ( ! class_exists( API::class ) ) {
	/**
	 * Minimal API double.
	 */
	class API {

		/**
		 * Last [primary, fallback] passed to set_fallback().
		 *
		 * @var array<int, string>
		 */
		public static array $fallback = array();

		/**
		 * Hosts the class was constructed with.
		 *
		 * @var array<int, string>
		 */
		public static array $hosts = array();

		/**
		 * Constructor.
		 *
		 * @param string $url Host.
		 */
		public function __construct( string $url ) {
			self::$hosts[] = $url;
		}

		/**
		 * Record the fallback pair.
		 *
		 * @param string $primary  Primary host.
		 * @param string $fallback Fallback host.
		 */
		public static function set_fallback( string $primary, string $fallback ): void {
			self::$fallback = array( $primary, $fallback );
		}

		/**
		 * Answer as the test configures.
		 *
		 * @param array<string, mixed> $params Request params.
		 * @return object|false
		 */
		public function make_request( array $params ) {
			return ( $GLOBALS['wbgam_test_licence'] )( $params );
		}
	}
}
