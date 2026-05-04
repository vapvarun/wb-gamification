<?php
/**
 * WB Gamification — LearnDash Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Triggers fire only when LearnDash is active (function existence guards).
 *
 * Actions covered:
 *   Course completed    — ldlms_program_course_completed / learndash_course_completed
 *   Lesson completed    — learndash_lesson_completed
 *   Topic completed     — learndash_topic_completed
 *   Quiz passed         — learndash_quiz_completed (score ≥ passing score)
 *   Assignment approved — learndash_assignment_approved
 *
 * @package WB_Gamification
 * @see     https://www.learndash.com/support/docs/add-ons/hooks/
 */

defined( 'ABSPATH' ) || exit;

// Only load if LearnDash is active.
if ( ! function_exists( 'learndash_get_course_id' ) ) {
	return [];
}

return [
	'plugin'   => 'LearnDash',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'ld_course_completed',
			'label'          => 'Complete a LearnDash course',
			'description'    => 'Awarded when a learner completes any LearnDash course.',
			'hook'           => 'learndash_course_completed',
			'user_callback'  => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'default_points' => 100,
			'category'       => 'learning',
			'icon'           => 'dashicons-welcome-learn-more',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'ld_lesson_completed',
			'label'          => 'Complete a LearnDash lesson',
			'description'    => 'Awarded when a learner completes any lesson.',
			'hook'           => 'learndash_lesson_completed',
			'user_callback'  => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'default_points' => 15,
			'category'       => 'learning',
			'icon'           => 'dashicons-book-alt',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'ld_topic_completed',
			'label'          => 'Complete a LearnDash topic',
			'description'    => 'Awarded when a learner completes any topic.',
			'hook'           => 'learndash_topic_completed',
			'user_callback'  => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'default_points' => 5,
			'category'       => 'learning',
			'icon'           => 'dashicons-media-document',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'                => 'ld_quiz_passed',
			'label'             => 'Pass a LearnDash quiz',
			'description'       => 'Awarded when a learner passes a quiz.',
			// LD fires: do_action( 'learndash_quiz_completed', $quizdata, $user ).
			// Two positional args — NOT a single array.
			'hook'              => 'learndash_quiz_completed',
			'user_callback'     => function ( $quizdata, $user = null ): int {
				$quizdata = is_array( $quizdata ) ? $quizdata : (array) $quizdata;
				$passed   = ! empty( $quizdata['pass'] );
				if ( ! $passed ) {
					return 0;
				}
				if ( is_object( $user ) && isset( $user->ID ) ) {
					return (int) $user->ID;
				}
				return 0;
			},
			'metadata_callback' => function ( $quizdata, $user = null ): array {
				$quizdata = is_array( $quizdata ) ? $quizdata : (array) $quizdata;
				return array(
					'quiz_id'    => isset( $quizdata['quiz'] ) ? (int) $quizdata['quiz'] : 0,
					'percentage' => isset( $quizdata['percentage'] ) ? (float) $quizdata['percentage'] : 0.0,
				);
			},
			'default_points'    => 25,
			'category'          => 'learning',
			'icon'              => 'dashicons-awards',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

		[
			'id'                => 'ld_assignment_approved',
			'label'             => 'Assignment approved by instructor',
			'description'       => 'Awarded when an instructor approves a submitted assignment.',
			// LD fires: do_action( 'learndash_assignment_approved', $assignment_id ).
			// Note: NOT 'learndash_assignment_mark_approved' (that's an internal function name).
			'hook'              => 'learndash_assignment_approved',
			'user_callback'     => function ( int $assignment_id ): int {
				$assignment = get_post( $assignment_id );
				return $assignment ? (int) $assignment->post_author : 0;
			},
			'metadata_callback' => function ( int $assignment_id ): array {
				return array( 'assignment_id' => $assignment_id );
			},
			'default_points'    => 20,
			'category'          => 'learning',
			'icon'              => 'dashicons-yes-alt',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

	],
];
