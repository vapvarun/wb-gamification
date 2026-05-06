<?php
/**
 * Admin: Currency Conversions
 *
 * Adds "Conversions" submenu under WB Gamification.
 * Manages exchange-rate rules between point currencies (e.g. 100 Points → 1 Coin).
 *
 * REST-driven via the generic admin-rest-form.js driver — no per-page JS.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Services\PointTypeConversionService;
use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for managing currency-conversion rates.
 */
final class PointTypeConversionsPage {

	/**
	 * Register admin_menu + admin_enqueue_scripts hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue REST-form driver on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-conversions' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_enqueue_script(
			'wb-gam-admin-rest-form',
			plugins_url( 'assets/js/admin-rest-form.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-rest-form',
			'wbGamConversionsSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Conversion rule saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the conversion rule.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Register the submenu under WB Gamification.
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Conversions', 'wb-gamification' ),
			__( 'Conversions', 'wb-gamification' ),
			'manage_options',
			'wb-gam-conversions',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page — list of existing rules + create form.
	 */
	public static function render_page(): void {
		$type_service = new PointTypeService();
		$conv_service = new PointTypeConversionService();

		$types = $type_service->list();
		$rules = $conv_service->list_active();

		// Fewer than 2 types — conversion is meaningless. Show a guidance card
		// instead of the form so admins know what to do.
		if ( count( $types ) < 2 ) :
			?>
			<div class="wrap wbgam-wrap">
				<header class="wbgam-page-header">
					<div class="wbgam-page-header__main">
						<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Currency Conversions', 'wb-gamification' ); ?></h1>
						<p class="wbgam-page-header__desc"><?php esc_html_e( 'Define exchange rates between point currencies — e.g. 100 Points → 1 Coin. Members convert their balance via the Hub block or REST API.', 'wb-gamification' ); ?></p>
					</div>
				</header>
				<div class="wbgam-banner wbgam-banner--info wbgam-stack-block">
					<span class="wbgam-banner__icon dashicons dashicons-info" aria-hidden="true"></span>
					<div class="wbgam-banner__body">
						<strong class="wbgam-banner__title"><?php esc_html_e( 'Add a second currency first', 'wb-gamification' ); ?></strong>
						<p class="wbgam-banner__desc">
							<?php
							printf(
								/* translators: %s: link to Point Types admin page. */
								wp_kses(
									__( 'Conversion rules need at least two point types. Visit <a href="%s">Point Types</a> to add another currency (e.g. XP, Coins, Karma) before defining a conversion rule.', 'wb-gamification' ),
									array( 'a' => array( 'href' => array() ) )
								),
								esc_url( admin_url( 'admin.php?page=wb-gam-point-types' ) )
							);
							?>
						</p>
					</div>
				</div>
			</div>
			<?php
			return;
		endif;
		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Currency Conversions', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Define exchange rates between point currencies — e.g. 100 Points → 1 Coin. Members convert their balance via the Hub block or REST API. Defaults are permissive — only set cooldown / daily cap if your economy needs it.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<!-- Existing rules card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Active Rules', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<?php if ( empty( $rules ) ) : ?>
						<p class="wbgam-text-muted"><?php esc_html_e( 'No conversion rules yet. Add one below.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<div class="wbgam-table-scroll">
							<table class="wbgam-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Rate', 'wb-gamification' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Min', 'wb-gamification' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Cooldown', 'wb-gamification' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Daily cap', 'wb-gamification' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rules as $rule ) : ?>
										<?php
										$from_label = isset( $rule['from']['label'] ) ? (string) $rule['from']['label'] : (string) $rule['from_type'];
										$to_label   = isset( $rule['to']['label'] ) ? (string) $rule['to']['label'] : (string) $rule['to_type'];
										?>
										<tr>
											<td>
												<strong>
													<?php
													printf(
														/* translators: 1: source amount, 2: source label, 3: destination amount, 4: destination label. */
														esc_html__( '%1$d %2$s = %3$d %4$s', 'wb-gamification' ),
														(int) $rule['from_amount'],
														esc_html( $from_label ),
														(int) $rule['to_amount'],
														esc_html( $to_label )
													);
													?>
												</strong>
											</td>
											<td><?php echo esc_html( (string) ( $rule['min_convert'] ?? 1 ) ); ?></td>
											<td>
												<?php
												$cd = (int) ( $rule['cooldown_seconds'] ?? 0 );
												if ( 0 === $cd ) {
													echo '<span class="wbgam-pill wbgam-pill--inactive">' . esc_html__( 'None', 'wb-gamification' ) . '</span>';
												} else {
													echo esc_html( (string) $cd ) . 's';
												}
												?>
											</td>
											<td>
												<?php
												$cap = (int) ( $rule['max_per_day'] ?? 0 );
												if ( 0 === $cap ) {
													echo '<span class="wbgam-pill wbgam-pill--inactive">' . esc_html__( 'Unlimited', 'wb-gamification' ) . '</span>';
												} else {
													echo esc_html( (string) $cap );
												}
												?>
											</td>
											<td>
												<button type="button"
													class="wbgam-btn wbgam-btn--sm wbgam-btn--danger"
													data-wb-gam-rest-action="wbGamConversionsSettings"
													data-wb-gam-rest-method="DELETE"
													data-wb-gam-rest-path="/point-type-conversions/<?php echo (int) $rule['id']; ?>"
													data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this conversion rule? Past conversions stay in members\' history.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Conversion rule deleted.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-after="remove-row">
													<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Create rule card -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Add Conversion Rule', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form data-wb-gam-rest-form="wbGamConversionsSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="/point-type-conversions"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Conversion rule added.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Could not add the rule.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload">

						<table class="form-table">
							<tr>
								<th><label for="wb-gam-conv-from"><?php esc_html_e( 'From', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-conv-from-amount" name="from_amount" class="wbgam-input wbgam-input--xs" required min="1" max="999999" value="100">
									<select name="from_type" id="wb-gam-conv-from" class="wbgam-select" required>
										<?php foreach ( $types as $t ) : ?>
											<option value="<?php echo esc_attr( (string) $t['slug'] ); ?>"><?php echo esc_html( (string) $t['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Source amount + currency the member spends.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-conv-to"><?php esc_html_e( 'To', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-conv-to-amount" name="to_amount" class="wbgam-input wbgam-input--xs" required min="1" max="999999" value="1">
									<select name="to_type" id="wb-gam-conv-to" class="wbgam-select" required>
										<?php foreach ( $types as $t ) : ?>
											<option value="<?php echo esc_attr( (string) $t['slug'] ); ?>"><?php echo esc_html( (string) $t['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Destination amount + currency the member receives.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-conv-min"><?php esc_html_e( 'Minimum', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-conv-min" name="min_convert" class="wbgam-input wbgam-input--xs" value="1" min="1" max="999999">
									<p class="description"><?php esc_html_e( 'Smallest source amount per conversion. Default 1 (no extra restriction beyond the rate).', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-conv-cooldown"><?php esc_html_e( 'Cooldown (seconds)', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-conv-cooldown" name="cooldown_seconds" class="wbgam-input wbgam-input--xs" value="0" min="0" max="604800">
									<p class="description"><?php esc_html_e( 'Time between conversions per user. 0 = no cooldown (recommended unless you see abuse).', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-conv-cap"><?php esc_html_e( 'Daily cap', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-conv-cap" name="max_per_day" class="wbgam-input wbgam-input--xs" value="0" min="0" max="9999">
									<p class="description"><?php esc_html_e( 'Max conversions per user per day for this pair. 0 = unlimited (recommended).', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="wbgam-btn"><?php esc_html_e( 'Add Rule', 'wb-gamification' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
