<?php
/**
 * WB Gamification — Dynamic OG share image.
 *
 * Renders a 1200x630 PNG "share card" per badge + earner so the badge share
 * page produces a rich social preview (Facebook, X, LinkedIn, Slack, WhatsApp).
 * The badge's own artwork is an SVG, which GD cannot composite and social
 * platforms will not render as an og:image — so this composes a branded card
 * (medallion + badge name + earner + date + site) with GD/FreeType instead.
 *
 * The result is cached to the uploads directory keyed by a content hash, so it
 * is generated once and regenerated only when the underlying data changes.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and caches the dynamic Open Graph share image for a badge.
 */
final class BadgeOgImage {

	const WIDTH  = 1200;
	const HEIGHT = 630;

	/**
	 * Ensure the OG image exists for a badge + earner and return its public URL.
	 *
	 * Returns an empty string if GD/FreeType or the bundled font is unavailable,
	 * letting the caller fall back to a static image.
	 *
	 * @param array          $badge     Badge definition (id, name, description).
	 * @param \WP_User       $user      Earner.
	 * @param \DateTime|null $issued_dt Issue date (UTC) or null.
	 * @return string Public URL of the cached PNG, or '' on failure.
	 */
	public static function ensure( array $badge, \WP_User $user, ?\DateTime $issued_dt ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			return '';
		}
		$font = self::font_path();
		if ( '' === $font ) {
			return '';
		}

		$issued_label = $issued_dt
			? date_i18n( get_option( 'date_format' ), $issued_dt->getTimestamp() )
			: '';
		$accent       = self::accent_color();

		$signature = implode(
			'|',
			array(
				(string) ( $badge['id'] ?? '' ),
				(string) ( $badge['name'] ?? '' ),
				(string) ( $badge['description'] ?? '' ),
				$user->display_name,
				$issued_label,
				$accent,
				'v1',
			)
		);
		$hash      = substr( md5( $signature ), 0, 10 );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir      = trailingslashit( $uploads['basedir'] ) . 'wb-gamification/og';
		$filename = sanitize_file_name( ( $badge['id'] ?? 'badge' ) . '-' . $user->ID . '-' . $hash . '.png' );
		$path     = $dir . '/' . $filename;
		$url      = trailingslashit( $uploads['baseurl'] ) . 'wb-gamification/og/' . $filename;

		if ( file_exists( $path ) ) {
			return $url;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$ok = self::render(
			$path,
			$font,
			$accent,
			(string) ( $badge['name'] ?? '' ),
			(string) ( $badge['description'] ?? '' ),
			$user->display_name,
			$issued_label
		);

		return $ok ? $url : '';
	}

	/**
	 * Path to the bundled text font, or '' if absent.
	 *
	 * @return string
	 */
	private static function font_path(): string {
		$font = WB_GAM_PATH . 'assets/fonts/Inter.ttf';
		return is_readable( $font ) ? $font : '';
	}

	/**
	 * Brand accent colour for the card. Filterable hex string.
	 *
	 * @return string Hex colour (e.g. "#5b4cdb").
	 */
	private static function accent_color(): string {
		/**
		 * Filter the accent colour used in the generated OG share image.
		 *
		 * @param string $hex Accent colour as a hex string.
		 */
		$hex = (string) apply_filters( 'wb_gam_og_accent_color', '#5b4cdb' );
		return preg_match( '/^#?[0-9a-fA-F]{6}$/', $hex ) ? $hex : '#5b4cdb';
	}

