<?php
/**
 * WB Gamification — WordPress Core Integration Manifest
 *
 * Auto-loaded by ManifestLoader. No dependency on WB Gamification at load time.
 *
 * Trigger groups:
 *   standalone_only: false — always active regardless of BuddyPress
 *   standalone_only: true  — skipped when BuddyPress is active (BP covers the same event)
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

return [
	'plugin'   => 'WordPress',
	'version'  => '1.0.0',
	'triggers' => [

		// ── Always-on triggers (Standalone + Community + Full) ──────────────

		[
			'id'             => 'wp_user_register',
			'label'          => 'Join the site',
			'description'    => 'Awarded once when a user registers.',
			'hook'           => 'user_register',
			'user_callback'  => fn( int $user_id ) => $user_id,
			'default_points' => 15,
			'category'       => 'wordpress',
			'icon'           => 'dashicons-admin-users',
			'repeatable'     => false,
			'standalone_only' => false,
		],

		[
			'id'             => 'wp_first_login',
			'label'          => 'First login',
			'description'    => 'Awarded once on the very first login.',
			'hook'           => 'wp_login',
			'user_callback'  => fn( string $user_login, \WP_User $user ) => $user->ID,
			'default_points' => 10,
			'category'       => 'wordpress',
			'icon'           => 'dashicons-lock',
			'repeatable'     => false,
			'standalone_only' => false,
		],

		[
			'id'             => 'wp_profile_complete',
			'label'          => 'Complete WordPress profile',
			'description'    => 'Awarded once when the user saves their WP profile with a bio.',
			'hook'           => 'personal_options_update',
			'user_callback'  => function ( int $user_id ): int {
				return get_user_meta( $user_id, 'description', true ) ? $user_id : 0;
			},
			'default_points' => 10,
			'category'       => 'wordpress',
			'icon'           => 'dashicons-id-alt',
			'repeatable'     => false,
			'standalone_only' => false,
		],

		[
			'id'             => 'wp_post_receives_comment',
			'label'          => 'Post receives a comment',
			'description'    => 'Post author earns points when an approved comment is left on their content.',
			'hook'           => 'comment_post',
			'user_callback'  => function ( int $comment_id, int|string $approved ): int {
				if ( 1 !== (int) $approved ) {
					return 0;
				}
				$comment = get_comment( $comment_id );
				if ( ! $comment ) {
					return 0;
				}
				// Skip product reviews — WooCommerce manifest handles those separately.
				$post = get_post( $comment->comment_post_ID );
				if ( ! $post ) {
					return 0;
				}
				if ( 'product' === $post->post_type ) {
					return 0;
				}
				return (int) $post->post_author;
			},
			'default_points' => 3,
			'category'       => 'wordpress',
			'icon'           => 'dashicons-admin-comments',
			'repeatable'     => true,
			'standalone_only' => false,
		],

		// ── Standalone-only triggers (Standalone mode — no BuddyPress) ──────

		[
			'id'              => 'wp_publish_post',
			'label'           => 'Publish a blog post',
			'description'     => 'Awarded when the author publishes a new post.',
			'hook'            => 'publish_post',
			'user_callback'   => function ( int $post_id ): int {
				$post = get_post( $post_id );
				if ( ! $post || 'post' !== $post->post_type ) {
					return 0;
				}
				return (int) $post->post_author;
			},
			'default_points'  => 25,
			'category'        => 'wordpress',
			'icon'            => 'dashicons-admin-post',
			'repeatable'      => true,
			'standalone_only' => true,
		],

		[
			'id'              => 'wp_first_post',
			'label'           => 'Publish first post ever',
			'description'     => "Awarded once on the author's very first published post.",
			'hook'            => 'publish_post',
			'user_callback'   => function ( int $post_id ): int {
				$post = get_post( $post_id );
				if ( ! $post || 'post' !== $post->post_type ) {
					return 0;
				}
				$author_id  = (int) $post->post_author;
				$post_count = (int) count_user_posts( $author_id, 'post' );
				return 1 === $post_count ? $author_id : 0;
			},
			'default_points'  => 20,
			'category'        => 'wordpress',
			'icon'            => 'dashicons-star-filled',
			'repeatable'      => false,
			'standalone_only' => true,
		],

		[
			'id'              => 'wp_leave_comment',
			'label'           => 'Leave a comment',
			'description'     => 'Commenter earns points when their approved comment is posted.',
			'hook'            => 'comment_post',
			'user_callback'   => function ( int $comment_id, int|string $approved ): int {
				if ( 1 !== (int) $approved ) {
					return 0;
				}
				$comment = get_comment( $comment_id );
				if ( ! $comment || empty( $comment->user_id ) ) {
					return 0;
				}
				// Skip product reviews — WooCommerce manifest handles those separately.
				$post = get_post( $comment->comment_post_ID );
				if ( $post && 'product' === $post->post_type ) {
					return 0;
				}
				return (int) $comment->user_id;
			},
			'default_points'  => 5,
			'category'        => 'wordpress',
			'icon'            => 'dashicons-format-chat',
			'repeatable'      => true,
			'cooldown'        => 60,
			'standalone_only' => true,
		],

		[
			'id'              => 'wp_comment_approved',
			'label'           => 'Comment approved from moderation',
			'description'     => 'Awarded when a previously pending comment gets approved.',
			'hook'            => 'transition_comment_status',
			'user_callback'   => function ( string $new_status, string $old_status, \WP_Comment $comment ): int {
				if ( 'approved' !== $new_status || 'approved' === $old_status ) {
					return 0;
				}
				// Skip product reviews — WooCommerce manifest handles those separately.
				$post = get_post( $comment->comment_post_ID );
				if ( $post && 'product' === $post->post_type ) {
					return 0;
				}
				return (int) $comment->user_id;
			},
			'default_points'  => 5,
			'category'        => 'wordpress',
			'icon'            => 'dashicons-yes-alt',
			'repeatable'      => true,
			'standalone_only' => true,
		],

	],
];
