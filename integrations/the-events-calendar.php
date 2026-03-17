<?php
/**
 * WB Gamification — The Events Calendar Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Fires only when The Events Calendar (tribe-common) is active.
 *
 * Actions covered:
 *   Event RSVP     — tribe_tickets_rsvp_attendee_created
 *   Ticket purchase — tribe_tickets_order_created (WooCommerce tickets)
 *   Event check-in  — event_tickets_checkin
 *
 * @package WB_Gamification
 * @see     https://theeventscalendar.com/knowledgebase/k/action-hooks/
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Tribe__Events__Main' ) ) {
	return [];
}

return [
	'plugin'   => 'The Events Calendar',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'tec_rsvp_registered',
			'label'          => 'RSVP to an event',
			'description'    => 'Awarded when a member RSVPs to any event.',
			'hook'           => 'tribe_tickets_rsvp_attendee_created',
			'user_callback'  => function ( int $attendee_id, int $post_id, int $order_id, string $status ): int {
				// Only award on "going" RSVPs.
				if ( 'yes' !== strtolower( $status ) ) {
					return 0;
				}
				$attendee = get_post( $attendee_id );
				return $attendee ? (int) $attendee->post_author : 0;
			},
			'default_points' => 10,
			'category'       => 'social',
			'icon'           => 'dashicons-calendar-alt',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'tec_ticket_purchased',
			'label'          => 'Purchase an event ticket',
			'description'    => 'Awarded when a member purchases a ticket to any event.',
			'hook'           => 'event_tickets_checkin',
			'user_callback'  => function ( int $attendee_id ): int {
				$meta = get_post_meta( $attendee_id, '_tribe_tickets_attendee_user_id', true );
				return $meta ? (int) $meta : 0;
			},
			'default_points' => 20,
			'category'       => 'social',
			'icon'           => 'dashicons-tickets-alt',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'tec_event_checked_in',
			'label'          => 'Check in to an event',
			'description'    => 'Awarded when an attendee checks in at the event.',
			'hook'           => 'event_tickets_checkin',
			'user_callback'  => function ( int $attendee_id ): int {
				$meta = get_post_meta( $attendee_id, '_tribe_tickets_attendee_user_id', true );
				return $meta ? (int) $meta : 0;
			},
			'default_points' => 15,
			'category'       => 'social',
			'icon'           => 'dashicons-location',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
