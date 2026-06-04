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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Only load if WPMediaVerse (Free) is active. Pro extends Free, so Free is
// the source of truth for the MediaRepository class used by the callbacks.
if ( ! defined( 'MVS_VERSION' ) ) {
	return array();
}

// In-tree manifest is the canonical source for both Free and Pro action ids.
// WPMediaVerse Pro <= 1.1.3 still ships its own wb-gamification.php manifest
// with an older 3-action winner pattern (mvs_challenge_win_1st / _2nd / _3rd);
// the in-tree manifest below uses the rank-based mvs_challenge_winner that
// the same Pro plugin >= 1.2.3 fires through mvs_challenge_winner_named.
// ManifestLoader silently skips duplicates from third-party files so the
// in-tree definition always wins — bundled-Pro's 3 legacy ids still register
// (in-tree doesn't have them) so members on the older Pro release continue
// to earn for top-3 finishes until they upgrade.
$pro_active = defined( 'MVS_PRO_VERSION' );

if ( ! function_exists( 'wb_gam_mvs_media_author' ) ) {
	/**
	 * Resolve a MediaVerse media item's author.
	 *
	 * Used by reaction/comment/favorite triggers where the hook fires with the
	 * *actor* user_id but the gamification award goes to the media *owner*.
	 * The MediaRepository service is a non-static class on the container —
	 * resolving through the container is the documented Pro-boundary pattern.
	 *
	 * @param int $media_id Media post ID.
	 * @return int Author user ID (0 on miss).
	 */
	function wb_gam_mvs_media_author( int $media_id ): int {
		if ( ! class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			return 0;
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		$repo      = $container ? $container->get( 'media_repository' ) : null;
		return $repo ? (int) $repo->get_author( $media_id ) : 0;
	}
}

$free_triggers = array(

	/*
	 * ---------------------------------------------------------------
	 * Content Creation
	 * ---------------------------------------------------------------
	 */

	array(
		'id'                => 'mvs_upload_photo',
		'label'             => 'Upload a photo',
		'description'       => 'Awarded when a member uploads a photo or video.',
		'hook'              => 'mvs_media_uploaded',
		// Signature (MVS 1.2.3+): ($media_id, $file_data, $user_id, $media_type).
		'user_callback'     => function ( int $media_id, array $file_data, int $user_id = 0, string $media_type = '' ): int {
			return $user_id ?: (int) ( $file_data['user_id'] ?? 0 );
		},
		'metadata_callback' => function ( int $media_id, array $file_data, int $user_id = 0, string $media_type = '' ): array {
			return array(
				'media_id'   => $media_id,
				'media_type' => $media_type ?: (string) ( $file_data['media_type'] ?? '' ),
				'file_type'  => (string) ( $file_data['file_type'] ?? $file_data['mime'] ?? '' ),
				'is_first'   => ! empty( $file_data['is_first'] ),
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
		'label'          => 'Add items to an album',
		'description'    => 'Awarded when a member adds media to an album (rewards the actor, not just the album owner).',
		'hook'           => 'mvs_album_items_added',
		// Signature (MVS 1.2.3+): ($album_id, $actor_id, $media_ids, $added).
		'user_callback'  => function ( int $album_id, int $actor_id, array $media_ids, int $added ): int {
			return $actor_id;
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
		'label'             => 'Receive a like on photo',
		'description'       => 'Awarded to the media owner when someone likes their photo.',
		'hook'              => 'mvs_reaction_added',
		'user_callback'     => function ( int $media_id, int $user_id, string $type ): int {
			$author = wb_gam_mvs_media_author( $media_id );
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
		'async'             => false,
	),

	array(
		'id'                => 'mvs_receive_comment',
		'label'             => 'Receive a comment on photo',
		'description'       => 'Awarded to the media owner when someone comments on their photo.',
		'hook'              => 'mvs_comment_created',
		'user_callback'     => function ( int $media_id, int $user_id, int $comment_id, string $content ): int {
			$author = wb_gam_mvs_media_author( $media_id );
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
		'async'             => false,
	),

	array(
		'id'                => 'mvs_receive_follow',
		'label'             => 'Gain a new follower',
		'description'       => 'Awarded when another member follows you.',
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
		'label'          => 'Photo bookmarked by someone',
		'description'    => 'Awarded to the media owner when someone bookmarks their photo.',
		'hook'           => 'mvs_favorite_toggled',
		'user_callback'  => function ( int $media_id, int $user_id, string $action ): int {
			if ( 'added' !== $action ) {
				return 0;
			}
			$author = wb_gam_mvs_media_author( $media_id );
			return ( $author && $author !== $user_id ) ? $author : 0;
		},
		'default_points' => 2,
		'category'       => 'media',
		'icon'           => 'dashicons-star-filled',
		'repeatable'     => true,
		'async'          => false,
	),

	/*
	 * ---------------------------------------------------------------
	 * Engagement Given (awards the actor)
	 * ---------------------------------------------------------------
	 */

	array(
		'id'             => 'mvs_give_comment',
		'label'          => 'Write a meaningful comment',
		'description'    => 'Awarded when a member leaves a comment of 20+ characters.',
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
		'label'          => 'Follow another member',
		'description'    => 'Awarded when a member follows another user.',
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
		'label'          => 'Bookmark a photo',
		'description'    => 'Awarded when a member saves a photo to favorites.',
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
			'label'             => 'Win a photo battle',
			'description'       => 'Awarded to the winner of a 1v1 photo battle.',
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
			'label'          => 'Enter a photo challenge',
			'description'    => 'Awarded when a member submits an entry to a photo challenge.',
			'hook'              => 'mvs_challenge_entry_submitted',
			'user_callback'     => function ( int $challenge_id, int $user_id, int $media_id ): int {
				return $user_id;
			},
			// Carry the challenge id so MVS Pro's wb_gam_points_for_action
			// bridge can award the challenge's configured participation XP.
			'metadata_callback' => function ( int $challenge_id, int $user_id, int $media_id ): array {
				return array(
					'challenge_id' => $challenge_id,
				);
			},
			'default_points'    => 10,
			'category'          => 'competition',
			'icon'              => 'dashicons-megaphone',
			'repeatable'        => true,
		),

		// Single per-rank trigger fired once per top-3 rank via the MVS Pro
		// `mvs_challenge_winner_named` action. NOTE: the engine does not consume
		// points_callback - the per-rank and per-challenge amounts are resolved
		// by MVS Pro's wb_gam_points_for_action filter (CompetePointsBridge),
		// which reads challenge_id + rank from the metadata below. default_points
		// (200) is only the fallback when MVS Pro is inactive.
		array(
			'id'                => 'mvs_challenge_winner',
			'label'             => 'Place in a photo challenge',
			'description'       => 'Awarded to the top-3 finishers of a photo challenge (200/100/50 points for 1st/2nd/3rd).',
			'hook'              => 'mvs_challenge_winner_named',
			'user_callback'     => function ( int $challenge_id, int $user_id, int $rank ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $challenge_id, int $user_id, int $rank ): array {
				return array(
					'challenge_id' => $challenge_id,
					'rank'         => $rank,
				);
			},
			'default_points'    => 200,
			'category'          => 'competition',
			'icon'              => 'dashicons-trophy',
			'repeatable'        => true,
		),

		array(
			'id'             => 'mvs_tournament_round_win',
			'label'          => 'Win a tournament round',
			'description'    => 'Awarded for winning a round in a photo tournament.',
			'hook'              => 'mvs_tournament_match_resolved',
			'user_callback'     => function ( int $match_id, int $winner_id ): int {
				return $winner_id;
			},
			// Carry the match id so MVS Pro's bridge can resolve the parent
			// tournament and award its configured round-win XP.
			'metadata_callback' => function ( int $match_id, int $winner_id ): array {
				return array(
					'match_id' => $match_id,
				);
			},
			'default_points'    => 150,
			'category'          => 'competition',
			'icon'              => 'dashicons-shield',
			'repeatable'        => true,
		),

		array(
			'id'             => 'mvs_tournament_win',
			'label'          => 'Win a tournament',
			'description'    => 'Awarded to the grand champion of a photo tournament.',
			'hook'              => 'mvs_tournament_finalized',
			'user_callback'     => function ( int $tournament_id, int $winner_id ): int {
				return $winner_id;
			},
			// Carry the tournament id so MVS Pro's bridge can award the
			// tournament's configured champion XP.
			'metadata_callback' => function ( int $tournament_id, int $winner_id ): array {
				return array(
					'tournament_id' => $tournament_id,
				);
			},
			'default_points'    => 500,
			'category'          => 'competition',
			'icon'              => 'dashicons-star-filled',
			'repeatable'        => true,
		),

		/*
		 * ---------------------------------------------------------------
		 * Streaks (Pro)
		 * ---------------------------------------------------------------
		 */

		array(
			'id'                => 'mvs_streak_milestone',
			'label'             => 'Hit an upload streak milestone',
			'description'       => 'Awarded when a member hits 7, 30, 100, or 365 consecutive upload days.',
			'hook'              => 'mvs_streak_milestone',
			'user_callback'     => function ( int $user_id, int $days, int $xp ): int {
				return $user_id;
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
