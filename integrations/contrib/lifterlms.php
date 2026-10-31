<?php
/**
 * WB Gamification — LifterLMS Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Triggers fire only when LifterLMS is active.
 *
 * Actions covered:
 *   Course completed   — lifterlms_course_completed
 *   Lesson completed   — lifterlms_lesson_completed
 *   Quiz passed        — lifterlms_quiz_passed
 *   Course enrolled    — llms_user_enrolled_in_course
 *   Membership joined  — llms_user_added_to_membership_level
 *   Achievement earned — llms_user_earned_achievement
 *   Certificate earned — llms_user_earned_certificate
 *
 * A member earns each of these once per course, lesson, quiz, membership, achievement or certificate.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LLMS_Student' ) ) {
	return [];
}

// LifterLMS fires these with ( $user_id, $object_id, ... ).
$wb_gam_llms_user   = static fn( $user_id ): int => (int) $user_id;
$wb_gam_llms_object = static fn( $user_id, $object_id ): int => (int) $object_id;

return [
	'plugin'   => 'LifterLMS',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'              => 'llms_course_completed',
			'label'           => static fn(): string => __( 'Complete a LifterLMS course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student completes any LifterLMS course.', 'wb-gamification' ),
			'hook'            => 'lifterlms_course_completed',
			'user_callback'   => $wb_gam_llms_user,
			'once_per_object' => $wb_gam_llms_object,
			'default_points'  => 100,
			'category'        => 'learning',
			'icon'            => 'icon-graduation-cap',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'llms_lesson_completed',
			'label'           => static fn(): string => __( 'Complete a LifterLMS lesson', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student completes any lesson.', 'wb-gamification' ),
			'hook'            => 'lifterlms_lesson_completed',
			'user_callback'   => $wb_gam_llms_user,
			'once_per_object' => $wb_gam_llms_object,
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-book-open',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'llms_quiz_passed',
			'label'           => static fn(): string => __( 'Pass a LifterLMS quiz', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a student achieves a passing grade on a quiz.', 'wb-gamification' ),
			'hook'            => 'lifterlms_quiz_passed',
			'user_callback'   => $wb_gam_llms_user,
			'once_per_object' => $wb_gam_llms_object,
			'default_points'  => 25,
			'category'        => 'learning',
			'icon'            => 'icon-award',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'llms_course_enrolled',
			'label'           => static fn(): string => __( 'Enroll in a LifterLMS course', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per course when a student is enrolled.', 'wb-gamification' ),
			'hook'            => 'llms_user_enrolled_in_course',
			'user_callback'   => $wb_gam_llms_user,
			'once_per_object' => $wb_gam_llms_object,
			'default_points'  => 10,
			'category'        => 'learning',
			'icon'            => 'icon-log-in',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'llms_membership_joined',
			'label'           => static fn(): string => __( 'Join a LifterLMS membership', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded once per membership when a student joins it.', 'wb-gamification' ),
			'hook'            => 'llms_user_added_to_membership_level',
			'user_callback'   => $wb_gam_llms_user,
			'once_per_object' => $wb_gam_llms_object,
			'default_points'  => 20,
			'category'        => 'learning',
			'icon'            => 'icon-users',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'             => 'llms_achievement_earned',
			'label'          => static fn(): string => __( 'Earn a LifterLMS achievement', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded when a student earns any LifterLMS achievement.', 'wb-gamification' ),
			'hook'           => 'llms_user_earned_achievement',
			'user_callback'  => $wb_gam_llms_user,
			'default_points' => 30,
			'category'       => 'learning',
			'icon'           => 'icon-star',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'llms_certificate_earned',
			'label'          => static fn(): string => __( 'Earn a LifterLMS certificate', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded when a student earns a course completion certificate.', 'wb-gamification' ),
			'hook'           => 'llms_user_earned_certificate',
			'user_callback'  => $wb_gam_llms_user,
			'default_points' => 50,
			'category'       => 'learning',
			'icon'           => 'icon-file-text',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
