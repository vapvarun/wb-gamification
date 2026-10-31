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
 * Ticket check-in and following are Pro events and
 * live in eventonomy-pro.php.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/eventonomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EVENTONOMY_VERSION' ) ) {
	return array();
}

// A place is reserved at creation or on promotion from the waitlist, and a trigger listens to one hook.
add_action( 'evnm_after_create_rsvp', static fn( $full, $context ) => do_action( 'wb_gam_evnm_rsvp_going', $full, $context ), 10, 2 );
add_action( 'evnm_after_promote_waitlist', static fn( $full, $context ) => do_action( 'wb_gam_evnm_rsvp_going', $full, $context ), 10, 2 );

return array(
	'plugin'   => 'Eventonomy',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'evnm_rsvp_going',
			'label'             => static fn(): string => __( 'Reserve a place at an event', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member reserves a place at an event. Waitlisted reservations do not award until the member is moved to going, and a daily limit keeps repeat reservations in check.', 'wb-gamification' ),
			// Free fires: do_action( 'evnm_after_create_rsvp', array|null $full, array $context ) and the same on promotion.
			'hook'              => 'wb_gam_evnm_rsvp_going',
			'user_callback'     => function ( $full, array $context ): int {
				if ( ! is_array( $full ) ) {
					return 0;
				}
				// Only a confirmed "going" reservation earns; waitlisted entries wait.
				if ( 'going' !== (string) ( $full['status'] ?? '' ) ) {
					return 0;
				}
				// Attendee rows written for a paid order carry order_id; the ticket award pays those.
				if ( ! empty( $full['order_id'] ) ) {
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
			'label'             => static fn(): string => __( 'Submit an event', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the author when an event is submitted. A daily limit rewards genuine organizing without encouraging duplicate submissions.', 'wb-gamification' ),
			// Free fires: do_action( 'evnm_after_create_event', array $full, array $context ).
			'hook'              => 'evnm_after_create_event',
			'user_callback'     => function ( array $full, array $context ): int {
				// An import submits nothing: the author falls back to whoever ran it.
				if ( 'import' === ( $context['source'] ?? '' ) ) {
					return 0;
				}
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
			'label'             => static fn(): string => __( 'Complete a ticket order', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member completes a paid ticket order. Free and immediately paid orders settle through this event; orders paid later have their own action.', 'wb-gamification' ),
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

		array(
			'id'                => 'evnm_ticket_purchased_gateway',
			'label'             => static fn(): string => __( 'Pay for a ticket order after checkout', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a pending ticket order is paid later, such as a card payment settling or an offline payment being marked as paid.', 'wb-gamification' ),
			// Fires: do_action( 'evnm_after_update_order', array $full, array $changed, array $ctx ).
			// A settlement webhook or "Mark as paid" moves the order to 'paid'. The context may
			// carry no actor, or an admin, so the buyer is read from the order record itself.
			'hook'              => 'evnm_after_update_order',
			'user_callback'     => function ( array $full, array $changed, $ctx ): int {
				if ( ! in_array( 'status', (array) $changed, true ) ) {
					return 0;
				}
				if ( 'paid' !== (string) ( $full['status'] ?? '' ) ) {
					return 0;
				}
				return (int) ( $full['user_id'] ?? 0 );
			},
			'metadata_callback' => function ( array $full, array $changed, $ctx ): array {
				return array(
					'order_id' => (int) ( $full['id'] ?? 0 ),
				);
			},
			'default_points'    => 20,
			'category'          => 'commerce',
			'icon'              => 'icon-ticket',
			'repeatable'        => true,
			'once_per_object'   => static fn( array $full ): int => (int) ( $full['id'] ?? 0 ),
			// Purchases are infrequent and expected to reward immediately.
			'async'             => false,
		),

	),
);
