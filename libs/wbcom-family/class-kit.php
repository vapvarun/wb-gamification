<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * Public entry point. The host plugin calls Kit::boot() once and Kit::render()
 * inside its Integrations tab.
 */
class Kit {

	/** @var array<string,mixed> */
	private static $config = array();
	/** @var bool */
	private static $booted = false;

	public static function boot( array $config ): void {
		self::$config = $config;
		if ( self::$booted ) {
			// Config is always updated above; hook registration is one-time only.
			return;
		}
		self::$booted = true;
		Installer::register();
	}

	public static function render(): string {
		return Page::render(
			array(
				'host'           => self::$config['host'] ?? '',
				'onboarding_url' => self::$config['onboarding_url'] ?? null,
				'nonce'          => wp_create_nonce( Installer::ACTION ),
			)
		);
	}
}
