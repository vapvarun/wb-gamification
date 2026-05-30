<?php
/**
 * WB Gamification — Jetonomy Pro Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Jetonomy Pro is active.
 * No dependency on WB Gamification at load time.
 *
 * Reputation events (post/reply/vote/idea/flag) flow through Jetonomy's free
 * `jetonomy_reputation_changed` action and are mirrored into the WB Gam ledger
 * by `WBGam\Integrations\Jetonomy\JetonomyIntegration`. This manifest covers
 * Pro-exclusive events that do NOT go through reputation: polls, private
 * messaging, custom-badge earnings, and reactions.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/jetonomy-pro/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Jetonomy Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'jetonomy_pro_poll_created',
			'label'             => 'Create a poll',
			'description'       => 'Awarded when a member creates a Jetonomy poll on a post.',
			// Pro fires: do_action( 'jetonomy_pro_poll_created', $poll_id, $post_id, $user_id ).
			'hook'              => 'jetonomy_pro_poll_created',
			'user_callback'     => function ( int $poll_id, int $post_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $poll_id, int $post_id, int $user_id ): array {
				return array(
					'poll_id' => $poll_id,
					'post_id' => $post_id,
				);
			},
			'default_points'    => 10,
			'category'          => 'social',
			'icon'              => 'icon-chart-bar',
			'repeatable'        => true,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'jetonomy_pro_poll_voted',
			'label'             => 'Vote on a poll',
			'description'       => 'Awarded when a member casts a vote on a Jetonomy poll.',
			// Pro fires: do_action( 'jetonomy_pro_poll_voted', $poll_id, $user_id, $option_ids ).
			'hook'              => 'jetonomy_pro_poll_voted',
			'user_callback'     => function ( int $poll_id, int $user_id, array $option_ids ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $poll_id, int $user_id, array $option_ids ): array {
				return array(
					'poll_id'      => $poll_id,
					'option_count' => count( $option_ids ),
				);
			},
			'default_points'    => 2,
			'category'          => 'social',
			'icon'              => 'icon-check',
			'repeatable'        => true,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'jetonomy_pro_message_sent',
			'label'             => 'Send a Jetonomy private message',
			'description'       => 'Awarded when a member sends a Jetonomy private message. Daily cap blocks DM-farming.',
			// Pro fires: do_action( 'jetonomy_pro_message_sent', $message_id, $conversation_id, $sender_id ).
			'hook'              => 'jetonomy_pro_message_sent',
			'user_callback'     => function ( int $message_id, int $conversation_id, int $sender_id ): int {
				return $sender_id;
			},
			'metadata_callback' => function ( int $message_id, int $conversation_id, int $sender_id ): array {
				return array(
					'message_id'      => $message_id,
					'conversation_id' => $conversation_id,
				);
			},
			'default_points'    => 2,
			'category'          => 'social',
			'icon'              => 'icon-mail',
			'repeatable'        => true,
			'cooldown'          => 60,
			'daily_cap'         => 20,
		),

		array(
			'id'                => 'jetonomy_pro_conversation_created',
			'label'             => 'Start a Jetonomy conversation',
			'description'       => 'Awarded once per conversation when a member opens a new Jetonomy DM thread.',
			// Pro fires: do_action( 'jetonomy_pro_conversation_created', $conversation_id, $user_id, $all_participants ).
			'hook'              => 'jetonomy_pro_conversation_created',
			'user_callback'     => function ( int $conversation_id, int $user_id, array $all_participants ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $conversation_id, int $user_id, array $all_participants ): array {
				return array(
					'conversation_id'   => $conversation_id,
					'participant_count' => count( $all_participants ),
				);
			},
			'default_points'    => 5,
			'category'          => 'social',
			'icon'              => 'icon-message-circle',
			'repeatable'        => true,
			'cooldown'          => 120,
		),

		array(
			'id'                => 'jetonomy_pro_badge_earned',
			'label'             => 'Earn a Jetonomy badge',
			'description'       => 'Awarded when a member earns a Jetonomy custom badge.',
			// Pro fires: do_action( 'jetonomy_pro_badge_earned', $user_id, (int) $badge->id, $badge ).
			'hook'              => 'jetonomy_pro_badge_earned',
			'user_callback'     => function ( int $user_id, int $badge_id, $badge ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $user_id, int $badge_id, $badge ): array {
				$slug = '';
				if ( is_object( $badge ) && isset( $badge->slug ) ) {
					$slug = (string) $badge->slug;
				} elseif ( is_array( $badge ) && isset( $badge['slug'] ) ) {
					$slug = (string) $badge['slug'];
				}
				return array(
					'badge_id'   => $badge_id,
					'badge_slug' => $slug,
				);
			},
			'default_points'    => 15,
			'category'          => 'social',
			'icon'              => 'icon-award',
			'repeatable'        => true,
		),

		array(
			'id'                => 'jetonomy_pro_dm_received',
			'label'             => 'Receive a Jetonomy private message',
			'description'       => 'Awarded to the recipient when a Jetonomy private message is delivered. Daily cap protects against spam-DM gaming.',
			// Pro fires: do_action( 'jetonomy_pro_dm_received', int $message_id, int $conversation_id, int $sender_id, int $recipient_id ).
			'hook'              => 'jetonomy_pro_dm_received',
			'user_callback'     => function ( int $message_id, int $conversation_id, int $sender_id, int $recipient_id ): int {
				// Never reward DMs from the same user (rare edge: self-thread).
				return $sender_id !== $recipient_id ? $recipient_id : 0;
			},
			'metadata_callback' => function ( int $message_id, int $conversation_id, int $sender_id, int $recipient_id ): array {
				return array(
					'message_id'      => $message_id,
					'conversation_id' => $conversation_id,
					'sender_id'       => $sender_id,
				);
			},
			'default_points'    => 1,
			'category'          => 'social',
			'icon'              => 'icon-inbox',
			'repeatable'        => true,
			'cooldown'          => 120,
			'daily_cap'         => 10,
		),

		array(
			'id'                => 'jetonomy_pro_reaction_added',
			'label'             => 'Send a reaction',
			'description'       => 'Awarded when a member adds a reaction to a post or reply. Removing a reaction does not award.',
			// Pro fires: do_action( 'jetonomy_pro_reaction_toggled', $object_type, $object_id, $emoji, $user_id, $action ).
			// $action is 'added' or 'removed' — only the 'added' branch earns points.
			'hook'              => 'jetonomy_pro_reaction_toggled',
			'user_callback'     => function ( string $object_type, int $object_id, string $emoji, int $user_id, string $action ): int {
				return 'added' === $action ? $user_id : 0;
			},
			'metadata_callback' => function ( string $object_type, int $object_id, string $emoji, int $user_id, string $action ): array {
				return array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'emoji'       => $emoji,
				);
			},
			'default_points'    => 1,
			'category'          => 'social',
			'icon'              => 'icon-heart',
			'repeatable'        => true,
			'cooldown'          => 30,
			'daily_cap'         => 50,
		),

	),
);
