<?php
/**
 * WB Gamification — MemberPress Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Fires only when MemberPress is active.
 *
 * Actions covered:
 *   Membership activated  — mepr-event-signup-completed
 *   Membership renewed    — mepr-event-renewals
 *   Membership expired    — mepr-event-member-deactivated-account (negative or neutral)
 *   First purchase ever   — mepr-event-signup-completed (first time only)
 *
 * @package WB_Gamification
 * @see     https://docs.memberpress.com/article/204-hooks-filters
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MeprUser' ) ) {
	return [];
}

return [
	'plugin'   => 'MemberPress',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'mp_membership_activated',
			'label'          => 'Activate a MemberPress membership',
			'description'    => 'Awarded each time a member activates (or re-activates) a membership.',
			'hook'           => 'mepr-event-signup-completed',
			'user_callback'  => function ( \MeprEvent $event ): int {
				return isset( $event->member ) ? (int) $event->member->ID : 0;
			},
			'default_points' => 50,
			'category'       => 'commerce',
			'icon'           => 'icon-users',
			'repeatable'     => true,
			'cooldown'       => 3600,
		],

		[
			'id'             => 'mp_membership_renewed',
			'label'          => 'Renew a MemberPress membership',
			'description'    => 'Awarded when a member renews (repeats) an existing membership.',
			'hook'           => 'mepr-event-renewals',
			'user_callback'  => function ( \MeprEvent $event ): int {
				return isset( $event->member ) ? (int) $event->member->ID : 0;
			},
			'default_points' => 30,
			'category'       => 'commerce',
			'icon'           => 'icon-refresh-cw',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'mp_first_membership',
			'label'          => 'Join as a paid member for the first time',
			'description'    => 'Awarded once when a user activates their very first membership.',
			'hook'           => 'mepr-event-signup-completed',
			'user_callback'  => function ( \MeprEvent $event ): int {
				if ( ! isset( $event->member ) ) {
					return 0;
				}
				$user    = new \MeprUser( $event->member->ID );
				$subs    = $user->subscriptions();
				// Only award if this is the user's first subscription.
				return count( $subs ) <= 1 ? (int) $event->member->ID : 0;
			},
			'default_points' => 100,
			'category'       => 'commerce',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		],

	],
];