	/**
	 * Render the OG card to a PNG file.
	 *
	 * @param string $path         Destination file path.
	 * @param string $font         TTF font path.
	 * @param string $accent_hex   Accent colour hex.
	 * @param string $badge_name   Badge display name.
	 * @param string $description  Badge description.
	 * @param string $earner_name  Earner display name.
	 * @param string $issued_label Localised issue date, or ''.
	 * @return bool True on success.
	 */
	private static function render( string $path, string $font, string $accent_hex, string $badge_name, string $description, string $earner_name, string $issued_label ): bool {
		$img = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( false === $img ) {
			return false;
		}
		imagealphablending( $img, true );
		imageantialias( $img, true );

		list( $ar, $ag, $ab ) = self::hex_to_rgb( $accent_hex );

		$bg        = imagecolorallocate( $img, 247, 248, 250 ); // #f7f8fa surface.
		$white     = imagecolorallocate( $img, 255, 255, 255 );
		$ink       = imagecolorallocate( $img, 26, 26, 26 );    // #1a1a1a.
		$muted     = imagecolorallocate( $img, 102, 102, 102 ); // #666.
		$accent    = imagecolorallocate( $img, $ar, $ag, $ab );
		$accent_lt = imagecolorallocatealpha( $img, $ar, $ag, $ab, 110 ); // tinted ring.

		imagefilledrectangle( $img, 0, 0, self::WIDTH, self::HEIGHT, $bg );

		// White content panel with margin.
		$m = 48;
		imagefilledrectangle( $img, $m, $m, self::WIDTH - $m, self::HEIGHT - $m, $white );
		// Accent bottom bar inside the panel.
		imagefilledrectangle( $img, $m, self::HEIGHT - $m - 14, self::WIDTH - $m, self::HEIGHT - $m, $accent );

		// Site name (top-left).
		$site = mb_strtoupper( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		imagettftext( $img, 22, 0, $m + 56, $m + 78, $accent, $font, $site );

		// Badge medallion: tinted ring + accent circle + white star.
		$cx = $m + 130;
		$cy = 340;
		imagefilledellipse( $img, $cx, $cy, 200, 200, $accent_lt );
		imagefilledellipse( $img, $cx, $cy, 150, 150, $accent );
		self::draw_star( $img, $cx, $cy, 52, $white );

		// Text column to the right of the medallion.
		$tx       = $m + 260;
		$max_text = self::WIDTH - $m - $tx - 40;

		// Badge name (wrapped, large).
		$name_lines = self::wrap( $badge_name, $font, 58, $max_text );
		$name_lines = array_slice( $name_lines, 0, 2 );
		$y          = 250;
		foreach ( $name_lines as $line ) {
			imagettftext( $img, 58, 0, $tx, $y, $ink, $font, $line );
			$y += 76;
		}

		// Description (one wrapped line max).
		if ( '' !== $description ) {
			$desc_lines = self::wrap( $description, $font, 28, $max_text );
			$desc       = $desc_lines[0] ?? '';
			if ( count( $desc_lines ) > 1 ) {
				$desc = rtrim( $desc ) . '…';
			}
			imagettftext( $img, 28, 0, $tx, $y + 6, $muted, $font, $desc );
			$y += 56;
		}

		// Earner + date.
		$earned = $issued_label
			/* translators: 1: member name, 2: date */
			? sprintf( __( 'Earned by %1$s · %2$s', 'wb-gamification' ), $earner_name, $issued_label )
			/* translators: %s: member name */
			: sprintf( __( 'Earned by %s', 'wb-gamification' ), $earner_name );
		$earned = self::wrap( $earned, $font, 26, $max_text )[0] ?? $earned;
		imagettftext( $img, 26, 0, $tx, $y + 18, $accent, $font, $earned );

		$ok = imagepng( $img, $path, 6 );
		imagedestroy( $img );
		return (bool) $ok;
	}

	/**
	 * Draw a filled 5-point star centred at (cx, cy).
	 *
	 * @param \GdImage|resource $img   Image.
	 * @param int               $cx    Centre x.
	 * @param int               $cy    Centre y.
	 * @param int               $r     Outer radius.
	 * @param int               $color Allocated colour.
	 * @return void
	 */
	private static function draw_star( $img, int $cx, int $cy, int $r, int $color ): void {
		$points = array();
		$inner  = $r * 0.42;
		for ( $i = 0; $i < 10; $i++ ) {
			$radius   = ( 0 === $i % 2 ) ? $r : $inner;
			$angle    = ( M_PI / 5 ) * $i - ( M_PI / 2 );
			$points[] = (int) round( $cx + $radius * cos( $angle ) );
			$points[] = (int) round( $cy + $radius * sin( $angle ) );
		}
		// PHP 8 imagefilledpolygon accepts the points array directly.
		imagefilledpolygon( $img, $points, $color );
	}

	/**
	 * Word-wrap text to fit a pixel width at a given TTF size.
	 *
	 * @param string $text Text.
	 * @param string $font Font path.
	 * @param int    $size Point size.
	 * @param int    $max  Max width in px.
	 * @return string[] Lines.
	 */
	private static function wrap( string $text, string $font, int $size, int $max ): array {
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( empty( $words ) ) {
			return array( '' );
		}
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			$box = imagettfbbox( $size, 0, $font, $try );
			$w   = abs( $box[2] - $box[0] );
			if ( $w > $max && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Convert a hex colour to an [r, g, b] array.
	 *
	 * @param string $hex Hex colour.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
