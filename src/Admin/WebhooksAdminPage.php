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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_webhook_{save,delete} removed in 1.0.0 — page now
		// consumes /wb-gamification/v1/webhooks (POST/DELETE) directly via the
		// generic admin-rest-form driver. See Tier 0.C migration.
	}

	/**
	 * Enqueue REST-driven JS bundle on the Webhooks admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-webhooks' !== $hook_suffix ) {
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
			'wbGamWebhooksSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Webhook saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the webhook.', 'wb-gamification' ),
				),
			)
		);
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
		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Webhooks', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Outbound webhooks notify external services (Zapier, Slack, custom servers) when gamification events fire. Failed deliveries auto-retry up to 3 times with exponential backoff.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<details class="wbgam-help-panel">
				<summary class="wbgam-help-panel__summary">
					<span class="icon-info" aria-hidden="true"></span>
					<?php esc_html_e( 'How webhook delivery + signing works', 'wb-gamification' ); ?>
				</summary>
				<div class="wbgam-help-panel__body">
					<p>
						<strong><?php esc_html_e( 'Delivery:', 'wb-gamification' ); ?></strong>
						<?php esc_html_e( 'When a subscribed event fires (e.g. points awarded, badge earned), the dispatcher POSTs a JSON body to your endpoint URL. Delivery is async (Action Scheduler queue) so the page-load that triggered the event is never blocked.', 'wb-gamification' ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Signature verification:', 'wb-gamification' ); ?></strong>
						<?php
						printf(
							wp_kses(
								/* translators: %s: header name wrapped in code */
								__( 'Each request includes a %s header containing an HMAC-SHA256 signature of the raw body, computed with your webhook secret. Always verify before trusting the delivery — anyone can hit your URL.', 'wb-gamification' ),
								array( 'code' => array() )
							),
							'<code>X-WB-Gam-Signature</code>'
						);
						?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Retry behavior:', 'wb-gamification' ); ?></strong>
						<?php esc_html_e( 'A non-2xx response (or a network failure) schedules a retry with exponential backoff (1m → 5m → 30m). After 3 failed attempts the webhook is automatically paused. Resume by editing the row in the database (set is_active=1) — admin UI flag in v1.1.', 'wb-gamification' ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Payload shape:', 'wb-gamification' ); ?></strong>
						<?php esc_html_e( 'JSON object with `event` (e.g. badge_earned), `user_id`, `data` (event-specific fields), and `fired_at` (ISO 8601). Match against the event slug to dispatch on the receiver side.', 'wb-gamification' ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Production hygiene:', 'wb-gamification' ); ?></strong>
						<?php esc_html_e( 'Use HTTPS endpoints only. Rotate the secret if you suspect a leak (delete + recreate the webhook). Failed deliveries log to the WP error log with full request/response context.', 'wb-gamification' ); ?>
					</p>
				</div>
			</details>

			<?php if ( isset( $_GET['notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display. ?>
				<div class="wbgam-banner wbgam-banner--success wbgam-stack-block" role="status" aria-live="polite">
					<span class="wbgam-banner__icon icon-check-circle" aria-hidden="true"></span>
					<div class="wbgam-banner__body">
						<p class="wbgam-banner__desc"><?php echo esc_html( self::notice_text( sanitize_key( wp_unslash( $_GET['notice'] ) ) ) ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<!-- Add Webhook Card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Add Webhook', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form
						class="wb-gam-webhook-form"
						data-wb-gam-rest-form="wbGamWebhooksSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="/webhooks"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Webhook saved.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to save the webhook.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload"
					>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="wb-gam-webhook-url"><?php esc_html_e( 'Endpoint URL', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<input type="url" id="wb-gam-webhook-url" name="url" class="regular-text wbgam-input" required placeholder="https://example.com/webhook" />
									<p class="description"><?php esc_html_e( 'HTTPS only in production. Each delivery POSTs JSON with an X-WB-Gam-Signature HMAC header.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wb-gam-webhook-secret"><?php esc_html_e( 'Secret', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<input type="text" id="wb-gam-webhook-secret" name="secret" class="regular-text wbgam-input" required placeholder="<?php esc_attr_e( 'auto-generated if blank', 'wb-gamification' ); ?>" />
									<p class="description">
										<?php esc_html_e( 'Used to sign every payload via HMAC-SHA256. The receiver computes the same HMAC over the raw request body and compares against the X-WB-Gam-Signature header. If you leave this blank, a 32-byte random secret is generated for you.', 'wb-gamification' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Events', 'wb-gamification' ); ?></th>
								<td>
									<fieldset class="wbgam-event-list">
										<legend class="screen-reader-text"><?php esc_html_e( 'Events to subscribe to', 'wb-gamification' ); ?></legend>
										<?php foreach ( self::available_events() as $event => $label ) : ?>
											<label class="wbgam-event-list__item" title="<?php echo esc_attr( self::event_payload_hint( $event ) ); ?>">
												<input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" />
												<code class="wbgam-event-list__code"><?php echo esc_html( $event ); ?></code>
												<span class="wbgam-event-list__desc"><?php echo esc_html( $label ); ?></span>
											</label>
										<?php endforeach; ?>
									</fieldset>
									<p class="description">
										<?php esc_html_e( 'Hover any event for the payload schema. Pick only the events your receiver knows how to handle — extra events waste delivery attempts.', 'wb-gamification' ); ?>
									</p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" class="wbgam-btn"><?php esc_html_e( 'Add Webhook', 'wb-gamification' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Configured Webhooks Card -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Configured Webhooks', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<?php if ( empty( $webhooks ) ) : ?>
						<p class="wbgam-text-muted"><?php esc_html_e( 'No webhooks configured yet. Add one above.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<div class="wbgam-table-scroll">
							<table class="wbgam-table">
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
									<?php
									foreach ( $webhooks as $hook ) :
										$events = json_decode( $hook['events'], true ) ?: array();
										?>
										<tr>
											<td><code><?php echo esc_html( $hook['url'] ); ?></code></td>
											<td>
												<?php foreach ( $events as $e ) : ?>
													<code class="wb-gam-event-tag"><?php echo esc_html( $e ); ?></code>
												<?php endforeach; ?>
											</td>
											<td>
												<?php if ( $hook['is_active'] ) : ?>
													<span class="wbgam-pill wbgam-pill--active"><?php esc_html_e( 'Active', 'wb-gamification' ); ?></span>
												<?php else : ?>
													<span class="wbgam-pill wbgam-pill--inactive"><?php esc_html_e( 'Paused', 'wb-gamification' ); ?></span>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $hook['created_at'] ); ?></td>
											<td>
												<button
													type="button"
													class="wbgam-btn wbgam-btn--sm wbgam-btn--danger"
													data-wb-gam-rest-action="wbGamWebhooksSettings"
													data-wb-gam-rest-method="DELETE"
													data-wb-gam-rest-path="/webhooks/<?php echo (int) $hook['id']; ?>"
													data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this webhook? Existing deliveries already in flight will still complete.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Webhook deleted.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to delete webhook.', 'wb-gamification' ); ?>"
													data-wb-gam-rest-after="remove-row"
												>
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
		</div>
		<?php
	}

	// handle_save() / handle_delete() removed in 1.0.0 (Tier 0.C). Webhooks are
	// now written by WebhooksController endpoints (POST + DELETE on
	// /wb-gamification/v1/webhooks). The admin UI uses the generic
	// admin-rest-form driver via data-* attributes.

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Available event types subscribers can subscribe to.
	 *
	 * @return array<string,string> Event name → human description.
	 */
	private static function available_events(): array {
		// Must match WebhooksController::ALLOWED_EVENTS — the REST schema is
		// the canonical source of truth (admin UI + 3rd-party clients agree
		// on the same enum).
		return array(
			'points_awarded'      => __( 'Points awarded to a member', 'wb-gamification' ),
			'badge_earned'        => __( 'Member earned a badge', 'wb-gamification' ),
			'level_changed'       => __( 'Member crossed a level threshold', 'wb-gamification' ),
			'streak_milestone'    => __( 'Streak milestone reached', 'wb-gamification' ),
			'challenge_completed' => __( 'Individual challenge completed', 'wb-gamification' ),
			'kudos_given'         => __( 'Peer kudos transaction', 'wb-gamification' ),
		);
	}

	/**
	 * Tooltip describing the payload shape for an event — shown on hover
	 * over the event checkbox. Helps admins decide which events to pick
	 * without leaving the page to read docs.
	 *
	 * @param string $event Event slug.
	 */
	private static function event_payload_hint( string $event ): string {
		switch ( $event ) {
			case 'points_awarded':
				return __( 'Fires every time PointsEngine::award succeeds. Payload: user_id, points, point_type, action_id, event_id.', 'wb-gamification' );
			case 'badge_earned':
				return __( 'Fires when a member earns a new badge. Payload: user_id, badge_id, badge_slug, badge_name, earned_at.', 'wb-gamification' );
			case 'level_changed':
				return __( 'Fires when a member crosses a level threshold (up OR down). Payload: user_id, old_level_id, new_level_id, new_level_name.', 'wb-gamification' );
			case 'streak_milestone':
				return __( 'Fires at every streak checkpoint (7/14/30/60/100/180/365 days). Payload: user_id, streak_length, milestone_days.', 'wb-gamification' );
			case 'challenge_completed':
				return __( 'Fires when a member completes an individual challenge. Payload: user_id, challenge_id, challenge_slug, completed_at.', 'wb-gamification' );
			case 'kudos_given':
				return __( 'Fires for every peer-to-peer kudos. Payload: giver_id, receiver_id, kudos_id, message (truncated to 200 chars).', 'wb-gamification' );
			default:
				return '';
		}
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
	 * Notice text by key.
	 *
	 * Retained for the legacy `?notice=...` query-arg pattern that older
	 * bookmarks may still use; current admin UI shows toasts instead.
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
