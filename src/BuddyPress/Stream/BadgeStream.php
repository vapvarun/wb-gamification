<?php
/**
 * WB Gamification — BuddyPress badge_earned stream poster.
 *
 * BadgeEngine fires:
 *   do_action( 'wb_gam_badge_awarded', $user_id, $def, $badge_id )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

use WBGam\Engine\BadgeEngine;
use WBGam\Engine\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Posts "Badge earned" entries to the BuddyPress activity stream.
 *
 * Volume rule (owner decision, 1.6.8): a rare badge gets its own post. A member's other badges
 * earned on the same site day are grouped into one post ("Emma earned 4 badges") by editing that
 * day's post, which carries activity meta META_IDS. The post keeps its original date, and posts
 * from earlier days are never touched.
 *
 * Rare = a credential badge, a badge with a member limit (max_earners), or one held by 5% of
 * members or fewer on a site with 100 or more members. Filter: `wb_gam_activity_badge_is_rare`.
 *
 * @package WB_Gamification
 */
final class BadgeStream {

	private const TYPE       = 'badge_earned';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_badge_earned';

	/**
	 * Activity meta holding the badge ids a grouped post covers (BuddyPress's own meta table).
	 */
	public const META_IDS = 'wb_gam_badge_ids';

	/**
	 * Below this many members a percentage says nothing about rarity.
	 */
	private const RARE_MIN_MEMBERS = 100;

