<?php
/**
 * Points History block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
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

$wb_gam_classes = array_filter( array( 'wb-gam-points-history', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_classes[] = 'wb-gam-points-history--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'points-history', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-points-history__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your points history.', 'wb-gamification' )
	);
	BlockHooks::after( 'points-history', $wb_gam_attrs );
	return;
}

$wb_gam_limit      = max( 1, min( 100, (int) ( $wb_gam_attrs['limit'] ?? 20 ) ) );
$wb_gam_show_label = ! empty( $wb_gam_attrs['show_action_label'] );

$wb_gam_point_type = (string) ( $wb_gam_attrs['pointType'] ?? '' );
$wb_gam_rows       = PointsEngine::get_history( $wb_gam_user_id, $wb_gam_limit, $wb_gam_point_type ?: null );

// Pre-fetch label map so each row can render its actual currency name (no
// N+1 — single $service->list() call). Rows from sites with only the
// primary currency keep saying "Points".
$wb_gam_pt_service = new \WBGam\Services\PointTypeService();
$wb_gam_label_map  = array();
foreach ( $wb_gam_pt_service->list() as $wb_gam_pt ) {
	$wb_gam_label_map[ (string) $wb_gam_pt['slug'] ] = (string) $wb_gam_pt['label'];
}
$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'points-history', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $wb_gam_rows ) ) : ?>
		<p class="wb-gam-points-history__empty">
			<?php esc_html_e( 'No point activity yet — start participating to earn points!', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-points-history__list" role="list">
			<?php foreach ( $wb_gam_rows as $wb_gam_row ) :
				$wb_gam_pts        = (int) ( $wb_gam_row['points'] ?? 0 );
				$wb_gam_pos_neg    = $wb_gam_pts >= 0 ? 'positive' : 'negative';
				$wb_gam_row_type   = (string) ( $wb_gam_row['point_type'] ?? '' );
				$wb_gam_row_label  = $wb_gam_label_map[ $wb_gam_row_type ] ?? __( 'pts', 'wb-gamification' );
				?>
				<li class="wb-gam-points-history__item wb-gam-points-history__item--<?php echo esc_attr( $wb_gam_pos_neg ); ?>">
					<?php if ( $wb_gam_show_label ) : ?>
						<span class="wb-gam-points-history__action">
							<?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $wb_gam_row['action_id'] ?? '' ) ) ) ); ?>
						</span>
					<?php endif; ?>
					<span class="wb-gam-points-history__points">
						<?php
						printf(
							/* translators: 1: formatted points with sign, e.g. "+10" or "-5"; 2: currency label (e.g. "Points", "Coins"). */
							esc_html__( '%1$s %2$s', 'wb-gamification' ),
							esc_html( ( $wb_gam_pts >= 0 ? '+' : '' ) . number_format_i18n( $wb_gam_pts ) ),
							esc_html( $wb_gam_row_label )
						);
						?>
					</span>
					<time class="wb-gam-points-history__date" datetime="<?php echo esc_attr( (string) ( $wb_gam_row['created_at'] ?? '' ) ); ?>">
						<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) ( $wb_gam_row['created_at'] ?? 'now' ) ) ) ); ?>
					</time>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'points-history', $wb_gam_attrs );
