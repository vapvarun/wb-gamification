<?php
/**
 * WB Gamification - Learnomy (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Learnomy free is active. No dependency on
 * WB Gamification at load time. Mirrors the Jetonomy/WPMediaVerse pattern:
 * pure manifest, triggers surface in Settings + the Setup Wizard automatically.
 *
 * Hook signatures verified against Learnomy 1.1.1 service classes.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/learnomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LEARNOMY_VERSION' ) && ! class_exists( '\\Learnomy\\Plugin' ) ) {
	return array();
}

return array(
	'plugin'   => 'Learnomy',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'learnomy_lesson_completed',
			'label'             => 'Complete a lesson',
			'description'       => 'Awarded each time a member completes a lesson. Cooldown prevents rapid-fire farming.',
			// Free fires: do_action( 'learnomy_lesson_completed', int $user_id, int $lesson_id, int $course_id ).
			'hook'              => 'learnomy_lesson_completed',
			'user_callback'     => function ( int $user_id, int $lesson_id, int $course_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $user_id, int $lesson_id, int $course_id ): array {
				return array(
					'lesson_id' => $lesson_id,
					'course_id' => $course_id,
				);
			},
			'default_points'    => 10,
			'category'          => 'learning',
			'icon'              => 'icon-book-open',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'learnomy_quiz_passed',
			'label'             => 'Pass a quiz',
			'description'       => 'Awarded when a member passes a quiz. Fires only on a passing attempt (failed attempts award nothing); cooldown limits retake farming.',
			// Free fires: do_action( 'learnomy_quiz_passed', int $attempt_id, int $user_id, int $quiz_id, int $score ).
			'hook'              => 'learnomy_quiz_passed',
			'user_callback'     => function ( int $attempt_id, int $user_id, int $quiz_id, int $score = 0 ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $attempt_id, int $user_id, int $quiz_id, int $score = 0 ): array {
				return array(
					'quiz_id' => $quiz_id,
					'score'   => $score,
				);
			},
			'default_points'    => 25,
			'category'          => 'learning',
			'icon'              => 'icon-check-circle',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'learnomy_course_completed',
			'label'             => 'Complete a course',
			'description'       => 'Awarded when a member finishes every lesson and quiz in a course.',
			// Free fires: do_action( 'learnomy_course_completed', int $enrollment_id, int $user_id, int $course_id ).
			'hook'              => 'learnomy_course_completed',
			'user_callback'     => function ( int $enrollment_id, int $user_id, int $course_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $enrollment_id, int $user_id, int $course_id ): array {
				return array(
					'enrollment_id' => $enrollment_id,
					'course_id'     => $course_id,
				);
			},
			'default_points'    => 100,
			'category'          => 'learning',
			'icon'              => 'icon-award',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_student_enrolled',
			'label'             => 'Enrol in a course',
			'description'       => 'Awarded when a member enrols in a course. Daily cap prevents bulk-enrol farming.',
			// Free fires: do_action( 'learnomy_student_enrolled', int $enrollment_id, int $user_id, int $course_id, string $source ).
			'hook'              => 'learnomy_student_enrolled',
			'user_callback'     => function ( int $enrollment_id, int $user_id, int $course_id, string $source = '' ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $enrollment_id, int $user_id, int $course_id, string $source = '' ): array {
				return array(
					'course_id' => $course_id,
					'source'    => $source,
				);
			},
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-log-in',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'learnomy_certificate_issued',
			'label'             => 'Earn a certificate',
			'description'       => 'Awarded when a member earns a course completion certificate.',
			// Free fires: do_action( 'learnomy_certificate_issued', int $certificate_id, int $user_id, int $course_id ).
			'hook'              => 'learnomy_certificate_issued',
			'user_callback'     => function ( int $certificate_id, int $user_id, int $course_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $certificate_id, int $user_id, int $course_id ): array {
				return array(
					'certificate_id' => $certificate_id,
					'course_id'      => $course_id,
				);
			},
			'default_points'    => 50,
			'category'          => 'learning',
			'icon'              => 'icon-file-badge',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_review_submitted',
			'label'             => 'Review a course',
			'description'       => 'Awarded when a member writes a course review. Cooldown limits review farming.',
			// Free fires: do_action( 'learnomy_review_submitted', int $review_id, int $user_id, int $course_id ).
			'hook'              => 'learnomy_review_submitted',
			'user_callback'     => function ( int $review_id, int $user_id, int $course_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $review_id, int $user_id, int $course_id ): array {
				return array( 'course_id' => $course_id );
			},
			'default_points'    => 8,
			'category'          => 'learning',
			'icon'              => 'icon-star',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 300,
		),

		array(
			'id'             => 'learnomy_instructor_application',
			'label'          => 'Apply to teach',
			'description'    => 'Awarded once when a member applies to become an instructor.',
			// Free fires: do_action( 'learnomy_instructor_application_submitted', int $application_id, int $user_id ).
			'hook'           => 'learnomy_instructor_application_submitted',
			'user_callback'  => function ( int $application_id, int $user_id ): int {
				return $user_id;
			},
			'default_points' => 5,
			'category'       => 'learning',
			'icon'           => 'icon-user-plus',
			'repeatable'     => false,
		),

	),
);
