<?php
/**
 * Block: Top Members
 *
 * Podium or list view of highest-ranked community members.
 *
 * @package WB_Gamification
 * @since   0.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\LevelEngine;

$limit      = max( 1, min( 20, (int) ( $attributes['limit'] ?? 3 ) ) );
$period_map = [
	'all_time'   => 'all',
	'this_week'  => 'week',
	'this_month' => 'month',
];
$period      = $period_map[ $attributes['period'] ?? 'all_time' ] ?? 'all';
$show_badges = ! empty( $attributes['show_badges'] );
$show_level  = ! empty( $attributes['show_level'] );
$layout      = in_array( $attributes['layout'] ?? 'podium', [ 'podium', 'list' ], true )
	? $attributes['layout']
	: 'podium';

$rows = LeaderboardEngine::get_leaderboard( $period, $limit );

if ( empty( $rows ) ) {
	return;
}

// Pre-fetch badge counts in one query.
$user_ids         = array_column( $rows, 'user_id' );
$badge_count_map  = [];
if ( $show_badges && ! empty( $user_ids ) ) {
	global $wpdb;
	$placeholders    = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$badge_rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT user_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id IN ($placeholders) GROUP BY user_id",
			...$user_ids
		),
		ARRAY_A
	);
	foreach ( $badge_rows ?: [] as $br ) {
		$badge_count_map[ (int) $br['user_id'] ] = (int) $br['cnt'];
	}
}

// Pre-fetch level names in one pass (LevelEngine caches internally).
$level_map = [];
if ( $show_level ) {
	foreach ( $user_ids as $uid ) {
		$lvl = LevelEngine::get_level_for_user( (int) $uid );
		if ( $lvl ) {
			$level_map[ (int) $uid ] = $lvl['name'];
		}
	}
}

$wrapper_attrs = get_block_wrapper_attributes(
	[ 'class' => 'wb-gam-top-members wb-gam-top-members--' . esc_attr( $layout ) ]
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php if ( 'podium' === $layout ) : ?>
		<?php
		// Reorder for podium display: 2nd, 1st, 3rd.
		$podium = [];
		foreach ( [ 1, 0, 2 ] as $idx ) {
			if ( isset( $rows[ $idx ] ) ) {
				$podium[] = [ 'row' => $rows[ $idx ], 'original_rank' => $idx + 1 ];
			}
		}
		?>
		<div class="wb-gam-top-members__podium">
			<?php foreach ( $podium as $entry ) :
				$row  = $entry['row'];
				$rank = $entry['original_rank'];
				$uid  = (int) $row['user_id'];
				$profile_url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $uid ) : get_author_posts_url( $uid );
			?>
				<div class="wb-gam-top-members__podium-slot wb-gam-top-members__podium-slot--<?php echo esc_attr( $rank ); ?>">
					<div class="wb-gam-top-members__podium-crown">
						<?php if ( 1 === $rank ) : ?>
							<span aria-hidden="true">&#x1F451;</span>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="wb-gam-top-members__avatar-link">
						<?php echo get_avatar( $uid, 72, '', esc_attr( $row['display_name'] ) ); ?>
					</a>
					<span class="wb-gam-top-members__rank-badge wb-gam-top-members__rank-badge--<?php echo esc_attr( $rank ); ?>">
						<?php echo esc_html( '#' . $rank ); ?>
					</span>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="wb-gam-top-members__name">
						<?php echo esc_html( $row['display_name'] ); ?>
					</a>
					<span class="wb-gam-top-members__points">
						<?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?>
						<span class="wb-gam-top-members__pts-label"><?php esc_html_e( 'pts', 'wb-gamification' ); ?></span>
					</span>
					<?php if ( $show_level && isset( $level_map[ $uid ] ) ) : ?>
						<span class="wb-gam-top-members__level"><?php echo esc_html( $level_map[ $uid ] ); ?></span>
					<?php endif; ?>
					<?php if ( $show_badges && isset( $badge_count_map[ $uid ] ) ) : ?>
						<span class="wb-gam-top-members__badges">
							&#x1F3C5; <?php echo esc_html( number_format_i18n( $badge_count_map[ $uid ] ) ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( count( $rows ) > 3 ) : ?>
			<ol class="wb-gam-top-members__rest" start="4">
				<?php foreach ( array_slice( $rows, 3 ) as $i => $row ) :
					$uid  = (int) $row['user_id'];
					$rank = $i + 4;
					$profile_url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $uid ) : get_author_posts_url( $uid );
				?>
					<li class="wb-gam-top-members__rest-item">
						<span class="wb-gam-top-members__rest-rank"><?php echo esc_html( '#' . $rank ); ?></span>
						<?php echo get_avatar( $uid, 32, '', esc_attr( $row['display_name'] ) ); ?>
						<a href="<?php echo esc_url( $profile_url ); ?>" class="wb-gam-top-members__rest-name">
							<?php echo esc_html( $row['display_name'] ); ?>
						</a>
						<span class="wb-gam-top-members__rest-points">
							<?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

	<?php else : // list layout ?>

		<ol class="wb-gam-top-members__list">
			<?php foreach ( $rows as $rank_0 => $row ) :
				$uid  = (int) $row['user_id'];
				$rank = $rank_0 + 1;
				$profile_url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $uid ) : get_author_posts_url( $uid );
			?>
				<li class="wb-gam-top-members__list-item">
					<span class="wb-gam-top-members__list-rank"><?php echo esc_html( '#' . $rank ); ?></span>
					<a href="<?php echo esc_url( $profile_url ); ?>"><?php echo get_avatar( $uid, 40 ); ?></a>
					<div class="wb-gam-top-members__list-info">
						<a href="<?php echo esc_url( $profile_url ); ?>" class="wb-gam-top-members__list-name">
							<?php echo esc_html( $row['display_name'] ); ?>
						</a>
						<?php if ( $show_level && isset( $level_map[ $uid ] ) ) : ?>
							<span class="wb-gam-top-members__list-level"><?php echo esc_html( $level_map[ $uid ] ); ?></span>
						<?php endif; ?>
					</div>
					<span class="wb-gam-top-members__list-points">
						<?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?>
						<span class="wb-gam-top-members__pts-label"><?php esc_html_e( 'pts', 'wb-gamification' ); ?></span>
					</span>
					<?php if ( $show_badges && isset( $badge_count_map[ $uid ] ) ) : ?>
						<span class="wb-gam-top-members__list-badges">&#x1F3C5; <?php echo esc_html( $badge_count_map[ $uid ] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

	<?php endif; ?>
</div>
