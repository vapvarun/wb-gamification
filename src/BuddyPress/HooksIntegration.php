<?php
/**
 * BuddyPress Hooks Integration
 *
 * Registers all default BuddyPress actions as gamification triggers.
 * Fires on 'wb_gamification_register' — after the Registry is initialized.
 *
 * Phase 0: will be replaced by integrations/buddypress.php manifest.
 *
 * @package WB_Gamification
 */

namespace WBGam\BuddyPress;

defined( 'ABSPATH' ) || exit;

final class HooksIntegration {

	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		add_action( 'wb_gamification_register', [ self::class, 'register_actions' ] );
	}

	public static function register_actions(): void {
		$actions = [
			[
				'id'             => 'bp_activity_post',
				'label'          => __( 'Post an activity update', 'wb-gamification' ),
				'hook'           => 'bp_activity_posted_update',
				'user_callback'  => fn( $content, $user_id ) => $user_id,
				'default_points' => 10,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-edit',
				'repeatable'     => true,
				'cooldown'       => 60,
			],
			[
				'id'             => 'bp_activity_comment',
				'label'          => __( 'Comment on an activity', 'wb-gamification' ),
				'hook'           => 'bp_activity_comment_posted',
				'user_callback'  => fn( $comment_id, $params ) => $params['user_id'] ?? get_current_user_id(),
				'default_points' => 5,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-format-chat',
				'repeatable'     => true,
				'cooldown'       => 30,
			],
			[
				'id'             => 'bp_friendship_accepted',
				'label'          => __( 'Accept a friendship', 'wb-gamification' ),
				'hook'           => 'friends_friendship_accepted',
				'user_callback'  => fn( $friendship_id, $friendship ) => $friendship->initiator_user_id ?? get_current_user_id(),
				'default_points' => 8,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-groups',
				'repeatable'     => true,
				'cooldown'       => 0,
			],
			[
				'id'             => 'bp_group_join',
				'label'          => __( 'Join a group', 'wb-gamification' ),
				'hook'           => 'groups_join_group',
				'user_callback'  => fn( $group_id, $user_id ) => $user_id,
				'default_points' => 8,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-admin-users',
				'repeatable'     => true,
				'cooldown'       => 0,
			],
			[
				'id'             => 'bp_group_create',
				'label'          => __( 'Create a group', 'wb-gamification' ),
				'hook'           => 'groups_group_create_complete',
				'user_callback'  => fn( $group_id ) => get_current_user_id(),
				'default_points' => 20,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-plus-alt',
				'repeatable'     => true,
				'cooldown'       => 0,
			],
			[
				'id'             => 'bp_profile_update',
				'label'          => __( 'Update extended profile', 'wb-gamification' ),
				'hook'           => 'xprofile_updated_profile',
				'user_callback'  => fn( $user_id ) => $user_id,
				'default_points' => 15,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-id',
				'repeatable'     => false,
				'cooldown'       => 0,
			],
			[
				'id'             => 'bp_media_upload',
				'label'          => __( 'Upload media', 'wb-gamification' ),
				'hook'           => 'bp_media_add',
				'user_callback'  => fn( $media_id ) => get_current_user_id(),
				'default_points' => 5,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-format-image',
				'repeatable'     => true,
				'cooldown'       => 60,
			],
			[
				'id'             => 'bbp_forum_reply',
				'label'          => __( 'Reply in a forum', 'wb-gamification' ),
				'hook'           => 'bbp_new_reply',
				'user_callback'  => fn( $reply_id ) => (int) bbp_get_reply_author_id( $reply_id ),
				'default_points' => 8,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-format-aside',
				'repeatable'     => true,
				'cooldown'       => 30,
			],
			[
				'id'             => 'bp_reaction_received',
				'label'          => __( 'Receive a reaction on content', 'wb-gamification' ),
				'hook'           => 'bp_reactions_add',
				'user_callback'  => fn( $reaction_id ) => get_current_user_id(),
				'default_points' => 3,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-heart',
				'repeatable'     => true,
				'cooldown'       => 0,
			],
			[
				'id'             => 'bp_poll_create',
				'label'          => __( 'Create a poll', 'wb-gamification' ),
				'hook'           => 'bp_polls_created',
				'user_callback'  => fn( $poll_id ) => get_current_user_id(),
				'default_points' => 10,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-chart-bar',
				'repeatable'     => true,
				'cooldown'       => 300,
			],
			[
				'id'             => 'member_blog_publish',
				'label'          => __( 'Publish a member blog post', 'wb-gamification' ),
				'hook'           => 'publish_post',
				'user_callback'  => fn( $post_id ) => (int) get_post_field( 'post_author', $post_id ),
				'default_points' => 25,
				'category'       => 'buddypress',
				'icon'           => 'dashicons-admin-post',
				'repeatable'     => true,
				'cooldown'       => 0,
			],
		];

		foreach ( $actions as $action ) {
			wb_gamification_register_action( $action );
		}
	}
}
