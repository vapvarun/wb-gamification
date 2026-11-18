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

// A need a moderator rejects (Listora Pro trashes it), or that is trashed or deleted, takes back
// what it earned. Listora Pro fires nothing when a need comes back from the trash, so there is no restore.
$wb_gam_listora_need_id      = static fn( $post_id ): int => 'listora_need' === get_post_type( (int) $post_id ) ? (int) $post_id : 0;
$wb_gam_listora_need_removed = array(
	'wb_listora_pro_need_rejected' => static fn( $need_id ): int => (int) $need_id,
	'wp_trash_post'                => $wb_gam_listora_need_id,
	'before_delete_post'           => $wb_gam_listora_need_id,
);

return array(
	'plugin'   => 'Listora Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'listora_need_submitted',
			'label'             => static fn(): string => __( 'Post a need', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member who posts a need (a reverse listing / request).', 'wb-gamification' ),
			// Pro fires: do_action( 'wb_listora_pro_need_submitted', int $need_id, $request ). No user id - author is the poster.
			'hook'              => 'wb_listora_pro_need_submitted',
			'once_per_object'   => static fn( $need_id ): int => (int) $need_id,
			'object_date'       => \WBGam\Engine\Registry::post_object_date(),
			'reverse_on'        => $wb_gam_listora_need_removed,
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
			'async'             => false,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'listora_need_published',
			'label'             => static fn(): string => __( 'Get a need approved', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member when their need is approved and goes live.', 'wb-gamification' ),
			// Pro fires: do_action( 'wb_listora_pro_need_published', int $need_id ). No user id - author is the poster.
			'hook'              => 'wb_listora_pro_need_published',
			'once_per_object'   => static fn( $need_id ): int => (int) $need_id,
			'object_date'       => \WBGam\Engine\Registry::post_object_date(),
			'reverse_on'        => $wb_gam_listora_need_removed,
			'user_callback'     => function ( int $need_id ): int {
				return (int) get_post_field( 'post_author', $need_id );
			},
			'metadata_callback' => function ( int $need_id ): array {
				return array( 'need_id' => $need_id );
			},
			'default_points'    => 5,
			'category'          => 'listings',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'listora_need_response',
			'label'             => static fn(): string => __( 'Respond to a need', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member who responds to another member\'s need with a listing.', 'wb-gamification' ),
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
			'permanent'         => 'No delete action for a need response in Listora Pro (card on the Listora board).',
			'default_points'    => 5,
			'category'          => 'listings',
			'icon'              => 'icon-reply',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 10,
		),

	),
);
