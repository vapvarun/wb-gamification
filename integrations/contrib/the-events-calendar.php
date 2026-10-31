<?php
/**
 * WB Gamification — The Events Calendar Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Fires only when The Events Calendar (tribe-common) is active.
 *
 * Actions covered:
 *   Event RSVP      — wb_gam_tec_rsvp (RSVP v1 attendee, or RSVP v2 Tickets Commerce order)
 *   Ticket purchase — wb_gam_tec_ticket_purchased (completed Tickets Commerce order)
 *   Event check-in  — wb_gam_tec_checked_in (ticket or RSVP attendee)
 *
 * Tickets sold through Event Tickets Plus (WooCommerce, EDD) are not covered.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

/*
 * TIMING NOTE — why this guard uses class_exists(), not function_exists().
 *
 * Manifest files are include()d by ManifestLoader::scan() at plugins_loaded
 * priority 5. Any detection you run in a manifest's TOP-LEVEL body (i.e. to
 * decide what array to return) must be answerable that early.
 *
 * `Tribe__Events__Main` is declared at file-parse time, so class_exists() is
 * true as soon as TEC's main file is loaded — safe to gate on here.
 *
 * Do NOT copy this shape with function_exists() for a plugin that defines its
 * API INSIDE its own plugins_loaded callback (FluentCRM's fluentCrmApi(),
 * for example, is defined at plugins_loaded priority 10). At priority 5 that
 * function does not exist yet, so the guard returns [] on every request with
 * no error and no warning. For those plugins, register late instead of via a
 * drop-in manifest — see examples/14-fluentcrm-hooked-api/.
 */
if ( ! class_exists( 'Tribe__Events__Main' ) ) {
	return [];
}

// An event with several entry points is funnelled into one hook, because a trigger listens to one.
add_action(
	'event_tickets_rsvp_attendee_created',
	static function ( $attendee_id, $event_id ): void {
		if ( 'yes' === get_post_meta( $attendee_id, '_tribe_rsvp_status', true ) ) {
			do_action( 'wb_gam_tec_rsvp', (int) get_post_meta( $attendee_id, '_tribe_tickets_attendee_user_id', true ), (int) $event_id );
		}
	},
	10,
	2
);
add_action(
	'tec_tickets_commerce_order_status_completed',
	static function ( $new_status, $old_status, $post ): void {
		$order = tec_tc_get_order( $post );
		// An attendee an admin adds by hand is a manual order: nobody bought or chose anything.
		if ( 'manual' === $order->gateway ) {
			return;
		}
		foreach ( (array) $order->items as $item ) {
			$user_id  = (int) $order->purchaser['user_id'];
			$event_id = (int) ( $item['event_id'] ?? 0 );
			$type     = $item['type'] ?? '';
			if ( 'ticket' === $type ) {
				do_action( 'wb_gam_tec_ticket_purchased', $user_id, $event_id );
			} elseif ( 'tc-rsvp' === $type && tribe_is_truthy( $item['extra']['order_status'] ?? 'yes' ) ) {
				do_action( 'wb_gam_tec_rsvp', $user_id, $event_id );
			}
		}
	},
	10,
	3
);
add_action( 'event_tickets_checkin', static fn( $attendee_id ) => do_action( 'wb_gam_tec_checked_in', (int) $attendee_id ) );
add_action( 'rsvp_checkin', static fn( $attendee_id ) => do_action( 'wb_gam_tec_checked_in', (int) $attendee_id ) );

// The bridges above fire these with ( $user_id, $event_id ).
$wb_gam_tec_user  = static fn( $user_id ): int => (int) $user_id;
$wb_gam_tec_event = static fn( $user_id, $event_id ): int => (int) $event_id;

return [
	'plugin'   => 'The Events Calendar',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'              => 'tec_rsvp_registered',
			'label'           => static fn(): string => __( 'RSVP to an event', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a member RSVPs as going, once per event.', 'wb-gamification' ),
			'hook'            => 'wb_gam_tec_rsvp',
			'user_callback'   => $wb_gam_tec_user,
			'once_per_object' => $wb_gam_tec_event,
			'default_points'  => 10,
			'category'        => 'social',
			'icon'            => 'icon-calendar',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tec_ticket_purchased',
			'label'           => static fn(): string => __( 'Purchase an event ticket', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a member completes a ticket order, once per event.', 'wb-gamification' ),
			'hook'            => 'wb_gam_tec_ticket_purchased',
			'user_callback'   => $wb_gam_tec_user,
			'once_per_object' => $wb_gam_tec_event,
			'default_points'  => 20,
			'category'        => 'social',
			'icon'            => 'icon-ticket',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'              => 'tec_event_checked_in',
			'label'           => static fn(): string => __( 'Check in to an event', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when an attendee checks in at the event.', 'wb-gamification' ),
			'hook'            => 'wb_gam_tec_checked_in',
			'user_callback'   => static fn( $attendee_id ): int => (int) get_post_meta( $attendee_id, '_tribe_tickets_attendee_user_id', true ),
			'once_per_object' => static fn( $attendee_id ): int => (int) $attendee_id,
			'default_points'  => 15,
			'category'        => 'social',
			'icon'            => 'icon-map-pin',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

	],
];
