<?php
/**
 * Admin: Point Types
 *
 * Adds "Point Types" submenu under WB Gamification.
 * Manages the catalogue of point currencies (Points / XP / Coins / ...).
 *
 * REST-driven via the generic `assets/js/admin-rest-form.js` driver — no
 * per-page JS. Form attributes route through `wbGamPointTypesSettings`
 * (localised below) to `/wb-gamification/v1/point-types`.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for managing the point-types catalogue.
 */
final class PointTypesPage {

	/**
	 * Register admin_menu + admin_enqueue_scripts hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the REST-form driver on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-point-types' !== $hook_suffix ) {
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
			'wbGamPointTypesSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Point type saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the point type.', 'wb-gamification' ),
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
			__( 'Point Types', 'wb-gamification' ),
			__( 'Point Types', 'wb-gamification' ),
			'manage_options',
			'wb-gam-point-types',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page — list of existing types + create form.
	 */
	public static function render_page(): void {
		$service = new PointTypeService();
		$types   = $service->list();
		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Point Types', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Define the point currencies your site supports — e.g. Points for general activity, XP for learning, Coins for the redemption store. Each currency has its own ledger; balances stay isolated. The default currency is used when an action does not specify a type.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<!-- Existing types card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Existing Types', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<?php if ( empty( $types ) ) : ?>
						<p><?php esc_html_e( 'No point types yet.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<div class="wbgam-table-scroll">
						<table class="wbgam-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Slug', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Label', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Default', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Position', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $types as $type ) : ?>
									<?php
									$slug        = (string) $type['slug'];
									$is_default  = (int) $type['is_default'] === 1;
									$delete_path = '/point-types/' . rawurlencode( $slug );
									?>
									<tr>
										<td><code><?php echo esc_html( $slug ); ?></code></td>
										<td><?php echo esc_html( (string) $type['label'] ); ?></td>
										<td><?php echo esc_html( (string) ( $type['description'] ?? '' ) ); ?></td>
										<td>
											<?php if ( $is_default ) : ?>
												<span class="wbgam-badge wbgam-badge--success"><?php esc_html_e( 'Default', 'wb-gamification' ); ?></span>
											<?php else : ?>
												<button type="button"
													class="button button-link"
													data-wb-gam-rest-action="wbGamPointTypesSettings"
													data-wb-gam-rest-method="PUT"
													data-wb-gam-rest-path="/point-types/<?php echo esc_attr( $slug ); ?>"
													data-wb-gam-rest-body='{"is_default":true}'
													data-wb-gam-rest-confirm="<?php
													/* translators: %s: candidate point-type label about to be promoted to default. */
													echo esc_attr( sprintf( __( 'Make %s the default currency? Actions without an explicit currency will start awarding this type going forward. Existing balances are NOT migrated — every row keeps its original ledger.', 'wb-gamification' ), (string) $type['label'] ) );
													?>"
													data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Default point type updated.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-after="reload">
													<?php esc_html_e( 'Make default', 'wb-gamification' ); ?>
												</button>
											<?php endif; ?>
										</td>
										<td><?php echo (int) $type['position']; ?></td>
										<td>
											<?php if ( ! $is_default ) : ?>
												<button type="button"
													class="button button-small button-link-delete"
													data-wb-gam-rest-action="wbGamPointTypesSettings"
													data-wb-gam-rest-method="DELETE"
													data-wb-gam-rest-path="<?php echo esc_attr( $delete_path ); ?>"
													data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this point type? Existing rows in the ledger will keep this slug.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Point type deleted.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-after="remove-row">
													<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
												</button>
											<?php else : ?>
												<span class="description"><?php esc_html_e( 'Default — protected', 'wb-gamification' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Create new type card -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Add New Point Type', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form data-wb-gam-rest-form="wbGamPointTypesSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="/point-types"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Point type created.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Could not create the point type.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload">

						<table class="form-table">
							<tr>
								<th><label for="wb-gam-pt-slug"><?php esc_html_e( 'Slug', 'wb-gamification' ); ?> <span class="required">*</span></label></th>
								<td>
									<input type="text" id="wb-gam-pt-slug" name="slug" class="wbgam-input regular-text" required pattern="[a-z0-9_-]+" maxlength="60" placeholder="xp">
									<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, dashes, underscores. Immutable after creation. Used as the API identifier.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-pt-label"><?php esc_html_e( 'Label', 'wb-gamification' ); ?> <span class="required">*</span></label></th>
								<td>
									<input type="text" id="wb-gam-pt-label" name="label" class="wbgam-input regular-text" required maxlength="100" placeholder="<?php esc_attr_e( 'XP', 'wb-gamification' ); ?>">
									<p class="description"><?php esc_html_e( 'Human-readable name shown to members.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-pt-description"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></label></th>
								<td>
									<textarea id="wb-gam-pt-description" name="description" class="wbgam-textarea large-text" rows="2"></textarea>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-pt-icon"><?php esc_html_e( 'Icon', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" id="wb-gam-pt-icon" name="icon" class="wbgam-input regular-text" placeholder="star">
									<p class="description">
										<?php
										printf(
											/* translators: %s: link to the Lucide icons site. */
											wp_kses(
												__( 'Lucide icon name (e.g. <code>star</code>, <code>coins</code>, <code>flame</code>). Browse all icons at <a href="%s" target="_blank" rel="noopener">lucide.dev/icons</a>.', 'wb-gamification' ),
												array(
													'code' => array(),
													'a'    => array(
														'href'   => array(),
														'target' => array(),
														'rel'    => array(),
													),
												)
											),
											'https://lucide.dev/icons/'
										);
										?>
									</p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-pt-position"><?php esc_html_e( 'Position', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" id="wb-gam-pt-position" name="position" class="wbgam-input small-text" value="0" min="0" step="1">
									<p class="description"><?php esc_html_e( 'Display order in lists. Lower numbers appear first.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Point Type', 'wb-gamification' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
