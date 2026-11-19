<?php
/**
 * Minimal WP_Error for unit tests that read an error's code and status.
 *
 * @package WB_Gamification
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Test double for core's WP_Error.
	 */
	class WP_Error {

		/**
		 * Build an error.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
