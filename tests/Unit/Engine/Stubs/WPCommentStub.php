<?php
/**
 * Minimal WP_Comment for unit tests that read a comment's type and approval.
 *
 * @package WB_Gamification
 */

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Test double for core's WP_Comment.
	 */
	final class WP_Comment {

		/**
		 * Comment type.
		 *
		 * @var string
		 */
		public $comment_type = 'comment';

		/**
		 * Approval status.
		 *
		 * @var string
		 */
		public $comment_approved = '1';
	}
}
