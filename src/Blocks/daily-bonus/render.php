<?php
/**
 * Daily Login Bonus block — Wbcom Block Quality Standard render.
 *
 * Shows:
 *   - Current login streak (days)
 *   - Today's bonus (the most-recent tier ≤ streak day)
 *   - Tier ladder visualisation
 *   - Personal best
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\LoginBonusEngine;
use WBGam\Services\PointTypeService;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = get_current_user_id();

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter( array(
	'wb-gam-daily-bonus',
	'wb-gam-block-' . $wb_gam_unique,
	$wb_gam_visibility,
) );

wp_enqueue_style( 'wb-gam-tokens' );
wp_enqueue_style( 'lucide-icons' );

$wb_gam_inline = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-accent: %s;', sanitize_text_field( (string) $wb_gam_attrs['accentColor'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-white: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] ) );
}

if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_classes[] = 'wb-gam-daily-bonus--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes( array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	) );
	BlockHooks::before( 'daily-bonus', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-daily-bonus__guest">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to claim your daily bonus.', 'wb-gamification' )
	);
	BlockHooks::after( 'daily-bonus', $wb_gam_attrs );
	return;
}

$wb_gam_state         = LoginBonusEngine::get_state( $wb_gam_user_id );
$wb_gam_pt_service    = new PointTypeService();
$wb_gam_pt_record     = $wb_gam_pt_service->get( $wb_gam_pt_service->default_slug() );
$wb_gam_points_label  = (string) ( $wb_gam_pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );
$wb_gam_today_claimed = ( current_time( 'Y-m-d' ) === $wb_gam_state['last'] );

/**
 * Filter the daily-bonus block data before render.
 *
 * @since 1.0.0
 *
 * @param array $data       Includes streak, max, today_bonus, next_bonus, tiers, today_claimed.
 * @param array $attributes Block attributes.
 * @param int   $user_id    Member whose bonus state is rendered.
 */
$wb_gam_data = (array) apply_filters( 'wb_gam_block_daily_bonus_data', array_merge( $wb_gam_state, array( 'today_claimed' => $wb_gam_today_claimed ) ), $wb_gam_attrs, $wb_gam_user_id );

$wb_gam_wrapper = get_block_wrapper_attributes( array(
	'class' => implode( ' ', $wb_gam_classes ),
	'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
) );

BlockHooks::before( 'daily-bonus', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<header class="wb-gam-daily-bonus__head">
		<span class="wb-gam-daily-bonus__icon icon-flame" aria-hidden="true"></span>
		<div class="wb-gam-daily-bonus__head-text">
			<strong class="wb-gam-daily-bonus__streak">
				<?php
				printf(
					/* translators: %d: consecutive day count */
					esc_html( _n( '%d-day login streak', '%d-day login streak', max( 1, (int) $wb_gam_data['streak'] ), 'wb-gamification' ) ),
					(int) max( 1, $wb_gam_data['streak'] )
				);
				?>
			</strong>
			<?php if ( ! empty( $wb_gam_data['max'] ) ) : ?>
				<span class="wb-gam-daily-bonus__max">
					<?php
					printf(
						/* translators: %d: longest streak */
						esc_html__( 'Best: %d days', 'wb-gamification' ),
						(int) $wb_gam_data['max']
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</header>

	<div class="wb-gam-daily-bonus__today">
		<?php if ( $wb_gam_data['today_claimed'] ) : ?>
			<span class="wb-gam-daily-bonus__chip wb-gam-daily-bonus__chip--claimed">
				<span class="icon-check-circle" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: 1: amount, 2: currency label. */
					esc_html__( 'Claimed today: %1$d %2$s', 'wb-gamification' ),
					(int) $wb_gam_data['today_bonus'],
					esc_html( $wb_gam_points_label )
				);
				?>
			</span>
			<?php if ( ! empty( $wb_gam_data['next_bonus'] ) ) : ?>
				<span class="wb-gam-daily-bonus__next">
					<?php
					printf(
						/* translators: 1: tomorrow amount, 2: currency label. */
						esc_html__( 'Tomorrow: %1$d %2$s', 'wb-gamification' ),
						(int) $wb_gam_data['next_bonus'],
						esc_html( $wb_gam_points_label )
					);
					?>
				</span>
			<?php endif; ?>
		<?php else : ?>
			<span class="wb-gam-daily-bonus__chip">
				<?php
				printf(
					/* translators: 1: amount, 2: currency label. */
					esc_html__( 'Available now: %1$d %2$s', 'wb-gamification' ),
					(int) $wb_gam_data['today_bonus'],
					esc_html( $wb_gam_points_label )
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $wb_gam_data['tiers'] ) ) : ?>
		<ol class="wb-gam-daily-bonus__ladder" role="list">
			<?php
			foreach ( $wb_gam_data['tiers'] as $wb_gam_day => $wb_gam_pts ) :
				$wb_gam_reached = (int) $wb_gam_data['streak'] >= (int) $wb_gam_day;
				?>
				<li class="wb-gam-daily-bonus__tier<?php echo $wb_gam_reached ? ' is-reached' : ''; ?>">
					<span class="wb-gam-daily-bonus__tier-day">
						<?php
						printf(
							/* translators: %d: day number */
							esc_html__( 'Day %d', 'wb-gamification' ),
							(int) $wb_gam_day
						);
						?>
					</span>
					<span class="wb-gam-daily-bonus__tier-bonus">
						<?php
						printf(
							/* translators: 1: amount, 2: currency label. */
							esc_html__( '%1$d %2$s', 'wb-gamification' ),
							(int) $wb_gam_pts,
							esc_html( $wb_gam_points_label )
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'daily-bonus', $wb_gam_attrs );
