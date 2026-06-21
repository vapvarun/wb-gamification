<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

// This Kit uses its own 'wbcom-family' text domain - host-agnostic and portable across any Wbcom plugin.

/**
 * Renders the outcome-first family guide under a "Wbcom Family" brand header:
 * a brand strip, then outcome rows (each naming the product that enables it
 * with one clear action), then get-started and also-works-with regions.
 * Guide tone - one primary action per outcome, no promo chrome.
 */
class Page {

	public static function render( array $config ): string {
		$registry = registry();
		$host     = (string) ( $config['host'] ?? '' );
		$nonce    = (string) ( $config['nonce'] ?? '' );
		$onboard  = $config['onboarding_url'] ?? null;

		$out  = '<div class="wbcom-family">';

		// Brand header - establishes "Wbcom Family" with the Wbcom logo.
		$out .= '<div class="wbcom-family__header" data-region="brand">'
			. '<div class="wbcom-family__brand">' . self::brand_mark() . '</div>'
			. '<div class="wbcom-family__brandtext">'
			. '<h2>' . esc_html__( 'Wbcom Family', 'wbcom-family' ) . '</h2>'
			. '<p>' . esc_html__( 'One connected suite for your community. Pick what you want to do - we point you to the plugin that does it.', 'wbcom-family' ) . '</p>'
			. '</div></div>';

		// Region 1: outcomes (primary).
		$out .= '<div class="wbcom-family__outcomes" data-region="outcomes">';
		foreach ( $registry['outcomes'] as $key => $outcome ) {
			$out .= self::outcome_row( $key, $outcome, $registry, $host, $nonce );
		}
		$out .= '</div>';

		// Region 2: get-started (secondary) - link to existing onboarding.
		if ( $onboard ) {
			$out .= '<div class="wbcom-family__start" data-region="getstarted">'
				. '<a class="wbcom-family__link" href="' . esc_url( $onboard ) . '">'
				. esc_html__( 'New here? Run the setup guide', 'wbcom-family' ) . '</a></div>';
		}

		// Region 3: also-works-with (tertiary, de-emphasized).
		$out .= '<details class="wbcom-family__thirdparty" data-region="thirdparty"><summary>'
			. esc_html__( 'Also works with', 'wbcom-family' ) . '</summary><ul>';
		foreach ( $registry['third_party'] as $tp ) {
			$out .= '<li><strong>' . esc_html( $tp['name'] ) . '</strong> - ' . esc_html( $tp['note'] ) . '</li>';
		}
		$out .= '</ul></details></div>';

		return $out;
	}

	private static function outcome_row( string $key, array $outcome, array $registry, string $host, string $nonce ): string {
		// The member that enables this outcome (first requirement).
		$slug   = $outcome['requires'][0] ?? '';
		$member = $registry['members'][ $slug ] ?? array();
		$name   = (string) ( $member['name'] ?? $slug );
		$state  = $member ? State::member_state( $member ) : 'not_installed';

		$row  = '<div class="wbcom-family__outcome" data-outcome="' . esc_attr( $key ) . '" data-state="' . esc_attr( $state ) . '">'
			. self::logo_html( $slug, $member )
			. '<div class="wbcom-family__body">'
			. '<div class="wbcom-family__titlerow"><h3>' . esc_html( $outcome['title'] ) . '</h3>'
			. '<span class="wbcom-family__member">' . esc_html( $name ) . '</span></div>'
			. '<p>' . esc_html( $outcome['description'] ) . '</p></div>'
			. '<div class="wbcom-family__actions">' . self::actions_html( $slug, $name, $member, $state, $host, $nonce ) . '</div>'
			. '</div>';

		return $row;
	}

	/**
	 * The single primary action (plus an optional secondary "view details"
	 * link for not-installed premium members), decided from live state.
	 */
	private static function actions_html( string $slug, string $name, array $member, string $state, string $host, string $nonce ): string {
		// Already here - configure it.
		if ( $slug === $host || 'active' === $state ) {
			return self::action_link( 'configure', $slug, $nonce, admin_url( 'admin.php?page=' . $slug ), __( 'Set it up', 'wbcom-family' ), false );
		}
		// Installed but inactive - one-click activate (handled in JS).
		if ( 'installed_inactive' === $state ) {
			return self::action_link( 'activate', $slug, $nonce, '#', __( 'Activate', 'wbcom-family' ), false );
		}
		// Free on wp.org - one-click install + activate (handled in JS).
		if ( ! empty( $member['wporg_slug'] ) ) {
			return self::action_link( 'install', $slug, $nonce, '#', __( 'Install & activate', 'wbcom-family' ), false );
		}
		// Not yet on the store - honest "coming soon", no broken link.
		if ( ! empty( $member['coming_soon'] ) ) {
			return '<span class="wbcom-family__action wbcom-family__action--soon" data-action="soon">' . esc_html__( 'Coming soon', 'wbcom-family' ) . '</span>';
		}
		// Premium member - primary "Get <name>" (live download page) + optional "View details".
		/* translators: %s: product name. */
		$get_label = sprintf( __( 'Get %s', 'wbcom-family' ), $name );
		$out       = self::action_link( 'get', $slug, $nonce, $member['learn_url'] ?? '#', $get_label, true );
		if ( ! empty( $member['pro_url'] ) ) {
			$out .= '<a class="wbcom-family__details" href="' . esc_url( $member['pro_url'] ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'View details', 'wbcom-family' ) . '</a>';
		}
		return $out;
	}

	private static function action_link( string $action, string $slug, string $nonce, string $href, string $label, bool $external ): string {
		$ext = $external ? ' target="_blank" rel="noopener"' : '';
		return '<a class="wbcom-family__action" data-action="' . esc_attr( $action ) . '" data-slug="' . esc_attr( $slug ) . '" '
			. 'data-nonce="' . esc_attr( $nonce ) . '" href="' . esc_url( $href ) . '"' . $ext . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * The shared Wbcom brand mark, inlined from the bundled SVG (data-URI).
	 */
	private static function brand_mark(): string {
		$svg = WBCOM_FAMILY_KIT_DIR . '/logos/wbcom.svg';
		if ( is_readable( $svg ) ) {
			return file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- trusted bundled SVG, not remote.
		}
		return '<strong>' . esc_html__( 'Wbcom', 'wbcom-family' ) . '</strong>';
	}

	/**
	 * Real bundled brand mark when present, else the Lucide icon fallback.
	 * SVG is a trusted bundled asset - inlined to stay portable (no URL).
	 */
	private static function logo_html( string $slug, array $member ): string {
		$safe = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $slug ) );
		$svg  = WBCOM_FAMILY_KIT_DIR . '/logos/' . $safe . '.svg';
		if ( '' !== $safe && is_readable( $svg ) ) {
			return '<div class="wbcom-family__logo">' . file_get_contents( $svg ) . '</div>'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- trusted bundled SVG, not remote.
		}
		return '<div class="wbcom-family__icon" data-icon="' . esc_attr( $member['icon'] ?? 'circle' ) . '"></div>';
	}
}
