<?php
/**
 * WB Gamification — Tutor LMS Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Triggers fire only when Tutor LMS is active.
 *
 * Actions covered:
 *   Course completed — tutor_course_complete_after
 *   Lesson completed — tutor_mark_lesson_complete_after (both the button and a finished video)
 *   Quiz passed      — tutor_quiz/attempt_ended, tutor_quiz/attempt/graded
 *   Course enrolled  — tutor_after_enroll, tutor_after_enrolled, tutor/course/enrol_status_change/after
 *   Review posted    — tutor_after_rating_placed
 *   Question asked   — tutor_after_asked_question
 *
 * A member earns the first five once per course, lesson or quiz.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TUTOR_VERSION' ) ) {
	return [];
}

// An event with several entry points is funnelled into one hook, because a trigger listens to one.
add_action( 'tutor_quiz/attempt_ended', static fn( $attempt_id ) => do_action( 'wb_gam_tutor_quiz_result', (int) $attempt_id ) );
add_action( 'tutor_quiz/attempt/graded', static fn( $attempt_id ) => do_action( 'wb_gam_tutor_quiz_result', (int) $attempt_id ) );
add_action( 'tutor_after_enroll', static fn( $course_id, $enrol_id ) => do_action( 'wb_gam_tutor_enrolled', (int) $enrol_id ), 10, 2 );
add_action( 'tutor_after_enrolled', static fn( $course_id, $user_id, $enrol_id ) => do_action( 'wb_gam_tutor_enrolled', (int) $enrol_id ), 10, 3 );
add_action( 'tutor/course/enrol_status_change/after', static fn( $enrol_id ) => do_action( 'wb_gam_tutor_enrolled', (int) $enrol_id ) );

// Tutor fires these with ( $object_id, $user_id ).
$wb_gam_tutor_user   = static fn( $object_id, $user_id ): int => (int) $user_id;
$wb_gam_tutor_object = static fn( $object_id ): int => (int) $object_id;

return [
	'plugin'   => 'Tutor LMS',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'              => 'tutor_course_completed',
			'label'           => static fn(): string => __( 'Complete a Tutor LMS course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student completes any Tutor LMS course.', 'wb-gamification' ),
			'hook'            => 'tutor_course_complete_after',
			'user_callback'   => $wb_gam_tutor_user,
			'once_per_object' => $wb_gam_tutor_object,
			'default_points'  => 100,
			'category'        => 'learning',
			'icon'            => 'icon-graduation-cap',
			'repeatable'      => true,
			'async'           => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tutor_lesson_completed',
			'label'           => static fn(): string => __( 'Complete a Tutor LMS lesson', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student completes any lesson.', 'wb-gamification' ),
			'hook'            => 'tutor_mark_lesson_complete_after',
			'user_callback'   => $wb_gam_tutor_user,
			'once_per_object' => $wb_gam_tutor_object,
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-book-open',
			'repeatable'      => true,
			'async'           => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tutor_quiz_passed',
			'label'           => static fn(): string => __( 'Pass a Tutor LMS quiz', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student passes a quiz, including one an instructor grades by hand.', 'wb-gamification' ),
			'hook'            => 'wb_gam_tutor_quiz_result',
			'user_callback'   => static function ( int $attempt_id ): int {
				$attempt = tutor_utils()->get_attempt( $attempt_id );
				return $attempt && \Tutor\Models\QuizModel::RESULT_PASS === \Tutor\Models\QuizModel::get_attempt_result( $attempt_id ) ? (int) $attempt->user_id : 0;
			},
			'once_per_object' => static function ( int $attempt_id ): int {
				$attempt = tutor_utils()->get_attempt( $attempt_id );
				return $attempt ? (int) $attempt->quiz_id : 0;
			},
			'default_points'  => 25,
			'category'        => 'learning',
			'icon'            => 'icon-award',
			'repeatable'      => true,
			'async'           => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tutor_course_enrolled',
			'label'           => static fn(): string => __( 'Enroll in a Tutor LMS course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per course when an enrollment is completed. A pending paid enrollment earns nothing.', 'wb-gamification' ),
			'hook'            => 'wb_gam_tutor_enrolled',
			'user_callback'   => static fn( int $enrol_id ): int => 'completed' === get_post_status( $enrol_id ) ? (int) get_post_field( 'post_author', $enrol_id ) : 0,
			'once_per_object' => static fn( int $enrol_id ): int => (int) get_post_field( 'post_parent', $enrol_id ),
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-log-in',
			'repeatable'      => true,
			'async'           => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tutor_review_posted',
			'label'           => static fn(): string => __( 'Review a Tutor LMS course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per course when a student posts a review.', 'wb-gamification' ),
			'hook'            => 'tutor_after_rating_placed',
			'user_callback'   => static function ( $comment_id ): int {
				$comment = get_comment( (int) $comment_id );
				return $comment ? (int) $comment->user_id : 0;
			},
			'once_per_object' => static function ( $comment_id ): int {
				$comment = get_comment( (int) $comment_id );
				return $comment ? (int) $comment->comment_post_ID : 0;
			},
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-star',
			'repeatable'      => true,
			'async'           => true,
			'cooldown'        => 0,
		],

		[
			'id'             => 'tutor_question_asked',
			'label'          => static fn(): string => __( 'Ask a question in a Tutor LMS course', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded when a student asks a new question, up to five a day. Replies earn nothing.', 'wb-gamification' ),
			// Tutor also fires this for replies; those carry a comment_parent.
			'hook'           => 'tutor_after_asked_question',
			'user_callback'  => static fn( array $data ): int => empty( $data['comment_parent'] ) ? (int) ( $data['user_id'] ?? 0 ) : 0,
			'default_points' => 5,
			'category'       => 'learning',
			'icon'           => 'icon-circle-help',
			'repeatable'     => true,
			'async'          => true,
			'cooldown'       => 0,
			'daily_cap'      => 5,
		],

	],
];
