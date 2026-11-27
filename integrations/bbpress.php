<?php
/**
 * WB Gamification - bbPress Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when bbPress is active.
 *
 * Actions covered:
 *   New topic created      - bbp_new_topic
 *   New reply posted       - bbp_new_reply
 *   Topic closed           - bbp_closed_topic (topic author)
 *
 * Note: These run as standalone_only: false - they fire alongside BuddyPress
 * activity feed triggers because bbPress actions are structurally different
 * from BP activity updates (different content types, different communities).
 *
 * @package WB_Gamification
 * @see     https://bbpress.org/forums/
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bbpress' ) ) {
	return [];
}

// A restored topic or reply pays back only once it is public again (published, or a closed topic).
$wb_gam_bbp_public = static fn( $post_id ): int => in_array( get_post_status( (int) $post_id ), [ 'publish', 'closed' ], true ) ? (int) $post_id : 0;

return [
	'plugin'   => 'bbPress',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'              => 'bbp_new_topic',
			'label'           => static fn(): string => __( 'Create a forum topic', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a member creates a new bbPress topic.', 'wb-gamification' ),
			'hook'            => 'bbp_new_topic',
			'user_callback'   => function ( int $topic_id, int $forum_id, array $anonymous_data, int $topic_author ): int {
				return $topic_author > 0 ? $topic_author : (int) get_current_user_id();
			},
			// A trashed, spammed or deleted topic takes its award back; untrashed or unspammed and
			// public again gives it back.
			'once_per_object' => static fn( $topic_id ): int => (int) $topic_id,
			'reverse_on'      => [
				'bbp_trashed_topic' => static fn( $topic_id ): int => (int) $topic_id,
				'bbp_spammed_topic' => static fn( $topic_id ): int => (int) $topic_id,
				'bbp_deleted_topic' => static fn( $topic_id ): int => (int) $topic_id,
			],
			'restore_on'      => [
				'bbp_untrashed_topic' => $wb_gam_bbp_public,
				'bbp_unspammed_topic' => $wb_gam_bbp_public,
			],
			'default_points'  => 10,
			'category'        => 'forums',
			'icon'            => 'icon-message-square',
			'repeatable'      => true,
			'async'           => false,
			'cooldown'        => 300,
		],

		[
			'id'              => 'bbp_new_reply',
			'label'           => static fn(): string => __( 'Post a forum reply', 'wb-gamification' ),
			'description'     => static fn(): string => __( 'Awarded when a member posts a reply in any bbPress topic.', 'wb-gamification' ),
			'hook'            => 'bbp_new_reply',
			'user_callback'   => function ( int $reply_id, int $topic_id, int $forum_id, array $anonymous_data, int $reply_author ): int {
				return $reply_author > 0 ? $reply_author : (int) get_current_user_id();
			},
			'once_per_object' => static fn( $reply_id ): int => (int) $reply_id,
			'reverse_on'      => [
				'bbp_trashed_reply' => static fn( $reply_id ): int => (int) $reply_id,
				'bbp_spammed_reply' => static fn( $reply_id ): int => (int) $reply_id,
				'bbp_deleted_reply' => static fn( $reply_id ): int => (int) $reply_id,
			],
			'restore_on'      => [
				'bbp_untrashed_reply' => $wb_gam_bbp_public,
				'bbp_unspammed_reply' => $wb_gam_bbp_public,
			],
			'default_points'  => 5,
			'category'        => 'forums',
			'icon'            => 'icon-message-circle',
			'repeatable'      => true,
			'async'           => false,
			'cooldown'        => 60,
		],

		[
			'id'                => 'bbp_topic_closed',
			'label'             => static fn(): string => __( 'Have a forum topic closed', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the topic author when their topic is closed (resolved).', 'wb-gamification' ),
			// bbPress fires: do_action( 'bbp_closed_topic', $topic_id ).
			// Note: 'bbp_toggle_topic_close' is an admin-action string CONSTANT, not a hook.
			'hook'              => 'bbp_closed_topic',
			'user_callback'     => function ( int $topic_id ): int {
				$topic = get_post( $topic_id );
				return $topic ? (int) $topic->post_author : 0;
			},
			'metadata_callback' => function ( int $topic_id ): array {
				return array( 'topic_id' => $topic_id );
			},
			'once_per_object'   => static fn( int $topic_id ): int => $topic_id,
			// Reopening the topic takes the points back; closing it again pays again.
			'reverse_on'        => array(
				'bbp_opened_topic'  => static fn( $topic_id ): int => (int) $topic_id,
				// The topic itself going away takes it back too, as for Create a forum topic.
				'bbp_trashed_topic' => static fn( $topic_id ): int => (int) $topic_id,
				'bbp_spammed_topic' => static fn( $topic_id ): int => (int) $topic_id,
				'bbp_deleted_topic' => static fn( $topic_id ): int => (int) $topic_id,
			),
			// Back from trash or spam still closed: the points come back.
			'restore_on'        => array(
				'bbp_untrashed_topic' => static fn( $topic_id ): int => 'closed' === get_post_status( (int) $topic_id ) ? (int) $topic_id : 0,
				'bbp_unspammed_topic' => static fn( $topic_id ): int => 'closed' === get_post_status( (int) $topic_id ) ? (int) $topic_id : 0,
			),
			'async'             => false,
			'default_points'    => 20,
			'category'          => 'social',
			'icon'              => 'icon-circle-check',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

	],
];
