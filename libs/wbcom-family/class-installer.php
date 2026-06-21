<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * One-click install + activate for FREE family members (wporg_slug set).
 * Pro/unknown members are never auto-installed.
 */
class Installer {

	const ACTION = 'wbcom_family_install';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to install plugins.' ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		$slug     = sanitize_key( $_POST['slug'] ?? '' );
		$registry = registry();
		$member   = $registry['members'][ $slug ] ?? null;

		if ( null === $member || empty( $member['wporg_slug'] ) ) {
			wp_send_json_error( array( 'message' => 'This plugin cannot be installed automatically.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$api = plugins_api( 'plugin_information', array( 'slug' => $member['wporg_slug'], 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ), 502 );
		}

		if ( empty( $api->download_link ) ) {
			wp_send_json_error( array( 'message' => 'Could not resolve the plugin download.' ), 502 );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) || ! $result ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'Installation failed.';
			wp_send_json_error( array( 'message' => $msg ), 500 );
		}

		$activated = activate_plugin( $member['slug_free'] );
		if ( is_wp_error( $activated ) ) {
			wp_send_json_error( array( 'message' => $activated->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'slug' => $slug, 'state' => 'active' ) );
	}
}
