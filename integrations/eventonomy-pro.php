<?php
/**
 * WB Gamification — Eventonomy Pro Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Eventonomy Pro is active.
 * No dependency on WB Gamification at load time.
 *
 * Covers the Pro-only event actions: attending an event (ticket check-in),
 * and following an event, organizer, venue,
 * or member. Attendance relies on the `evnm_after_checkin` action added to
 * Eventonomy Pro's CheckinService, which fires once per attendee on a
 * successful, non-voided check-in.
 *
 * Guests are never awarded: each callback resolves a positive WordPress user
 * id and returns 0 (a silent skip) when none is present.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/eventonomy-pro/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EVENTONOMY_PRO_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Eventonomy Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'evnm_event_attended',
			'label'             => static fn(): string => __( 'Attend an event', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member checks in at an event with a valid ticket. Cancelled or refunded tickets cannot check in, so attendance is only ever rewarded once per ticket.', 'wb-gamification' ),
			// Pro fires: do_action( 'evnm_after_checkin', array $row, int $event_id )
			// once on the first successful check-in (CheckinService::check_in).
			'hook'              => 'evnm_after_checkin',
			'user_callback'     => function ( array $row, int $event_id ): int {
				return (int) ( $row['user_id'] ?? 0 );
			},
			'metadata_callback' => function ( array $row, int $event_id ): array {
				return array(
					'event_id' => $event_id > 0 ? $event_id : (int) ( $row['event_id'] ?? 0 ),
					'rsvp_id'  => (int) ( $row['id'] ?? 0 ),
				);
			},
			'default_points'    => 30,
			'category'          => 'events',
			'icon'              => 'icon-user-check',
			'repeatable'        => true,
			// The flagship award, expected the moment the attendee is admitted:
			// award in-request rather than on the next queue run.
			'async'             => false,
		),

		array(
			'id'                => 'evnm_follow',
			'label'             => static fn(): string => __( 'Follow an event or organizer', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member follows an event, organizer, venue, or another member to stay up to date. A daily limit keeps the reward proportionate.', 'wb-gamification' ),
			// Pro fires: do_action( 'evnm_after_follow', int $user_id, string $type, int $object_id ).
			// $type is one of event, organizer, member, space, category.
			'hook'              => 'evnm_after_follow',
			'user_callback'     => function ( int $user_id, string $type, int $object_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $user_id, string $type, int $object_id ): array {
				return array(
					'follow_type' => $type,
					'object_id'   => $object_id,
				);
			},
			'default_points'    => 2,
			'category'          => 'events',
			'icon'              => 'icon-bell',
			'repeatable'        => true,
			'cooldown'          => 30,
			'daily_cap'         => 10,
			// Member-initiated and reward-toasted: award in-request for immediate
			// feedback. Bounded by the cooldown + daily cap above.
			'async'             => false,
		),

	),
);
