<?php
/**
 * WB Gamification — LifterLMS Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Triggers fire only when LifterLMS is active.
 *
 * Actions covered:
 *   Course completed — llms_user_course_complete
 *   Lesson completed — llms_user_lesson_complete
 *   Quiz passed      — llms_user_quiz_complete (grade ≥ 70)
 *   Achievement earned — llms_user_earned_achievement
 *   Certificate earned — llms_user_earned_certificate
 *
 * @package WB_Gamification
 * @see     https://lifterlms.com/docs/hooks/
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LLMS_Student' ) ) {
	return [];
}

return [
	'plugin'   => 'LifterLMS',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'llms_course_completed',
			'label'          => 'Complete a LifterLMS course',
			'description'    => 'Awarded when a student completes any LifterLMS course.',
			'hook'           => 'llms_user_course_complete',
			'user_callback'  => fn( int $user_id, int $course_id ) => $user_id,
			'default_points' => 100,
			'category'       => 'learning',
			'icon'           => 'dashicons-welcome-learn-more',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'llms_lesson_completed',
			'label'          => 'Complete a LifterLMS lesson',
			'description'    => 'Awarded when a student completes any lesson.',
			'hook'           => 'llms_user_lesson_complete',
			'user_callback'  => fn( int $user_id, int $lesson_id ) => $user_id,
			'default_points' => 10,
			'category'       => 'learning',
			'icon'           => 'dashicons-book-alt',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'llms_quiz_passed',
			'label'          => 'Pass a LifterLMS quiz',
			'description'    => 'Awarded when a student achieves a passing grade on a quiz.',
			'hook'           => 'llms_user_quiz_complete',
			'user_callback'  => function ( int $user_id, int $quiz_id, \LLMS_Quiz_Attempt $attempt ): int {
				return $attempt->get( 'passed' ) ? $user_id : 0;
			},
			'default_points' => 25,
			'category'       => 'learning',
			'icon'           => 'dashicons-awards',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'llms_achievement_earned',
			'label'          => 'Earn a LifterLMS achievement',
			'description'    => 'Awarded when a student earns any LifterLMS achievement.',
			'hook'           => 'llms_user_earned_achievement',
			'user_callback'  => fn( int $user_id, \LLMS_User_Achievement $achievement ) => $user_id,
			'default_points' => 30,
			'category'       => 'learning',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'llms_certificate_earned',
			'label'          => 'Earn a LifterLMS certificate',
			'description'    => 'Awarded when a student earns a course completion certificate.',
			'hook'           => 'llms_user_earned_certificate',
			'user_callback'  => fn( int $user_id, \LLMS_User_Certificate $certificate ) => $user_id,
			'default_points' => 50,
			'category'       => 'learning',
			'icon'           => 'dashicons-media-document',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
