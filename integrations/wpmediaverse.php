<?php
/**
 * WB Gamification — WPMediaVerse Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Provides dedicated gamification support for
 * both WPMediaVerse (Free) and WPMediaVerse Pro from inside this plugin —
 * neither MVS plugin needs to ship its own wb-gamification.php manifest.
 *
 * Gating:
 *   - Free triggers load when MVS_VERSION is defined (Free is active).
 *   - Pro triggers load when MVS_PRO_VERSION is ALSO defined (Pro is active).
 *
 * Extension surface:
 *   - Filter `wb_gam_wpmediaverse_triggers` — final triggers array. Use to
 *     add, remove, or tune triggers from Free, Pro, or any third-party plugin.
 *   - Filter `wb_gam_wpmediaverse_free_triggers` — Free-only subset.
 *   - Filter `wb_gam_wpmediaverse_pro_triggers` — Pro-only subset (only
 *     applied when Pro is active).
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

// Only load if WPMediaVerse (Free) is active. Pro extends Free, so Free is
// the source of truth for the MediaRepository class used by the callbacks.
if ( ! defined( 'MVS_VERSION' ) ) {
	return array();
}

$pro_active = defined( 'MVS_PRO_VERSION' );

$free_triggers = array(

	/*
	 * ---------------------------------------------------------------
	 * Content Creation
	 * ---------------------------------------------------------------
	 */

	array(
		'id'                => 'mvs_upload_photo',
		'label'             => __( 'Upload a photo', 'wb-gamification' ),
		'description'       => __( 'Awarded when a member uploads a photo or video.', 'wb-gamification' ),
		'hook'              => 'mvs_media_uploaded',
		'user_callback'     => function ( int $media_id, array $file_data ): int {
			return \WPMediaVerse\Repository\MediaRepository::get_author( $media_id );
		},
		'metadata_callback' => function ( int $media_id, array $file_data ): array {
			return array(
				'media_id'   => $media_id,
				'media_type' => \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'media_type' ),
				'file_type'  => isset( $file_data['type'] ) ? $file_data['type'] : '',
			);
		},
		'default_points'    => 10,
		'category'          => 'media',
		'icon'              => 'dashicons-camera',
		'repeatable'        => true,
		'cooldown'          => 5,
	),

	array(
		'id'             => 'mvs_create_album',
		'label'          => __( 'Create an album', 'wb-gamification' ),
		'description'    => __( 'Awarded when a member creates a new album.', 'wb-gamification' ),
		'hook'           => 'mvs_album_items_added',
		'user_callback'  => function ( int $album_id, array $media_ids, int $added ): int {
			return \WPMediaVerse\Repository\MediaRepository::get_author( $album_id );
		},
		'default_points' => 15,
		'category'       => 'media',
		'icon'           => 'dashicons-images-alt2',
		'repeatable'     => true,
		'cooldown'       => 10,
	),

	/*
	 * ---------------------------------------------------------------
	 * Engagement Received (awards media owner)
	 * ---------------------------------------------------------------
	 */

	array(
		'id'                => 'mvs_receive_like',
		'label'             => __( 'Receive a like on photo', 'wb-gamification' ),
		'description'       => __( 'Awarded to the media owner when someone likes their photo.', 'wb-gamification' ),
		'hook'              => 'mvs_reaction_added',
		'user_callback'     => function ( int $media_id, int $user_id, string $type ): int {
			$author = \WPMediaVerse\Repository\MediaRepository::get_author( $media_id );
			// Don't award for liking your own content.
			return ( $author && $author !== $user_id ) ? $author : 0;
		},
		'metadata_callback' => function ( int $media_id, int $user_id, string $type ): array {
			return array(
				'media_id'      => $media_id,
				'reactor_id'    => $user_id,
				'reaction_type' => $type,
			);
		},
		'default_points'    => 2,
		'category'          => 'media',
		'icon'              => 'dashicons-heart',
		'repeatable'        => true,
		'async'             => true,
	),

	array(
		'id'                => 'mvs_receive_comment',
		'label'             => __( 'Receive a comment on photo', 'wb-gamification' ),
		'description'       => __( 'Awarded to the media owner when someone comments on their photo.', 'wb-gamification' ),
		'hook'              => 'mvs_comment_created',
		'user_callback'     => function ( int $media_id, int $user_id, int $comment_id, string $content ): int {
			$author = \WPMediaVerse\Repository\MediaRepository::get_author( $media_id );
			return ( $author && $author !== $user_id ) ? $author : 0;
		},
		'metadata_callback' => function ( int $media_id, int $user_id, int $comment_id, string $content ): array {
			return array(
				'media_id'   => $media_id,
				'commenter'  => $user_id,
				'comment_id' => $comment_id,
				'word_count' => str_word_count( wp_strip_all_tags( $content ) ),
			);
		},
		'default_points'    => 5,
		'category'          => 'media',
		'icon'              => 'dashicons-admin-comments',
		'repeatable'        => true,
		'async'             => true,
	),

	array(
		'id'                => 'mvs_receive_follow',
		'label'             => __( 'Gain a new follower', 'wb-gamification' ),
		'description'       => __( 'Awarded when another member follows you.', 'wb-gamification' ),
		'hook'              => 'mvs_user_followed',
		'user_callback'     => function ( int $follower_id, int $following_id ): int {
			return $following_id;
		},
		'metadata_callback' => function ( int $follower_id, int $following_id ): array {
			return array(
				'follower_id' => $follower_id,
			);
		},
		'default_points'    => 3,
		'category'          => 'social',
		'icon'              => 'dashicons-groups',
		'repeatable'        => true,
	),

	array(
		'id'             => 'mvs_receive_favorite',
		'label'          => __( 'Photo bookmarked by someone', 'wb-gamification' ),
		'description'    => __( 'Awarded to the media owner when someone bookmarks their photo.', 'wb-gamification' ),
		'hook'           => 'mvs_favorite_toggled',
		'user_callback'  => function ( int $media_id, int $user_id, string $action ): int {
			if ( 'added' !== $action ) {
				return 0;
			}
			$author = \WPMediaVerse\Repository\MediaRepository::get_author( $media_id );
			return ( $author && $author !== $user_id ) ? $author : 0;
		},
		'default_points' => 2,
		'category'       => 'media',
		'icon'           => 'dashicons-star-filled',
		'repeatable'     => true,
		'async'          => true,
	),

	/*
	 * ---------------------------------------------------------------
	 * Engagement Given (awards the actor)
	 * ---------------------------------------------------------------
	 */

	array(
		'id'             => 'mvs_give_comment',
		'label'          => __( 'Write a meaningful comment', 'wb-gamification' ),
		'description'    => __( 'Awarded when a member leaves a comment of 20+ characters.', 'wb-gamification' ),
		'hook'           => 'mvs_comment_created',
		'user_callback'  => function ( int $media_id, int $user_id, int $comment_id, string $content ): int {
			// Only award for meaningful comments (20+ chars).
			return strlen( $content ) >= 20 ? $user_id : 0;
		},
		'default_points' => 3,
		'category'       => 'social',
		'icon'           => 'dashicons-format-chat',
		'repeatable'     => true,
		'cooldown'       => 30,
		'daily_cap'      => 20,
	),

	array(
		'id'             => 'mvs_give_follow',
		'label'          => __( 'Follow another member', 'wb-gamification' ),
		'description'    => __( 'Awarded when a member follows another user.', 'wb-gamification' ),
		'hook'           => 'mvs_user_followed',
		'user_callback'  => function ( int $follower_id, int $following_id ): int {
			return $follower_id;
		},
		'default_points' => 1,
		'category'       => 'social',
		'icon'           => 'dashicons-plus-alt2',
		'repeatable'     => true,
		'daily_cap'      => 50,
	),

	array(
		'id'             => 'mvs_bookmark_photo',
		'label'          => __( 'Bookmark a photo', 'wb-gamification' ),
		'description'    => __( 'Awarded when a member saves a photo to favorites.', 'wb-gamification' ),
		'hook'           => 'mvs_favorite_toggled',
		'user_callback'  => function ( int $media_id, int $user_id, string $action ): int {
			return 'added' === $action ? $user_id : 0;
		},
		'default_points' => 1,
		'category'       => 'social',
		'icon'           => 'dashicons-bookmark',
		'repeatable'     => true,
		'daily_cap'      => 30,
	),
);

