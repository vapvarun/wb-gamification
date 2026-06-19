<?php
/**
 * WB Gamification — BuddyNext (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when BuddyNext free is active.
 * No dependency on WB Gamification at load time.
 *
 * All producer wiring previously in BuddyNext's GamificationBridge (ACTION_CATALOGUE,
 * NOOP_HOOK, register_actions(), and on_* handlers calling wb_gam_submit_event) has
 * been retired. The engine now auto-binds every hook listed here; BuddyNext emits
 * the action and the engine awards points exactly once with no bridge intermediary.
 *
 * Action IDs are preserved from the original ACTION_CATALOGUE so any existing badge,
 * challenge, or rule config keyed on them continues to work without migration.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/buddynext/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BUDDYNEXT_VERSION' ) && ! class_exists( '\\BuddyNext\\Plugin' ) ) {
	return array();
}

return array(
	'plugin'   => 'BuddyNext',
	'version'  => '1.0.0',
	'triggers' => array(

		// -----------------------------------------------------------------------
		// Content
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_post_created',
			'label'             => 'Post created',
			'description'       => 'Awarded each time a member creates a post. Daily cap prevents farming.',
			// Fires: do_action( 'buddynext_post_created', int $post_id, int $user_id, string $type ).
			'hook'              => 'buddynext_post_created',
			'user_callback'     => function ( int $post_id, int $user_id, string $type ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $post_id, int $user_id, string $type ): array {
				return array(
					'post_id' => $post_id,
					'type'    => $type,
				);
			},
			'default_points'    => 5,
			'category'          => 'content',
			'icon'              => 'icon-file-text',
			'repeatable'        => true,
			'cooldown'          => 30,
			'daily_cap'         => 20,
		),

		array(
			'id'                => 'bn_post_shared',
			'label'             => 'Post shared',
			'description'       => 'Awarded when a member shares an existing post. Daily cap limits farming.',
			// Fires: do_action( 'buddynext_post_shared', int $share_id, int $post_id, int $user_id ).
			'hook'              => 'buddynext_post_shared',
			'user_callback'     => function ( int $share_id, int $post_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $share_id, int $post_id, int $user_id ): array {
				return array(
					'share_id' => $share_id,
					'post_id'  => $post_id,
				);
			},
			'default_points'    => 5,
			'category'          => 'content',
			'icon'              => 'icon-share-2',
			'repeatable'        => true,
			'daily_cap'         => 10,
		),

		array(
			'id'                => 'bn_comment_created',
			'label'             => 'Comment created',
			'description'       => 'Awarded when a member comments on a post. Cooldown prevents rapid-fire farming.',
			// Fires: do_action( 'buddynext_comment_created', int $comment_id, string $object_type, int $object_id, int $user_id ).
			'hook'              => 'buddynext_comment_created',
			'user_callback'     => function ( int $comment_id, string $object_type, int $object_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $comment_id, string $object_type, int $object_id, int $user_id ): array {
				return array(
					'comment_id'  => $comment_id,
					'object_type' => $object_type,
					'object_id'   => $object_id,
				);
			},
			'default_points'    => 3,
			'category'          => 'content',
			'icon'              => 'icon-message-square',
			'repeatable'        => true,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'bn_reaction_received',
			'label'             => 'Reaction received on your content',
			'description'       => 'Awarded to the content owner when another member reacts to their post. BuddyNext only fires this for cross-user reactions (self-reactions are excluded upstream in ReactionService).',
			// Fires: do_action( 'buddynext_post_reaction_received', int $object_id, int $author_id, int $reactor_id, string $emoji ).
			'hook'              => 'buddynext_post_reaction_received',
			'user_callback'     => function ( int $object_id, int $author_id, int $reactor_id, string $emoji ): int {
				// Award the content owner, not the reactor.
				return $author_id;
			},
			'metadata_callback' => function ( int $object_id, int $author_id, int $reactor_id, string $emoji ): array {
				return array(
					'post_id'    => $object_id,
					'reactor_id' => $reactor_id,
					'emoji'      => $emoji,
				);
			},
			'default_points'    => 2,
			'category'          => 'content',
			'icon'              => 'icon-heart',
			'repeatable'        => true,
			'daily_cap'         => 20,
		),

		array(
			'id'                => 'bn_poll_voted',
			'label'             => 'Poll voted',
			'description'       => 'Awarded when a member votes on a poll. Daily cap prevents ballot-box farming.',
			// Fires: do_action( 'buddynext_poll_voted', int $post_id, int $option_id, int $user_id ).
			'hook'              => 'buddynext_poll_voted',
			'user_callback'     => function ( int $post_id, int $option_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $post_id, int $option_id, int $user_id ): array {
				return array(
					'post_id'   => $post_id,
					'option_id' => $option_id,
				);
			},
			'default_points'    => 1,
			'category'          => 'content',
			'icon'              => 'icon-bar-chart-2',
			'repeatable'        => true,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'bn_post_bookmarked',
			'label'             => 'Post bookmarked',
			'description'       => 'Awarded the first time a member bookmarks a post. Daily cap limits farming.',
			// Fires: do_action( 'buddynext_post_bookmarked', int $post_id, int $user_id ) — only on first bookmark per post/user pair.
			'hook'              => 'buddynext_post_bookmarked',
			'user_callback'     => function ( int $post_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $post_id, int $user_id ): array {
				return array( 'post_id' => $post_id );
			},
			'default_points'    => 1,
			'category'          => 'content',
			'icon'              => 'icon-bookmark',
			'repeatable'        => true,
			'daily_cap'         => 5,
		),

		// -----------------------------------------------------------------------
		// Social
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_followed',
			'label'             => 'Followed by a member',
			'description'       => 'Awarded to the member who gains a new follower. Daily cap prevents follow-farming.',
			// Fires: do_action( 'buddynext_follower_gained', int $following_id, int $follower_id ).
			// The recipient is the member who GAINED the follower ($following_id = arg1).
			'hook'              => 'buddynext_follower_gained',
			'user_callback'     => function ( int $following_id, int $follower_id ): int {
				return $following_id;
			},
			'metadata_callback' => function ( int $following_id, int $follower_id ): array {
				return array( 'follower_id' => $follower_id );
			},
			'default_points'    => 5,
			'category'          => 'social',
			'icon'              => 'icon-user-plus',
			'repeatable'        => true,
			'daily_cap'         => 10,
		),

		array(
			'id'             => 'bn_first_follow',
			'label'          => 'First follow made',
			'description'    => 'Awarded once when a member follows their first person.',
			// Fires: do_action( 'buddynext_user_followed_first_time', int $follower_id, int $following_id ).
			'hook'           => 'buddynext_user_followed_first_time',
			'user_callback'  => function ( int $follower_id, int $following_id ): int {
				return $follower_id;
			},
			'default_points' => 5,
			'category'       => 'social',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		),

		array(
			'id'                => 'bn_connected',
			'label'             => 'Connection accepted',
			'description'       => 'Awarded to the initiating user (user_a) when a connection request is accepted. Note: the manifest engine awards one user_id per trigger; only user_a is awarded here. Sites needing bilateral awards can wire a second trigger on the same hook returning $user_b.',
			// Fires: do_action( 'buddynext_connection_accepted', int $connection_id, int $user_a, int $user_b ).
			'hook'              => 'buddynext_connection_accepted',
			'user_callback'     => function ( int $connection_id, int $user_a, int $user_b ): int {
				// Only user_a is awarded per this trigger. See description for bilateral note.
				return $user_a;
			},
			'metadata_callback' => function ( int $connection_id, int $user_a, int $user_b ): array {
				return array(
					'connection_id' => $connection_id,
					'peer_id'       => $user_b,
				);
			},
			'default_points'    => 10,
			'category'          => 'social',
			'icon'              => 'icon-link',
			'repeatable'        => true,
		),

		array(
			'id'                => 'bn_connection_requested',
			'label'             => 'Connection request sent',
			'description'       => 'Awarded when a member sends a connection request. Daily cap prevents spam-requesting.',
			// Fires: do_action( 'buddynext_connection_requested', int $connection_id, int $requester_id, int $recipient_id, string $note ).
			'hook'              => 'buddynext_connection_requested',
			'user_callback'     => function ( int $connection_id, int $requester_id, int $recipient_id, string $note = '' ): int {
				return $requester_id;
			},
			'metadata_callback' => function ( int $connection_id, int $requester_id, int $recipient_id, string $note = '' ): array {
				return array(
					'connection_id' => $connection_id,
					'recipient_id'  => $recipient_id,
				);
			},
			'default_points'    => 1,
			'category'          => 'social',
			'icon'              => 'icon-send',
			'repeatable'        => true,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'bn_dm_sent',
			'label'             => 'Direct message sent',
			'description'       => 'Awarded when a member sends a DM. Daily cap keeps points proportional to real engagement.',
			// Fires: do_action( 'buddynext_dm_sent', int $sender_id, int $message_id, int $conversation_id, array $recipients ).
			'hook'              => 'buddynext_dm_sent',
			'user_callback'     => function ( int $sender_id, int $message_id, int $conversation_id, array $recipients ): int {
				return $sender_id;
			},
			'metadata_callback' => function ( int $sender_id, int $message_id, int $conversation_id, array $recipients ): array {
				return array(
					'message_id'      => $message_id,
					'conversation_id' => $conversation_id,
				);
			},
			'default_points'    => 1,
			'category'          => 'social',
			'icon'              => 'icon-mail',
			'repeatable'        => true,
			'daily_cap'         => 10,
		),

		// -----------------------------------------------------------------------
		// Community
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_space_joined',
			'label'             => 'Joined a space',
			'description'       => 'Awarded when a member joins a community space. Daily cap prevents bulk-join farming.',
			// Fires: do_action( 'buddynext_space_member_joined', int $space_id, int $user_id, string $role ).
			'hook'              => 'buddynext_space_member_joined',
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
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'bn_space_created',
			'label'             => 'Space created',
			'description'       => 'Awarded when a member creates a new community space.',
			// Fires: do_action( 'buddynext_space_created', int $space_id, int $owner_id ).
			'hook'              => 'buddynext_space_created',
			'user_callback'     => function ( int $space_id, int $owner_id ): int {
				return $owner_id;
			},
			'metadata_callback' => function ( int $space_id, int $owner_id ): array {
				return array( 'space_id' => $space_id );
			},
			'default_points'    => 10,
			'category'          => 'community',
			'icon'              => 'icon-layout',
			'repeatable'        => true,
		),

		// -----------------------------------------------------------------------
		// Profile & Onboarding
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_profile_updated',
			'label'             => 'Profile updated',
			'description'       => 'Awarded when a member updates their profile completion percentage (but not on the 100% milestone, which is the separate bn_profile_completed trigger).',
			// Fires: do_action( 'buddynext_profile_completion_changed', int $user_id, int $percent ).
			'hook'              => 'buddynext_profile_completion_changed',
			'user_callback'     => function ( int $user_id, int $percent ): int {
				// The 100% case is handled by bn_profile_completed below.
				return (int) $percent < 100 ? $user_id : 0;
			},
			'metadata_callback' => function ( int $user_id, int $percent ): array {
				return array( 'percent' => $percent );
			},
			'default_points'    => 2,
			'category'          => 'community',
			'icon'              => 'icon-user',
			'repeatable'        => true,
			'cooldown'          => 300,
		),

		array(
			'id'             => 'bn_profile_completed',
			'label'          => 'Profile completed',
			'description'    => 'Awarded once when a member reaches 100% profile completion.',
			// Fires: do_action( 'buddynext_profile_completion_changed', int $user_id, int $percent ).
			'hook'           => 'buddynext_profile_completion_changed',
			'user_callback'  => function ( int $user_id, int $percent ): int {
				return 100 === (int) $percent ? $user_id : 0;
			},
			'default_points' => 25,
			'category'       => 'community',
			'icon'           => 'icon-check-circle',
			'repeatable'     => false,
		),

		array(
			'id'             => 'bn_onboarding_completed',
			'label'          => 'Onboarding completed',
			'description'    => 'Awarded once when a member completes the BuddyNext onboarding wizard.',
			// Fires: do_action( 'buddynext_onboarding_completed', int $user_id ).
			'hook'           => 'buddynext_onboarding_completed',
			'user_callback'  => function ( int $user_id ): int {
				return $user_id;
			},
			'default_points' => 20,
			'category'       => 'community',
			'icon'           => 'icon-flag',
			'repeatable'     => false,
		),

	),
);
