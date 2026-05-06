<?php
/**
 * Admin: API Keys Management
 *
 * Lets admins create, view, revoke, and delete API keys for remote
 * sites connecting to this gamification center.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\API\ApiKeyAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the API Keys admin page — form, nonce handling, and key listing.
 *
 * @package WB_Gamification
 */
final class ApiKeysPage {

	/**
	 * Register admin_menu and admin_post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_{create,revoke,delete}_api_key removed in 1.0.0:
		// page now consumes /wb-gamification/v1/api-keys (POST/PATCH/DELETE)
		// directly via assets/js/admin-api-keys.js. See Tier 0.C migration.
	}

	/**
	 * Enqueue REST-driven JS bundle on the API Keys admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-api-keys' !== $hook_suffix ) {
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
			'wb-gam-admin-api-keys',
			plugins_url( 'assets/js/admin-api-keys.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);

		wp_localize_script(
			'wb-gam-admin-api-keys',
			'wbGamApiKeysSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'active'         => __( 'Active', 'wb-gamification' ),
					'revoked'        => __( 'Revoked', 'wb-gamification' ),
					'revoke'         => __( 'Revoke', 'wb-gamification' ),
					'delete'         => __( 'Delete', 'wb-gamification' ),
					'created'        => __( 'API key generated.', 'wb-gamification' ),
					'create_failed'  => __( 'Failed to generate API key.', 'wb-gamification' ),
					'label_required' => __( 'Label is required.', 'wb-gamification' ),
					'revoked_msg'    => __( 'API key revoked.', 'wb-gamification' ),
					'revoke_failed'  => __( 'Failed to revoke key.', 'wb-gamification' ),
					'deleted'        => __( 'API key deleted.', 'wb-gamification' ),
					'delete_failed'  => __( 'Failed to delete key.', 'wb-gamification' ),
					'confirm_delete' => __( 'Delete this key permanently?', 'wb-gamification' ),
					'refresh_failed' => __( 'Failed to load API keys.', 'wb-gamification' ),
					'row_no_key'     => __( 'Reload the page and try again.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Register the API Keys submenu under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'API Keys', 'wb-gamification' ),
			__( 'API Keys', 'wb-gamification' ),
			'manage_options',
			'wb-gam-api-keys',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the API Keys admin page with creation form and key listing.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		$keys = ApiKeyAuth::get_keys();
		?>
		<div class="wrap wbgam-wrap" data-wb-gam-api-keys-root>
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'API Keys', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Generate API keys for remote sites to connect to this gamification center. Keys authenticate via the X-WB-Gam-Key header.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<div class="wbgam-banner wbgam-banner--success wbgam-stack-block wbgam-is-hidden" data-wb-gam-api-keys-fresh role="status" aria-live="polite">
				<span class="wbgam-banner__icon icon-check-circle" aria-hidden="true"></span>
				<div class="wbgam-banner__body">
					<strong class="wbgam-banner__title"><?php esc_html_e( 'New API key generated', 'wb-gamification' ); ?></strong>
					<p class="wbgam-banner__desc"><?php esc_html_e( 'Copy it now — it will not be shown again.', 'wb-gamification' ); ?></p>
					<code class="wbgam-key-display" data-wb-gam-api-keys-fresh-code></code>
				</div>
				<button type="button" class="wbgam-banner__dismiss" data-wb-gam-banner-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'wb-gamification' ); ?>">
					<span class="icon-x" aria-hidden="true"></span>
				</button>
			</div>

			<!-- Generate Key Form -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Generate New Key', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form data-wb-gam-api-keys-create-form>
						<table class="form-table">
							<tr>
								<th><label for="key_label"><?php esc_html_e( 'Label', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" id="key_label" name="key_label" class="regular-text wbgam-input" placeholder="e.g. MediaVerse Production" required>
									<p class="description"><?php esc_html_e( 'A human-readable name to identify this key, e.g. the site or application it belongs to.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="site_id"><?php esc_html_e( 'Site ID', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" id="site_id" name="site_id" class="regular-text wbgam-input" placeholder="e.g. mediaverse-prod">
									<p class="description"><?php esc_html_e( 'Unique identifier for the remote site. Used for per-site reporting.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>
						<p><button type="submit" class="wbgam-btn" data-wb-gam-api-keys-create><?php esc_html_e( 'Generate Key', 'wb-gamification' ); ?></button></p>
					</form>
				</div>
			</div>

			<!-- Active Keys List (server-rendered for first paint; JS rerenders on every mutation) -->
			<div class="wbgam-card" data-wb-gam-api-keys-card<?php echo empty( $keys ) ? ' class="wbgam-is-hidden"' : ''; ?>>
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Active Keys', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body wbgam-card-body--flush">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Site ID', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Key (prefix)', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Created', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Last Used', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody data-wb-gam-api-keys-tbody>
							<?php foreach ( $keys as $key => $data ) : ?>
								<tr data-key-preview="<?php echo esc_attr( substr( $key, 0, 10 ) . '…' . substr( $key, -4 ) ); ?>" data-full-key="<?php echo esc_attr( $key ); ?>">
									<td><strong><?php echo esc_html( $data['label'] ?? '' ); ?></strong></td>
									<td><code><?php echo esc_html( $data['site_id'] ?? '—' ); ?></code></td>
									<td><code><?php echo esc_html( substr( $key, 0, 10 ) . '…' . substr( $key, -4 ) ); ?></code></td>
									<td><?php echo esc_html( $data['created'] ?? '' ); ?></td>
									<td><?php echo esc_html( $data['last_used'] ?: '—' ); ?></td>
									<td>
										<?php if ( ! empty( $data['active'] ) ) : ?>
											<span class="wbgam-pill wbgam-pill--active"><?php esc_html_e( 'Active', 'wb-gamification' ); ?></span>
										<?php else : ?>
											<span class="wbgam-pill wbgam-pill--danger"><?php esc_html_e( 'Revoked', 'wb-gamification' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $data['active'] ) ) : ?>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary" data-wb-gam-api-key-action="revoke"><?php esc_html_e( 'Revoke', 'wb-gamification' ); ?></button>
										<?php endif; ?>
										<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger wbgam-ms-xs" data-wb-gam-api-key-action="delete"><?php esc_html_e( 'Delete', 'wb-gamification' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="wbgam-empty" data-wb-gam-api-keys-empty<?php echo empty( $keys ) ? '' : ' class="wbgam-is-hidden"'; ?>>
				<div class="wbgam-empty-icon"><span class="icon-network wbgam-icon-xl wbgam-icon-xl--muted"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No API keys yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Generate your first API key above to connect remote sites.', 'wb-gamification' ); ?></p>
			</div>
		</div>
		<?php
	}
}
