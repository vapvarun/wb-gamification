<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

// This Kit uses its own 'wbcom-family' text domain — host-agnostic and portable across any Wbcom plugin.

/**
 * Renders the outcome-first family guide: 3 regions (outcomes, get-started,
 * also-works-with). Guide tone — one action per outcome, no promo chrome.
 */
class Page {

	public static function render( array $config ): string {
		$registry = registry();
		$host     = (string) ( $config['host'] ?? '' );
		$nonce    = (string) ( $config['nonce'] ?? '' );
		$onboard  = $config['onboarding_url'] ?? null;

		$out  = '<div class="wbcom-family">';
		// Region 1: outcomes (primary).
		$out .= '<div class="wbcom-family__outcomes" data-region="outcomes">';
		foreach ( $registry['outcomes'] as $key => $outcome ) {
			$out .= self::outcome_row( $key, $outcome, $registry, $host, $nonce );
		}
		$out .= '</div>';

		// Region 2: get-started (secondary) — link to existing onboarding.
		if ( $onboard ) {
			$out .= '<div class="wbcom-family__start" data-region="getstarted">'
				. '<a class="wbcom-family__link" href="' . esc_url( $onboard ) . '">'
				. esc_html__( 'New here? Run the setup guide', 'wbcom-family' ) . '</a></div>';
		}

		// Region 3: also-works-with (tertiary, de-emphasized).
		$out .= '<details class="wbcom-family__thirdparty" data-region="thirdparty"><summary>'
			. esc_html__( 'Also works with', 'wbcom-family' ) . '</summary><ul>';
		foreach ( $registry['third_party'] as $tp ) {
			$out .= '<li><strong>' . esc_html( $tp['name'] ) . '</strong> — ' . esc_html( $tp['note'] ) . '</li>';
		}
		$out .= '</ul></details></div>';

		return $out;
	}

	private static function outcome_row( string $key, array $outcome, array $registry, string $host, string $nonce ): string {
		// The member that enables this outcome (first requirement).
		$slug   = $outcome['requires'][0] ?? '';
		$member = $registry['members'][ $slug ] ?? array();
		$state  = $member ? State::member_state( $member ) : 'not_installed';

		// Decide the single action.
		if ( $slug === $host || 'active' === $state ) {
			$action = 'configure';
			$label  = __( 'Set it up', 'wbcom-family' );
			$href   = admin_url( 'admin.php?page=' . $slug );
		} elseif ( 'installed_inactive' === $state ) {
			$action = 'activate';
			$label  = __( 'Activate', 'wbcom-family' );
			$href   = '#';
		} elseif ( ! empty( $member['wporg_slug'] ) ) {
			$action = 'install';
			$label  = __( 'Install & activate', 'wbcom-family' );
			$href   = '#';
		} else {
			$action = 'learn';
			$label  = __( 'See how it works', 'wbcom-family' );
			$href   = $member['learn_url'] ?? '#';
		}

		return '<div class="wbcom-family__outcome" data-outcome="' . esc_attr( $key ) . '" data-state="' . esc_attr( $state ) . '">'
			. self::logo_html( $slug, $member )
			. '<div class="wbcom-family__body"><h3>' . esc_html( $outcome['title'] ) . '</h3>'
			. '<p>' . esc_html( $outcome['description'] ) . '</p></div>'
			. '<a class="wbcom-family__action" data-action="' . esc_attr( $action ) . '" data-slug="' . esc_attr( $slug ) . '" '
			. 'data-nonce="' . esc_attr( $nonce ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>'
			. '</div>';
	}

	/**
	 * Real bundled brand mark when present, else the Lucide icon fallback.
	 * SVG is a trusted bundled asset — inlined to stay portable (no URL).
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
