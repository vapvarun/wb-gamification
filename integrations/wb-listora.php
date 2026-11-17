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

/*
 * A listing's points follow the listing. Rejected or trashed (wb_listora_listing_status_changed fires
 * on every status move, from admin, REST and bulk alike) or deleted for good takes back what it
 * earned; published again gives it back once (submission points included).
 */
$wb_gam_listora_listing_removed  = array(
	'wb_listora_listing_status_changed' => static fn( $post_id, $new_status = '' ): int => in_array( $new_status, array( 'listora_rejected', 'trash' ), true ) ? (int) $post_id : 0,
	'before_delete_post'                => static fn( $post_id ): int => 'listora_listing' === get_post_type( (int) $post_id ) ? (int) $post_id : 0,
);
$wb_gam_listora_listing_restored = array(
	'wb_listora_listing_status_changed' => static fn( $post_id, $new_status = '' ): int => 'publish' === $new_status ? (int) $post_id : 0,
);

/*
 * A review pays when it is published: at submission when the site auto-approves reviews, otherwise
 * when a moderator approves it. Listora fires wb_listora_review_submitted for pending reviews too,
 * so the trigger listens to this bridge, which reads the review's status and author once.
 * Moderation fires wb_listora_review_status_changed (admin screen and REST alike).
 */
$wb_gam_listora_review_published = static function ( $review_id ): void {
	$review = class_exists( '\WBListora\Core\Reviews_Model' ) ? \WBListora\Core\Reviews_Model::get( (int) $review_id ) : null;
	if ( is_array( $review ) && 'approved' === ( $review['status'] ?? '' ) ) {
		do_action( 'wb_gam_listora_review_published', (int) $review_id, (int) ( $review['user_id'] ?? 0 ), (int) ( $review['listing_id'] ?? 0 ) );
	}
};
add_action( 'wb_listora_review_submitted', $wb_gam_listora_review_published, 10, 1 );
add_action(
	'wb_listora_review_status_changed',
	static function ( $review_id, $status ) use ( $wb_gam_listora_review_published ): void {
		if ( 'approved' === $status ) {
			$wb_gam_listora_review_published( $review_id );
		}
	},
	10,
	2
);

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
			'once_per_object'   => static fn( int $post_id ): int => $post_id,
			'object_date'       => \WBGam\Engine\Registry::post_object_date(),
			'reverse_on'        => $wb_gam_listora_listing_removed,
			'restore_on'        => $wb_gam_listora_listing_restored,
			'user_callback'     => function ( int $post_id, $status = '', $request = null, $context = null ): int {
				// Saving a draft fires this too, and a draft is not a submission.
				if ( 'draft' === $status ) {
					return 0;
				}
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
			// Also fires on reactivating or renewing a listing, so once per listing.
			'hook'              => 'wb_listora_after_approve_listing',
			'once_per_object'   => static fn( int $post_id ): int => $post_id,
			'object_date'       => \WBGam\Engine\Registry::post_object_date(),
			'reverse_on'        => $wb_gam_listora_listing_removed,
			'restore_on'        => $wb_gam_listora_listing_restored,
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
			'description'       => static fn(): string => __( 'Awarded to the reviewer when their listing review is published (approved). Cooldown limits review farming.', 'wb-gamification' ),
			// Fires: the bridge above, ( int $review_id, int $user_id, int $listing_id ), once the review is approved.
			'hook'              => 'wb_gam_listora_review_published',
			'user_callback'     => function ( int $review_id, int $user_id, int $listing_id = 0 ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $review_id, int $user_id, int $listing_id = 0 ): array {
				return array( 'listing_id' => $listing_id );
			},
			// Once per review while it is published: rejected, sent back to pending (an edit) or
			// deleted takes the points back; approved again gives them back.
			'once_per_object'   => static fn( int $review_id ): int => $review_id,
			'reverse_on'        => array(
				'wb_listora_review_status_changed' => static fn( $review_id, $status = '' ): int => 'approved' === $status ? 0 : (int) $review_id,
				'wb_listora_after_delete_review'   => static fn( $review_id ): int => (int) $review_id,
			),
			'restore_on'        => array(
				'wb_listora_review_status_changed' => static fn( $review_id, $status = '' ): int => 'approved' === $status ? (int) $review_id : 0,
			),
			'default_points'    => 8,
			'category'          => 'listings',
			'icon'              => 'icon-star',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 300,
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
