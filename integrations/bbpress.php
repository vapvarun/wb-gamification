<?php
/**
 * WB Gamification — bbPress Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when bbPress is active.
 *
 * Actions covered:
 *   New topic created      — bbp_new_topic
 *   New reply posted       — bbp_new_reply
 *   Topic closed           — bbp_closed_topic (topic author)
 *
 * Note: These run as standalone_only: false — they fire alongside BuddyPress
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

return [
	'plugin'   => 'bbPress',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'bbp_new_topic',
			'label'          => 'Create a forum topic',
			'description'    => 'Awarded when a member creates a new bbPress topic.',
			'hook'           => 'bbp_new_topic',
			'user_callback'  => function ( int $topic_id, int $forum_id, array $anonymous_data, int $topic_author ): int {
				return $topic_author > 0 ? $topic_author : (int) get_current_user_id();
			},
			'default_points' => 10,
			'category'       => 'social',
			'icon'           => 'dashicons-format-chat',
			'repeatable'     => true,
			'cooldown'       => 300,
		],

		[
			'id'             => 'bbp_new_reply',
			'label'          => 'Post a forum reply',
			'description'    => 'Awarded when a member posts a reply in any bbPress topic.',
			'hook'           => 'bbp_new_reply',
			'user_callback'  => function ( int $reply_id, int $topic_id, int $forum_id, array $anonymous_data, int $reply_author ): int {
				return $reply_author > 0 ? $reply_author : (int) get_current_user_id();
			},
			'default_points' => 5,
			'category'       => 'social',
			'icon'           => 'dashicons-admin-comments',
			'repeatable'     => true,
			'cooldown'       => 60,
		],

		[
			'id'                => 'bbp_topic_closed',
			'label'             => 'Topic resolved / closed',
			'description'       => 'Awarded to the topic author when their topic is closed (resolved).',
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
			'default_points'    => 20,
			'category'          => 'social',
			'icon'              => 'dashicons-yes-alt',
			'repeatable'        => true,
			'cooldown'          => 0,
		],

	],
];
