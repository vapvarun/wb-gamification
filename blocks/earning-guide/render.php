<?php
/**
 * Earning Guide block render callback.
 *
 * Shows all enabled gamification actions with point values,
 * grouped by category. Helps members understand how to earn.
 *
 * @package WB_Gamification
 * @since   1.0.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

$actions = Registry::get_actions();
if ( empty( $actions ) ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-earning-guide' ) );
	printf(
		'<div %s><p class="wb-gam-earning-guide__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'No earning opportunities available yet.', 'wb-gamification' )
	);
	return;
}

$columns      = (int) ( $attributes['columns'] ?? 3 );
$show_headers = (bool) ( $attributes['show_category_headers'] ?? true );

// Group enabled actions by category.
$grouped = array();
foreach ( $actions as $id => $action ) {
	$enabled = (bool) get_option( 'wb_gam_enabled_' . $id, true );
	if ( ! $enabled ) {
		continue;
	}

	$category = $action['category'] ?? 'general';
	$pts      = (int) get_option( 'wb_gam_points_' . $id, $action['default_points'] ?? 0 );

	if ( $pts <= 0 ) {
		continue;
	}

	$grouped[ $category ][] = array(
		'label'  => $action['label'] ?? $id,
		'icon'   => $action['icon'] ?? 'dashicons-star-filled',
		'points' => $pts,
	);
}

if ( empty( $grouped ) ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-earning-guide' ) );
	printf(
		'<div %s><p class="wb-gam-earning-guide__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'No earning opportunities available yet.', 'wb-gamification' )
	);
	return;
}

// Sort categories alphabetically.
ksort( $grouped );

$wrapper_attrs = get_block_wrapper_attributes(
	array( 'class' => 'wb-gam-earning-guide' )
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php foreach ( $grouped as $category => $items ) : ?>
		<?php if ( $show_headers ) : ?>
			<h3 class="wb-gam-earning-guide__category"><?php echo esc_html( ucfirst( $category ) ); ?></h3>
		<?php endif; ?>
		<div class="wb-gam-earning-guide__grid" style="grid-template-columns:repeat(<?php echo esc_attr( $columns ); ?>,1fr);">
			<?php foreach ( $items as $item ) : ?>
				<div class="wb-gam-earning-guide__card">
					<span class="wb-gam-earning-guide__icon dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
					<span class="wb-gam-earning-guide__label"><?php echo esc_html( $item['label'] ); ?></span>
					<span class="wb-gam-earning-guide__pts">
						<?php
						printf(
							/* translators: %s: point value */
							esc_html__( '+%s pts', 'wb-gamification' ),
							esc_html( number_format_i18n( $item['points'] ) )
						);
						?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
