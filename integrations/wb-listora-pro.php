<?php
/**
 * WB Gamification - Listora Pro Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Listora Pro is active. Covers the Pro-only
 * "needs" (reverse-listing) achievements. Hook signatures verified against
 * wb-listora-pro 1.2.x.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/listora-pro/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WB_LISTORA_PRO_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Listora Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'listora_need_submitted',
			'label'             => 'Post a need',
			'description'       => 'Awarded to the member who posts a need (a reverse listing / request).',
			// Pro fires: do_action( 'wb_listora_pro_need_submitted', int $need_id, $request ). No user id - author is the poster.
			'hook'              => 'wb_listora_pro_need_submitted',
			'user_callback'     => function ( int $need_id, $request = null ): int {
				return (int) get_post_field( 'post_author', $need_id );
			},
			'metadata_callback' => function ( int $need_id, $request = null ): array {
				return array( 'need_id' => $need_id );
			},
			'default_points'    => 8,
			'category'          => 'listings',
			'icon'              => 'icon-megaphone',
			'repeatable'        => true,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'listora_need_published',
			'label'             => 'Need approved',
			'description'       => 'Awarded to the member when their need is approved and goes live.',
			// Pro fires: do_action( 'wb_listora_pro_need_published', int $need_id ). No user id - author is the poster.
			'hook'              => 'wb_listora_pro_need_published',
			'user_callback'     => function ( int $need_id ): int {
				return (int) get_post_field( 'post_author', $need_id );
			},
			'metadata_callback' => function ( int $need_id ): array {
				return array( 'need_id' => $need_id );
			},
			'default_points'    => 5,
			'category'          => 'listings',
			'icon'              => 'icon-check-circle',
			'repeatable'        => true,
		),

		array(
			'id'                => 'listora_need_response',
			'label'             => 'Respond to a need',
			'description'       => 'Awarded to the member who responds to another member\'s need with a listing.',
			// Pro fires: do_action( 'wb_listora_pro_need_response_created', int $response_id, int $need_id, int $listing_id, int $user_id ).
			'hook'              => 'wb_listora_pro_need_response_created',
			'user_callback'     => function ( int $response_id, int $need_id, int $listing_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $response_id, int $need_id, int $listing_id, int $user_id ): array {
				return array(
					'need_id'    => $need_id,
					'listing_id' => $listing_id,
				);
			},
			'default_points'    => 5,
			'category'          => 'listings',
			'icon'              => 'icon-reply',
			'repeatable'        => true,
			'daily_cap'         => 10,
		),

	),
);
