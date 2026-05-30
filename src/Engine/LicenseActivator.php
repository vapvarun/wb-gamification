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
	 * Flag option set once the store returns a `valid` activation for this
	 * domain. Gates the remote POST so it runs at most once per site.
	 *
	 * @var string
	 */
	private const ACTIVATED_OPTION = 'wb_gam_preset_activated';

	/**
	 * Store the preset key locally and register the site with the EDD store.
	 *
	 * Idempotent: the key is (re)written only when it differs, and the
	 * remote `activate_license` POST is skipped once the domain is marked
	 * activated. Network failures are non-fatal — the activated flag is set
	 * only on a `valid` response, so the `admin_init` fallback retries.
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

		// Already registered with the store for this domain — skip the POST.
		if ( get_option( self::ACTIVATED_OPTION ) ) {
			return;
		}

		$response = wp_remote_post(
			WB_GAM_EDD_STORE_URL,
			array(
				'timeout' => 15,
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

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 'valid' === ( $body['license'] ?? '' ) ) {
			update_option( self::ACTIVATED_OPTION, 1, false );
			// Auto-enable the usage-tracking checkbox the SDK reads.
			update_option(
				self::KEY_OPTION . '_allow_tracking',
				array(
					'allowed'   => true,
					'timestamp' => time(),
				),
				false
			);
		}
	}
}
