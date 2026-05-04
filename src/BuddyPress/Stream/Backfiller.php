<?php
/**
 * WB Gamification — BuddyPress activity backfiller.
 *
 * Repairs activity rows that were posted before:
 *   - the badge-name parameter-order bug was fixed (action contains <strong></strong>)
 *   - the activity content card was introduced (content is empty/null)
 *
 * Idempotent: only updates rows that look incomplete.
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot data repairer for legacy gamification activity rows.
 *
 * @package WB_Gamification
 */
final class Backfiller {

	/**
	 * Backfill all gamification activity rows missing content cards.
	 *
	 * @return array{badge_earned:int,level_changed:int,kudos_given:int} Counts updated.
	 */
	public static function run(): array {
		return array(
			'badge_earned'  => self::fix_badge_rows(),
			'level_changed' => self::fix_level_rows(),
			'kudos_given'   => self::fix_kudos_rows(),
		);
	}

	/**
	 * Repair badge_earned rows whose action has empty <strong></strong> or no content card.
	 */
	private static function fix_badge_rows(): int {
		global $wpdb;
		$bp_activity = $wpdb->prefix . 'bp_activity';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, user_id, action, content, date_recorded FROM {$bp_activity}
			 WHERE component = 'wb_gamification' AND type = 'badge_earned'
			 ORDER BY id ASC"
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			$needs_update = ( false !== strpos( (string) $row->action, '<strong></strong>' ) )
				|| empty( $row->content )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false !== strpos( (string) $row->content, '<p class="wb-gam-activity-card__desc"' )
				|| false !== strpos( (string) $row->content, 'data:image' )
				|| false !== strpos( (string) $row->content, 'src="image/svg' )
				|| false !== strpos( (string) $row->content, 'loading="lazy"' )
				|| false !== strpos( (string) $row->content, '<strong class="wb-gam-activity-card__title"' );
			if ( ! $needs_update ) {
				continue;
			}

			// Match the closest user_badges row by earned_at proximity to date_recorded.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$badge_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges
					 WHERE user_id = %d
					 ORDER BY ABS(TIMESTAMPDIFF(SECOND, earned_at, %s)) ASC
					 LIMIT 1",
					(int) $row->user_id,
					$row->date_recorded
				)
			);
			if ( ! $badge_id ) {
				continue;
			}

			$def        = ActivityCard::lookup_badge_def( (string) $badge_id );
			$badge_name = ! empty( $def['name'] ) ? $def['name'] : ActivityCard::humanize_slug( (string) $badge_id );
			$badge_desc = $def['description'] ?? '';
			$badge_img  = ! empty( $def['image_url'] ) ? $def['image_url'] : ActivityCard::default_badge_image();
			$user_link  = ActivityCard::user_link( (int) $row->user_id );

			$new_action = sprintf(
				/* translators: 1: user display name link, 2: badge name */
				__( '%1$s earned the <strong>%2$s</strong> badge', 'wb-gamification' ),
				$user_link,
				esc_html( $badge_name )
			);
			$new_content = ActivityCard::render( 'badge', $badge_img, $badge_name, $badge_desc );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$bp_activity,
				array(
					'action'  => $new_action,
					'content' => $new_content,
				),
				array( 'id' => (int) $row->id )
			);
			++$updated;
		}

		return $updated;
	}

	/**
	 * Repair level_changed rows missing a content card.
	 */
	private static function fix_level_rows(): int {
		global $wpdb;
		$bp_activity = $wpdb->prefix . 'bp_activity';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, item_id, content FROM {$bp_activity}
			 WHERE component = 'wb_gamification' AND type = 'level_changed'"
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			$needs_update = empty( $row->content )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false !== strpos( (string) $row->content, '<p class="wb-gam-activity-card__desc"' )
				|| false !== strpos( (string) $row->content, 'data:image' )
				|| false !== strpos( (string) $row->content, 'src="image/svg' )
				|| false !== strpos( (string) $row->content, 'loading="lazy"' )
				|| false !== strpos( (string) $row->content, '<strong class="wb-gam-activity-card__title"' );
			if ( ! $needs_update ) {
				continue;
			}
			$level = ActivityCard::lookup_level( (int) $row->item_id );
			if ( ! $level ) {
				continue;
			}
			$min_points = (int) ( $level['min_points'] ?? 0 );
			$desc       = $min_points > 0
				? sprintf(
					/* translators: %d: points required */
					_n( 'Awarded for reaching %d point.', 'Awarded for reaching %d points.', $min_points, 'wb-gamification' ),
					$min_points
				)
				: __( 'A new milestone reached.', 'wb-gamification' );
			$content = ActivityCard::render(
				'level',
				! empty( $level['icon_url'] ) ? $level['icon_url'] : ActivityCard::default_level_image(),
				$level['name'] ?? '',
				$desc
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $bp_activity, array( 'content' => $content ), array( 'id' => (int) $row->id ) );
			++$updated;
		}

		return $updated;
	}

	/**
	 * Repair kudos_given rows missing a content card.
	 */
	private static function fix_kudos_rows(): int {
		global $wpdb;
		$bp_activity = $wpdb->prefix . 'bp_activity';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, item_id, content FROM {$bp_activity}
			 WHERE component = 'wb_gamification' AND type = 'kudos_given'"
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			$needs_update = empty( $row->content )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false === strpos( (string) $row->content, 'wb-gam-activity-card__icon' )
				|| false !== strpos( (string) $row->content, '<p class="wb-gam-activity-card__desc"' )
				|| false !== strpos( (string) $row->content, 'data:image' )
				|| false !== strpos( (string) $row->content, 'src="image/svg' )
				|| false !== strpos( (string) $row->content, 'loading="lazy"' )
				|| false !== strpos( (string) $row->content, '<strong class="wb-gam-activity-card__title"' );
			if ( ! $needs_update ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$kudos = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT receiver_id, message FROM {$wpdb->prefix}wb_gam_kudos WHERE id = %d",
					(int) $row->item_id
				),
				ARRAY_A
			);
			if ( ! $kudos ) {
				continue;
			}

			$receiver_id   = (int) $kudos['receiver_id'];
			$msg           = (string) ( $kudos['message'] ?? '' );
			$receiver_name = ActivityCard::user_display_name( $receiver_id );
			$avatar        = KudosStream::receiver_avatar_url( $receiver_id );

			$content = ActivityCard::render(
				'kudos',
				$avatar,
				sprintf(
					/* translators: %s: receiver display name */
					__( 'Kudos for %s', 'wb-gamification' ),
					$receiver_name
				),
				'' !== $msg
					? wp_strip_all_tags( $msg )
					: sprintf(
						/* translators: %s: receiver display name */
						__( 'A kudos was sent to %s.', 'wb-gamification' ),
						$receiver_name
					)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $bp_activity, array( 'content' => $content ), array( 'id' => (int) $row->id ) );
			++$updated;
		}

		return $updated;
	}
}
