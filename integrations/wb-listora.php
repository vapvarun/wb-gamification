<?php
/**
 * WB Gamification - Listora (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Listora (wb-listora) free is active. Pure
 * manifest, same pattern as Jetonomy/WPMediaVerse - triggers surface in
 * Settings + the Setup Wizard automatically.
 *
 * Hook signatures verified against wb-listora 1.2.x. Listing hooks do not carry
 * a user id, so the owner is resolved from the listing's `post_author`.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/listora/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WB_LISTORA_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Listora',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'listora_listing_submitted',
			'label'             => static fn(): string => __( 'Submit a listing', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the owner when a listing is submitted. Cooldown prevents bulk-submit farming.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_listing_submitted', int $post_id, string $status, $request, $context ). No user id - owner is post_author.
			'hook'              => 'wb_listora_listing_submitted',
			'user_callback'     => function ( int $post_id, $status = '', $request = null, $context = null ): int {
				// Migrators fire this with source 'migration'; importing a site's listings is not
				// its members submitting them.
				if ( is_array( $context ) && 'migration' === ( $context['source'] ?? '' ) ) {
					return 0;
				}
				return (int) get_post_field( 'post_author', $post_id );
			},
			'metadata_callback' => function ( int $post_id, $status = '', $request = null, $context = null ): array {
				return array( 'listing_id' => $post_id );
			},
			'default_points'    => 10,
			'category'          => 'listings',
			'icon'              => 'icon-list',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'listora_listing_published',
			'label'             => static fn(): string => __( 'Get a listing approved', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the owner when their listing is approved and goes live.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_after_approve_listing', int $post_id ). No user id - owner is post_author.
			'hook'              => 'wb_listora_after_approve_listing',
			'user_callback'     => function ( int $post_id ): int {
				return (int) get_post_field( 'post_author', $post_id );
			},
			'metadata_callback' => function ( int $post_id ): array {
				return array( 'listing_id' => $post_id );
			},
			'default_points'    => 10,
			'category'          => 'listings',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'listora_review_written',
			'label'             => static fn(): string => __( 'Write a review', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the reviewer when they leave a listing review. Cooldown limits review farming.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_review_submitted', int $review_id, int $listing_id, int $user_id, ... ).
			'hook'              => 'wb_listora_review_submitted',
			'user_callback'     => function ( int $review_id, int $listing_id, int $user_id, $criteria = null, $photos = null, $request = null ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $review_id, int $listing_id, int $user_id, $criteria = null, $photos = null, $request = null ): array {
				return array( 'listing_id' => $listing_id );
			},
			'default_points'    => 8,
			'category'          => 'listings',
			'icon'              => 'icon-star',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 300,
		),

		array(
			'id'                => 'listora_favorite_added',
			'label'             => static fn(): string => __( 'Favourite a listing', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member who favourites a listing. Daily cap prevents farming.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_favorite_added', int $listing_id, int $user_id ).
			'hook'              => 'wb_listora_favorite_added',
			'user_callback'     => function ( int $listing_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $listing_id, int $user_id ): array {
				return array( 'listing_id' => $listing_id );
			},
			'default_points'    => 2,
			'category'          => 'listings',
			'icon'              => 'icon-heart',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 10,
		),

		array(
			'id'                => 'listora_claim_approved',
			'label'             => static fn(): string => __( 'Claim a listing', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member when their listing claim is approved.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_claim_approved', int $claim_id, int $listing_id, int $claimant ).
			'hook'              => 'wb_listora_claim_approved',
			'user_callback'     => function ( int $claim_id, int $listing_id, int $claimant ): int {
				return $claimant;
			},
			'metadata_callback' => function ( int $claim_id, int $listing_id, int $claimant ): array {
				return array( 'listing_id' => $listing_id );
			},
			'default_points'    => 15,
			'category'          => 'listings',
			'icon'              => 'icon-shield-check',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'listora_listing_renewed',
			'label'             => static fn(): string => __( 'Renew a listing', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the owner when they renew an expiring or expired listing.', 'wb-gamification' ),
			// Fires: do_action( 'wb_listora_listing_renewed', int $post_id ). No user id - owner is post_author.
			'hook'              => 'wb_listora_listing_renewed',
			'user_callback'     => function ( int $post_id ): int {
				return (int) get_post_field( 'post_author', $post_id );
			},
			'metadata_callback' => function ( int $post_id ): array {
				return array( 'listing_id' => $post_id );
			},
			'default_points'    => 3,
			'category'          => 'listings',
			'icon'              => 'icon-refresh-cw',
			'repeatable'        => true,
			'async'             => false,
		),

	),
);
