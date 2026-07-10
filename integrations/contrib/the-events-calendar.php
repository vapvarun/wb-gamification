<?php
/**
 * WB Gamification — The Events Calendar Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Fires only when The Events Calendar (tribe-common) is active.
 *
 * Actions covered:
 *   Event RSVP     — tribe_tickets_rsvp_attendee_created
 *   Ticket purchase — event_tickets_after_save_ticket
 *   Event check-in  — event_tickets_checkin
 *
 * @package WB_Gamification
 * @see     https://theeventscalendar.com/knowledgebase/k/action-hooks/
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
			'icon'           => 'icon-calendar',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'tec_ticket_purchased',
			'label'          => 'Purchase an event ticket',
			'description'    => 'Awarded when a member purchases a ticket to any event.',
			'hook'           => 'event_tickets_after_save_ticket',
			'user_callback'  => function ( int $post_id, $ticket, array $raw_data, string $class_name ): int {
				return get_current_user_id();
			},
			'default_points' => 20,
			'category'       => 'social',
			'icon'           => 'icon-ticket',
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
			'icon'           => 'icon-map-pin',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
