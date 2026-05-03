<?php
/**
 * Member Points block — Wbcom Block Quality Standard render.
 *
 * Phase D.1 migration: per-instance scoped CSS via
 * `WBGam\Blocks\CSS::add()`, design tokens loaded through the
 * `wb-gam-tokens` style handle, wrapper class
 * `.wb-gam-block-{uniqueId}` so the block honours its own padding /
 * margin / responsive variants without touching neighbouring blocks.
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
if ( 0 === $wb_gam_user_id ) {
	$wb_gam_user_id = get_current_user_id();
}

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-member-points',
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

$wb_gam_inline_overrides = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf( '--wb-gam-color-accent: %s;', sanitize_text_field( (string) $wb_gam_attrs['accentColor'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline_overrides .= sprintf( '--wb-gam-color-white: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBorderColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf( '--wb-gam-color-border: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBorderColor'] ) );
}

wp_enqueue_style( 'wb-gam-tokens' );

if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_classes[] = 'wb-gam-member-points--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline_overrides ? $wb_gam_inline_overrides : null,
		)
	);
	BlockHooks::before( 'member-points', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-member-points__guest">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your points.', 'wb-gamification' )
	);
	BlockHooks::after( 'member-points', $wb_gam_attrs );
	return;
}

$wb_gam_user = get_userdata( $wb_gam_user_id );
if ( ! $wb_gam_user ) {
	return '';
}

$wb_gam_show_level    = ! isset( $wb_gam_attrs['show_level'] ) || ! empty( $wb_gam_attrs['show_level'] );
$wb_gam_show_progress = ! isset( $wb_gam_attrs['show_progress_bar'] ) || ! empty( $wb_gam_attrs['show_progress_bar'] );

$wb_gam_points       = (int) PointsEngine::get_total( $wb_gam_user_id );
$wb_gam_level        = $wb_gam_show_level ? LevelEngine::get_level_for_user( $wb_gam_user_id ) : null;
$wb_gam_next_level   = $wb_gam_show_level ? LevelEngine::get_next_level( $wb_gam_user_id ) : null;
$wb_gam_progress_pct = $wb_gam_show_progress ? (int) LevelEngine::get_progress_percent( $wb_gam_user_id ) : 0;

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline_overrides ? $wb_gam_inline_overrides : null,
	)
);

BlockHooks::before( 'member-points', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-member-points__total">
		<span class="wb-gam-member-points__number"><?php echo esc_html( number_format_i18n( $wb_gam_points ) ); ?></span>
		<span class="wb-gam-member-points__label"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></span>
	</div>

	<?php if ( $wb_gam_level && $wb_gam_show_level ) : ?>
		<div class="wb-gam-member-points__level">
			<?php if ( ! empty( $wb_gam_level['icon_url'] ) ) : ?>
				<img src="<?php echo esc_url( $wb_gam_level['icon_url'] ); ?>"
					alt="<?php echo esc_attr( (string) ( $wb_gam_level['name'] ?? '' ) ); ?>"
					class="wb-gam-member-points__level-icon"
					width="24" height="24" />
			<?php endif; ?>
			<span class="wb-gam-member-points__level-name"><?php echo esc_html( (string) ( $wb_gam_level['name'] ?? '' ) ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( $wb_gam_show_progress && $wb_gam_level ) : ?>
		<div class="wb-gam-member-points__progress" role="progressbar"
			aria-valuenow="<?php echo esc_attr( (string) $wb_gam_progress_pct ); ?>"
			aria-valuemin="0" aria-valuemax="100"
			aria-label="<?php esc_attr_e( 'Level progress', 'wb-gamification' ); ?>">
			<div class="wb-gam-member-points__progress-bar"
				style="--wb-gam-fill: <?php echo esc_attr( (string) $wb_gam_progress_pct ); ?>%"></div>
		</div>

		<?php if ( $wb_gam_next_level ) : ?>
			<p class="wb-gam-member-points__next">
				<?php
				$wb_gam_pts_to_next = max( 0, (int) ( $wb_gam_next_level['min_points'] ?? 0 ) - $wb_gam_points );
				/* translators: 1: points to next level, 2: next level name */
				printf(
					esc_html__( '%1$s pts to %2$s', 'wb-gamification' ),
					esc_html( number_format_i18n( $wb_gam_pts_to_next ) ),
					esc_html( (string) ( $wb_gam_next_level['name'] ?? '' ) )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'member-points', $wb_gam_attrs );
