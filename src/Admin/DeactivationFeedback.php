<?php
/**
 * WB Gamification — deactivation feedback.
 *
 * On the Plugins screen, intercepts THIS plugin's Deactivate link and shows a
 * small optional survey (a native <dialog>, so Esc + focus-trap come free)
 * asking why. The answer is the highest-value product signal we otherwise
 * throw away. It is fully optional: Skip always deactivates immediately, any
 * failure still deactivates, and nothing is sent unless the admin submits.
 *
 * Privacy: the payload is anonymous by default (reason, optional free text,
 * plugin/WP/PHP versions, locale, a one-way site hash). No email or site URL
 * is included unless the admin ticks the contact consent box. Stored locally
 * in a rolling option; a site can forward to an external collector by
 * returning a URL from the `wb_gam_deactivation_collector_url` filter.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders + records the deactivation feedback survey.
 *
 * @package WB_Gamification
 */
final class DeactivationFeedback {

	private const AJAX_ACTION  = 'wb_gam_deactivation_feedback';
	private const NONCE        = 'wb_gam_deactivation_feedback';
	private const OPTION       = 'wb_gam_deactivation_reasons';
	private const REPROMPT_KEY = 'wb_gam_deactivation_prompted';
	private const MAX_STORED   = 50;

	/**
	 * The survey reasons (value => label). Kept server-side so the stored
	 * value is always a known key.
	 *
	 * @return array<string,string>
	 */
	private static function reasons(): array {
		return array(
			'temporary'       => __( 'Just deactivating temporarily / troubleshooting', 'wb-gamification' ),
			'bug'             => __( 'I found a bug', 'wb-gamification' ),
			'missing_feature' => __( 'A feature I need is missing', 'wb-gamification' ),
			'performance'     => __( 'It slowed my site down', 'wb-gamification' ),
			'switching'       => __( 'Switching to another plugin', 'wb-gamification' ),
			'other'           => __( 'Other', 'wb-gamification' ),
		);
	}

	/**
	 * Hook the plugins-screen assets + the AJAX collector.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer-plugins.php', array( __CLASS__, 'render_dialog' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Enqueue the survey script on the Plugins screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix || ! current_user_can( 'deactivate_plugin', WB_GAM_BASENAME ) ) {
			return;
		}
		// Don't re-prompt if we asked (or they skipped) in the last 30 days.
		if ( get_transient( self::REPROMPT_KEY ) ) {
			return;
		}

		wp_enqueue_style(
			'wb-gam-deactivation-feedback',
			plugins_url( 'assets/css/admin/deactivation-feedback.css', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION
		);
		wp_enqueue_script(
			'wb-gam-deactivation-feedback',
			plugins_url( 'assets/js/admin-deactivation-feedback.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-deactivation-feedback',
			'wbGamDeactivate',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::AJAX_ACTION,
				'nonce'    => wp_create_nonce( self::NONCE ),
				'basename' => WB_GAM_BASENAME,
			)
		);
	}

	/**
	 * Render the hidden survey <dialog> in the Plugins-screen footer.
	 */
	public static function render_dialog(): void {
		if ( ! current_user_can( 'deactivate_plugin', WB_GAM_BASENAME ) || get_transient( self::REPROMPT_KEY ) ) {
			return;
		}
		?>
		<dialog id="wb-gam-deactivate-dialog" class="wb-gam-deactivate" aria-labelledby="wb-gam-deactivate-title">
			<form method="dialog" class="wb-gam-deactivate__form">
				<h2 id="wb-gam-deactivate-title" class="wb-gam-deactivate__title"><?php esc_html_e( 'Quick question before you go', 'wb-gamification' ); ?></h2>
				<p class="wb-gam-deactivate__intro"><?php esc_html_e( 'If you have a moment, what is prompting the deactivation? This is optional and helps us improve.', 'wb-gamification' ); ?></p>

				<div class="wb-gam-deactivate__reasons">
					<?php foreach ( self::reasons() as $value => $label ) : ?>
						<label class="wb-gam-deactivate__reason">
							<input type="radio" name="wb_gam_reason" value="<?php echo esc_attr( $value ); ?>" />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<label class="wb-gam-deactivate__detail-label" for="wb-gam-deactivate-detail"><?php esc_html_e( 'Anything else? (optional)', 'wb-gamification' ); ?></label>
				<textarea id="wb-gam-deactivate-detail" class="wb-gam-deactivate__detail" rows="2"></textarea>

				<label class="wb-gam-deactivate__consent">
					<input type="checkbox" id="wb-gam-deactivate-consent" />
					<span><?php esc_html_e( 'You may follow up with me at my account email about this.', 'wb-gamification' ); ?></span>
				</label>

				<div class="wb-gam-deactivate__actions">
					<button type="button" class="button button-link wb-gam-deactivate__skip"><?php esc_html_e( 'Skip &amp; deactivate', 'wb-gamification' ); ?></button>
					<button type="button" class="button button-primary wb-gam-deactivate__submit"><?php esc_html_e( 'Submit &amp; deactivate', 'wb-gamification' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * AJAX: store the submitted reason (or record a skip) and set the
	 * 30-day re-prompt guard. Always returns success — feedback must never
	 * block deactivation.
	 */
	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'deactivate_plugin', WB_GAM_BASENAME ) ) {
			wp_send_json_error( array(), 403 );
		}

