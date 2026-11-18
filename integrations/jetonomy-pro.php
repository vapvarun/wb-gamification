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
			'label'             => static fn(): string => __( 'Create a poll', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member creates a Jetonomy poll on a post.', 'wb-gamification' ),
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
			'once_per_object'   => static fn( int $poll_id, int $post_id ): int => $post_id,
			// Keyed by the forum post that holds the poll; deleting the post takes it back. Jetonomy Pro's admin
			// poll delete runs raw SQL with no action (card on the Jetonomy board).
			'reverse_on'        => array(
				'jetonomy_post_deleted' => static fn( $post_id ): int => (int) $post_id,
			),
			'async'             => false,
			'default_points'    => 10,
			'category'          => 'social',
			'icon'              => 'icon-chart-bar',
			'repeatable'        => true,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'jetonomy_pro_poll_voted',
			'label'             => static fn(): string => __( 'Vote on a poll', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member casts a vote on a Jetonomy poll.', 'wb-gamification' ),
			// Pro fires: do_action( 'jetonomy_pro_poll_voted', $poll_id, $user_id, $option_ids ).
			'hook'              => 'jetonomy_pro_poll_voted',
			'user_callback'     => function ( int $poll_id, int $user_id, array $option_ids ): int {
				return $user_id;
			},
			'owner_callback'    => static function ( int $poll_id ): int {
				global $wpdb;
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT created_by FROM {$wpdb->prefix}jt_pro_polls WHERE id = %d", $poll_id ) );
			},
			'metadata_callback' => function ( int $poll_id, int $user_id, array $option_ids ): array {
				return array(
					'poll_id'      => $poll_id,
					'option_count' => count( $option_ids ),
				);
			},
			'once_per_object'   => static fn( int $poll_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $poll_id ),
			'reverse_on'        => array(
				'jetonomy_pro_poll_unvoted' => static fn( $poll_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $poll_id ),
			),
			'async'             => false,
			'default_points'    => 2,
			'category'          => 'social',
			'icon'              => 'icon-check',
			'repeatable'        => true,
			'cooldown'          => 30,
		),

		// No trigger for jetonomy_pro_badge_earned: the badge pays its reputation bonus through
		// Reputation::award_custom(), which the Jetonomy mirror already records as
		// jetonomy_badge_earned. Paying here too gave every badge twice (card 10344441524).

		array(
			'id'                => 'jetonomy_pro_reaction_added',
			'label'             => static fn(): string => __( 'Send a reaction', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member adds a reaction to a post or reply. Removing a reaction does not award.', 'wb-gamification' ),
			// Pro fires: do_action( 'jetonomy_pro_reaction_toggled', $object_type, $object_id, $emoji, $user_id, $action ).
			// $action is 'added' or 'removed' — only the 'added' branch earns points.
			'hook'              => 'jetonomy_pro_reaction_toggled',
			'user_callback'     => function ( string $object_type, int $object_id, string $emoji, int $user_id, string $action ): int {
				return 'added' === $action ? $user_id : 0;
			},
			'owner_callback'    => static function ( string $object_type, int $object_id ): int {
				global $wpdb;
				$table = $wpdb->prefix . ( 'reply' === $object_type ? 'jt_replies' : 'jt_posts' );
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT author_id FROM %i WHERE id = %d', $table, $object_id ) );
			},
			'metadata_callback' => function ( string $object_type, int $object_id, string $emoji, int $user_id, string $action ): array {
				return array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'emoji'       => $emoji,
				);
			},
			'once_per_object'   => static fn( string $object_type, int $object_id, string $emoji, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $object_type, $object_id, $emoji ),
			// Jetonomy fires one hook for add and remove; a removal takes that reaction's points back.
			'reverse_on'        => array(
				'jetonomy_pro_reaction_toggled' => static fn( $object_type, $object_id, $emoji, $user_id, $action ): int => 'removed' === $action ? \WBGam\Engine\Registry::object_key( (int) $user_id, (string) $object_type, (int) $object_id, (string) $emoji ) : 0,
			),
			'async'             => false,
			'default_points'    => 1,
			'category'          => 'social',
			'icon'              => 'icon-heart',
			'repeatable'        => true,
			'cooldown'          => 30,
			'daily_cap'         => 50,
		),

	),
);
