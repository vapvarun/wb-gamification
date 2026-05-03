<?php
/**
 * Test fixture: a minimal stand-in for WP_Block_Type_Registry.
 *
 * Loaded by RegistrarTest when the real class isn't available (which
 * is true under our Brain\Monkey-based unit suite).
 *
 * @package WB_Gamification
 */

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {

		/**
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * @var array<int, string>
		 */
		private $registered = array();

		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function is_registered( string $name ): bool {
			return in_array( $name, $this->registered, true );
		}

		public function _add_for_test( string $name ): void {
			$this->registered[] = $name;
		}

		public function _reset(): void {
			$this->registered = array();
		}
	}
}
