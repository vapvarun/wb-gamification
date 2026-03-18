<?php
/**
 * Block: Level Progress
 *
 * @package WB_Gamification
 * @since   0.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\LevelEngine;
use WBGam\Engine\PointsEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}

if ( ! $user_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-level-progress wb-gam-level-progress--guest' ] );
	printf(
		'<div %s><p class="wb-gam-level-progress__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your level progress.', 'wb-gamification' )
	);
	return;
}

$show_bar   = ! empty( $attributes['show_progress_bar'] );
$show_next  = ! empty( $attributes['show_next_level'] );
$show_icon  = ! empty( $attributes['show_icon'] );

$points  = PointsEngine::get_total( $user_id );
$level   = LevelEngine::get_level_for_user( $user_id );
$next    = LevelEngine::get_next_level( $user_id );
$pct     = LevelEngine::get_progress_percent( $user_id );

if ( ! $level ) {
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-level-progress wb-gam-level-progress--empty' ] );
	printf(
		'<div %s><p class="wb-gam-level-progress__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Keep earning points to unlock your first level!', 'wb-gamification' )
	);
	return;
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-level-progress' ] );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-level-progress__header">
		<?php if ( $show_icon && ! empty( $level['icon_url'] ) ) : ?>
			<img
				src="<?php echo esc_url( $level['icon_url'] ); ?>"
				alt="<?php echo esc_attr( $level['name'] ); ?>"
				class="wb-gam-level-progress__icon"
				width="48"
				height="48"
			/>
		<?php endif; ?>
		<div class="wb-gam-level-progress__info">
			<span class="wb-gam-level-progress__label"><?php esc_html_e( 'Current Level', 'wb-gamification' ); ?></span>
			<span class="wb-gam-level-progress__name"><?php echo esc_html( $level['name'] ); ?></span>
			<span class="wb-gam-level-progress__points">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s = points total */
						__( '%s points', 'wb-gamification' ),
						number_format_i18n( $points )
					)
				);
				?>
			</span>
		</div>
	</div>

	<?php if ( $show_bar ) : ?>
		<div class="wb-gam-level-progress__bar-wrap" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="wb-gam-level-progress__bar" style="width: <?php echo esc_attr( $pct ); ?>%"></div>
		</div>
		<span class="wb-gam-level-progress__pct"><?php echo esc_html( number_format_i18n( $pct, 1 ) . '%' ); ?></span>
	<?php endif; ?>

	<?php if ( $show_next && $next ) : ?>
		<div class="wb-gam-level-progress__next">
			<?php
			$pts_needed = $next['min_points'] - $points;
			echo esc_html(
				sprintf(
					/* translators: 1 = points needed, 2 = level name */
					__( '%1$s pts to %2$s', 'wb-gamification' ),
					number_format_i18n( max( 0, $pts_needed ) ),
					$next['name']
				)
			);
			?>
		</div>
	<?php elseif ( $show_next ) : ?>
		<div class="wb-gam-level-progress__next wb-gam-level-progress__next--max">
			<?php esc_html_e( 'Max level reached!', 'wb-gamification' ); ?>
		</div>
	<?php endif; ?>
</div>
