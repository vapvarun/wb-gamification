<?php
/**
 * WB Gamification — Webhooks admin page
 *
 * Closes the surface gap surfaced in
 * plans/ARCHITECTURE-DRIVEN-PLAN.md Phase 3.1: WebhookDispatcher is
 * an Admin-tier engine and was REST-only. Site owners without API
 * skills now manage webhooks from this page.
 *
 * The page reads/writes the wb_gam_webhooks table directly via the
 * existing WebhookDispatcher schema. Form submissions are nonce-gated
 * and capability-gated by wb_gam_manage_webhooks.
 *
 * @package WB_Gamification
 * @since   1.1.0
 */

namespace WBGam\Admin;

use WBGam\Engine\Capabilities;
use WBGam\Engine\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for managing outbound webhooks.
 *
 * @package WB_Gamification
 */
final class WebhooksAdminPage {

	private const PAGE_SLUG  = 'wb-gam-webhooks';
	private const NONCE_NAME = 'wb_gam_webhooks_nonce';

	/**
	 * Hook the admin menu + form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wb_gam_webhook_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_webhook_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the submenu under Gamification.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Webhooks', 'wb-gamification' ),
			__( 'Webhooks', 'wb-gamification' ),
			'wb_gam_manage_webhooks',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page (list + create form).
	 */
	public static function render(): void {
		if ( ! Capabilities::user_can( 'wb_gam_manage_webhooks' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage webhooks.', 'wb-gamification' ) );
		}

		$webhooks = self::list_webhooks();
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webhooks', 'wb-gamification' ); ?></h1>
			<p><?php esc_html_e( 'Outbound webhooks notify external services (Zapier, Slack, custom servers) when gamification events fire. Failed deliveries auto-retry up to 3 times with exponential backoff.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $_GET['notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( self::notice_text( sanitize_key( wp_unslash( $_GET['notice'] ) ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add Webhook', 'wb-gamification' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wb-gam-webhook-form">
				<?php wp_nonce_field( 'wb_gam_webhook_save', self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="wb_gam_webhook_save">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wb-gam-webhook-url"><?php esc_html_e( 'Endpoint URL', 'wb-gamification' ); ?></label>
						</th>
						<td>
							<input type="url" id="wb-gam-webhook-url" name="url" class="regular-text" required placeholder="https://example.com/webhook" />
							<p class="description"><?php esc_html_e( 'HTTPS only in production. Each delivery POSTs JSON with an X-WB-Gam-Signature HMAC header.', 'wb-gamification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wb-gam-webhook-secret"><?php esc_html_e( 'Secret', 'wb-gamification' ); ?></label>
						</th>
						<td>
							<input type="text" id="wb-gam-webhook-secret" name="secret" class="regular-text" required placeholder="<?php esc_attr_e( 'auto-generated if blank', 'wb-gamification' ); ?>" />
							<p class="description"><?php esc_html_e( 'Used to sign every payload (sha256 HMAC). Receiver verifies the signature before trusting the delivery.', 'wb-gamification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events', 'wb-gamification' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Events to subscribe to', 'wb-gamification' ); ?></legend>
								<?php foreach ( self::available_events() as $event => $label ) : ?>
									<label class="wb-gam-event-checkbox">
										<input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" />
										<code><?php echo esc_html( $event ); ?></code>
										<span class="description">— <?php echo esc_html( $label ); ?></span>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Add Webhook', 'wb-gamification' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Configured Webhooks', 'wb-gamification' ); ?></h2>
			<?php if ( empty( $webhooks ) ) : ?>
				<p class="wb-gam-empty">
					<?php esc_html_e( 'No webhooks configured yet. Add one above.', 'wb-gamification' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Events', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Active', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Created', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $webhooks as $hook ) :
							$events = json_decode( $hook['events'], true ) ?: array();
							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'     => 'wb_gam_webhook_delete',
										'webhook_id' => (int) $hook['id'],
									),
									admin_url( 'admin-post.php' )
								),
								'wb_gam_webhook_delete_' . $hook['id']
							);
						?>
							<tr>
								<td><code><?php echo esc_html( $hook['url'] ); ?></code></td>
								<td>
									<?php foreach ( $events as $e ) : ?>
										<code class="wb-gam-event-tag"><?php echo esc_html( $e ); ?></code>
									<?php endforeach; ?>
								</td>
								<td>
									<?php echo $hook['is_active'] ? '✓' : '—'; ?>
								</td>
								<td><?php echo esc_html( $hook['created_at'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( $delete_url ); ?>"
									   class="button button-link-delete"
									   onclick="return confirm('<?php echo esc_js( __( 'Delete this webhook? Existing deliveries already in flight will still complete.', 'wb-gamification' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Form handlers ──────────────────────────────────────────────────────────

	/**
	 * Handle the "Add Webhook" form submission.
	 */
	public static function handle_save(): void {
		if ( ! Capabilities::user_can( 'wb_gam_manage_webhooks' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		check_admin_referer( 'wb_gam_webhook_save', self::NONCE_NAME );

		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		$events = isset( $_POST['events'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['events'] ) ) : array();

		if ( empty( $url ) ) {
			self::redirect_back( 'invalid_url' );
		}

		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 32, false );
		}

		$valid_events = array_keys( self::available_events() );
		$events       = array_values( array_intersect( $events, $valid_events ) );

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_webhooks',
			array(
				'url'    => $url,
				'secret' => $secret,
				'events' => wp_json_encode( $events ),
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			Log::error(
				'WebhooksAdminPage: failed to insert wb_gam_webhooks row.',
				array(
					'url'      => $url,
					'wpdb_err' => $wpdb->last_error ?: 'unknown',
				)
			);
			self::redirect_back( 'save_failed' );
		}

		self::redirect_back( 'saved' );
	}

	/**
	 * Handle the per-row delete action.
	 */
	public static function handle_delete(): void {
		if ( ! Capabilities::user_can( 'wb_gam_manage_webhooks' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		$webhook_id = isset( $_GET['webhook_id'] ) ? (int) $_GET['webhook_id'] : 0;
		check_admin_referer( 'wb_gam_webhook_delete_' . $webhook_id );

		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'wb_gam_webhooks',
			array( 'id' => $webhook_id ),
			array( '%d' )
		);

		self::redirect_back( 'deleted' );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Available event types subscribers can subscribe to.
	 *
	 * @return array<string,string> Event name → human description.
	 */
	private static function available_events(): array {
		return array(
			'points_awarded'                => __( 'Points awarded to a member', 'wb-gamification' ),
			'points_revoked'                => __( 'Points revoked by an admin', 'wb-gamification' ),
			'badge_awarded'                 => __( 'Member earned a badge', 'wb-gamification' ),
			'level_changed'                 => __( 'Member crossed a level threshold', 'wb-gamification' ),
			'streak_milestone'              => __( 'Streak milestone reached', 'wb-gamification' ),
			'streak_broken'                 => __( 'Streak broken', 'wb-gamification' ),
			'kudos_given'                   => __( 'Peer kudos transaction', 'wb-gamification' ),
			'challenge_completed'           => __( 'Individual challenge completed', 'wb-gamification' ),
			'community_challenge_completed' => __( 'Community challenge target reached', 'wb-gamification' ),
		);
	}

	/**
	 * Read all webhooks from the DB.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function list_webhooks(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			"SELECT id, url, events, is_active, created_at
			   FROM {$wpdb->prefix}wb_gam_webhooks
			  ORDER BY created_at DESC",
			ARRAY_A
		);
	}

	/**
	 * Redirect back to the page with a notice.
	 *
	 * @param string $notice Notice key.
	 */
	private static function redirect_back( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Notice text by key.
	 *
	 * @param string $key Notice key.
	 * @return string Localized notice.
	 */
	private static function notice_text( string $key ): string {
		switch ( $key ) {
			case 'saved':       return __( 'Webhook saved.', 'wb-gamification' );
			case 'deleted':     return __( 'Webhook deleted.', 'wb-gamification' );
			case 'invalid_url': return __( 'A valid URL is required.', 'wb-gamification' );
			case 'save_failed': return __( 'Failed to save the webhook. Check the error log for details.', 'wb-gamification' );
			default:            return '';
		}
	}
}
