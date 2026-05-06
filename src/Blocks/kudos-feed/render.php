<?php
/**
 * Kudos Feed block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\KudosEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_limit         = max( 1, min( 50, (int) ( $wb_gam_attrs['limit'] ?? 10 ) ) );
$wb_gam_show_messages = ! isset( $wb_gam_attrs['show_messages'] ) || ! empty( $wb_gam_attrs['show_messages'] );

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

$wb_gam_classes = array_filter( array( 'wb-gam-kudos-feed', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_kudos = KudosEngine::get_recent( $wb_gam_limit );

/**
 * Filter the kudos-feed entries before render.
 *
 * @since 1.0.0
 *
 * @param array $kudos      Recent kudos rows.
 * @param array $attributes Block attributes (limit).
 */
$wb_gam_kudos = (array) apply_filters( 'wb_gam_block_kudos_feed_data', $wb_gam_kudos, $wb_gam_attrs );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'kudos-feed', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-kudos-feed__header">
		<h3 class="wb-gam-kudos-feed__title"><?php esc_html_e( 'Kudos', 'wb-gamification' ); ?></h3>
	</div>

	<?php if ( empty( $wb_gam_kudos ) ) : ?>
		<p class="wb-gam-kudos-feed__empty"><?php esc_html_e( 'No kudos given yet — be the first!', 'wb-gamification' ); ?></p>
	<?php else : ?>
		<ul class="wb-gam-kudos-feed__list" role="list">
			<?php foreach ( $wb_gam_kudos as $wb_gam_item ) :
				$wb_gam_giver_url    = function_exists( 'bp_core_get_user_domain' ) ? (string) bp_core_get_user_domain( (int) ( $wb_gam_item['giver_id'] ?? 0 ) ) : '';
				$wb_gam_receiver_url = function_exists( 'bp_core_get_user_domain' ) ? (string) bp_core_get_user_domain( (int) ( $wb_gam_item['receiver_id'] ?? 0 ) ) : '';
				?>
				<li class="wb-gam-kudos-feed__item">
					<span class="wb-gam-kudos-feed__giver">
						<?php echo get_avatar( (int) ( $wb_gam_item['giver_id'] ?? 0 ), 32 ); ?>
						<?php if ( $wb_gam_giver_url ) : ?>
							<a href="<?php echo esc_url( $wb_gam_giver_url ); ?>"><?php echo esc_html( (string) ( $wb_gam_item['giver_name'] ?? '' ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) ( $wb_gam_item['giver_name'] ?? '' ) ); ?>
						<?php endif; ?>
					</span>

					<span class="wb-gam-kudos-feed__arrow" aria-hidden="true">→</span>

					<span class="wb-gam-kudos-feed__receiver">
						<?php echo get_avatar( (int) ( $wb_gam_item['receiver_id'] ?? 0 ), 32 ); ?>
						<?php if ( $wb_gam_receiver_url ) : ?>
							<a href="<?php echo esc_url( $wb_gam_receiver_url ); ?>"><?php echo esc_html( (string) ( $wb_gam_item['receiver_name'] ?? '' ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) ( $wb_gam_item['receiver_name'] ?? '' ) ); ?>
						<?php endif; ?>
					</span>

					<?php if ( $wb_gam_show_messages && ! empty( $wb_gam_item['message'] ) ) : ?>
						<q class="wb-gam-kudos-feed__message"><?php echo esc_html( (string) $wb_gam_item['message'] ); ?></q>
					<?php endif; ?>

					<time class="wb-gam-kudos-feed__time"
						datetime="<?php echo esc_attr( (string) ( $wb_gam_item['created_at'] ?? '' ) ); ?>"
						title="<?php echo esc_attr( (string) ( $wb_gam_item['created_at'] ?? '' ) ); ?>">
						<?php echo esc_html( human_time_diff( strtotime( (string) ( $wb_gam_item['created_at'] ?? 'now' ) ), time() ) . ' ' . __( 'ago', 'wb-gamification' ) ); ?>
					</time>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'kudos-feed', $wb_gam_attrs );
