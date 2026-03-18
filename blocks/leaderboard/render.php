<?php
/**
 * Leaderboard block — server-side render.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content (unused).
 * @var WP_Block $block      Block object.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\LeaderboardEngine;

$period     = $attributes['period'] ?? 'all';
$limit      = (int) ( $attributes['limit'] ?? 10 );
$scope_type = sanitize_key( $attributes['scope_type'] ?? '' );
$scope_id   = (int) ( $attributes['scope_id'] ?? 0 );
$show_avatars = (bool) ( $attributes['show_avatars'] ?? true );

$rows = LeaderboardEngine::get_leaderboard( $period, $limit, $scope_type, $scope_id );

$period_labels = [
	'all'   => __( 'All Time', 'wb-gamification' ),
	'month' => __( 'This Month', 'wb-gamification' ),
	'week'  => __( 'This Week', 'wb-gamification' ),
	'day'   => __( 'Today', 'wb-gamification' ),
];

$period_label = $period_labels[ $period ] ?? __( 'All Time', 'wb-gamification' );

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'wb-gam-leaderboard' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<div class="wb-gam-leaderboard__header">
		<h3 class="wb-gam-leaderboard__title">
			<?php
			/* translators: %s: period label e.g. "This Week" */
			printf( esc_html__( 'Leaderboard — %s', 'wb-gamification' ), esc_html( $period_label ) );
			?>
		</h3>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p class="wb-gam-leaderboard__empty">
			<?php esc_html_e( 'No members on the leaderboard yet.', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ol class="wb-gam-leaderboard__list">
			<?php foreach ( $rows as $row ) : ?>
				<li class="wb-gam-leaderboard__entry wb-gam-rank-<?php echo esc_attr( $row['rank'] ); ?>">
					<span class="wb-gam-leaderboard__rank" aria-label="<?php esc_attr_e( 'Rank', 'wb-gamification' ); ?>">
						<?php echo esc_html( $row['rank'] ); ?>
					</span>

					<?php if ( $show_avatars ) : ?>
						<span class="wb-gam-leaderboard__avatar">
							<?php echo get_avatar( $row['user_id'], 40, '', esc_attr( $row['display_name'] ) ); ?>
						</span>
					<?php endif; ?>

					<span class="wb-gam-leaderboard__name">
						<?php
						if ( function_exists( 'bp_core_get_user_domain' ) ) {
							$url = esc_url( bp_core_get_user_domain( $row['user_id'] ) );
							printf( '<a href="%s">%s</a>', $url, esc_html( $row['display_name'] ) );
						} else {
							echo esc_html( $row['display_name'] );
						}
						?>
					</span>

					<span class="wb-gam-leaderboard__points" aria-label="<?php esc_attr_e( 'Points', 'wb-gamification' ); ?>">
						<?php
						/* translators: %s: formatted points number */
						printf( esc_html__( '%s pts', 'wb-gamification' ), esc_html( number_format_i18n( $row['points'] ) ) );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php
	// ── Personal rank nudge ───────────────────────────────────────────────────
	// Show the current user's rank if they're not already visible in the list.
	$current_uid = get_current_user_id();

	if ( $current_uid > 0 && ! empty( $rows ) ) {
		$visible_ids = array_column( $rows, 'user_id' );

		if ( ! in_array( $current_uid, $visible_ids, true ) ) {
			$my_rank = LeaderboardEngine::get_user_rank( $current_uid, $period, $scope_type, $scope_id );

			if ( $my_rank['points'] > 0 ) {
				?>
				<div class="wb-gam-leaderboard__my-rank">
					<span class="wb-gam-leaderboard__my-rank-label">
						<?php esc_html_e( 'Your rank', 'wb-gamification' ); ?>
					</span>
					<span class="wb-gam-leaderboard__my-rank-position">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d = rank number */
								__( '#%d', 'wb-gamification' ),
								$my_rank['rank']
							)
						);
						?>
					</span>
					<span class="wb-gam-leaderboard__my-rank-points">
						<?php
						printf(
							/* translators: %s = formatted points total */
							esc_html__( '%s pts', 'wb-gamification' ),
							esc_html( number_format_i18n( $my_rank['points'] ) )
						);
						?>
					</span>
					<?php if ( null !== $my_rank['points_to_next'] ) : ?>
						<span class="wb-gam-leaderboard__my-rank-gap">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s = points needed to move up one rank */
									__( '%s pts from the next rank', 'wb-gamification' ),
									number_format_i18n( $my_rank['points_to_next'] )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php
			}
		}
	}
	?>
</div>
