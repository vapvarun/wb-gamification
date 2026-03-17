<?php
/**
 * Block: Streak
 *
 * @package WB_Gamification
 * @since   0.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\StreakEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}

if ( ! $user_id ) {
	return;
}

$show_longest  = ! empty( $attributes['show_longest'] );
$show_heatmap  = ! empty( $attributes['show_heatmap'] );
$heatmap_days  = max( 1, min( 365, (int) ( $attributes['heatmap_days'] ?? 90 ) ) );

$streak  = StreakEngine::get_streak( $user_id );
$heatmap = $show_heatmap ? StreakEngine::get_contribution_data( $user_id, $heatmap_days ) : [];

$current  = (int) $streak['current_streak'];
$longest  = (int) $streak['longest_streak'];

/**
 * Milestones used to show the "next milestone" nudge.
 *
 * @var int[]
 */
$milestones   = [ 7, 14, 30, 60, 100, 180, 365 ];
$next_mile    = null;
foreach ( $milestones as $ms ) {
	if ( $current < $ms ) {
		$next_mile = $ms;
		break;
	}
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-streak' ] );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-streak__stats">
		<div class="wb-gam-streak__stat wb-gam-streak__stat--current">
			<span class="wb-gam-streak__flame" aria-hidden="true">&#x1F525;</span>
			<span class="wb-gam-streak__number"><?php echo esc_html( number_format_i18n( $current ) ); ?></span>
			<span class="wb-gam-streak__label"><?php esc_html_e( 'Day streak', 'wb-gamification' ); ?></span>
		</div>

		<?php if ( $show_longest ) : ?>
			<div class="wb-gam-streak__stat wb-gam-streak__stat--longest">
				<span class="wb-gam-streak__number wb-gam-streak__number--longest"><?php echo esc_html( number_format_i18n( $longest ) ); ?></span>
				<span class="wb-gam-streak__label"><?php esc_html_e( 'Best streak', 'wb-gamification' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $next_mile ) : ?>
		<p class="wb-gam-streak__nudge">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1 = days remaining, 2 = milestone target */
					_n(
						'%1$d more day to reach the %2$d-day milestone!',
						'%1$d more days to reach the %2$d-day milestone!',
						$next_mile - $current,
						'wb-gamification'
					),
					$next_mile - $current,
					$next_mile
				)
			);
			?>
		</p>
	<?php else : ?>
		<p class="wb-gam-streak__nudge wb-gam-streak__nudge--elite">
			<?php esc_html_e( 'Amazing — you\'ve hit every milestone! Keep it up!', 'wb-gamification' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $show_heatmap && ! empty( $heatmap ) ) : ?>
		<div class="wb-gam-streak__heatmap" aria-label="<?php esc_attr_e( 'Contribution heatmap', 'wb-gamification' ); ?>">
			<?php
			// Build a full date range so gaps (no activity) still render as empty cells.
			$end_ts   = current_time( 'timestamp' );
			$start_ts = strtotime( "-{$heatmap_days} days", $end_ts );
			$max_pts  = $heatmap ? max( $heatmap ) : 1;

			for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
				$date  = gmdate( 'Y-m-d', $ts );
				$pts   = $heatmap[ $date ] ?? 0;
				$level = 0;
				if ( $pts > 0 ) {
					$level = (int) ceil( ( $pts / $max_pts ) * 4 ); // 1–4
				}
				printf(
					'<span class="wb-gam-streak__cell wb-gam-streak__cell--%d" title="%s: %s pts" aria-label="%s"></span>',
					esc_attr( $level ),
					esc_attr( $date ),
					esc_attr( number_format_i18n( $pts ) ),
					esc_attr(
						sprintf(
							/* translators: 1 = date, 2 = points */
							__( '%1$s: %2$s points', 'wb-gamification' ),
							$date,
							number_format_i18n( $pts )
						)
					)
				);
			}
			?>
		</div>
	<?php endif; ?>
</div>
