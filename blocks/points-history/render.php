<?php
/**
 * Block: Points History
 *
 * Renders a member's recent point transactions — what action, how many points, when.
 *
 * @package WB_Gamification
 * @since   0.5.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\PointsEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}

if ( $user_id <= 0 ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-points-history wb-gam-points-history--guest' ) );
	printf(
		'<div %s><p class="wb-gam-points-history__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your points history.', 'wb-gamification' )
	);
	return;
}

$limit      = max( 1, min( 100, (int) ( $attributes['limit'] ?? 20 ) ) );
$show_label = ! empty( $attributes['show_action_label'] );

$rows = PointsEngine::get_history( $user_id, $limit );

$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-points-history' ) );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php if ( empty( $rows ) ) : ?>

		<p class="wb-gam-points-history__empty">
			<?php esc_html_e( 'No point activity yet — start participating to earn points!', 'wb-gamification' ); ?>
		</p>

	<?php else : ?>

		<ul class="wb-gam-points-history__list" role="list">
			<?php
			foreach ( $rows as $row ) :
				$pts     = (int) $row['points'];
				$pos_neg = $pts >= 0 ? 'positive' : 'negative';
				?>
			<li class="wb-gam-points-history__item wb-gam-points-history__item--<?php echo esc_attr( $pos_neg ); ?>">

				<?php if ( $show_label ) : ?>
					<span class="wb-gam-points-history__action">
						<?php
						// Format action_id as human-readable: 'wp_create_post' → 'Wp Create Post'.
						echo esc_html( ucwords( str_replace( '_', ' ', $row['action_id'] ) ) );
						?>
					</span>
				<?php endif; ?>

				<span class="wb-gam-points-history__points">
					<?php
					printf(
					/* translators: %s = formatted points with sign (e.g. "+10" or "-5") */
						esc_html__( '%s pts', 'wb-gamification' ),
						esc_html( ( $pts >= 0 ? '+' : '' ) . number_format_i18n( $pts ) )
					);
					?>
				</span>

				<time class="wb-gam-points-history__date"
						datetime="<?php echo esc_attr( $row['created_at'] ); ?>">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['created_at'] ) ) ); ?>
				</time>

			</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>
</div>
