<?php
namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

use Wbcom\Family\Kit;

/**
 * Host adapter: boots the Wbcom Family Kit for wb-gamification and exposes an
 * "Integrations" tab inside the gamification settings screen.
 */
class IntegrationsTab {

	public static function init(): void {
		require_once WB_GAM_PATH . 'libs/wbcom-family/bootstrap.php';
		Kit::boot(
			array(
				'host'           => 'wb-gamification',
				'onboarding_url' => admin_url( 'admin.php?page=wb-gamification-setup' ),
			)
		);
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function render(): string {
		return Kit::render();
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification' ) ) {
			return;
		}
		wp_enqueue_style( 'wbcom-family', WB_GAM_URL . 'assets/admin/family.css', array(), WB_GAM_VERSION );
		wp_enqueue_script( 'wbcom-family', WB_GAM_URL . 'assets/admin/family.js', array(), WB_GAM_VERSION, true );
		wp_localize_script( 'wbcom-family', 'wbcomFamily', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}
}
