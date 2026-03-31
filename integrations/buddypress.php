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
			'label'               => 'Post an activity update',
			'description'         => 'Awarded when a member posts an activity update.',
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
			'icon'                => 'dashicons-admin-comments',
			'repeatable'          => true,
			'cooldown'            => 30,
			'async'               => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_activity_comment',
			'label'               => 'Comment on an activity',
			'description'         => 'Awarded when a member comments on an activity update.',
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
			'icon'                => 'dashicons-format-chat',
			'repeatable'          => true,
			'cooldown'            => 30,
			'async'               => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_friends_accepted',
			'label'               => 'Accept a friendship',
			'description'         => 'Awarded to the member who accepts a friendship request.',
			'hook'                => 'friends_friendship_accepted',
			'user_callback'       => function ( int $initiator_id, int $friend_id, int $friendship_id, $friendship ): int {
				// Award the acceptor (friend_user_id), not the requester.
				return is_object( $friendship ) ? (int) ( $friendship->friend_user_id ?? $friend_id ) : $friend_id;
			},
			'default_points'      => 8,
			'category'            => 'buddypress',
			'icon'                => 'dashicons-groups',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_groups_join',
			'label'               => 'Join a group',
			'description'         => 'Awarded when a member joins a BuddyPress group.',
			'hook'                => 'groups_join_group',
			'user_callback'       => fn( int $group_id, int $user_id ) => $user_id,
			'default_points'      => 8,
			'category'            => 'buddypress',
			'icon'                => 'dashicons-networking',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_groups_create',
			'label'               => 'Create a group',
			'description'         => 'Awarded when a member creates a new BuddyPress group.',
			'hook'                => 'groups_group_create_complete',
			'user_callback'       => function ( int $group_id ): int {
				$group = groups_get_group( $group_id );
				return $group ? (int) $group->creator_id : 0;
			},
			'default_points'      => 20,
			'category'            => 'buddypress',
			'icon'                => 'dashicons-plus-alt',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_profile_complete',
			'label'               => 'Complete extended profile',
			'description'         => 'Awarded once when a member saves their extended BuddyPress profile.',
			'hook'                => 'xprofile_updated_profile',
			'user_callback'       => fn( int $user_id ) => $user_id,
			'default_points'      => 15,
			'category'            => 'buddypress',
			'icon'                => 'dashicons-id-alt',
			'repeatable'          => false,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_reactions_received',
			'label'               => 'Receive a reaction',
			'description'         => 'Awarded when a member receives a reaction on their activity.',
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
			'icon'                => 'dashicons-heart',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_polls_created',
			'label'               => 'Create a poll',
			'description'         => 'Awarded when a member creates a BuddyPress poll.',
			'hook'                => 'bp_polls_created',
			'user_callback'       => fn( int $poll_id, int $user_id ) => $user_id,
			'default_points'      => 10,
			'category'            => 'buddypress',
			'icon'                => 'dashicons-chart-bar',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

		[
			'id'                  => 'bp_publish_post',
			'label'               => 'Publish a member blog post',
			'description'         => 'Awarded when a member publishes a post via BP Member Blog.',
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
			'icon'                => 'dashicons-admin-post',
			'repeatable'          => true,
			'requires_buddypress' => true,
		],

	],
];
