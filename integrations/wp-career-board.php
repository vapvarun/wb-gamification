<?php
/**
 * WB Gamification - WP Career Board (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when WP Career Board free is active. Pure
 * manifest, same pattern as Jetonomy/WPMediaVerse - triggers surface in
 * Settings + the Setup Wizard automatically.
 *
 * Hook signatures verified against WP Career Board 1.4.x. Job/listing hooks do
 * not carry a user id, so the author is resolved from `post_author`.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/wp-career-board/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WCB_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'WP Career Board',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'wcb_job_posted',
			'label'             => static fn(): string => __( 'Post a job', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the employer when a new job listing is created.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_job_created', int $job_id, WP_REST_Request $request ). No user id - author is the poster.
			'hook'              => 'wcb_job_created',
			'user_callback'     => function ( int $job_id, $request = null ): int {
				return (int) get_post_field( 'post_author', $job_id );
			},
			'metadata_callback' => function ( int $job_id, $request = null ): array {
				return array( 'job_id' => $job_id );
			},
			'default_points'    => 10,
			'category'          => 'careers',
			'icon'              => 'icon-briefcase',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'wcb_job_approved',
			'label'             => static fn(): string => __( 'Get a job approved', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the employer when their job listing is approved and published.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_job_approved', int $job_id ). No user id - author is the poster.
			'hook'              => 'wcb_job_approved',
			'user_callback'     => function ( int $job_id ): int {
				return (int) get_post_field( 'post_author', $job_id );
			},
			'metadata_callback' => function ( int $job_id ): array {
				return array( 'job_id' => $job_id );
			},
			'default_points'    => 5,
			'category'          => 'careers',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'wcb_application_submitted',
			'label'             => static fn(): string => __( 'Apply to a job', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the candidate when they submit a job application. Daily cap prevents spam applications.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_application_submitted', int $app_id, int $job_id, int $candidate_id ).
			'hook'              => 'wcb_application_submitted',
			'user_callback'     => function ( int $app_id, int $job_id, int $candidate_id ): int {
				return $candidate_id;
			},
			'metadata_callback' => function ( int $app_id, int $job_id, int $candidate_id ): array {
				return array(
					'application_id' => $app_id,
					'job_id'         => $job_id,
				);
			},
			'default_points'    => 8,
			'category'          => 'careers',
			'icon'              => 'icon-send',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 10,
		),

		array(
			'id'                => 'wcb_candidate_hired',
			'label'             => static fn(): string => __( 'Get hired', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the candidate when their application status changes to hired.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_application_status_changed', int $app_id, string $old_status, string $new_status )
			// (arg order normalised across all call sites). Candidate resolved from the application's _wcb_candidate_id meta.
			'hook'              => 'wcb_application_status_changed',
			'user_callback'     => function ( int $app_id, string $old_status = '', string $new_status = '' ): int {
				// Only the transition INTO hired earns (a hired -> hired bulk re-save does not); Engine drops 0.
				if ( 'hired' !== $new_status || 'hired' === $old_status ) {
					return 0;
				}
				return (int) get_post_meta( $app_id, '_wcb_candidate_id', true );
			},
			'metadata_callback' => function ( int $app_id, string $old_status = '', string $new_status = '' ): array {
				return array( 'application_id' => $app_id );
			},
			'default_points'    => 100,
			'category'          => 'careers',
			'icon'              => 'icon-trophy',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'             => 'wcb_candidate_registered',
			'label'          => static fn(): string => __( 'Join as a candidate', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a member registers a candidate profile.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_candidate_registered', int $user_id ).
			'hook'           => 'wcb_candidate_registered',
			'user_callback'  => function ( int $user_id ): int {
				return $user_id;
			},
			'default_points' => 15,
			'category'       => 'careers',
			'icon'           => 'icon-user-plus',
			'repeatable'     => false,
		),

		array(
			'id'                => 'wcb_employer_registered',
			'label'             => static fn(): string => __( 'Join as an employer', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded once when a member registers an employer account.', 'wb-gamification' ),
			// Fires: do_action( 'wcb_employer_registered', int $user_id, int $company_id ).
			'hook'              => 'wcb_employer_registered',
			'user_callback'     => function ( int $user_id, int $company_id = 0 ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $user_id, int $company_id = 0 ): array {
				return array( 'company_id' => $company_id );
			},
			'default_points'    => 15,
			'category'          => 'careers',
			'icon'              => 'icon-building',
			'repeatable'        => false,
		),

	),
);
