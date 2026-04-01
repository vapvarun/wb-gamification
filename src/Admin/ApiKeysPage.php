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
		add_action( 'admin_post_wb_gam_create_api_key', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_wb_gam_revoke_api_key', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_wb_gam_delete_api_key', array( __CLASS__, 'handle_delete' ) );
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
		$keys    = ApiKeyAuth::get_keys();
		$new_key = get_transient( 'wb_gam_new_api_key' );
		if ( $new_key ) {
			delete_transient( 'wb_gam_new_api_key' );
		}
		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'API Keys', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Generate API keys for remote sites to connect to this gamification center. Keys authenticate via the X-WB-Gam-Key header.', 'wb-gamification' ); ?></p>

			<?php if ( $new_key ) : ?>
				<div class="notice notice-success wb-gam-notice">
					<p><strong><?php esc_html_e( 'New API key generated. Copy it now — it will not be shown again:', 'wb-gamification' ); ?></strong></p>
					<p><code class="wbgam-key-display"><?php echo esc_html( $new_key ); ?></code></p>
				</div>
			<?php endif; ?>

			<!-- Generate Key Form -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Generate New Key', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wb_gam_create_api_key', 'wb_gam_key_nonce' ); ?>
						<input type="hidden" name="action" value="wb_gam_create_api_key">
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
						<p><button type="submit" class="wbgam-btn"><?php esc_html_e( 'Generate Key', 'wb-gamification' ); ?></button></p>
					</form>
				</div>
			</div>

			<!-- Active Keys List -->
			<?php if ( ! empty( $keys ) ) : ?>
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Active Keys', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body" style="padding:0;">
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
						<tbody>
							<?php foreach ( $keys as $key => $data ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $data['label'] ?? '' ); ?></strong></td>
									<td><code><?php echo esc_html( $data['site_id'] ?? '—' ); ?></code></td>
									<td><code><?php echo esc_html( substr( $key, 0, 12 ) . '...' ); ?></code></td>
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
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wb_gam_revoke_api_key', 'wb_gam_key_nonce' ); ?>
												<input type="hidden" name="action" value="wb_gam_revoke_api_key">
												<input type="hidden" name="api_key" value="<?php echo esc_attr( $key ); ?>">
												<button type="submit" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary"><?php esc_html_e( 'Revoke', 'wb-gamification' ); ?></button>
											</form>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'wb_gam_delete_api_key', 'wb_gam_key_nonce' ); ?>
											<input type="hidden" name="action" value="wb_gam_delete_api_key">
											<input type="hidden" name="api_key" value="<?php echo esc_attr( $key ); ?>">
											<button type="submit" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger" onclick="return confirm('<?php esc_attr_e( 'Delete this key permanently?', 'wb-gamification' ); ?>');" style="margin-left:4px;"><?php esc_html_e( 'Delete', 'wb-gamification' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-admin-network" style="font-size:48px;width:48px;height:48px;color:var(--wbgam-text-muted);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No API keys yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Generate your first API key above to connect remote sites.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle API key creation form submission.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		check_admin_referer( 'wb_gam_create_api_key', 'wb_gam_key_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$label   = sanitize_text_field( wp_unslash( $_POST['key_label'] ?? '' ) );
		$site_id = sanitize_key( wp_unslash( $_POST['site_id'] ?? '' ) );

		if ( ! $label ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-api-keys&error=no_label' ) );
			exit;
		}

		$key = ApiKeyAuth::create_key( $label, get_current_user_id(), $site_id );
		set_transient( 'wb_gam_new_api_key', $key, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-api-keys' ) );
		exit;
	}

	/**
	 * Handle API key revocation form submission.
	 *
	 * @return void
	 */
	public static function handle_revoke(): void {
		check_admin_referer( 'wb_gam_revoke_api_key', 'wb_gam_key_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		ApiKeyAuth::revoke_key( $key );
		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-api-keys' ) );
		exit;
	}

	/**
	 * Handle API key deletion form submission.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		check_admin_referer( 'wb_gam_delete_api_key', 'wb_gam_key_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		ApiKeyAuth::delete_key( $key );
		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-api-keys' ) );
		exit;
	}
}
