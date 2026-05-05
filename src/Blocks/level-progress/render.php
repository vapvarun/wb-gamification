<?php
/**
 * Level Progress block — Wbcom Block Quality Standard render.
 *
 * Phase D.1 migration: per-instance scoped CSS via
 * `WBGam\Blocks\CSS::add()`, design tokens through `wb-gam-tokens`,
 * `.wb-gam-block-{uniqueId}` wrapper class.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\PointsEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-level-progress',
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

$wb_gam_inline = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-accent: %s;', sanitize_text_field( (string) $wb_gam_attrs['accentColor'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-white: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBorderColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-border: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBorderColor'] ) );
}

wp_enqueue_style( 'wb-gam-tokens' );

if ( ! $wb_gam_user_id ) {
	$wb_gam_classes[] = 'wb-gam-level-progress--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'level-progress', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-level-progress__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your level progress.', 'wb-gamification' )
	);
	BlockHooks::after( 'level-progress', $wb_gam_attrs );
	return;
}

$wb_gam_show_bar  = ! isset( $wb_gam_attrs['show_progress_bar'] ) || ! empty( $wb_gam_attrs['show_progress_bar'] );
$wb_gam_show_next = ! isset( $wb_gam_attrs['show_next_level'] ) || ! empty( $wb_gam_attrs['show_next_level'] );
$wb_gam_show_icon = ! isset( $wb_gam_attrs['show_icon'] ) || ! empty( $wb_gam_attrs['show_icon'] );

$wb_gam_points = (int) PointsEngine::get_total( $wb_gam_user_id );
$wb_gam_level  = LevelEngine::get_level_for_user( $wb_gam_user_id );
$wb_gam_next   = LevelEngine::get_next_level( $wb_gam_user_id );
$wb_gam_pct    = (float) LevelEngine::get_progress_percent( $wb_gam_user_id );

if ( ! $wb_gam_level ) {
	$wb_gam_classes[] = 'wb-gam-level-progress--empty';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'level-progress', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-level-progress__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Keep earning points to unlock your first level!', 'wb-gamification' )
	);
	BlockHooks::after( 'level-progress', $wb_gam_attrs );
	return;
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'level-progress', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-level-progress__header">
		<?php if ( $wb_gam_show_icon && ! empty( $wb_gam_level['icon_url'] ) ) : ?>
			<img alt="<?php echo esc_attr( (string) ( $wb_gam_level['name'] ?? '' ) ); ?>"
				src="<?php echo esc_url( (string) $wb_gam_level['icon_url'] ); ?>"
				class="wb-gam-level-progress__icon"
				width="48"
				height="48"
			/>
		<?php endif; ?>
		<div class="wb-gam-level-progress__info">
			<span class="wb-gam-level-progress__label"><?php esc_html_e( 'Current Level', 'wb-gamification' ); ?></span>
			<span class="wb-gam-level-progress__name"><?php echo esc_html( (string) ( $wb_gam_level['name'] ?? '' ) ); ?></span>
			<span class="wb-gam-level-progress__points">
				<?php
				/* translators: %s = points total */
				printf( esc_html__( '%s points', 'wb-gamification' ), esc_html( number_format_i18n( $wb_gam_points ) ) );
				?>
			</span>
		</div>
	</div>

	<?php if ( $wb_gam_show_bar ) : ?>
		<div class="wb-gam-level-progress__bar-wrap"
			role="progressbar"
			aria-valuenow="<?php echo esc_attr( (string) (int) $wb_gam_pct ); ?>"
			aria-valuemin="0"
			aria-valuemax="100"
		>
			<div class="wb-gam-level-progress__bar" style="--wb-gam-fill: <?php echo esc_attr( (string) $wb_gam_pct ); ?>%"></div>
		</div>
		<span class="wb-gam-level-progress__pct"><?php echo esc_html( number_format_i18n( $wb_gam_pct, 1 ) . '%' ); ?></span>
	<?php endif; ?>

	<?php if ( $wb_gam_show_next && $wb_gam_next ) : ?>
		<div class="wb-gam-level-progress__next">
			<?php
			$wb_gam_pts_needed = (int) ( $wb_gam_next['min_points'] ?? 0 ) - $wb_gam_points;
			printf(
				/* translators: 1: formatted points needed to reach next level, 2: name of the next level */
				esc_html__( '%1$s pts to %2$s', 'wb-gamification' ),
				esc_html( number_format_i18n( max( 0, $wb_gam_pts_needed ) ) ),
				esc_html( (string) ( $wb_gam_next['name'] ?? '' ) )
			);
			?>
		</div>
	<?php elseif ( $wb_gam_show_next ) : ?>
		<div class="wb-gam-level-progress__next wb-gam-level-progress__next--max">
			<?php esc_html_e( 'Max level reached!', 'wb-gamification' ); ?>
		</div>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'level-progress', $wb_gam_attrs );
