<?php
/**
 * WB Gamification — Eventonomy (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Eventonomy (free) is active.
 * No dependency on WB Gamification at load time.
 *
 * Covers the member-facing event actions that Eventonomy's free core emits:
 * reserving a place at an event, submitting an event, and completing a paid
 * order. Every write in Eventonomy fires an `evnm_after_{verb}_{resource}`
 * action carrying the full record, which is the seam these triggers listen on.
 *
 * Guests are never awarded: each callback resolves a positive WordPress user
 * id from the record and returns 0 (a silent skip) when none is present.
 *
 * Ticket check-in, following, and gateway-settled orders are Pro events and
 * live in eventonomy-pro.php.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/eventonomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EVENTONOMY_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Eventonomy',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'evnm_rsvp_going',
			'label'             => 'Reserve a place at an event',
			'description'       => 'Awarded when a member reserves a place at an event. Waitlisted reservations do not award until the member is moved to going, and a daily limit keeps repeat reservations in check.',
			// Free fires: do_action( 'evnm_after_create_rsvp', array|null $full, array $context ).
			'hook'              => 'evnm_after_create_rsvp',
			'user_callback'     => function ( $full, array $context ): int {
				if ( ! is_array( $full ) ) {
					return 0;
				}
				// Only a confirmed "going" reservation earns; waitlisted entries wait.
				if ( 'going' !== (string) ( $full['status'] ?? '' ) ) {
					return 0;
				}
				return (int) ( $full['user_id'] ?? 0 );
			},
			'metadata_callback' => function ( $full, array $context ): array {
				return array(
					'event_id'      => is_array( $full ) ? (int) ( $full['event_id'] ?? 0 ) : 0,
					'occurrence_id' => is_array( $full ) ? (int) ( $full['occurrence_id'] ?? 0 ) : 0,
				);
			},
			'default_points'    => 10,
			'category'          => 'events',
			'icon'              => 'icon-calendar-check',
			'repeatable'        => true,
			'cooldown'          => 300,
			'daily_cap'         => 10,
			// Member-initiated and reward-toasted: award in-request so the member
			// sees the points immediately, not on the next queue run.
			'async'             => false,
		),

		array(
			'id'                => 'evnm_event_created',
			'label'             => 'Submit an event',
			'description'       => 'Awarded to the author when an event is submitted. A daily limit rewards genuine organizing without encouraging duplicate submissions.',
			// Free fires: do_action( 'evnm_after_create_event', array $full, array $context ).
			'hook'              => 'evnm_after_create_event',
			'user_callback'     => function ( array $full, array $context ): int {
				$author = (int) ( $full['author_id'] ?? 0 );
				return $author > 0 ? $author : (int) ( $context['user_id'] ?? 0 );
			},
			'metadata_callback' => function ( array $full, array $context ): array {
				return array(
					'event_id' => (int) ( $full['id'] ?? 0 ),
					'status'   => (string) ( $full['status'] ?? '' ),
				);
			},
			'default_points'    => 25,
			'category'          => 'events',
			'icon'              => 'icon-calendar-plus',
			'repeatable'        => true,
			'daily_cap'         => 3,
			// Low-frequency, high-intent action: award in-request so the organizer
			// sees the points immediately rather than on the next queue run.
			'async'             => false,
		),

		array(
			'id'                => 'evnm_ticket_purchased',
			'label'             => 'Complete a ticket order',
			'description'       => 'Awarded when a member completes a paid ticket order. Free and immediately paid orders settle through this event; card-gateway orders are handled by the Pro integration.',
			// Free fires: do_action( 'evnm_after_create_order', array|null $full, array $context )
			// with status 'paid' for free / immediately paid orders.
			'hook'              => 'evnm_after_create_order',
			'user_callback'     => function ( $full, array $context ): int {
				if ( ! is_array( $full ) ) {
					return 0;
				}
				if ( 'paid' !== (string) ( $full['status'] ?? '' ) ) {
					return 0;
				}
				return (int) ( $full['user_id'] ?? 0 );
			},
			'metadata_callback' => function ( $full, array $context ): array {
				return array(
					'order_id' => is_array( $full ) ? (int) ( $full['id'] ?? 0 ) : 0,
				);
			},
			'default_points'    => 20,
			'category'          => 'commerce',
			'icon'              => 'icon-ticket',
			'repeatable'        => true,
			// Purchases are infrequent and expected to reward immediately.
			'async'             => false,
		),

	),
);
