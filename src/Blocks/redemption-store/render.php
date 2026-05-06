<?php
/**
 * Block: Redemption Store (Wbcom Block Quality Standard pilot).
 *
 * Phase C of the migration: drops the inline <style>/<script> emitted
 * by the legacy block, calls `WBGam\Blocks\CSS::add()` to register
 * per-instance scoped CSS, and emits `data-wp-*` directives for the
 * Interactivity API store wired up in `view.js`. The frontend
 * confirmation flow is in-DOM markup toggled via state — replacing
 * `window.confirm` per the Block Quality Standard.
 *
 * @package WB_Gamification
 * @since   2.0.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\RedemptionEngine;

$wb_gam_attrs    = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique   = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_limit    = max( 0, (int) ( $wb_gam_attrs['limit'] ?? 0 ) );
$wb_gam_columns  = max( 1, min( 4, (int) ( $wb_gam_attrs['columns'] ?? 3 ) ) );
$wb_gam_balance  = ! empty( $wb_gam_attrs['showBalance'] );
$wb_gam_stock_on = ! isset( $wb_gam_attrs['showStock'] ) || ! empty( $wb_gam_attrs['showStock'] );
$wb_gam_btn_lbl  = isset( $wb_gam_attrs['buttonLabel'] ) && '' !== trim( (string) $wb_gam_attrs['buttonLabel'] )
	? (string) $wb_gam_attrs['buttonLabel']
	: __( 'Redeem', 'wb-gamification' );
$wb_gam_empty    = isset( $wb_gam_attrs['emptyMessage'] ) && '' !== trim( (string) $wb_gam_attrs['emptyMessage'] )
	? (string) $wb_gam_attrs['emptyMessage']
	: __( 'No rewards available yet. Check back soon!', 'wb-gamification' );

$wb_gam_items = RedemptionEngine::get_items();
if ( $wb_gam_limit > 0 ) {
	$wb_gam_items = array_slice( $wb_gam_items, 0, $wb_gam_limit );
}

/**
 * Filter the redemption-store items before render.
 *
 * @since 1.0.0
 *
 * @param array $items      Reward items {id, title, points_cost, point_type, stock, ...}.
 * @param array $attributes Block attributes (limit, pointType).
 */
$wb_gam_items = (array) apply_filters( 'wb_gam_block_redemption_store_data', $wb_gam_items, $wb_gam_attrs );

$wb_gam_user_id     = get_current_user_id();
$wb_gam_point_type  = (string) ( $wb_gam_attrs['pointType'] ?? '' );
$wb_gam_balance_pts = $wb_gam_user_id ? (int) PointsEngine::get_total( $wb_gam_user_id, $wb_gam_point_type ) : 0;

// Pre-fetch the currency label map so each reward can render its own
// point_type label without an N+1 query inside the loop. Each reward
// item carries a `point_type` field — the cost suffix matches that
// type so a "Coins" reward says "100 Coins" not "100 pts".
$wb_gam_pt_service = new \WBGam\Services\PointTypeService();
$wb_gam_label_map  = array();
foreach ( $wb_gam_pt_service->list() as $wb_gam_pt ) {
	$wb_gam_label_map[ (string) $wb_gam_pt['slug'] ] = (string) $wb_gam_pt['label'];
}
$wb_gam_default_label = $wb_gam_label_map[ $wb_gam_pt_service->default_slug() ] ?? __( 'pts', 'wb-gamification' );

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );

$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );
$wb_gam_extra_css  = array();

$wb_gam_inline_styles = array_filter(
	array(
		'accentColor'      => 'background',
		'accentHoverColor' => null, // Hover only — applied via inline rule below.
	),
	static fn ( $value ) => null !== $value
);

$wb_gam_inline_overrides = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf(
		'--wb-gam-redemption-accent: %s;',
		sanitize_text_field( (string) $wb_gam_attrs['accentColor'] )
	);
}
if ( ! empty( $wb_gam_attrs['accentHoverColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf(
		'--wb-gam-redemption-accent-hover: %s;',
		sanitize_text_field( (string) $wb_gam_attrs['accentHoverColor'] )
	);
}
if ( ! empty( $wb_gam_attrs['buttonTextColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf(
		'--wb-gam-redemption-text-on-accent: %s;',
		sanitize_text_field( (string) $wb_gam_attrs['buttonTextColor'] )
	);
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline_overrides .= sprintf(
		'--wb-gam-redemption-card-bg: %s;',
		sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] )
	);
}
if ( ! empty( $wb_gam_attrs['cardBorderColor'] ) ) {
	$wb_gam_inline_overrides .= sprintf(
		'--wb-gam-redemption-card-border: %s;',
		sanitize_text_field( (string) $wb_gam_attrs['cardBorderColor'] )
	);
}