	/**
	 * Held by this share of members or fewer = rare.
	 */
	private const RARE_MAX_PERCENT = 5.0;

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'post' ), 10, 3 );
	}

	/**
	 * Post (or group) a badge_earned activity for the user.
	 *
	 * @param int          $user_id  User who earned the badge.
	 * @param array|object $def      Badge definition row (id, name, description, image_url, …).
	 * @param string       $badge_id Badge slug (matches $def['id']).
	 */
	public static function post( int $user_id, $def = array(), string $badge_id = '' ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$def = is_array( $def ) ? $def : (array) $def;

		// Engine sometimes fires before the def is hydrated — backfill from DB.
		if ( '' !== $badge_id && empty( $def['name'] ) ) {
			$def = ActivityCard::lookup_badge_def( $badge_id ) ?: $def;
		}

		$rare = self::is_rare( $user_id, $badge_id, $def );

		/**
		 * Fires when a gamification event is about to be posted to an activity stream.
		 *
		 * A host community with its own feed (BuddyNext) can listen here and post the same compact
		 * card (ActivityCard::render()) in its own stream. The payload carries the decision this
		 * plugin made: a rare badge stands alone, others group per `group_key` (member + site day).
		 *
		 * @since 1.6.8
		 *
		 * @param string $type    badge | level | kudos | challenge.
		 * @param int    $user_id Member the post is about.
		 * @param array  $payload Card fields and, for badges, `rare` and `group_key`.
		 */
		do_action(
			'wb_gam_activity_post',
			'badge',
			$user_id,
			array(
				'badge_id'    => $badge_id,
				'title'       => self::badge_name( $def, $badge_id ),
				'description' => (string) ( $def['description'] ?? '' ),
				'image_url'   => self::badge_image( $def ),
				'rare'        => $rare,
				'group_key'   => $rare ? '' : $user_id . ':' . Clock::site_date(),
			)
		);

		if ( ! function_exists( 'bp_activity_add' ) || ! function_exists( 'bp_activity_update_meta' ) ) {
			return;
		}

		if ( ! $rare ) {
			$today = self::todays_group( $user_id );
			if ( $today ) {
				self::add_to_group( $today, $badge_id );
				return;
			}
		}

		$id = ActivityCard::add(
			array(
				'user_id'       => $user_id,
				'type'          => self::TYPE,
				'action'        => ActivityCard::action_line( ActivityCard::user_link( $user_id ), 'badge' ),
				'content'       => ActivityCard::render( 'badge', self::badge_image( $def ), self::badge_name( $def, $badge_id ), (string) ( $def['description'] ?? '' ) ),
				'item_id'       => 0,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);

		// A post that later badges of the same day can join.
		if ( $id && ! $rare && '' !== $badge_id ) {
			bp_activity_update_meta( $id, self::META_IDS, array( $badge_id ) );
		}
	}

	/**
	 * Whether a badge is rare enough for a post of its own.
	 *
	 * @param int                  $user_id  Member.
	 * @param string               $badge_id Badge slug.
	 * @param array<string, mixed> $def      Badge definition.
	 */
	public static function is_rare( int $user_id, string $badge_id, array $def ): bool {
		$rare = ! empty( $def['is_credential'] ) || ! empty( $def['max_earners'] );

		if ( ! $rare && '' !== $badge_id && get_user_count() >= self::RARE_MIN_MEMBERS ) {
			// Cached share of members holding each badge (BadgeEngine flushes it on every award).
			$map  = BadgeEngine::get_rarity_map();
			$rare = isset( $map[ $badge_id ] ) && (float) $map[ $badge_id ] <= self::RARE_MAX_PERCENT;
		}

		/**
		 * Filter whether a badge gets its own activity post instead of joining the day's group.
		 *
		 * @since 1.6.8
		 *
		 * @param bool   $rare     Default decision.
		 * @param string $badge_id Badge slug.
		 * @param array  $def      Badge definition.
		 * @param int    $user_id  Member who earned it.
		 */
		return (bool) apply_filters( 'wb_gam_activity_badge_is_rare', $rare, $badge_id, $def, $user_id );
	}

	/**
	 * UTC bounds of the current site day: [ start, next day's start ).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function site_day_window(): array {
		return array( Clock::site_day_start( 'today' ), Clock::site_day_start( 'tomorrow' ) );
	}

	/**
	 * The member's groupable badge post of the current site day, if any.
	 *
	 * @param int $user_id Member.
	 * @return object|null A loaded BP_Activity_Activity.
	 */
	private static function todays_group( int $user_id ): ?object {
		if ( ! function_exists( 'bp_activity_get' ) || ! class_exists( '\BP_Activity_Activity' ) ) {
			return null;
		}
		[ $from, $to ] = self::site_day_window();

		$found = bp_activity_get(
			array(
				'filter'            => array(
					'user_id' => $user_id,
					'object'  => ActivityCard::COMPONENT,
					'action'  => self::TYPE,
				),
				'meta_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one member, one day, LIMIT 1.
					array(
						'key'     => self::META_IDS,
						'compare' => 'EXISTS',
					),
				),
				'date_query'        => array(
					array(
						'column'    => 'date_recorded',
						'after'     => $from,
						'before'    => $to,
						'inclusive' => true,
					),
				),
				'per_page'          => 1,
				'page'              => 1,
				'sort'              => 'DESC',
				'display_comments'  => false,
				'show_hidden'       => true,
				'count_total'       => false,
				'update_meta_cache' => false,
			)
		);

		$activity = $found['activities'][0] ?? null;
		if ( ! is_object( $activity ) || empty( $activity->id ) ) {
			return null;
		}

		$loaded = new \BP_Activity_Activity( (int) $activity->id );
		return $loaded->id ? $loaded : null;
	}

	/**
	 * Add a badge to the day's post and redraw it as a group ("Emma earned 4 badges").
	 *
	 * @param object $activity The day's post (a loaded BP_Activity_Activity).
	 * @param string $badge_id Badge slug.
	 */
	private static function add_to_group( object $activity, string $badge_id ): void {
		if ( ! function_exists( 'bp_activity_get_meta' ) || ! function_exists( 'bp_activity_update_meta' ) ) {
			return;
		}
		$ids = bp_activity_get_meta( (int) $activity->id, self::META_IDS, true );
		$ids = is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : array();
		if ( '' !== $badge_id && ! in_array( $badge_id, $ids, true ) ) {
			$ids[] = $badge_id;
		}

		$redrawn = self::group_markup( (int) $activity->user_id, $ids );

		$activity->action  = $redrawn['action'];
		$activity->content = $redrawn['content'];
		if ( ActivityCard::update( $activity ) ) {
			bp_activity_update_meta( (int) $activity->id, self::META_IDS, $ids );
		}
	}

	/**
	 * Headline and card for a post covering one or more badges. Shared with the Backfiller.
	 *
	 * @param int           $user_id   Member.
	 * @param array<string> $badge_ids Badges in the order earned.
	 * @return array{action:string,content:string}
	 */
	public static function group_markup( int $user_id, array $badge_ids ): array {
		$user_link = ActivityCard::user_link( $user_id );
		$defs      = array();
		foreach ( $badge_ids as $id ) {
			$defs[ $id ] = ActivityCard::lookup_badge_def( (string) $id ) ?? array();
		}

		$count = count( $badge_ids );
		$last  = (string) end( $badge_ids );
		$def   = $defs[ $last ] ?? array();

		if ( $count <= 1 ) {
			return array(
				'action'  => ActivityCard::action_line( $user_link, 'badge' ),
				'content' => ActivityCard::render( 'badge', self::badge_image( $def ), self::badge_name( $def, $last ), (string) ( $def['description'] ?? '' ) ),
			);
		}

		$names = array();
		foreach ( array_reverse( $badge_ids ) as $id ) {
			$names[] = self::badge_name( $defs[ $id ] ?? array(), (string) $id );
		}

		return array(
			'action'  => sprintf(
				/* translators: 1: member name (a link to their profile), 2: number of badges earned today. */
				_n( '%1$s earned %2$d badge', '%1$s earned %2$d badges', $count, 'wb-gamification' ),
				$user_link,
				$count
			),
			'content' => ActivityCard::render(
				'badge',
				self::badge_image( $def ),
				/* translators: list separator between badge names, e.g. "Blog Publisher, First Comment". */
				implode( _x( ', ', 'badge name list separator', 'wb-gamification' ), $names ),
				'',
				__( 'Badges earned', 'wb-gamification' )
			),
		);
	}

	/**
	 * Display name of a badge.
	 *
	 * @param array<string, mixed> $def      Badge definition.
	 * @param string               $badge_id Badge slug.
	 */
	private static function badge_name( array $def, string $badge_id ): string {
		return ! empty( $def['name'] ) ? (string) $def['name'] : ActivityCard::humanize_slug( $badge_id );
	}

	/**
	 * Icon URL of a badge, the default badge icon when it has none.
	 *
	 * @param array<string, mixed> $def Badge definition.
	 */
	private static function badge_image( array $def ): string {
		return ! empty( $def['image_url'] ) ? (string) $def['image_url'] : ActivityCard::default_badge_image();
	}

	/**
	 * Whether the badge_earned stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
