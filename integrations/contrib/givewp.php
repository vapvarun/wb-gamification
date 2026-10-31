<?php
/**
 * WB Gamification — GiveWP Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when GiveWP is active.
 *
 * Actions covered:
 *   Donation completed      — give_complete_donation (any amount, any form)
 *   First donation ever     — give_complete_donation (once only)
 *   Recurring donation      — give_recurring_record_payment (Give Recurring add-on)
 *   Campaign goal reached   — wb_gam_give_campaign_goal_reached (GiveWP has no hook for this)
 *
 * Nonprofit community model:
 *   Recognize the act of donating, not the amount (privacy-preserving).
 *   Reward consistency (recurring donors) over one-off large gifts.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'give' ) ) {
	return [];
}

// GiveWP fires nothing when a campaign reaches its goal, so check after each donation.
// add_option() only succeeds once, so a refund and a new gift cannot reach the same goal twice.
add_action(
	'give_complete_donation',
	static function ( $payment_id ): void {
		$campaign = \Give\Campaigns\Models\Campaign::findByFormId( (int) give_get_payment_form_id( $payment_id ) );
		if ( $campaign && 100 <= $campaign->getGoalStats()['percentage'] && add_option( 'wb_gam_give_goal_reached_' . $campaign->id, 1, '', false ) ) {
			do_action( 'wb_gam_give_campaign_goal_reached', (int) $payment_id );
		}
	}
);

return [
	'plugin'   => 'GiveWP',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'give_donation_completed',
			'label'          => static fn(): string => __( 'Complete a donation', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded each time a donation is successfully processed.', 'wb-gamification' ),
			'hook'           => 'give_complete_donation',
			'user_callback'  => static fn( $payment_id ): int => (int) give_get_payment_user_id( $payment_id ),
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
			'hook'           => 'give_complete_donation',
			'user_callback'  => static function ( $payment_id ): int {
				// GiveWP counts this gift before it fires the hook, so a first-time donor is at 1.
				$donor = new \Give_Donor( give_get_payment_donor_id( $payment_id ) );
				return 1 === (int) $donor->purchase_count ? (int) give_get_payment_user_id( $payment_id ) : 0;
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
			// GiveWP fires this with ( $payment, $parent_payment_id, $total, $transaction_id ).
			'user_callback'  => static fn( $payment, $parent_payment_id ): int => (int) give_get_payment_user_id( $parent_payment_id ),
			'default_points' => 20,
			'category'       => 'social',
			'icon'           => 'icon-refresh-cw',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'give_campaign_goal_reached',
			'label'          => static fn(): string => __( 'Campaign reaches its goal', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded to the donor whose gift takes a campaign to its goal.', 'wb-gamification' ),
			'hook'           => 'wb_gam_give_campaign_goal_reached',
			'user_callback'  => static fn( $payment_id ): int => (int) give_get_payment_user_id( $payment_id ),
			'default_points' => 15,
			'category'       => 'social',
			'icon'           => 'icon-megaphone',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

	],
];
