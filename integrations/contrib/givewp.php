<?php
/**
 * WB Gamification — GiveWP Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when GiveWP is active.
 *
 * Actions covered:
 *   Donation completed      — give_complete_purchase (any amount, any form)
 *   First donation ever     — give_complete_purchase (once only)
 *   Recurring donation      — give_recurring_record_payment (Give Recurring add-on)
 *   Campaign milestone      — give_goal_complete (form goal reached)
 *
 * Nonprofit community model:
 *   Recognize the act of donating, not the amount (privacy-preserving).
 *   Reward consistency (recurring donors) over one-off large gifts.
 *
 * @package WB_Gamification
 * @see     https://givewp.com/documentation/developers/hooks/
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'give' ) ) {
	return [];
}

return [
	'plugin'   => 'GiveWP',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'give_donation_completed',
			'label'          => static fn(): string => __( 'Complete a donation', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded each time a donation is successfully processed.', 'wb-gamification' ),
			'hook'           => 'give_complete_purchase',
			'user_callback'  => function ( int $payment_id ): int {
				$user_id = (int) give_get_payment_user_id( $payment_id );
				return $user_id > 0 ? $user_id : 0;
			},
			'default_points' => 30,
			'category'       => 'social',
			'icon'           => 'icon-heart',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'give_first_donation',
			'label'          => static fn(): string => __( 'Make first donation ever', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a member makes their very first donation.', 'wb-gamification' ),
			'hook'           => 'give_complete_purchase',
			'user_callback'  => function ( int $payment_id ): int {
				$user_id = (int) give_get_payment_user_id( $payment_id );
				if ( ! $user_id ) {
					return 0;
				}
				// Check if this is their first donation.
				$donations = give_get_payments(
					[
						'user_id' => $user_id,
						'status'  => [ 'publish', 'give_subscription' ],
						'number'  => 2,
					]
				);
				return count( $donations ) === 1 ? $user_id : 0;
			},
			'default_points' => 75,
			'category'       => 'social',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		],

		[
			'id'             => 'give_recurring_donation',
			'label'          => static fn(): string => __( 'Make a recurring donation payment', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded on each successful recurring donation charge.', 'wb-gamification' ),
			'hook'           => 'give_recurring_record_payment',
			'user_callback'  => function ( int $parent_payment_id, int $subscription_id, float $amount, string $transaction_id ): int {
				return (int) give_get_payment_user_id( $parent_payment_id );
			},
			'default_points' => 20,
			'category'       => 'social',
			'icon'           => 'icon-refresh-cw',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'give_campaign_goal_reached',
			'label'          => static fn(): string => __( 'Campaign reaches its goal', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded to all donors when a fundraising campaign reaches its goal.', 'wb-gamification' ),
			'hook'           => 'give_goal_complete',
			'user_callback'  => function ( int $form_id ): int {
				// Award the user who triggered completion (last donor).
				// Full campaign-wide award requires a custom action outside this manifest.
				return (int) get_current_user_id();
			},
			'default_points' => 15,
			'category'       => 'social',
			'icon'           => 'icon-megaphone',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
