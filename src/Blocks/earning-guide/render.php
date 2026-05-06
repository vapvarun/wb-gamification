<?php
/**
 * Earning Guide block — Wbcom Block Quality Standard render.
 *
 * Phase D.1 migration: per-instance scoped CSS via
 * `WBGam\Blocks\CSS::add()`, design tokens via `wb-gam-tokens`,
 * `.wb-gam-block-{uniqueId}` wrapper class.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Registry;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_columns = max( 1, min( 4, (int) ( $wb_gam_attrs['columns'] ?? 3 ) ) );
$wb_gam_show_h  = ! isset( $wb_gam_attrs['show_category_headers'] ) || ! empty( $wb_gam_attrs['show_category_headers'] );

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-earning-guide',
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

$wb_gam_actions = Registry::get_actions();

$wb_gam_grouped = array();
if ( ! empty( $wb_gam_actions ) ) {
	foreach ( $wb_gam_actions as $wb_gam_id => $wb_gam_action ) {
		$wb_gam_enabled = (bool) get_option( 'wb_gam_enabled_' . $wb_gam_id, true );
		if ( ! $wb_gam_enabled ) {
			continue;
		}

		$wb_gam_category = (string) ( $wb_gam_action['category'] ?? 'general' );
		$wb_gam_pts      = (int) get_option( 'wb_gam_points_' . $wb_gam_id, $wb_gam_action['default_points'] ?? 0 );

		if ( $wb_gam_pts <= 0 ) {
			continue;
		}

		$wb_gam_grouped[ $wb_gam_category ][] = array(
			'label'  => (string) ( $wb_gam_action['label'] ?? $wb_gam_id ),
			'icon'   => (string) ( $wb_gam_action['icon'] ?? 'icon-star' ),
			'points' => $wb_gam_pts,
		);
	}
}

/**
 * Filter the earning-guide grouped action map before render.
 *
 * Map shape: [ category => [ ['label','icon','points'], ... ] ].
 *
 * @since 1.0.0
 *
 * @param array $grouped    Category-keyed action list.
 * @param array $attributes Block attributes.
 */
$wb_gam_grouped = (array) apply_filters( 'wb_gam_block_earning_guide_data', $wb_gam_grouped, $wb_gam_attrs );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

if ( empty( $wb_gam_grouped ) ) {
	BlockHooks::before( 'earning-guide', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-earning-guide__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'No earning opportunities available yet.', 'wb-gamification' )
	);
	BlockHooks::after( 'earning-guide', $wb_gam_attrs );
	return;
}

ksort( $wb_gam_grouped );

BlockHooks::before( 'earning-guide', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php foreach ( $wb_gam_grouped as $wb_gam_category => $wb_gam_items ) : ?>
		<?php if ( $wb_gam_show_h ) : ?>
			<h3 class="wb-gam-earning-guide__category"><?php echo esc_html( ucfirst( $wb_gam_category ) ); ?></h3>
		<?php endif; ?>
		<div class="wb-gam-earning-guide__grid" style="grid-template-columns:repeat(<?php echo (int) $wb_gam_columns; ?>,minmax(0,1fr));">
			<?php foreach ( $wb_gam_items as $wb_gam_item ) : ?>
				<div class="wb-gam-earning-guide__card">
					<span class="wb-gam-earning-guide__icon <?php echo esc_attr( (string) $wb_gam_item['icon'] ); ?>"></span>
					<span class="wb-gam-earning-guide__label"><?php echo esc_html( (string) $wb_gam_item['label'] ); ?></span>
					<span class="wb-gam-earning-guide__pts">
						<?php
						/* translators: %s: point value */
						printf( esc_html__( '+%s pts', 'wb-gamification' ), esc_html( number_format_i18n( (int) $wb_gam_item['points'] ) ) );
						?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php
BlockHooks::after( 'earning-guide', $wb_gam_attrs );
