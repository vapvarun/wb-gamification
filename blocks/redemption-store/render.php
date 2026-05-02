<?php
/**
 * Block: Redemption Store
 *
 * Member-facing rewards catalog. Renders all active items from
 * RedemptionEngine::get_items() as a card grid. Each card shows the
 * member's point cost, stock indicator (when limited), and a "Redeem"
 * button that POSTs to /wb-gamification/v1/redemptions.
 *
 * Logged-out visitors see a "Log in to redeem" link in place of buttons.
 * Users with insufficient points see the button disabled with a "need
 * X more pts" hint.
 *
 * @package WB_Gamification
 * @since   1.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\BlockHooks;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\RedemptionEngine;

$limit        = (int) ( $attributes['limit'] ?? 0 );
$columns      = max( 1, min( 4, (int) ( $attributes['columns'] ?? 3 ) ) );
$show_balance = ! empty( $attributes['show_balance'] );

$items = RedemptionEngine::get_items();
if ( $limit > 0 ) {
	$items = array_slice( $items, 0, $limit );
}

$user_id = get_current_user_id();
$balance = $user_id ? (int) PointsEngine::get_total( $user_id ) : 0;

$wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class'        => 'wb-gam-redemption',
		'data-columns' => (string) $columns,
	)
);

wp_enqueue_style( 'wb-gamification' );

BlockHooks::before(
	'redemption-store',
	$attributes,
	array(
		'count'   => count( $items ),
		'balance' => $balance,
	)
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns sanitized output. ?>>
	<div class="wb-gam-redemption__header">
		<h3 class="wb-gam-redemption__title"><?php esc_html_e( 'Redemption Store', 'wb-gamification' ); ?></h3>
		<?php if ( $show_balance && $user_id ) : ?>
			<span class="wb-gam-redemption__balance" data-wb-gam-balance>
				<?php
				/* translators: %s: formatted point total */
				printf( esc_html__( 'Balance: %s pts', 'wb-gamification' ), esc_html( number_format_i18n( $balance ) ) );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $items ) ) : ?>
		<p class="wb-gam-redemption__empty">
			<?php esc_html_e( 'No rewards available yet. Check back soon!', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-redemption__grid" role="list">
			<?php foreach ( $items as $item ) :
				$cost           = (int) $item['points_cost'];
				$stock          = $item['stock'];
				$out_of_stock   = ( null !== $stock && (int) $stock <= 0 );
				$insufficient   = $user_id && $balance < $cost;
				$missing_points = max( 0, $cost - $balance );
			?>
				<li class="wb-gam-redemption__card" data-item-id="<?php echo esc_attr( (string) (int) $item['id'] ); ?>">
					<h4 class="wb-gam-redemption__name"><?php echo esc_html( $item['title'] ); ?></h4>

					<?php if ( ! empty( $item['description'] ) ) : ?>
						<p class="wb-gam-redemption__desc"><?php echo esc_html( $item['description'] ); ?></p>
					<?php endif; ?>

					<div class="wb-gam-redemption__meta">
						<span class="wb-gam-redemption__cost">
							<?php echo esc_html( number_format_i18n( $cost ) ); ?>
							<small><?php esc_html_e( 'pts', 'wb-gamification' ); ?></small>
						</span>
						<?php if ( null !== $stock ) : ?>
							<span class="wb-gam-redemption__stock<?php echo $out_of_stock ? ' is-out' : ''; ?>">
								<?php
								if ( $out_of_stock ) {
									esc_html_e( 'Out of stock', 'wb-gamification' );
								} else {
									/* translators: %d: remaining stock */
									printf( esc_html__( '%d left', 'wb-gamification' ), (int) $stock );
								}
								?>
							</span>
						<?php endif; ?>
					</div>

					<div class="wb-gam-redemption__action">
						<?php if ( ! $user_id ) : ?>
							<a class="wb-gam-redemption__login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
								<?php esc_html_e( 'Log in to redeem', 'wb-gamification' ); ?>
							</a>
						<?php elseif ( $out_of_stock ) : ?>
							<button type="button" class="wb-gam-redemption__btn" disabled>
								<?php esc_html_e( 'Out of stock', 'wb-gamification' ); ?>
							</button>
						<?php elseif ( $insufficient ) : ?>
							<button type="button" class="wb-gam-redemption__btn" disabled>
								<?php
								/* translators: %s: formatted points still needed */
								printf( esc_html__( 'Need %s more pts', 'wb-gamification' ), esc_html( number_format_i18n( $missing_points ) ) );
								?>
							</button>
						<?php else : ?>
							<button type="button" class="wb-gam-redemption__btn"
								data-wb-gam-redeem
								data-cost="<?php echo esc_attr( (string) $cost ); ?>"
								aria-label="<?php
									/* translators: 1: reward name, 2: cost in points */
									echo esc_attr( sprintf( __( 'Redeem %1$s for %2$d points', 'wb-gamification' ), $item['title'], $cost ) );
								?>">
								<?php esc_html_e( 'Redeem', 'wb-gamification' ); ?>
							</button>
						<?php endif; ?>
					</div>

					<div class="wb-gam-redemption__result" data-wb-gam-result hidden></div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<style>
	.wb-gam-redemption { font-family: var(--wp--preset--font-family--system, sans-serif); }
	.wb-gam-redemption__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
	.wb-gam-redemption__title { margin: 0; font-size: 1.25rem; }
	.wb-gam-redemption__balance { background: #f0f4ff; color: #1a47ad; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 0.9rem; }
	.wb-gam-redemption__grid { list-style: none; margin: 0; padding: 0; display: grid; gap: 16px; grid-template-columns: repeat(var(--wb-gam-cols, 3), minmax(0, 1fr)); }
	.wb-gam-redemption[data-columns="1"] .wb-gam-redemption__grid { --wb-gam-cols: 1; }
	.wb-gam-redemption[data-columns="2"] .wb-gam-redemption__grid { --wb-gam-cols: 2; }
	.wb-gam-redemption[data-columns="3"] .wb-gam-redemption__grid { --wb-gam-cols: 3; }
	.wb-gam-redemption[data-columns="4"] .wb-gam-redemption__grid { --wb-gam-cols: 4; }
	.wb-gam-redemption__card { display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1px solid #e3e6ec; border-radius: 12px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
	.wb-gam-redemption__name { margin: 0; font-size: 1rem; }
	.wb-gam-redemption__desc { margin: 0; color: #555; font-size: 0.9rem; line-height: 1.4; }
	.wb-gam-redemption__meta { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
	.wb-gam-redemption__cost { font-weight: 700; font-size: 1.1rem; color: #1a47ad; }
	.wb-gam-redemption__cost small { font-weight: 500; color: #555; margin-left: 2px; font-size: 0.75rem; }
	.wb-gam-redemption__stock { font-size: 0.8rem; color: #555; }
	.wb-gam-redemption__stock.is-out { color: #b91c1c; font-weight: 600; }
	.wb-gam-redemption__action { margin-top: auto; }
	.wb-gam-redemption__btn { width: 100%; padding: 10px 14px; border: 0; border-radius: 8px; background: #1a47ad; color: #fff; font-weight: 600; cursor: pointer; transition: background 0.15s ease; }
	.wb-gam-redemption__btn:hover:not([disabled]) { background: #133586; }
	.wb-gam-redemption__btn:focus-visible { outline: 3px solid #ffd54a; outline-offset: 2px; }
	.wb-gam-redemption__btn[disabled] { background: #c9ced8; cursor: not-allowed; }
	.wb-gam-redemption__btn.is-loading { opacity: 0.7; pointer-events: none; }
	.wb-gam-redemption__login { display: inline-block; padding: 10px 14px; border-radius: 8px; background: #f0f4ff; color: #1a47ad; text-align: center; text-decoration: none; font-weight: 600; width: 100%; box-sizing: border-box; }
	.wb-gam-redemption__login:hover, .wb-gam-redemption__login:focus-visible { background: #dde6ff; }
	.wb-gam-redemption__result { font-size: 0.9rem; padding: 10px; border-radius: 8px; margin-top: 6px; }
	.wb-gam-redemption__result.is-success { background: #e6f7ec; color: #166534; }
	.wb-gam-redemption__result.is-error { background: #fde8e8; color: #b91c1c; }
	.wb-gam-redemption__result code { background: #fff; padding: 2px 6px; border-radius: 4px; font-weight: 600; user-select: all; }
	.wb-gam-redemption__empty { padding: 24px; text-align: center; color: #555; background: #f8f9fb; border-radius: 12px; }
	@media (max-width: 1024px) {
		.wb-gam-redemption[data-columns="3"] .wb-gam-redemption__grid,
		.wb-gam-redemption[data-columns="4"] .wb-gam-redemption__grid { --wb-gam-cols: 2; }
	}
	@media (max-width: 640px) {
		.wb-gam-redemption__grid { --wb-gam-cols: 1 !important; }
		.wb-gam-redemption__header { align-items: flex-start; }
	}
</style>

<script>
	(function () {
		var root = document.currentScript.previousElementSibling;
		while ( root && ! root.classList.contains( 'wb-gam-redemption' ) ) {
			root = root.previousElementSibling;
		}
		if ( ! root ) { return; }

		var endpoint   = <?php echo wp_json_encode( esc_url_raw( rest_url( 'wb-gamification/v1/redemptions' ) ) ); ?>;
		var nonce      = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		var balanceEl  = root.querySelector( '[data-wb-gam-balance]' );

		var I18N = {
			confirmPrefix: <?php echo wp_json_encode( __( 'Redeem this reward?', 'wb-gamification' ) ); ?>,
			confirmSuffix: <?php echo wp_json_encode( __( 'pts will be deducted.', 'wb-gamification' ) ); ?>,
			redeemFailed:  <?php echo wp_json_encode( __( 'Redemption failed.', 'wb-gamification' ) ); ?>,
			yourCode:      <?php echo wp_json_encode( __( 'Redeemed! Your code:', 'wb-gamification' ) ); ?>,
			pendingMsg:    <?php echo wp_json_encode( __( 'Redeemed! Check your email or your account for next steps.', 'wb-gamification' ) ); ?>,
			redeemedLabel: <?php echo wp_json_encode( __( 'Redeemed', 'wb-gamification' ) ); ?>,
			networkError:  <?php echo wp_json_encode( __( 'Network error. Please try again.', 'wb-gamification' ) ); ?>
		};

		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-wb-gam-redeem]' );
			if ( ! btn || btn.disabled ) { return; }

			var card    = btn.closest( '.wb-gam-redemption__card' );
			var itemId  = parseInt( card.getAttribute( 'data-item-id' ), 10 );
			var cost    = parseInt( btn.getAttribute( 'data-cost' ), 10 ) || 0;
			var result  = card.querySelector( '[data-wb-gam-result]' );

			if ( ! window.confirm( I18N.confirmPrefix + '\n' + cost + ' ' + I18N.confirmSuffix ) ) { return; }

			btn.classList.add( 'is-loading' );
			btn.disabled = true;

			fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { item_id: itemId } )
			} )
			.then( function ( res ) { return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } ); } )
			.then( function ( payload ) {
				btn.classList.remove( 'is-loading' );
				while ( result.firstChild ) { result.removeChild( result.firstChild ); }

				if ( ! payload.ok ) {
					result.hidden = false;
					result.className = 'wb-gam-redemption__result is-error';
					result.textContent = ( payload.body && payload.body.message ) ? payload.body.message : I18N.redeemFailed;
					btn.disabled = false;
					return;
				}

				result.hidden = false;
				result.className = 'wb-gam-redemption__result is-success';

				if ( payload.body && payload.body.coupon_code ) {
					result.appendChild( document.createTextNode( I18N.yourCode + ' ' ) );
					var codeEl = document.createElement( 'code' );
					codeEl.textContent = String( payload.body.coupon_code );
					result.appendChild( codeEl );
				} else {
					result.textContent = I18N.pendingMsg;
				}

				btn.textContent = I18N.redeemedLabel;
				if ( balanceEl ) {
					var existing  = parseInt( balanceEl.textContent.replace( /[^\d]/g, '' ), 10 ) || 0;
					var newAmount = Math.max( 0, existing - cost );
					balanceEl.textContent = balanceEl.textContent.replace( /[\d,.]+/, newAmount.toLocaleString() );
				}
			} )
			.catch( function () {
				btn.classList.remove( 'is-loading' );
				btn.disabled = false;
				while ( result.firstChild ) { result.removeChild( result.firstChild ); }
				result.hidden = false;
				result.className = 'wb-gam-redemption__result is-error';
				result.textContent = I18N.networkError;
			} );
		} );
	})();
</script>

<?php
BlockHooks::after(
	'redemption-store',
	$attributes,
	array(
		'count'   => count( $items ),
		'balance' => $balance,
	)
);