$wb_gam_classes = array(
	'wb-gam-redemption',
	'wb-gam-block-' . $wb_gam_unique,
);
if ( '' !== $wb_gam_visibility ) {
	$wb_gam_classes[] = $wb_gam_visibility;
}

$wb_gam_wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class'                      => implode( ' ', array_filter( $wb_gam_classes ) ),
		'data-columns'               => (string) $wb_gam_columns,
		'data-wp-interactive'        => 'wb-gamification/redemption',
		'data-wp-context'            => wp_json_encode(
			array(
				'rootBalance' => $wb_gam_balance_pts,
			)
		),
		'data-redemption-endpoint'   => esc_url_raw( rest_url( 'wb-gamification/v1/redemptions' ) ),
		'data-rest-nonce'            => $wb_gam_user_id ? wp_create_nonce( 'wp_rest' ) : '',
		'data-i18n-failed'           => __( 'Redemption failed.', 'wb-gamification' ),
		'data-i18n-pending'          => __( 'Redeemed! Check your email or your account for next steps.', 'wb-gamification' ),
		'data-i18n-network'          => __( 'Network error. Please try again.', 'wb-gamification' ),
		'data-i18n-missing-endpoint' => __( 'Redemption endpoint is unavailable.', 'wb-gamification' ),
		'style'                      => '' !== $wb_gam_inline_overrides ? $wb_gam_inline_overrides : null,
	)
);

wp_enqueue_style( 'wb-gam-tokens' );

