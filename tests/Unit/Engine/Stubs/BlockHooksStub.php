<?php
/**
 * Test fixture: minimal BlockHooks stub.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

if ( ! class_exists( __NAMESPACE__ . '\\BlockHooks' ) ) {
	final class BlockHooks {
		public static function before( string $slug, array $attrs, array $context ): void {}
		public static function after( string $slug, array $attrs, array $context ): void {}
	}
}
