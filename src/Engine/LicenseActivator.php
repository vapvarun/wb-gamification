<?php
/**
 * LicenseActivator — preactivates the bundled EDD preset license.
 *
 * WB Gamification is GPLv2 free, but it ships from store.wbcomdesigns.com
 * (not the wp.org repo), so the only way users see "1 update available" in
 * their admin is through the bundled EDD Software Licensing SDK. The SDK
 * needs a license key to authenticate update checks. Because the product
 * is free, we bundle a no-charge preset key (a registration token) and
 * activate it automatically — the site owner never types a key.
 *
 * {@see activate()} runs on BOTH plugin activation (preactivates the
 * instant the plugin is switched on) and `admin_init` (an idempotent
 * fallback for activation paths that bypass the activation hook — CLI
 * clone, restore-from-backup — and a retry after a transient network
 * error). The preset key + item id + store URL are the global
 * WB_GAM_LICENSE_PRESET_KEY / WB_GAM_EDD_ITEM_ID / WB_GAM_EDD_STORE_URL
 * constants defined in the main plugin file.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and remotely activates the bundled preset license.
 */
final class LicenseActivator {

	/**
	 * Option holding the (preset) license key the SDK reads.
	 *
	 * @var string
	 */
	private const KEY_OPTION = 'wb_gam_license_key';

	/**
	 * Flag option set once the store gives a final answer for this domain
	 * (`valid`, or a reply that will not change on retry). Gates the remote
	 * POST so it runs at most once per site.
	 *
	 * @var string
	 */
	private const ACTIVATED_OPTION = 'wb_gam_preset_activated';

	/**
	 * Transient that holds retries back for a day after a failed attempt.
	 *
	 * @var string
	 */
	private const RETRY_LOCK = 'wb_gam_preset_activation_lock';

	/**
	 * Store replies that will not change on retry.
	 *
	 * @var string[]
	 */
	private const FINAL_REPLIES = array( 'valid', 'invalid', 'disabled', 'expired', 'revoked', 'no_activations_left', 'missing', 'item_name_mismatch', 'invalid_item_id' );

	/**
	 * Store the preset key locally and register the site with the EDD store.
	 *
	 * The key is written first and always, so updates work even when the POST
	 * fails. The POST runs at most once per day until the store gives a final
	 * answer, and never on AJAX (Heartbeat), cron or REST requests: retrying on
	 * every admin request once sent hundreds of POSTs a day from one site.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$preset_key = WB_GAM_LICENSE_PRESET_KEY;

		// Store the key so the SDK can find it immediately — this is what
		// makes the plugin preactivated even before the remote round-trip.
		if ( WB_GAM_LICENSE_PRESET_KEY !== get_option( self::KEY_OPTION ) ) {
			update_option( self::KEY_OPTION, $preset_key, false );
		}

		if ( get_option( self::ACTIVATED_OPTION ) || get_transient( self::RETRY_LOCK ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Locked before the request, so a slow or failing store costs one try a day.
		set_transient( self::RETRY_LOCK, 1, DAY_IN_SECONDS );

		$response = wp_remote_post(
			WB_GAM_EDD_STORE_URL,
			array(
				'timeout' => 5,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => $preset_key,
					'item_id'    => WB_GAM_EDD_ITEM_ID,
					'url'        => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$reply = is_array( $body ) ? (string) ( $body['license'] ?? ( $body['error'] ?? '' ) ) : '';
		if ( in_array( $reply, self::FINAL_REPLIES, true ) ) {
			// Usage tracking is left to the owner's own opt-in; it is not switched on here.
			update_option( self::ACTIVATED_OPTION, $reply, false );
			delete_transient( self::RETRY_LOCK );
		}
	}
}
