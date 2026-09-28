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
			'description'         => static fn(): string => __( 'Awarded when a member receives a reaction on their activity.', 'wb-gamification' ),
			'hook'                => 'bp_reactions_add',
			'user_callback'       => function ( int $reaction_id, array $reaction_data ): int {
				return (int) ( $reaction_data['secondary_item_id'] ?? 0 );
			},
			// Capture the activity type so ActivityIntegration can apply quality weighting:
			// activity_update reactions worth 5 pts, activity_comment reactions worth 3 pts.
			'metadata_callback'   => function ( int $reaction_id, array $reaction_data ): array {
				$activity_id = (int) ( $reaction_data['item_id'] ?? 0 );
				if ( ! $activity_id || ! function_exists( 'bp_activity_get_specific' ) ) {
					return [ 'activity_id' => $activity_id ];
				}
				$result   = bp_activity_get_specific( [ 'activity_ids' => [ $activity_id ] ] );
				$activity = $result['activities'][0] ?? null;
				return [
					'activity_id'   => $activity_id,
					'activity_type' => $activity ? $activity->type : 'unknown',
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
			'description'         => static fn(): string => __( 'Awarded when a member creates a BuddyPress poll.', 'wb-gamification' ),
			'hook'                => 'bp_polls_created',
			'user_callback'       => fn( int $poll_id, int $user_id ) => $user_id,
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
			'id'                  => 'bp_media_upload',
			'label'               => static fn(): string => __( 'Upload media', 'wb-gamification' ),
			'description'         => static fn(): string => __( 'Awarded when a member uploads media via BuddyPress.', 'wb-gamification' ),
			'hook'                => 'bp_media_add',
			'user_callback'       => fn( int $media_id ) => get_current_user_id(),
			'default_points'      => 5,
			'category'            => 'buddypress',
			'icon'                => 'icon-image',
			'repeatable'          => true,
			'cooldown'            => 60,
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
			// repeatability is "false" with action_id namespaced by group via metadata, so each
			// fresh group cover earns once per (user, group) pair through the rate-limiter.
			'hook'                => 'groups_cover_image_uploaded',
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
