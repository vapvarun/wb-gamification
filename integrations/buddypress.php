<?php
/**
 * WB Gamification — BuddyPress Integration Manifest
 *
 * Auto-loaded by ManifestLoader when BuddyPress is active.
 * No dependency on WB Gamification at load time.
 *
 * All triggers here require BuddyPress. ManifestLoader skips this file
 * when BP is not active. Triggers marked requires_buddypress: true add
 * an explicit runtime guard for safety.
 *
 * Kudos triggers are handled by KudosEngine (Phase 2) — not listed here.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

// BuddyPress Reactions fires ( $reactor_id, $item_id, $content_type, $emoji_id, $is_update ).
// Its content types are activity, activity-comment, or a post type.
$wb_gam_bp_reacted_activity = static fn( $item_id ) => bp_activity_get_specific(
	[
		'activity_ids' => [ $item_id ],
		'show_hidden'  => true,
	]
)['activities'][0] ?? null;

// BuddyPress Polls has two ways to create a poll and no hook for either, so funnel both into one.
add_action(
	'bp_activity_after_save',
	static function ( $activity ): void {
		if ( 'activity_poll' === $activity->type ) {
			do_action( 'wb_gam_bp_poll_created', (int) $activity->user_id, (int) $activity->id );
		}
	}
);
add_action( 'publish_wbpoll', static fn( $post_id, $post ) => do_action( 'wb_gam_bp_poll_created', (int) $post->post_author, (int) $post_id ), 10, 2 );

return [
	'plugin'   => 'BuddyPress',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'                  => 'bp_activity_update',
			'label'               => static fn(): string => __( 'Post an activity update', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member posts an activity update.', 'wb-gamification' ),
			'hook'                => 'bp_activity_posted_update',
			'user_callback'       => fn( string $content, int $user_id, int $activity_id ) => $user_id,
			// Capture word count for quality-weighted scoring.
			'metadata_callback'   => function ( string $content, int $user_id, int $activity_id ): array {
				return [
					'word_count'  => str_word_count( wp_strip_all_tags( $content ) ),
					'activity_id' => $activity_id,
				];
			},
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'icon-message-circle',
			'repeatable'          => true,
			'cooldown'            => 30,
			'async'               => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_activity_comment',
			'label'               => static fn(): string => __( 'Comment on an activity', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member comments on an activity update.', 'wb-gamification' ),
			'hook'                => 'bp_activity_comment_posted',
			'user_callback'       => function ( int $comment_id, array $params, object $activity ): int {
				return (int) ( $params['user_id'] ?? 0 );
			},
			// Capture word count and parent activity for quality scoring.
			'metadata_callback'   => function ( int $comment_id, array $params, object $activity ): array {
				$content = $params['content'] ?? '';
				return [
					'word_count'  => str_word_count( wp_strip_all_tags( $content ) ),
					'activity_id' => (int) ( $params['activity_id'] ?? 0 ),
				];
			},
			'default_points'      => 5,
			'category'            => 'buddypress',
			'icon'                => 'icon-message-square',
			'repeatable'          => true,
			'cooldown'            => 30,
			'async'               => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_friends_accepted',
			'label'               => static fn(): string => __( 'Accept a friendship', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded to the member who accepts a friendship request.', 'wb-gamification' ),
			'hook'                => 'friends_friendship_accepted',
			'user_callback'       => function ( int $friendship_id, int $initiator_id, int $friend_id, $friendship ): int {
				// Award the acceptor (friend_user_id), not the requester.
				// BP fires: ($friendship->id, $initiator_user_id, $friend_user_id, $friendship_object)
				return is_object( $friendship ) ? (int) ( $friendship->friend_user_id ?? $friend_id ) : $friend_id;
			},
			'default_points'      => 8,
			'category'            => 'buddypress',
			'icon'                => 'icon-users',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_groups_join',
			'label'               => static fn(): string => __( 'Join a group', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member joins a BuddyPress group.', 'wb-gamification' ),
			'hook'                => 'groups_join_group',
			'user_callback'       => fn( int $group_id, int $user_id ) => $user_id,
			'default_points'      => 8,
			'category'            => 'buddypress',
			'icon'                => 'icon-network',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_groups_create',
			'label'               => static fn(): string => __( 'Create a group', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member creates a new BuddyPress group.', 'wb-gamification' ),
			'hook'                => 'groups_group_create_complete',
			'user_callback'       => function ( int $group_id ): int {
				$group = groups_get_group( $group_id );
				return $group ? (int) $group->creator_id : 0;
			},
			'default_points'      => 20,
			'category'            => 'buddypress',
			'icon'                => 'icon-plus',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_profile_complete',
			'label'               => static fn(): string => __( 'Complete extended profile', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded once when a member saves their extended BuddyPress profile.', 'wb-gamification' ),
			'hook'                => 'xprofile_updated_profile',
			'user_callback'       => fn( int $user_id ) => $user_id,
			'default_points'      => 15,
			'category'            => 'buddypress',
			'icon'                => 'icon-user',
			'repeatable'          => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_reactions_received',
			'label'               => static fn(): string => __( 'Receive a reaction', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when another member reacts to their content.', 'wb-gamification' ),
			'hook'                => 'bp_reactions_after_react',
			'user_callback'       => static function ( $reactor_id, $item_id, $content_type, $emoji_id, $is_update ) use ( $wb_gam_bp_reacted_activity ): int {
				if ( in_array( $content_type, [ 'activity', 'activity-comment' ], true ) ) {
					$owner_id = (int) ( $wb_gam_bp_reacted_activity( $item_id )->user_id ?? 0 );
				} else {
					$owner_id = (int) get_post_field( 'post_author', $item_id );
				}
				return $is_update || (int) $reactor_id === $owner_id ? 0 : $owner_id;
			},
			// One reactor pays once per item, so reacting, removing and reacting again earns nothing.
			'once_per_object'     => static fn( $reactor_id, $item_id, $content_type ): int => crc32( "{$reactor_id}:{$content_type}:{$item_id}" ),
			// Capture the activity type so ActivityIntegration can apply quality weighting:
			// activity_update reactions worth 5 pts, activity_comment reactions worth 3 pts.
			'metadata_callback'   => static function ( $reactor_id, $item_id, $content_type ) use ( $wb_gam_bp_reacted_activity ): array {
				return [
					'activity_id'   => (int) $item_id,
					'activity_type' => 'activity' === $content_type ? ( $wb_gam_bp_reacted_activity( $item_id )->type ?? 'unknown' ) : $content_type,
				];
			},
			'default_points'      => 3,
			'category'            => 'buddypress',
			'icon'                => 'icon-heart',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_polls_created',
			'label'               => static fn(): string => __( 'Create a poll', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member creates a poll with BuddyPress Polls.', 'wb-gamification' ),
			'hook'                => 'wb_gam_bp_poll_created',
			'user_callback'       => static fn( $user_id ): int => (int) $user_id,
			'once_per_object'     => static fn( $user_id, $poll_id ): int => (int) $poll_id,
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'icon-chart-bar',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_publish_post',
			'label'               => static fn(): string => __( 'Publish a member blog post', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member publishes a post via BP Member Blog.', 'wb-gamification' ),
			'hook'                => 'publish_post',
			'user_callback'       => function ( int $post_id ): int {
				$post = get_post( $post_id );
				// Only award for user-authored posts (not pages, products, etc.).
				if ( ! $post || 'post' !== $post->post_type ) {
					return 0;
				}
				return (int) $post->post_author;
			},
			'default_points'      => 25,
			'category'            => 'buddypress',
			'icon'                => 'icon-file-text',
			'repeatable'          => true,
			// Rate-limit: publish_post fires on every Publish click + revisions.
			// Cap at 5 awards/day so a member can't grind by republishing.
			'cooldown'            => 600,
			'daily_cap'           => 5,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_avatar_upload',
			'label'               => static fn(): string => __( 'Upload profile photo', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded the first time a member uploads a profile avatar.', 'wb-gamification' ),
			// BP fires: do_action( 'bp_members_avatar_uploaded', $item_id, $type, $args, $cropped_avatar ).
			'hook'                => 'bp_members_avatar_uploaded',
			'user_callback'       => fn( int $item_id, string $type = '' ) => $item_id,
			'metadata_callback'   => function ( int $item_id, string $type = '' ): array {
				return array( 'avatar_type' => $type );
			},
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'icon-user',
			'repeatable'          => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_cover_upload',
			'label'               => static fn(): string => __( 'Upload cover photo', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded the first time a member uploads a profile cover image.', 'wb-gamification' ),
			// BP fires: do_action( 'members_cover_image_uploaded', $item_id, $name, $cover_url, $feedback_code ).
			// $feedback_code === 1 means success — anything else is a soft-failure path we shouldn't reward.
			'hook'                => 'members_cover_image_uploaded',
			'user_callback'       => function ( int $item_id, string $name = '', string $cover_url = '', int $feedback_code = 0 ): int {
				return 1 === $feedback_code ? $item_id : 0;
			},
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'icon-image',
			'repeatable'          => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_group_cover_upload',
			'label'               => static fn(): string => __( 'Upload group cover photo', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded once per group when an admin uploads a cover image for a group.', 'wb-gamification' ),
			// BP fires: do_action( 'groups_cover_image_uploaded', $item_id, $name, $cover_url, $feedback_code ).
			// We award the acting user (the admin who uploaded), not the group creator —
			// each group cover earns once per (user, group) pair.
			'hook'                => 'groups_cover_image_uploaded',
			'once_per_object'     => static fn( int $item_id ): int => $item_id,
			'user_callback'       => function ( int $item_id, string $name = '', string $cover_url = '', int $feedback_code = 0 ): int {
				return 1 === $feedback_code ? get_current_user_id() : 0;
			},
			'metadata_callback'   => function ( int $item_id, string $name = '', string $cover_url = '', int $feedback_code = 0 ): array {
				return array( 'group_id' => $item_id );
			},
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'icon-image',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_message_sent',
			'label'               => static fn(): string => __( 'Send a private message', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded for sending a 1:1 message. Cooldown applies to limit spam.', 'wb-gamification' ),
			// BP fires: do_action_ref_array( 'messages_message_sent', array( &$message, $r ) ).
			'hook'                => 'messages_message_sent',
			'user_callback'       => function ( $message ): int {
				return is_object( $message ) ? (int) ( $message->sender_id ?? 0 ) : 0;
			},
			'metadata_callback'   => function ( $message ): array {
				return array(
					'thread_id' => is_object( $message ) ? (int) ( $message->thread_id ?? 0 ) : 0,
				);
			},
			'default_points'      => 3,
			'category'            => 'buddypress',
			'icon'                => 'icon-mail',
			'repeatable'          => true,
			'cooldown'            => 60,
			'requires_buddypress' => true,
		],

	],
];
