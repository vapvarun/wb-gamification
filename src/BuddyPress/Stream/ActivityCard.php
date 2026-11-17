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
	 * The BuddyPress component every gamification activity is saved under.
	 */
	public const COMPONENT = 'wb_gamification';

	/**
	 * Tags and attributes the card needs on top of BuddyPress's activity allow-list.
	 *
	 * BuddyPress runs activity content through bp_activity_filter_kses() on save AND on display,
	 * and that filter drops every `class` attribute and every `<div>`. BuddyBoss drops the divs.
	 * Either way the card arrived as a loose icon and three lines of text. These tags are added
	 * only while our own component's content is filtered, never for a member's own post.
	 */
	private const CARD_TAGS = array(
		'p'    => array( 'class' => true ),
		'div'  => array( 'class' => true ),
		'span' => array( 'class' => true ),
		'img'  => array(
			'class'  => true,
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
		),
	);

	/**
	 * True while content of our component is being saved or displayed.
	 *
	 * @var bool
	 */
	private static bool $own_content = false;

	/**
	 * True while wpautop is off for the card being displayed.
	 *
	 * @var bool
	 */
	private static bool $autop_removed = false;

	/**
	 * Let the card survive BuddyPress's kses for our component only, and load its stylesheet
	 * wherever an activity stream shows.
	 */
	public static function init(): void {
		add_filter( 'bp_activity_allowed_tags', array( __CLASS__, 'allowed_tags' ) );
		// bp_activity_filter_kses runs at priority 1; mark the activity before it, clear after.
		add_filter( 'bp_get_activity_content_body', array( __CLASS__, 'mark_display' ), 0, 2 );
		add_filter( 'bp_get_activity_content_body', array( __CLASS__, 'clear_mark' ), 2 );
		// wpautop (priority 10) is taken off for our card only and put back after the run.
		add_filter( 'bp_get_activity_content_body', array( __CLASS__, 'restore_autop' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_community_pages' ) );
		// Any other page that renders an activity loop on the server (a widget, a block).
		add_action( 'bp_before_activity_entry', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Widen the activity allow-list while our own content is filtered.
	 *
	 * @param array<string, array<string, bool>> $tags Allowed tags.
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_tags( $tags ) {
		if ( ! self::$own_content || ! is_array( $tags ) ) {
			return $tags;
		}
		foreach ( self::CARD_TAGS as $tag => $attrs ) {
			$tags[ $tag ] = array_merge( isset( $tags[ $tag ] ) && is_array( $tags[ $tag ] ) ? $tags[ $tag ] : array(), $attrs );
		}
		return $tags;
	}

	/**
	 * Mark the activity being displayed as ours (or not) before BuddyPress filters it.
	 *
	 * @param string      $content  Activity content.
	 * @param object|null $activity The activity being displayed.
	 * @return string Unchanged content.
	 */
	public static function mark_display( $content, $activity = null ) {
		self::$own_content = is_object( $activity ) && isset( $activity->component ) && self::COMPONENT === $activity->component;

		// BuddyPress runs wpautop on activity content. On the card it wraps the inline <img>
		// before the body <div> in a <p> of its own, and a theme that pads paragraphs (BuddyX:
		// 0 15px) turned that empty <p> into a 60px gap. The card is finished markup, so wpautop
		// is skipped for it; WP_Hook honours a removal made before its priority is reached.
		if ( self::$own_content ) {
			self::$autop_removed = (bool) remove_filter( 'bp_get_activity_content_body', 'wpautop' );
		}
		return $content;
	}

	/**
	 * Put wpautop back after our card has been filtered, so the next activity gets it.
	 *
	 * @param string $content Activity content.
	 * @return string Unchanged content.
	 */
	public static function restore_autop( $content ) {
		if ( self::$autop_removed ) {
			add_filter( 'bp_get_activity_content_body', 'wpautop' );
			self::$autop_removed = false;
		}
		return $content;
	}

	/**
	 * Clear the mark once BuddyPress has filtered the content.
	 *
	 * @param string $content Activity content.
	 * @return string Unchanged content.
	 */
	public static function clear_mark( $content ) {
		self::$own_content = false;
		return $content;
	}

	/**
	 * Save a gamification activity through BuddyPress with the card's tags allowed.
	 *
	 * @param array<string, mixed> $args bp_activity_add() arguments; the component is ours.
	 * @return int New activity id, 0 on failure.
	 */
	public static function add( array $args ): int {
		if ( ! function_exists( 'bp_activity_add' ) ) {
			return 0;
		}
		$args['component'] = self::COMPONENT;
		self::$own_content = true;
		try {
			$id = bp_activity_add( $args );
		} finally {
			self::$own_content = false;
		}
		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * Save an edited gamification activity with the card's tags allowed. The date is kept.
	 *
	 * @param object $activity Loaded BP_Activity_Activity of our component.
	 * @return bool
	 */
	public static function update( object $activity ): bool {
		if ( ! method_exists( $activity, 'save' ) || ! isset( $activity->component ) || self::COMPONENT !== $activity->component ) {
			return false;
		}
		self::$own_content = true;
		try {
			return (bool) $activity->save();
		} finally {
			self::$own_content = false;
		}
	}

	/**
	 * Load the card stylesheet on community pages. The activity directory and profile streams
	 * load their entries over AJAX, after the page has rendered, so the stylesheet has to be on
	 * the page before the first entry arrives.
	 */
	public static function enqueue_on_community_pages(): void {
		if ( function_exists( 'is_buddypress' ) && is_buddypress() ) {
			self::enqueue_style();
		}
	}

	/**
	 * Enqueue the stylesheet that styles the card (late enqueues print in the footer).
	 */
	public static function enqueue_style(): void {
		if ( wp_style_is( 'wb-gamification', 'registered' ) ) {
			wp_enqueue_style( 'wb-gamification' );
		}
	}

	/**
	 * Render the activity content card: icon, small uppercase label, name, one-line reason.
	 *
	 * Saved through add() / update(), so the wrapper and classes survive BuddyPress's kses.
	 * Absolute URLs only for the icon (kses drops `data:` URIs).
	 *
	 * @param string $type        One of: badge, level, kudos, challenge.
	 * @param string $image_url   Absolute URL for the icon/avatar (no data: URIs).
	 * @param string $title       Card title (plain text).
	 * @param string $description Card description (plain text).
	 * @param string $label       Label above the title; the type's own label when empty.
	 */
	public static function render( string $type, string $image_url, string $title, string $description, string $label = '' ): string {
		$type_class = sanitize_html_class( $type );
		$image_url  = $image_url ?: self::default_for_type( $type );

		$type_labels = array(
			'badge'     => __( 'Badge earned', 'wb-gamification' ),
			'level'     => __( 'Level up', 'wb-gamification' ),
			'kudos'     => __( 'Kudos', 'wb-gamification' ),
			'challenge' => __( 'Challenge completed', 'wb-gamification' ),
		);
		if ( '' === $label ) {
			$label = $type_labels[ $type ] ?? ucfirst( $type );
		}

		// The name sits right next to the icon, so the image is decorative (empty alt).
		// One paragraph of inline elements (laid out by CSS). Activity themes inset and space the
		// paragraphs of a post (BuddyX pads them 0 15px, Reign and Nouveau give them margins), so
		// a card that IS a paragraph sits inside the entry exactly like its text, on every theme,
		// with no theme-specific padding of its own. Inline-only content also leaves wpautop
		// nothing to wrap.
		return sprintf(
			'<p class="wb-gam-activity-card-wrap">'
				. '<span class="wb-gam-activity-card wb-gam-activity-card--%1$s">'
					. '<img class="wb-gam-activity-card__icon" src="%2$s" alt="" width="40" height="40" />'
					. '<span class="wb-gam-activity-card__body">'
						. '<span class="wb-gam-activity-card__eyebrow">%3$s</span>'
						. '<span class="wb-gam-activity-card__title">%4$s</span>'
						. ( '' !== $description ? '<span class="wb-gam-activity-card__desc">%5$s</span>' : '' )
					. '</span>'
				. '</span>'
			. '</p>',
			$type_class,
			esc_url( $image_url ),
			esc_html( $label ),
			esc_html( $title ),
			esc_html( $description )
		);
	}

	/**
	 * Whether stored content already carries the current card markup. Rows saved before 1.6.8
	 * through BuddyPress's kses lost the wrapper and classes; older rows had a different shape
	 * (including the 1.6.8 development <div> card), so the Backfiller redraws them.
	 *
	 * @param string $content Stored activity content.
	 */
	public static function has_card( string $content ): bool {
		if ( false === strpos( $content, '<p class="wb-gam-activity-card-wrap"><span class="wb-gam-activity-card wb-gam-activity-card--' )
			|| false === strpos( $content, '<span class="wb-gam-activity-card__body">' ) ) {
			return false;
		}
		foreach ( array( 'data:image', 'src="image/svg', 'loading="lazy"', '<strong class="wb-gam-activity-card__title"', '<p class="wb-gam-activity-card__desc"' ) as $legacy ) {
			if ( false !== strpos( $content, $legacy ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build the generic, non-duplicating activity action headline.
	 *
	 * The content card ({@see render()}) already names the specific badge /
	 * level / challenge / kudos recipient, so the BuddyPress action headline
	 * stays a short generic verb ("earned a badge") instead of repeating the
	 * specific name — otherwise every gamification activity states the same
	 * thing twice (the duplication QA flagged 2026-06).
	 *
	 * @param string $user_link HTML profile link for the actor (from user_link()).
	 * @param string $type      One of: badge, level, kudos, challenge.
	 */
	public static function action_line( string $user_link, string $type ): string {
		switch ( $type ) {
			case 'level':
				/* translators: %s: user display name link */
				$template = __( '%s reached a new level', 'wb-gamification' );
				break;
			case 'kudos':
				/* translators: %s: user display name link */
				$template = __( '%s gave kudos', 'wb-gamification' );
				break;
			case 'challenge':
				/* translators: %s: user display name link */
				$template = __( '%s completed a challenge', 'wb-gamification' );
				break;
			case 'badge':
			default:
				/* translators: %s: user display name link */
				$template = __( '%s earned a badge', 'wb-gamification' );
				break;
		}
		return sprintf( $template, $user_link );
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
	 * Build an HTML link to a member's profile, or their display name when
	 * they have no publicly reachable profile to link to.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function user_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		return \WBGam\Engine\MemberUrl::wrap(
			\WBGam\Engine\MemberUrl::resolve( (int) $user_id ),
			esc_html( $user->display_name )
		);
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