		// 30-day re-prompt guard regardless of skip/submit.
		set_transient( self::REPROMPT_KEY, 1, 30 * DAY_IN_SECONDS );

		$skipped = isset( $_POST['skipped'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['skipped'] ) );
		if ( $skipped ) {
			wp_send_json_success( array( 'recorded' => false ) );
		}

		$reasons = self::reasons();
		$reason  = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		if ( ! isset( $reasons[ $reason ] ) ) {
			wp_send_json_success( array( 'recorded' => false ) );
		}

		$detail  = isset( $_POST['detail'] ) ? sanitize_textarea_field( wp_unslash( $_POST['detail'] ) ) : '';
		$consent = isset( $_POST['consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );

		$entry = array(
			'reason'    => $reason,
			'detail'    => mb_substr( $detail, 0, 500 ),
			'plugin'    => WB_GAM_VERSION,
			'wp'        => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'locale'    => get_locale(),
			// One-way site fingerprint — not reversible to a URL.
			'site_hash' => hash( 'sha256', home_url() ),
			'at'        => gmdate( 'c' ),
			// Only included with explicit consent.
			'contact'   => $consent ? wp_get_current_user()->user_email : '',
		);

		self::store( $entry );

		/**
		 * Optional external collector. Return a URL to forward the anonymous
		 * feedback entry (fire-and-forget, non-blocking) instead of only
		 * keeping it in the local option.
		 *
		 * @since 1.6.2
		 * @param string $url   Collector endpoint ('' = local storage only).
		 * @param array  $entry The feedback entry.
		 */
		$collector = (string) apply_filters( 'wb_gam_deactivation_collector_url', '', $entry );
		if ( '' !== $collector ) {
			wp_remote_post(
				$collector,
				array(
					'timeout'  => 3,
					'blocking' => false,
					'body'     => wp_json_encode( $entry ),
					'headers'  => array( 'Content-Type' => 'application/json' ),
				)
			);
		}

		wp_send_json_success( array( 'recorded' => true ) );
	}

	/**
	 * Append an entry to the rolling local store (last MAX_STORED).
	 *
	 * @param array<string,mixed> $entry Feedback entry.
	 */
	private static function store( array $entry ): void {
		$all   = (array) get_option( self::OPTION, array() );
		$all[] = $entry;
		if ( count( $all ) > self::MAX_STORED ) {
			$all = array_slice( $all, -self::MAX_STORED );
		}
		update_option( self::OPTION, $all, false );
	}
}
