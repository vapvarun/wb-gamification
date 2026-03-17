<?php
/**
 * WordPress Native Hooks Integration
 *
 * Registers WordPress-native gamification triggers. Works without BuddyPress.
 *
 * Two groups:
 *   - always()    : hooks that fire in every WordPress install (BuddyPress or not)
 *   - standalone() : hooks only registered when BuddyPress is NOT active
 *                    (BuddyPress hooks cover the equivalent when BP is present)
 *
 * Phase 0: will be replaced by integrations/wordpress.php manifest.
 *
 * @package WB_Gamification
 */

namespace WBGam\Integrations\WordPress;

use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

final class HooksIntegration {

	public static function init(): void {
		add_action( 'wb_gamification_register', array( self::class, 'register_always' ) );

		if ( ! function_exists( 'buddypress' ) ) {
			add_action( 'wb_gamification_register', array( self::class, 'register_standalone' ) );
		}
	}

	/**
	 * Triggers available on ALL WordPress installs — BuddyPress has no equivalent.
	 */
	public static function register_always(): void {
		$actions = array(
			array(
				'id'             => 'wp_user_register',
				'label'          => __( 'Join the site', 'wb-gamification' ),
				'description'    => __( 'Awarded once when a user registers.', 'wb-gamification' ),
				'hook'           => 'user_register',
				'user_callback'  => fn( int $user_id ) => $user_id,
				'default_points' => 15,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-admin-users',
				'repeatable'     => false,
				'cooldown'       => 0,
			),
			array(
				'id'             => 'wp_first_login',
				'label'          => __( 'First login', 'wb-gamification' ),
				'description'    => __( 'Awarded once on the very first login.', 'wb-gamification' ),
				'hook'           => 'wp_login',
				'user_callback'  => fn( string $user_login, \WP_User $user ) => $user->ID,
				'default_points' => 10,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-lock',
				'repeatable'     => false,
				'cooldown'       => 0,
			),
			array(
				'id'             => 'wp_profile_complete',
				'label'          => __( 'Complete WordPress profile', 'wb-gamification' ),
				'description'    => __( 'Awarded once when the user saves their WP profile with a bio.', 'wb-gamification' ),
				'hook'           => 'personal_options_update',
				'user_callback'  => function ( int $user_id ): int {
					return get_user_meta( $user_id, 'description', true ) ? $user_id : 0;
				},
				'default_points' => 10,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-id-alt',
				'repeatable'     => false,
				'cooldown'       => 0,
			),
			array(
				'id'             => 'wp_post_receives_comment',
				'label'          => __( 'Post receives a comment', 'wb-gamification' ),
				'description'    => __( 'Post author earns points when an approved comment is left on their content.', 'wb-gamification' ),
				'hook'           => 'comment_post',
				'user_callback'  => function ( int $comment_id, int|string $approved ): int {
					if ( 1 !== (int) $approved ) {
						return 0;
					}
					$comment = get_comment( $comment_id );
					if ( ! $comment ) {
						return 0;
					}
					$post = get_post( $comment->comment_post_ID );
					return $post ? (int) $post->post_author : 0;
				},
				'default_points' => 3,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-admin-comments',
				'repeatable'     => true,
				'cooldown'       => 0,
			),
		);

		foreach ( $actions as $action ) {
			wb_gamification_register_action( $action );
		}
	}

	/**
	 * Triggers only registered when BuddyPress is NOT active.
	 */
	public static function register_standalone(): void {
		$actions = array(
			array(
				'id'             => 'wp_publish_post',
				'label'          => __( 'Publish a blog post', 'wb-gamification' ),
				'description'    => __( 'Awarded when the author publishes a new post.', 'wb-gamification' ),
				'hook'           => 'publish_post',
				'user_callback'  => function ( int $post_id ): int {
					$post = get_post( $post_id );
					if ( ! $post || 'post' !== $post->post_type ) {
						return 0;
					}
					return (int) $post->post_author;
				},
				'default_points' => 25,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-admin-post',
				'repeatable'     => true,
				'cooldown'       => 0,
			),
			array(
				'id'             => 'wp_first_post',
				'label'          => __( 'Publish first post ever', 'wb-gamification' ),
				'description'    => __( 'Awarded once on the author\'s very first published post.', 'wb-gamification' ),
				'hook'           => 'publish_post',
				'user_callback'  => function ( int $post_id ): int {
					$post = get_post( $post_id );
					if ( ! $post || 'post' !== $post->post_type ) {
						return 0;
					}
					$author_id  = (int) $post->post_author;
					$post_count = (int) count_user_posts( $author_id, 'post' );
					return 1 === $post_count ? $author_id : 0;
				},
				'default_points' => 20,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-star-filled',
				'repeatable'     => false,
				'cooldown'       => 0,
			),
			array(
				'id'             => 'wp_leave_comment',
				'label'          => __( 'Leave a comment', 'wb-gamification' ),
				'description'    => __( 'Commenter earns points when their approved comment is posted.', 'wb-gamification' ),
				'hook'           => 'comment_post',
				'user_callback'  => function ( int $comment_id, int|string $approved ): int {
					if ( 1 !== (int) $approved ) {
						return 0;
					}
					$comment = get_comment( $comment_id );
					if ( ! $comment || empty( $comment->user_id ) ) {
						return 0;
					}
					return (int) $comment->user_id;
				},
				'default_points' => 5,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-format-chat',
				'repeatable'     => true,
				'cooldown'       => 60,
			),
			array(
				'id'             => 'wp_comment_approved',
				'label'          => __( 'Comment moves from pending to approved', 'wb-gamification' ),
				'description'    => __( 'Awarded when a previously moderated comment gets approved.', 'wb-gamification' ),
				'hook'           => 'comment_approved_1',
				'user_callback'  => function ( \WP_Comment $comment ): int {
					return (int) $comment->user_id;
				},
				'default_points' => 5,
				'category'       => 'wordpress',
				'icon'           => 'dashicons-yes-alt',
				'repeatable'     => true,
				'cooldown'       => 0,
			),
		);

		// The comment_approved transition needs special wiring.
		add_action(
			'transition_comment_status',
			function ( string $new_status, string $old_status, \WP_Comment $comment ): void {
				if ( 'approved' !== $new_status || 'approved' === $old_status ) {
					return;
				}
				if ( empty( $comment->user_id ) ) {
					return;
				}
				PointsEngine::process_action( 'wp_comment_approved', (int) $comment->user_id );
			},
			10,
			3
		);

		foreach ( $actions as $action ) {
			if ( 'wp_comment_approved' === $action['id'] ) {
				continue;
			}
			wb_gamification_register_action( $action );
		}
	}
}
