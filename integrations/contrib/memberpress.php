<?php
/**
 * WB Gamification — MemberPress Integration Manifest
 *
 * Auto-loaded by ManifestLoader when this file exists in integrations/.
 * Fires only when MemberPress is active.
 *
 * Actions covered:
 *   Membership activated  — mepr-event-transaction-completed (once per membership)
 *   Membership renewed    — mepr-event-renewal-transaction-completed
 *   First signup ever     — mepr-event-member-signup-completed
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MeprUser' ) ) {
	return [];
}

// MemberPress passes a MeprEvent; get_data() is the transaction or member it was recorded for.
$wb_gam_mp_txn_user = static fn( \MeprEvent $event ): int => (int) $event->get_data()->user_id;

return [
	'plugin'   => 'MemberPress',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'              => 'mp_membership_activated',
			'label'           => static fn(): string => __( 'Activate a MemberPress membership', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded the first time a member activates each membership.', 'wb-gamification' ),
			'hook'            => 'mepr-event-transaction-completed',
			'user_callback'   => static function ( \MeprEvent $event ): int {
				$txn = $event->get_data();
				// A lapsed member dropped to a group's fallback membership has activated nothing.
				return \MeprTransaction::$fallback_str === $txn->txn_type ? 0 : (int) $txn->user_id;
			},
			'once_per_object' => static fn( \MeprEvent $event ): int => (int) $event->get_data()->product_id,
			'default_points'  => 50,
			'category'        => 'commerce',
			'icon'            => 'icon-users',
			'repeatable'      => true,
			'cooldown'        => 0,
		],

		[
			'id'             => 'mp_membership_renewed',
			'label'          => static fn(): string => __( 'Renew a MemberPress membership', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded on each renewal payment of a recurring membership.', 'wb-gamification' ),
			'hook'           => 'mepr-event-renewal-transaction-completed',
			'user_callback'  => $wb_gam_mp_txn_user,
			'default_points' => 30,
			'category'       => 'commerce',
			'icon'           => 'icon-refresh-cw',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'mp_first_membership',
			'label'          => static fn(): string => __( 'Join as a member for the first time', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a user activates their very first membership.', 'wb-gamification' ),
			'hook'           => 'mepr-event-member-signup-completed',
			'user_callback'  => static fn( \MeprEvent $event ): int => (int) $event->get_data()->ID,
			'default_points' => 100,
			'category'       => 'commerce',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		],

	],
];
