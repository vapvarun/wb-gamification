<?php
/**
 * WB Gamification - Learnomy (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Learnomy free is active. No dependency on
 * WB Gamification at load time. Mirrors the Jetonomy/WPMediaVerse pattern:
 * pure manifest, triggers surface in Settings + the Setup Wizard automatically.
 *
 * Hook signatures verified against Learnomy 1.5.0 and 1.6.0 service classes.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/learnomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LEARNOMY_VERSION' ) && ! class_exists( '\\Learnomy\\Plugin' ) ) {
	return array();
}

// A review pays once it is published: at once when Learnomy auto-approves reviews, otherwise when a
// moderator approves it. Learnomy fires learnomy_review_submitted for pending reviews too.
$wb_gam_learnomy_review_published = static function ( $review_id ): void {
	$review = class_exists( '\Learnomy\Models\Review' ) ? \Learnomy\Models\Review::find( (int) $review_id ) : null;
	if ( $review && 'approved' === (string) $review->status ) {
		do_action( 'wb_gam_learnomy_review_published', (int) $review_id, (int) $review->user_id, (int) $review->course_id );
	}
};
add_action( 'learnomy_review_submitted', $wb_gam_learnomy_review_published );
add_action( 'learnomy_review_approved', $wb_gam_learnomy_review_published );

return array(
	'plugin'   => 'Learnomy',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'learnomy_lesson_completed',
			'label'             => static fn(): string => __( 'Complete a lesson', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded each time a member completes a lesson. Cooldown prevents rapid-fire farming.', 'wb-gamification' ),
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
			'permanent'         => 'A completion. Learnomy resets progress only as an admin repair.',
			'default_points'    => 10,
			'category'          => 'learning',
			'icon'              => 'icon-book-open',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'learnomy_quiz_passed',
			'label'             => static fn(): string => __( 'Pass a quiz', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded once per quiz when a member passes it. Failed attempts and retakes award nothing.', 'wb-gamification' ),
			// Free fires: do_action( 'learnomy_quiz_passed', int $attempt_id, int $user_id, int $quiz_id, float $score ) - score is a percentage.
			'hook'              => 'learnomy_quiz_passed',
			'user_callback'     => function ( int $attempt_id, int $user_id, int $quiz_id, float $score = 0 ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $attempt_id, int $user_id, int $quiz_id, float $score = 0 ): array {
				return array(
					'quiz_id' => $quiz_id,
					'score'   => $score,
				);
			},
			'permanent'         => 'A completion. Learnomy resets progress only as an admin repair.',
			'once_per_object'   => static fn( int $attempt_id, int $user_id, int $quiz_id ): int => \WBGam\Engine\Registry::object_key( $user_id, 'quiz', $quiz_id ),
			'default_points'    => 25,
			'category'          => 'learning',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'learnomy_course_completed',
			'label'             => static fn(): string => __( 'Complete a course', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member finishes every lesson and quiz in a course.', 'wb-gamification' ),
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
			'permanent'         => 'A completion. Learnomy resets progress only as an admin repair.',
			'default_points'    => 100,
			'category'          => 'learning',
			'icon'              => 'icon-award',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_student_enrolled',
			'label'             => static fn(): string => __( 'Enrol in a course', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member enrols in a course. Daily cap prevents bulk-enrol farming.', 'wb-gamification' ),
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
			'once_per_object'   => static fn( int $enrollment_id, int $user_id, int $course_id ): int => $course_id > 0 ? \WBGam\Engine\Registry::object_key( $user_id, $course_id ) : 0,
			// Per member and course: unenrolling or losing access takes it back; enrolling again pays again.
			// An enrolment that simply expires ran its course and keeps its points.
			'reverse_on'        => array(
				'learnomy_student_unenrolled' => static fn( $enrollment_id, $user_id, $course_id, $access_remains = false ): int => $access_remains ? 0 : \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $course_id ),
				'learnomy_access_revoked'     => static fn( $enrollment_id, $user_id, $course_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $course_id ),
			),
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-log-in',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'learnomy_certificate_issued',
			'label'             => static fn(): string => __( 'Earn a certificate', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member earns a course completion certificate.', 'wb-gamification' ),
			// Free fires: do_action( 'learnomy_certificate_issued', int $certificate_id, int $user_id, int $course_id ).
			'hook'              => 'learnomy_certificate_issued',
			'user_callback'     => function ( int $certificate_id, int $user_id, int $course_id ): int {
				// A learning-path certificate is issued with course 0; learnomy_pro_path_completed
				// already pays for finishing the path.
				return $course_id > 0 ? $user_id : 0;
			},
			'metadata_callback' => function ( int $certificate_id, int $user_id, int $course_id ): array {
				return array(
					'certificate_id' => $certificate_id,
					'course_id'      => $course_id,
				);
			},
			'once_per_object'   => static fn( int $certificate_id ): int => $certificate_id,
			// A revoked certificate takes its points back; one that expires keeps them.
			'reverse_on'        => array(
				'learnomy_certificate_revoked' => static fn( $certificate_id ): int => (int) $certificate_id,
			),
			'default_points'    => 50,
			'category'          => 'learning',
			'icon'              => 'icon-file-badge',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_review_submitted',
			'label'             => static fn(): string => __( 'Review a course', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member\'s course review is published (approved). Cooldown limits review farming.', 'wb-gamification' ),
			// Fires: the bridge above, ( int $review_id, int $user_id, int $course_id ), once the review is approved.
			'hook'              => 'wb_gam_learnomy_review_published',
			'user_callback'     => function ( int $review_id, int $user_id, int $course_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $review_id, int $user_id, int $course_id ): array {
				return array( 'course_id' => $course_id );
			},
			// Once per member and course while the review is published (so a review deleted and written
			// again can never pay twice; Learnomy fires no hook on delete): rejected or marked spam takes
			// the points back, approved again gives them back.
			'once_per_object'   => static fn( int $review_id, int $user_id, int $course_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $course_id ),
			'reverse_on'        => array(
				'learnomy_review_rejected'    => static fn( $review_id, $course_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $course_id ),
				'learnomy_review_marked_spam' => static fn( $review_id, $course_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $course_id ),
			),
			'restore_on'        => array(
				'learnomy_review_approved' => static fn( $review_id, $course_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $course_id ),
			),
			'default_points'    => 8,
			'category'          => 'learning',
			'icon'              => 'icon-star',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 300,
		),

	),
);
