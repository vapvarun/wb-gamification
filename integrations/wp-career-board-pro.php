<?php
/**
 * WB Gamification - WP Career Board Pro Integration Manifest
 *
 * Auto-loaded by ManifestLoader when WP Career Board Pro is active. Covers the
 * Pro-only candidate achievement (publishing a resume). Hook signatures
 * verified against WP Career Board Pro 1.4.x.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/wp-career-board-pro/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WCBP_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'WP Career Board Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'wcbp_resume_published',
			'label'             => 'Publish your resume',
			'description'       => 'Awarded to the candidate when their resume is published and discoverable by employers.',
			// Pro fires: do_action( 'wcbp_resume_published', int $post_id, WP_Post $post ). No user id - author is the candidate.
			'hook'              => 'wcbp_resume_published',
			'user_callback'     => function ( int $post_id, $post = null ): int {
				return (int) get_post_field( 'post_author', $post_id );
			},
			'metadata_callback' => function ( int $post_id, $post = null ): array {
				return array( 'resume_id' => $post_id );
			},
			'default_points'    => 20,
			'category'          => 'careers',
			'icon'              => 'icon-file-text',
			'repeatable'        => false,
		),

	),
);
