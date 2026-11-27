<?php
/**
 * WB Gamification - BuddyNext (Free) Integration Manifest
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

// Who wrote a BuddyNext post: acting on your own post (comment, vote, bookmark, share) earns nothing.
$wb_gam_bn_post_owner = static function ( $post_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", (int) $post_id ) );
};

return array(
	'plugin'   => 'BuddyNext',
	'version'  => '1.0.0',
	'triggers' => array(

		// -----------------------------------------------------------------------
		// Content
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_post_created',
			'label'             => static fn(): string => __( 'Create a post', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded each time a member creates a post. Daily cap prevents farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_post_created', int $post_id, int $user_id, string $type ).
			'hook'              => 'buddynext_post_created',
			'user_callback'     => function ( int $post_id, int $user_id, string $type ): int {
				// Only what the member wrote. BuddyNext also creates feed cards for content
				// another plugin already rewarded (blog post, listing, job, course, event,
				// badge share) - those go through IntegrationActivity - and a share fires
				// buddynext_post_shared, which bn_post_shared pays. Paying here too doubled
				// every cross-posted award.
				if ( 'share' === $type ) {
					return 0;
				}
				if ( \WBGam\Engine\HostActivity::is_copy() ) {
					return 0;
				}
				return $user_id;
			},
			'metadata_callback' => function ( int $post_id, int $user_id, string $type ): array {
				return array(
					'post_id' => $post_id,
					'type'    => $type,
				);
			},
			// A deleted post takes its award back. BuddyNext passes the deleter (0 for a system delete),
			// so the award is found by the post, not by that user.
			'once_per_object'   => static fn( $post_id ): int => (int) $post_id,
			'reverse_on'        => array( 'buddynext_post_deleted' => static fn( $post_id ): int => (int) $post_id ),
			'default_points'    => 5,
			'category'          => 'content',
			'icon'              => 'icon-file-text',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
			'daily_cap'         => 20,
		),

		array(
			'id'                => 'bn_post_shared',
			'label'             => static fn(): string => __( 'Share a post', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member shares an existing post. Daily cap limits farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_post_shared', int $share_id, int $post_id, int $user_id ).
			'hook'              => 'buddynext_post_shared',
			'user_callback'     => function ( int $share_id, int $post_id, int $user_id ): int {
				return $user_id;
			},
			'owner_callback'    => static fn( $share_id, $post_id ): int => $wb_gam_bn_post_owner( $post_id ),
			'metadata_callback' => function ( int $share_id, int $post_id, int $user_id ): array {
				return array(
					'share_id' => $share_id,
					'post_id'  => $post_id,
				);
			},
			// Per sharer and original post (a re-share gets a new share id): unsharing, or deleting the
			// repost card, takes it back, so share/unshare loops net to one award.
			'once_per_object'   => static fn( int $share_id, int $post_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $post_id ),
			'reverse_on'        => array(
				'buddynext_post_unshared' => static fn( $share_id, $post_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $post_id ),
			),
			'default_points'    => 5,
			'category'          => 'content',
			'icon'              => 'icon-share-2',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 10,
		),

		array(
			'id'                => 'bn_comment_created',
			'label'             => static fn(): string => __( 'Write a comment', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member comments on a post. Cooldown prevents rapid-fire farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_comment_created', int $comment_id, string $object_type, int $object_id, int $user_id ).
			'hook'              => 'buddynext_comment_created',
			'user_callback'     => function ( int $comment_id, string $object_type, int $object_id, int $user_id ): int {
				// BuddyNext mirrors a WordPress blog comment onto the post's feed card from inside
				// wp_insert_comment. The commenter was already paid for that comment
				// (wp_leave_comment), so the mirror earns nothing.
				if ( doing_action( 'wp_insert_comment' ) ) {
					return 0;
				}
				// A MediaVerse lightbox comment or a Jetonomy reply copied onto a feed card was
				// already paid in its own plugin.
				if ( \WBGam\Engine\HostActivity::is_copy() ) {
					return 0;
				}
				return $user_id;
			},
			'metadata_callback' => function ( int $comment_id, string $object_type, int $object_id, int $user_id ): array {
				return array(
					'comment_id'  => $comment_id,
					'object_type' => $object_type,
					'object_id'   => $object_id,
				);
			},
			'once_per_object'   => static fn( $comment_id ): int => (int) $comment_id,
			// A deleted post removes its comments in bulk and reports them once per post, not per comment.
			'reverse_on'        => array(
				'buddynext_comment_deleted'       => static fn( $comment_id ): int => (int) $comment_id,
				'buddynext_post_comments_deleted' => static fn( $comment_ids ): array => array_map( 'intval', (array) $comment_ids ),
			),
			'owner_callback'    => static fn( $comment_id, $object_type, $object_id ): int => 'post' === $object_type ? $wb_gam_bn_post_owner( $object_id ) : 0,
			'default_points'    => 3,
			'category'          => 'content',
			'icon'              => 'icon-message-square',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 30,
		),

		array(
			'id'                => 'bn_reaction_received',
			'label'             => static fn(): string => __( 'Receive a reaction on your content', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the content owner when another member reacts to their post. BuddyNext only fires this for cross-user reactions (self-reactions are excluded upstream in ReactionService).', 'wb-gamification' ),
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
			'once_per_object'   => static fn( int $object_id, int $author_id, int $reactor_id ): int => \WBGam\Engine\Registry::object_key( $reactor_id, 'post', $object_id ),
			// Per reactor and post: removing the reaction takes the author's points back.
			'reverse_on'        => array(
				'buddynext_reaction_removed' => static fn( $object_type, $object_id, $user_id ): int => 'post' === $object_type ? \WBGam\Engine\Registry::object_key( (int) $user_id, 'post', (int) $object_id ) : 0,
				// A deleted post takes its reactions with it and names only its author (arg 3), so the
				// author's awards for that post are found by the post id their events recorded.
				'buddynext_post_deleted'     => static fn( $post_id, $user_id = 0, $author_id = 0 ): array => \WBGam\Engine\PointsEngine::object_ids_by_meta( (int) $author_id, 'bn_reaction_received', 'post_id', (int) $post_id ),
			),
			'default_points'    => 2,
			'category'          => 'content',
			'icon'              => 'icon-heart',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 20,
		),

		array(
			'id'                => 'bn_poll_voted',
			'label'             => static fn(): string => __( 'Vote on a poll', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member votes on a poll. Daily cap prevents ballot-box farming.', 'wb-gamification' ),
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
			// Per voter and poll: a vote taken back (toggle off, or the old side of a switch) takes the
			// point back, so a switch or a vote/remove loop nets to one award.
			'once_per_object'   => static fn( int $post_id, int $option_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $post_id ),
			'reverse_on'        => array(
				'buddynext_poll_vote_removed' => static fn( $post_id, $option_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $post_id ),
			),
			'owner_callback'    => static fn( $post_id ): int => $wb_gam_bn_post_owner( $post_id ),
			'default_points'    => 1,
			'category'          => 'content',
			'icon'              => 'icon-chart-no-axes-column',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'bn_post_bookmarked',
			'label'             => static fn(): string => __( 'Bookmark a post', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded the first time a member bookmarks a post. Daily cap limits farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_post_bookmarked', int $post_id, int $user_id ), again after a remove and re-add.
			'hook'              => 'buddynext_post_bookmarked',
			'once_per_object'   => static fn( int $post_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $post_id ),
			'user_callback'     => function ( int $post_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $post_id, int $user_id ): array {
				return array( 'post_id' => $post_id );
			},
			'reverse_on'        => array(
				'buddynext_post_unbookmarked' => static fn( $post_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $post_id ),
			),
			'owner_callback'    => static fn( $post_id ): int => $wb_gam_bn_post_owner( $post_id ),
			'default_points'    => 1,
			'category'          => 'content',
			'icon'              => 'icon-bookmark',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		// -----------------------------------------------------------------------
		// Social
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_followed',
			'label'             => static fn(): string => __( 'Gain a follower', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member who gains a new follower. Daily cap prevents follow-farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_follower_gained', int $following_id, int $follower_id ).
			// The recipient is the member who GAINED the follower ($following_id = arg1).
			'hook'              => 'buddynext_follower_gained',
			'user_callback'     => function ( int $following_id, int $follower_id ): int {
				// BuddyNext copy of a follow/comment another plugin already paid for.
				if ( \WBGam\Engine\HostActivity::is_copy() ) {
					return 0;
				}
				return $following_id;
			},
			'metadata_callback' => function ( int $following_id, int $follower_id ): array {
				return array( 'follower_id' => $follower_id );
			},
			'once_per_object'   => static fn( int $following_id, int $follower_id ): int => \WBGam\Engine\Registry::object_key( $follower_id, $following_id ),
			// Per follower and followed member: an unfollow takes it back, a refollow pays again.
			'reverse_on'        => array(
				'buddynext_user_unfollowed' => static fn( $follower_id, $following_id ): int => \WBGam\Engine\Registry::object_key( (int) $follower_id, (int) $following_id ),
			),
			'default_points'    => 5,
			'category'          => 'social',
			'icon'              => 'icon-user-plus',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 10,
		),

		array(
			'id'             => 'bn_first_follow',
			'label'          => static fn(): string => __( 'Follow a member for the first time', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a member follows their first person.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_user_followed_first_time', int $follower_id, int $following_id ).
			'hook'           => 'buddynext_user_followed_first_time',
			'user_callback'  => function ( int $follower_id, int $following_id ): int {
				// BuddyNext copy of a follow/comment another plugin already paid for.
				if ( \WBGam\Engine\HostActivity::is_copy() ) {
					return 0;
				}
				return $follower_id;
			},
			'permanent'      => 'A one-time milestone.',
			'default_points' => 5,
			'category'       => 'social',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		),

		array(
			'id'                => 'bn_connected',
			'label'             => static fn(): string => __( 'Have a connection request accepted', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the member who sent a connection request when it is accepted.', 'wb-gamification' ),
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
			'once_per_object'   => static fn( int $connection_id, int $user_a, int $user_b ): int => \WBGam\Engine\Registry::object_key( min( $user_a, $user_b ), max( $user_a, $user_b ) ),
			// Keyed by the pair, lower id first: BuddyNext reports a removal by the two members, not the connection id.
			'reverse_on'        => array(
				'buddynext_connection_removed' => static fn( $user_a, $user_b ): int => \WBGam\Engine\Registry::object_key( min( (int) $user_a, (int) $user_b ), max( (int) $user_a, (int) $user_b ) ),
			),
			'default_points'    => 10,
			'category'          => 'social',
			'icon'              => 'icon-link',
			'repeatable'        => true,
			'async'             => false,
		),

		// -----------------------------------------------------------------------
		// Community
		// -----------------------------------------------------------------------

		array(
			'id'                => 'bn_space_joined',
			'label'             => static fn(): string => __( 'Join a space', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member joins a community space. Daily cap prevents bulk-join farming.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_space_member_joined', int $space_id, int $user_id, string $role ).
			'hook'              => 'buddynext_space_member_joined',
			'user_callback'     => function ( int $space_id, int $user_id, string $role ): int {
				// A member BuddyNext copies into a space from its linked forum joined once, and was
				// paid there (jetonomy_space_joined).
				return \WBGam\Engine\HostActivity::is_copy() ? 0 : $user_id;
			},
			'metadata_callback' => function ( int $space_id, int $user_id, string $role ): array {
				return array(
					'space_id' => $space_id,
					'role'     => $role,
				);
			},
			'once_per_object'   => static fn( int $space_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $space_id ),
			'reverse_on'        => array(
				'buddynext_space_member_left'    => static fn( $space_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $space_id ),
				'buddynext_space_member_removed' => static fn( $space_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $space_id ),
			),
			'default_points'    => 10,
			'category'          => 'community',
			'icon'              => 'icon-users',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'bn_space_created',
			'label'             => static fn(): string => __( 'Create a space', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member creates a new community space.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_space_created', int $space_id, int $owner_id ).
			'hook'              => 'buddynext_space_created',
			'user_callback'     => function ( int $space_id, int $owner_id ): int {
				return $owner_id;
			},
			'metadata_callback' => function ( int $space_id, int $owner_id ): array {
				return array( 'space_id' => $space_id );
			},
			'once_per_object'   => static fn( int $space_id ): int => $space_id,
			'reverse_on'        => array(
				'buddynext_space_deleted' => static fn( $space_id ): int => (int) $space_id,
			),
			'default_points'    => 10,
			'category'          => 'community',
			'icon'              => 'icon-panels-top-left',
			'repeatable'        => true,
			'async'             => false,
		),

		// -----------------------------------------------------------------------
		// Profile & Onboarding
		// -----------------------------------------------------------------------

		array(
			'id'             => 'bn_profile_completed',
			'label'          => static fn(): string => __( 'Complete your profile', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a member completes every task on their Profile Strength checklist.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_profile_strength_changed', int $user_id, int $percent ).
			// Strength (BuddyNext 1.0.5+) is the curated checklist the member
			// actually sees on the Profile Strength ring - the old
			// completion_changed hook scores EVERY schema field, so its 100%
			// could stay unreachable while the member's widget said "All set"
			// and this award silently never fired.
			'hook'           => 'buddynext_profile_strength_changed',
			'user_callback'  => function ( int $user_id, int $percent ): int {
				return 100 === (int) $percent ? $user_id : 0;
			},
			'permanent'      => 'A one-time profile milestone.',
			'default_points' => 25,
			'category'       => 'community',
			'icon'           => 'icon-circle-check',
			'repeatable'     => false,
		),

		array(
			'id'             => 'bn_onboarding_completed',
			'label'          => static fn(): string => __( 'Complete onboarding', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded once when a member completes the BuddyNext onboarding wizard.', 'wb-gamification' ),
			// Fires: do_action( 'buddynext_onboarding_completed', int $user_id ).
			'hook'           => 'buddynext_onboarding_completed',
			'user_callback'  => function ( int $user_id ): int {
				return $user_id;
			},
			'permanent'      => 'A one-time milestone.',
			'default_points' => 20,
			'category'       => 'community',
			'icon'           => 'icon-flag',
			'repeatable'     => false,
		),

	),
);