/**
 * Filters the WPMediaVerse Free trigger set before any Pro additions.
 *
 * @since 1.0.0
 *
 * @param array $free_triggers Free trigger definitions.
 */
$free_triggers = apply_filters( 'wb_gam_wpmediaverse_free_triggers', $free_triggers );

$pro_triggers = array();

if ( $pro_active ) {

	$pro_triggers = array(

		/*
		 * ---------------------------------------------------------------
		 * Competition (Pro — battles, challenges, tournaments)
		 * ---------------------------------------------------------------
		 */

		array(
			'id'                => 'mvs_battle_win',
			'label'             => __( 'Win a photo battle', 'wb-gamification' ),
			'description'       => __( 'Awarded to the winner of a 1v1 photo battle.', 'wb-gamification' ),
			'hook'              => 'mvs_battle_resolved',
			'user_callback'     => function ( int $battle_id, int $winner_id, int $loser_id ): int {
				return $winner_id;
			},
			'metadata_callback' => function ( int $battle_id, int $winner_id, int $loser_id ): array {
				return array(
					'battle_id' => $battle_id,
					'loser_id'  => $loser_id,
				);
			},
			'default_points'    => 100,
			'category'          => 'competition',
			'icon'              => 'dashicons-awards',
			'repeatable'        => true,
		),

		array(
			'id'             => 'mvs_challenge_participate',
			'label'          => __( 'Enter a photo challenge', 'wb-gamification' ),
			'description'    => __( 'Awarded when a member submits an entry to a photo challenge.', 'wb-gamification' ),
			'hook'           => 'mvs_challenge_entry_submitted',
			'user_callback'  => function ( int $challenge_id, int $user_id, int $media_id ): int {
				return $user_id;
			},
			'default_points' => 10,
			'category'       => 'competition',
			'icon'           => 'dashicons-megaphone',
			'repeatable'     => true,
		),

		array(
			'id'             => 'mvs_challenge_win_1st',
			'label'          => __( 'Win 1st place in a photo challenge', 'wb-gamification' ),
			'description'    => __( 'Awarded to the 1st place winner of a photo challenge.', 'wb-gamification' ),
			'hook'           => 'mvs_challenge_finalized',
			'user_callback'  => function ( int $challenge_id, array $results ): int {
				return isset( $results['winner_1st'] ) ? (int) $results['winner_1st'] : 0;
			},
			'default_points' => 200,
			'category'       => 'competition',
			'icon'           => 'dashicons-trophy',
			'repeatable'     => true,
		),

		array(
			'id'             => 'mvs_challenge_win_2nd',
			'label'          => __( 'Win 2nd place in a photo challenge', 'wb-gamification' ),
			'description'    => __( 'Awarded to the 2nd place finisher of a photo challenge.', 'wb-gamification' ),
			'hook'           => 'mvs_challenge_finalized',
			'user_callback'  => function ( int $challenge_id, array $results ): int {
				return isset( $results['winner_2nd'] ) ? (int) $results['winner_2nd'] : 0;
			},
			'default_points' => 100,
			'category'       => 'competition',
			'icon'           => 'dashicons-awards',
			'repeatable'     => true,
		),

		array(
			'id'             => 'mvs_challenge_win_3rd',
			'label'          => __( 'Win 3rd place in a photo challenge', 'wb-gamification' ),
			'description'    => __( 'Awarded to the 3rd place finisher of a photo challenge.', 'wb-gamification' ),
			'hook'           => 'mvs_challenge_finalized',
			'user_callback'  => function ( int $challenge_id, array $results ): int {
				return isset( $results['winner_3rd'] ) ? (int) $results['winner_3rd'] : 0;
			},
			'default_points' => 50,
			'category'       => 'competition',
			'icon'           => 'dashicons-star-half',
			'repeatable'     => true,
		),

		array(
			'id'             => 'mvs_tournament_round_win',
			'label'          => __( 'Win a tournament round', 'wb-gamification' ),
			'description'    => __( 'Awarded for winning a round in a photo tournament.', 'wb-gamification' ),
			'hook'           => 'mvs_tournament_match_resolved',
			'user_callback'  => function ( int $match_id, int $winner_id ): int {
				return $winner_id;
			},
			'default_points' => 150,
			'category'       => 'competition',
			'icon'           => 'dashicons-shield',
			'repeatable'     => true,
		),

		array(
			'id'             => 'mvs_tournament_win',
			'label'          => __( 'Win a tournament', 'wb-gamification' ),
			'description'    => __( 'Awarded to the grand champion of a photo tournament.', 'wb-gamification' ),
			'hook'           => 'mvs_tournament_finalized',
			'user_callback'  => function ( int $tournament_id, int $winner_id ): int {
				return $winner_id;
			},
			'default_points' => 500,
			'category'       => 'competition',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => true,
		),

		/*
		 * ---------------------------------------------------------------
		 * Streaks (Pro)
		 * ---------------------------------------------------------------
		 */

		array(
			'id'                => 'mvs_streak_milestone',
			'label'             => __( 'Hit an upload streak milestone', 'wb-gamification' ),
			'description'       => __( 'Awarded when a member hits 7, 30, 100, or 365 consecutive upload days.', 'wb-gamification' ),
			'hook'              => 'mvs_streak_milestone',
			'user_callback'     => function ( int $user_id, int $days, int $xp ): int {
				return $user_id;
			},
			'points_callback'   => function ( int $user_id, int $days, int $xp ): int {
				return $xp;
			},
			'metadata_callback' => function ( int $user_id, int $days, int $xp ): array {
				return array(
					'streak_days' => $days,
					'xp_bonus'    => $xp,
				);
			},
			'default_points'    => 50,
			'category'          => 'engagement',
			'icon'              => 'dashicons-performance',
			'repeatable'        => true,
		),
	);

	/**
	 * Filters the WPMediaVerse Pro trigger set.
	 *
	 * Only applied when WPMediaVerse Pro is active (MVS_PRO_VERSION defined).
	 *
	 * @since 1.0.0
	 *
	 * @param array $pro_triggers Pro trigger definitions.
	 */
	$pro_triggers = apply_filters( 'wb_gam_wpmediaverse_pro_triggers', $pro_triggers );
}

$triggers = array_merge( $free_triggers, $pro_triggers );

/**
 * Filters the full WPMediaVerse trigger set (Free + Pro, post-merge).
 *
 * Final extension point — use this to add, remove, or modify any trigger
 * regardless of whether it came from the Free or Pro set.
 *
 * @since 1.0.0
 *
 * @param array $triggers   Combined trigger definitions.
 * @param bool  $pro_active Whether WPMediaVerse Pro is active.
 */
$triggers = apply_filters( 'wb_gam_wpmediaverse_triggers', $triggers, $pro_active );

return array(
	'plugin'   => 'WPMediaVerse',
	'version'  => '1.0.0',
	'triggers' => $triggers,
);
