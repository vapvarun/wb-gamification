<?php
/**
 * Lucide icon helper.
 *
 * Per `wp-plugin-development/references/admin-ux-rulebook.md` "Icons — Lucide
 * Only", admin UI uses Lucide icons (https://lucide.dev/icons/). This class
 * emits inline SVGs so we have zero dependency on a Lucide font / external
 * asset and icons inherit `currentColor` for theming.
 *
 * The bundled set covers every spot the WB Gamification admin currently uses
 * an icon. Add new entries to `paths()` as new pages need them — keep the
 * `viewBox` at `0 0 24 24` to match Lucide's canonical output, and copy the
 * paths verbatim from lucide.dev.
 *
 * Usage:
 *   echo \WBGam\Admin\Icon::svg( 'star' );                 // 1.25rem (default)
 *   echo \WBGam\Admin\Icon::svg( 'coins', [ 'size' => 32 ] );
 *   echo \WBGam\Admin\Icon::svg( 'flame', [ 'class' => 'wbgam-foo' ] );
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Inline-SVG renderer for the bundled Lucide icon set.
 */
final class Icon {

	/**
	 * Render a Lucide icon as an inline SVG string.
	 *
	 * @param string                                                                 $name Icon slug (e.g. 'star', 'coins').
	 * @param array{size?:int|string,class?:string,title?:string,decorative?:bool}   $args Display options.
	 * @return string SVG markup. Empty string if the name isn't bundled.
	 */
	public static function svg( string $name, array $args = array() ): string {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$size       = $args['size'] ?? '1.25rem';
		$class      = trim( 'wbgam-icon ' . ( $args['class'] ?? '' ) );
		$title      = $args['title'] ?? '';
		$decorative = ( $args['decorative'] ?? '' === $title ) || empty( $title );

		$attrs  = sprintf(
			'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$s" height="%1$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="%2$s"',
			esc_attr( is_numeric( $size ) ? $size . 'px' : (string) $size ),
			esc_attr( $class )
		);
		$attrs .= $decorative ? ' aria-hidden="true" focusable="false"' : ' role="img"';

		$svg  = '<svg ' . $attrs . '>';
		$svg .= ! $decorative ? '<title>' . esc_html( (string) $title ) . '</title>' : '';
		$svg .= $paths[ $name ];
		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * Echo helper — preferred in templates over `echo` wrapping.
	 *
	 * @param string $name Icon slug.
	 * @param array  $args See {@see Icon::svg()}.
	 */
	public static function render( string $name, array $args = array() ): void {
		echo self::svg( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup built from a fixed allowlist; user input can't reach it.
	}

	/**
	 * Path table — Lucide icon name → SVG path markup.
	 *
	 * Every entry comes verbatim from https://lucide.dev/icons/{slug}.
	 *
	 * @return array<string,string>
	 */
	private static function paths(): array {
		return array(
			'star'           => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
			'coins'          => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
			'flame'          => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
			'award'          => '<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>',
			'shield'         => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
			'flag'           => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
			'thumbs-up'      => '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
			'gauge'          => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
			'bar-chart'      => '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
			'rotate-cw'      => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
			'key'            => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
			'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'wrench'         => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
			'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
			'circle-check'   => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
			'x'              => '<line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/>',
			'plus'           => '<line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/>',
			'chevron-right'  => '<polyline points="9 18 15 12 9 6"/>',
			'chevron-down'   => '<polyline points="6 9 12 15 18 9"/>',
			'arrow-right'    => '<line x1="5" x2="19" y1="12" y2="12"/><polyline points="12 5 19 12 12 19"/>',
			'info'           => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>',
			'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
			'alert-circle'   => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
			'trophy'         => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
			'rocket'         => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
			'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'list-ordered'   => '<line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
			'settings'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
		);
	}
}
