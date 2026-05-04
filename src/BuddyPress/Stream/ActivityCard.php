<?php
/**
 * WB Gamification — BuddyPress Activity Card renderer + lookups.
 *
 * Shared utilities for every gamification stream poster:
 *   - render() — uniform HTML card with image + title + description
 *   - lookup_badge_def() / lookup_level() — DB hydration
 *   - user_link() / user_display_name() — profile-aware names
 *   - default_*_image() — embedded SVG data URIs for fallback icons
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper for activity-stream content cards.
 *
 * @package WB_Gamification
 */
final class ActivityCard {

	/**
	 * Render the activity content card.
	 *
	 * BP runs activity content through `wp_kses_post()`, which:
	 *   - strips the `class` attribute from `<strong>` (only span/p/div allow it)
	 *   - drops `data:` URIs from `<img src>` (not in default allowed protocols)
	 *
	 * Output uses `<span>` for the title and absolute URLs for icons accordingly.
	 *
	 * @param string $type        One of: badge, level, kudos, challenge.
	 * @param string $image_url   Absolute URL for the icon/avatar (no data: URIs).
	 * @param string $title       Card title (plain text).
	 * @param string $description Card description (plain text).
	 */
	public static function render( string $type, string $image_url, string $title, string $description ): string {
		$type_class = sanitize_html_class( $type );
		$image_url  = $image_url ?: self::default_for_type( $type );
		$src        = esc_url( $image_url );
		$alt        = esc_attr( $title );
		$title_html = esc_html( $title );
		$desc_html  = esc_html( $description );

		return sprintf(
			'<div class="wb-gam-activity-card wb-gam-activity-card--%1$s">'
				. '<img class="wb-gam-activity-card__icon" src="%2$s" alt="%3$s" width="64" height="64" />'
				. '<div class="wb-gam-activity-card__body">'
					. '<span class="wb-gam-activity-card__title"><strong>%4$s</strong></span>'
					. ( '' !== $desc_html ? '<div class="wb-gam-activity-card__desc">' . $desc_html . '</div>' : '' )
				. '</div>'
			. '</div>',
			$type_class,
			$src,
			$alt,
			$title_html
		);
	}

	/**
	 * Look up a badge definition row by slug.
	 *
	 * @param string $badge_id Badge slug (matches wb_gam_badge_defs.id).
	 * @return array|null Associative row, or null if not found.
	 */
	public static function lookup_badge_def( string $badge_id ): ?array {
		if ( '' === $badge_id ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, image_url FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s",
				$badge_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Look up a level row by ID.
	 *
	 * @param int $level_id Level numeric ID.
	 * @return array|null Associative row, or null if not found.
	 */
	public static function lookup_level( int $level_id ): ?array {
		if ( $level_id <= 0 ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, min_points, icon_url FROM {$wpdb->prefix}wb_gam_levels WHERE id = %d",
				$level_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Build an HTML link to a user's BP profile, or their display name if BP is unavailable.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function user_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$name = esc_html( $user->display_name );

		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			$url = esc_url( bp_core_get_user_domain( $user_id ) );
			return "<a href=\"{$url}\">{$name}</a>";
		}

		return $name;
	}

	/**
	 * Get a user's display name (escaped) or empty string.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function user_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? esc_html( $user->display_name ) : '';
	}

	/**
	 * Convert a slug to a human title (e.g. first_steps → "First Steps").
	 *
	 * @param string $slug Badge or level slug.
	 */
	public static function humanize_slug( string $slug ): string {
		if ( '' === $slug ) {
			return __( 'a new badge', 'wb-gamification' );
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Get a fallback image data URI for the given card type.
	 *
	 * @param string $type One of: badge, level, kudos, challenge.
	 */
	public static function default_for_type( string $type ): string {
		switch ( $type ) {
			case 'level':
				return self::default_level_image();
			case 'kudos':
				return self::default_kudos_image();
			case 'challenge':
				return self::default_challenge_image();
			case 'badge':
			default:
				return self::default_badge_image();
		}
	}

	public static function default_badge_image(): string {
		return WB_GAM_URL . 'assets/badges/_default.svg';
	}

	public static function default_level_image(): string {
		return WB_GAM_URL . 'assets/levels/_default.svg';
	}

	public static function default_kudos_image(): string {
		return WB_GAM_URL . 'assets/icons/_kudos.svg';
	}

	public static function default_challenge_image(): string {
		return WB_GAM_URL . 'assets/icons/_challenge.svg';
	}
}
