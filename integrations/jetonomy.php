<?php
/**
 * WB Gamification — Jetonomy (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Jetonomy free is active.
 * No dependency on WB Gamification at load time.
 *
 * Forum and content reputation events (post/reply/vote/idea/flag) flow
 * through `jetonomy_reputation_changed` and are mirrored by
 * `WBGam\Integrations\Jetonomy\JetonomyIntegration::on_reputation_changed`.
 * This manifest covers the FREE Jetonomy events that do NOT route through
 * Reputation: space membership, gated-space admission, trust-level
 * progression, and host-plugin membership activation.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/jetonomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'JETONOMY_VERSION' ) && ! class_exists( '\\Jetonomy\\Plugin' ) ) {
	return array();
}

return array(
	'plugin'   => 'Jetonomy',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'jetonomy_space_joined',
			'label'             => 'Join a space',
			'description'       => 'Awarded once when a member joins a Jetonomy community space. Daily cap prevents bulk-join farming.',
			// Free fires: do_action( 'jetonomy_user_joined_space', int $space_id, int $user_id, string $role ).
			'hook'              => 'jetonomy_user_joined_space',
			'user_callback'     => function ( int $space_id, int $user_id, string $role ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $space_id, int $user_id, string $role ): array {
				return array(
					'space_id' => $space_id,
					'role'     => $role,
				);
			},
			'default_points'    => 5,
			'category'          => 'community',
			'icon'              => 'icon-users',
			'repeatable'        => true,
			'cooldown'          => 60,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'jetonomy_join_request_approved',
			'label'             => 'Approved into a gated space',
			'description'       => 'Awarded once when a member is approved into a request-to-join space.',
			// Free fires: do_action( 'jetonomy_join_request_approved', int $space_id, int $user_id, int $reviewed_by ).
			'hook'              => 'jetonomy_join_request_approved',
			'user_callback'     => function ( int $space_id, int $user_id, int $reviewed_by ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $space_id, int $user_id, int $reviewed_by ): array {
				return array(
					'space_id'    => $space_id,
					'reviewed_by' => $reviewed_by,
				);
			},
			'default_points'    => 10,
			'category'          => 'community',
			'icon'              => 'icon-check-circle',
			'repeatable'        => true,
			'cooldown'          => 300,
		),

		array(
			'id'                => 'jetonomy_trust_level_up',
			'label'             => 'Trust level promoted',
			'description'       => 'Awarded when a member is promoted to a higher Jetonomy trust level (TL0 -> TL5). Demotions never award.',
			// Free fires: do_action( 'jetonomy_trust_level_changed', int $user_id, int $old_level, int $new_level ).
			'hook'              => 'jetonomy_trust_level_changed',
			'user_callback'     => function ( int $user_id, int $old_level, int $new_level ): int {
				// Only the upward direction earns. Demotions / no-op fire the same
				// action; returning 0 makes Engine::process drop the event silently.
				return $new_level > $old_level ? $user_id : 0;
			},
			'metadata_callback' => function ( int $user_id, int $old_level, int $new_level ): array {
				return array(
					'old_level' => $old_level,
					'new_level' => $new_level,
					'delta'     => $new_level - $old_level,
				);
			},
			'default_points'    => 50,
			'category'          => 'community',
			'icon'              => 'icon-trending-up',
			'repeatable'        => true,
		),

		array(
			'id'                => 'jetonomy_membership_activated',
			'label'             => 'Membership activated',
			'description'       => 'Awarded when a paid membership becomes active for the member (RCP / PMPro / MemberPress / WooCommerce Subscriptions / Sensei / LearnDash / MasterStudy / Tutor / LifterLMS).',
			// Free + Pro adapters fire: do_action( 'jetonomy_membership_activated', int $user_id, mixed $level_id, string $source ).
			'hook'              => 'jetonomy_membership_activated',
			'user_callback'     => function ( int $user_id, $level_id, string $source ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $user_id, $level_id, string $source ): array {
				return array(
					'level_id' => is_scalar( $level_id ) ? (string) $level_id : '',
					'source'   => $source,
				);
			},
			'default_points'    => 25,
			'category'          => 'community',
			'icon'              => 'icon-key',
			'repeatable'        => true,
		),

	),
);
