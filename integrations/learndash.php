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
 *   Course enrolled     — learndash_update_course_access (not on removal)
 *   Assignment uploaded — learndash_assignment_uploaded
 *   Group completed     — learndash_group_completed
 *
 * A member earns each of these once per course, lesson, topic, quiz, assignment or group.
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
			'id'              => 'ld_course_completed',
			'label'           => static fn(): string => __( 'Complete a LearnDash course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a learner completes any LearnDash course.', 'wb-gamification' ),
			'hook'            => 'learndash_course_completed',
			'user_callback'   => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'once_per_object' => static fn( array $data ): int => isset( $data['course'] ) ? (int) $data['course']->ID : 0,
			'default_points'  => 100,
			'category'        => 'learning',
			'icon'            => 'icon-graduation-cap',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'ld_lesson_completed',
			'label'           => static fn(): string => __( 'Complete a LearnDash lesson', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a learner completes any lesson.', 'wb-gamification' ),
			'hook'            => 'learndash_lesson_completed',
			'user_callback'   => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'once_per_object' => static fn( array $data ): int => isset( $data['lesson'] ) ? (int) $data['lesson']->ID : 0,
			'default_points'  => 15,
			'category'        => 'learning',
			'icon'            => 'icon-book-open',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'ld_topic_completed',
			'label'           => static fn(): string => __( 'Complete a LearnDash topic', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a learner completes any topic.', 'wb-gamification' ),
			'hook'            => 'learndash_topic_completed',
			'user_callback'   => function ( array $data ): int {
				return isset( $data['user'] ) ? (int) $data['user']->ID : 0;
			},
			'once_per_object' => static fn( array $data ): int => isset( $data['topic'] ) ? (int) $data['topic']->ID : 0,
			'default_points'  => 5,
			'category'        => 'learning',
			'icon'            => 'icon-file-text',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'                => 'ld_quiz_passed',
			'label'             => static fn(): string => __( 'Pass a LearnDash quiz', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a learner passes a quiz.', 'wb-gamification' ),
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
			'once_per_object'   => static fn( $quizdata ): int => (int) ( ( (array) $quizdata )['quiz'] ?? 0 ),
			'metadata_callback' => function ( $quizdata, $user = null ): array {
				$quizdata = is_array( $quizdata ) ? $quizdata : (array) $quizdata;
				return array(
					'quiz_id'    => isset( $quizdata['quiz'] ) ? (int) $quizdata['quiz'] : 0,
					'percentage' => isset( $quizdata['percentage'] ) ? (float) $quizdata['percentage'] : 0.0,
				);
			},
			'default_points'    => 25,
			'category'          => 'learning',
			'icon'              => 'icon-award',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

		[
			'id'                => 'ld_assignment_approved',
			'label'             => static fn(): string => __( 'Get an assignment approved', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when an instructor approves a submitted assignment.', 'wb-gamification' ),
			// LD fires: do_action( 'learndash_assignment_approved', $assignment_id ).
			// Note: NOT 'learndash_assignment_mark_approved' (that's an internal function name).
			'hook'              => 'learndash_assignment_approved',
			'user_callback'     => function ( int $assignment_id ): int {
				$assignment = get_post( $assignment_id );
				return $assignment ? (int) $assignment->post_author : 0;
			},
			'once_per_object'   => static fn( int $assignment_id ): int => $assignment_id,
			'metadata_callback' => function ( int $assignment_id ): array {
				return array( 'assignment_id' => $assignment_id );
			},
			'default_points'    => 20,
			'category'          => 'learning',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

		[
			'id'              => 'ld_course_enrolled',
			'label'           => static fn(): string => __( 'Enroll in a LearnDash course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per course when a learner gets access to it.', 'wb-gamification' ),
			// LD fires: do_action( 'learndash_update_course_access', $user_id, $course_id, $course_access_list, $remove ).
			'hook'            => 'learndash_update_course_access',
			'user_callback'   => static fn( $user_id, $course_id, $access_list = null, $remove = false ): int => $remove ? 0 : (int) $user_id,
			'once_per_object' => static fn( $user_id, $course_id ): int => (int) $course_id,
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-log-in',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'ld_assignment_uploaded',
			'label'           => static fn(): string => __( 'Submit a LearnDash assignment', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per lesson or topic when a learner uploads an assignment.', 'wb-gamification' ),
			// LD fires: do_action( 'learndash_assignment_uploaded', $assignment_post_id, $assignment_meta ).
			'hook'            => 'learndash_assignment_uploaded',
			'user_callback'   => static fn( $assignment_id ): int => (int) get_post_field( 'post_author', (int) $assignment_id ),
			'once_per_object' => static fn( $assignment_id, $meta = array() ): int => (int) ( $meta['lesson_id'] ?? 0 ),
			'default_points'  => 5,
			'category'        => 'learning',
			'icon'            => 'icon-upload',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'ld_group_completed',
			'label'           => static fn(): string => __( 'Complete a LearnDash group', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a learner completes every course in a group.', 'wb-gamification' ),
			'hook'            => 'learndash_group_completed',
			'user_callback'   => static fn( array $data ): int => isset( $data['user'] ) ? (int) $data['user']->ID : 0,
			'once_per_object' => static fn( array $data ): int => isset( $data['group'] ) ? (int) $data['group']->ID : 0,
			'default_points'  => 100,
			'category'        => 'learning',
			'icon'            => 'icon-users',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

	],
];