BlockHooks::before(
	'redemption-store',
	$wb_gam_attrs,
	array(
		'count'   => count( $wb_gam_items ),
		'balance' => $wb_gam_balance_pts,
	)
);
?>
<div <?php echo $wb_gam_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns escaped output. ?>>
	<div class="wb-gam-redemption__header">
		<h3 class="wb-gam-redemption__title"><?php esc_html_e( 'Redemption Store', 'wb-gamification' ); ?></h3>
		<?php if ( $wb_gam_balance && $wb_gam_user_id ) : ?>
			<span class="wb-gam-redemption__balance" data-wb-gam-balance>
				<?php
				printf(
					/* translators: %s: formatted point total */
					esc_html__( 'Balance: %s pts', 'wb-gamification' ),
					'<span data-wb-gam-balance-text>' . esc_html( number_format_i18n( $wb_gam_balance_pts ) ) . '</span>'
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $wb_gam_items ) ) : ?>
		<p class="wb-gam-redemption__empty"><?php echo esc_html( $wb_gam_empty ); ?></p>
	<?php else : ?>
		<ul class="wb-gam-redemption__grid" role="list">
			<?php
			foreach ( $wb_gam_items as $wb_gam_item ) :
				$wb_gam_cost           = (int) ( $wb_gam_item['points_cost'] ?? 0 );
				$wb_gam_stock          = $wb_gam_item['stock'] ?? null;
				$wb_gam_out_of_stock   = ( null !== $wb_gam_stock && (int) $wb_gam_stock <= 0 );
				$wb_gam_insufficient   = $wb_gam_user_id && $wb_gam_balance_pts < $wb_gam_cost;
				$wb_gam_missing_points = max( 0, $wb_gam_cost - $wb_gam_balance_pts );
				$wb_gam_item_type      = (string) ( $wb_gam_item['point_type'] ?? $wb_gam_pt_service->default_slug() );
				$wb_gam_item_label     = $wb_gam_label_map[ $wb_gam_item_type ] ?? $wb_gam_default_label;
				$wb_gam_card_context   = wp_json_encode(
					array(
						'itemId'         => (int) ( $wb_gam_item['id'] ?? 0 ),
						'cost'           => $wb_gam_cost,
						'balance'        => $wb_gam_balance_pts,
						'confirming'     => false,
						'loading'        => false,
						'redeemed'       => false,
						'errorMessage'   => '',
						'successMessage' => '',
						'couponCode'     => '',
					)
				);
				?>
				<li class="wb-gam-redemption__card"
					data-item-id="<?php echo esc_attr( (string) (int) ( $wb_gam_item['id'] ?? 0 ) ); ?>"
					data-wp-context="<?php echo esc_attr( $wb_gam_card_context ); ?>"
				>
					<h4 class="wb-gam-redemption__name"><?php echo esc_html( (string) ( $wb_gam_item['title'] ?? '' ) ); ?></h4>

					<?php if ( ! empty( $wb_gam_item['description'] ) ) : ?>
						<p class="wb-gam-redemption__desc"><?php echo esc_html( (string) $wb_gam_item['description'] ); ?></p>
					<?php endif; ?>

					<div class="wb-gam-redemption__meta">
						<span class="wb-gam-redemption__cost">
							<?php echo esc_html( number_format_i18n( $wb_gam_cost ) ); ?>
							<small class="wb-gam-redemption__cost-unit"><?php echo esc_html( $wb_gam_item_label ); ?></small>
						</span>
						<?php if ( $wb_gam_stock_on && null !== $wb_gam_stock ) : ?>
							<span class="wb-gam-redemption__stock<?php echo $wb_gam_out_of_stock ? ' is-out' : ''; ?>">
								<?php
								if ( $wb_gam_out_of_stock ) {
									esc_html_e( 'Out of stock', 'wb-gamification' );
								} else {
									/* translators: %d: remaining stock */
									printf( esc_html__( '%d left', 'wb-gamification' ), (int) $wb_gam_stock );
								}
								?>
							</span>
						<?php endif; ?>
					</div>

					<div class="wb-gam-redemption__action">
						<?php if ( ! $wb_gam_user_id ) : ?>
							<a class="wb-gam-redemption__login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
								<?php esc_html_e( 'Log in to redeem', 'wb-gamification' ); ?>
							</a>
						<?php elseif ( $wb_gam_out_of_stock ) : ?>
							<button type="button" class="wb-gam-redemption__btn" disabled>
								<?php esc_html_e( 'Out of stock', 'wb-gamification' ); ?>
							</button>
						<?php elseif ( $wb_gam_insufficient ) : ?>
							<button type="button" class="wb-gam-redemption__btn" disabled>
								<?php
								printf(
									/* translators: 1: amount needed, 2: currency label. */
									esc_html__( 'Need %1$s more %2$s', 'wb-gamification' ),
									esc_html( number_format_i18n( $wb_gam_missing_points ) ),
									esc_html( $wb_gam_item_label )
								);
								?>
							</button>
						<?php else : ?>
							<button type="button"
								class="wb-gam-redemption__btn"
								data-wp-on--click="actions.requestRedeem"
								data-wp-bind--hidden="context.confirming"
								data-wp-bind--disabled="context.loading"
								data-wp-class--is-loading="context.loading"
								aria-label="<?php
									/* translators: 1: reward name, 2: cost amount, 3: currency label. */
									echo esc_attr( sprintf( __( 'Redeem %1$s for %2$d %3$s', 'wb-gamification' ), (string) ( $wb_gam_item['title'] ?? '' ), $wb_gam_cost, $wb_gam_item_label ) );
								?>">
								<span data-wp-bind--hidden="context.redeemed"><?php echo esc_html( $wb_gam_btn_lbl ); ?></span>
								<span data-wp-bind--hidden="!context.redeemed" hidden><?php esc_html_e( 'Redeemed', 'wb-gamification' ); ?></span>
							</button>

							<div class="wb-gam-redemption__confirm"
								role="dialog"
								aria-modal="false"
								data-wp-bind--hidden="!context.confirming"
								hidden
							>
								<p class="wb-gam-redemption__confirm-message">
									<?php
									printf(
										/* translators: 1: cost amount, 2: currency label. */
										esc_html__( 'Redeem this reward? %1$s %2$s will be deducted.', 'wb-gamification' ),
										esc_html( number_format_i18n( $wb_gam_cost ) ),
										esc_html( $wb_gam_item_label )
									);
									?>
								</p>
								<div class="wb-gam-redemption__confirm-actions">
									<button type="button"
										class="wb-gam-redemption__confirm-yes"
										data-wp-on--click="actions.confirmRedeem"
									>
										<?php esc_html_e( 'Confirm', 'wb-gamification' ); ?>
									</button>
									<button type="button"
										class="wb-gam-redemption__confirm-no"
										data-wp-on--click="actions.cancelRedeem"
									>
										<?php esc_html_e( 'Cancel', 'wb-gamification' ); ?>
									</button>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<div class="wb-gam-redemption__result is-error"
						role="alert"
						data-wp-bind--hidden="!state.hasError"
						hidden
					>
						<span data-wp-text="context.errorMessage"></span>
						<button type="button"
							class="wb-gam-redemption__confirm-no"
							data-wp-on--click="actions.dismissResult"
							style="margin-left: 8px;"
						>
							<?php esc_html_e( 'Dismiss', 'wb-gamification' ); ?>
						</button>
					</div>

					<div class="wb-gam-redemption__result is-success"
						role="status"
						data-wp-bind--hidden="!state.hasSuccess"
						hidden
					>
						<span data-wp-bind--hidden="!context.couponCode" hidden>
							<?php esc_html_e( 'Redeemed! Your code:', 'wb-gamification' ); ?>
							<code class="wb-gam-redemption__result-code" data-wp-text="context.couponCode"></code>
						</span>
						<span data-wp-bind--hidden="context.couponCode" data-wp-text="context.successMessage"></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after(
	'redemption-store',
	$wb_gam_attrs,
	array(
		'count'   => count( $wb_gam_items ),
		'balance' => $wb_gam_balance_pts,
	)
);
