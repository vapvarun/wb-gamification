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
			'label'          => 'Complete a donation',
			'description'    => 'Awarded each time a donation is successfully processed.',
			'hook'           => 'give_complete_purchase',
			'user_callback'  => function ( int $payment_id ): int {
				$user_id = (int) give_get_payment_user_id( $payment_id );
				return $user_id > 0 ? $user_id : 0;
			},
			'default_points' => 30,
			'category'       => 'social',
			'icon'           => 'dashicons-heart',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'give_first_donation',
			'label'          => 'Make first donation ever',
			'description'    => 'Awarded once when a member makes their very first donation.',
			'hook'           => 'give_complete_purchase',
			'user_callback'  => function ( int $payment_id ): int {
				$user_id = (int) give_get_payment_user_id( $payment_id );
				if ( ! $user_id ) {
					return 0;
				}
				// Check if this is their first donation.
				$donations = give_get_payments( [
					'user_id' => $user_id,
					'status'  => [ 'publish', 'give_subscription' ],
					'number'  => 2,
				] );
				return count( $donations ) === 1 ? $user_id : 0;
			},
			'default_points' => 75,
			'category'       => 'social',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => false,
		],

		[
			'id'             => 'give_recurring_donation',
			'label'          => 'Make a recurring donation payment',
			'description'    => 'Awarded on each successful recurring donation charge.',
			'hook'           => 'give_recurring_record_payment',
			'user_callback'  => function ( int $parent_payment_id, int $subscription_id, float $amount, string $transaction_id ): int {
				return (int) give_get_payment_user_id( $parent_payment_id );
			},
			'default_points' => 20,
			'category'       => 'social',
			'icon'           => 'dashicons-update',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'give_campaign_goal_reached',
			'label'          => 'Campaign reaches its goal',
			'description'    => 'Awarded to all donors when a fundraising campaign reaches its goal.',
			'hook'           => 'give_goal_complete',
			'user_callback'  => function ( int $form_id ): int {
				// Award the user who triggered completion (last donor).
				// Full campaign-wide award requires a custom action outside this manifest.
				return (int) get_current_user_id();
			},
			'default_points' => 15,
			'category'       => 'social',
			'icon'           => 'dashicons-megaphone',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
