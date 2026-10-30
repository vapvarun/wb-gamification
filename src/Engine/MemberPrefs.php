<?php
/**
 * WB Gamification — Member preferences
 *
 * The one write path, and the read path for a single member, for a member's own
 * choices in `wb_gam_member_prefs`: hide their rank on their profile, stay off
 * the leaderboards, and stop reward emails. Set-based queries (boards, batches)
 * still join the table directly.
 *
 * Until 1.6.8 these columns were read by the leaderboard, nudges, weekly and
 * transactional emails, the profile header and REST, but nothing wrote them,
 * so a member could not make any of the three choices.
 *
 * @package WB_Gamification
 * @since   1.6.8
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Reads and writes a member's gamification preferences.
 */
final class MemberPrefs {

	/**
	 * Values for a member who has never saved a choice (the table defaults).
	 *
	 * @var array{leaderboard_opt_out: bool, show_rank: bool, notification_mode: string}
	 */
	public const DEFAULTS = array(
		'leaderboard_opt_out' => false,
		'show_rank'           => true,
		'notification_mode'   => 'smart',
	);

	/**
	 * Allowed `notification_mode` values: 'smart' sends reward emails, 'none' sends none.
	 *
	 * @var string[]
	 */
	public const NOTIFICATION_MODES = array( 'smart', 'none' );

	/**
	 * A member's preferences, defaults filled in.
	 *
	 * @param int $user_id Member.
	 * @return array{leaderboard_opt_out: bool, show_rank: bool, notification_mode: string}
	 */
	public static function get( int $user_id ): array {
		global $wpdb;

		$row = $user_id > 0
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT leaderboard_opt_out, show_rank, notification_mode FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
					$user_id
				),
				ARRAY_A
			)
			: null;

		if ( ! $row ) {
			return self::DEFAULTS;
		}

		return array(
			'leaderboard_opt_out' => (bool) $row['leaderboard_opt_out'],
			'show_rank'           => (bool) $row['show_rank'],
			'notification_mode'   => in_array( $row['notification_mode'], self::NOTIFICATION_MODES, true ) ? $row['notification_mode'] : 'smart',
		);
	}

	/**
	 * Save some or all of a member's preferences. Keys not given keep their value.
	 *
	 * @param int                  $user_id Member.
	 * @param array<string, mixed> $changes Any of leaderboard_opt_out (bool), show_rank (bool), notification_mode ('smart'|'none').
	 * @return array{leaderboard_opt_out: bool, show_rank: bool, notification_mode: string} The saved preferences.
	 */
	public static function set( int $user_id, array $changes ): array {
		global $wpdb;

		$columns = array();
		foreach ( array( 'leaderboard_opt_out', 'show_rank' ) as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$columns[ $key ] = $changes[ $key ] ? 1 : 0;
			}
		}
		if ( isset( $changes['notification_mode'] ) && in_array( $changes['notification_mode'], self::NOTIFICATION_MODES, true ) ) {
			$columns['notification_mode'] = $changes['notification_mode'];
		}
		if ( $user_id <= 0 || ! $columns ) {
			return self::get( $user_id );
		}

		// Only the given columns, in one statement: two choices saved at once must not
		// overwrite each other, which a read-then-replace of the whole row does.
		$names = array_keys( $columns );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wb_gam_member_prefs (user_id, " . implode( ', ', $names ) . ') VALUES (%d, ' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', array_map( static fn( $name ) => "{$name} = VALUES({$name})", $names ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Column names come from the fixed list above.
				$user_id,
				...array_values( $columns )
			)
		);

		// Leaving or rejoining the leaderboard must show on the next read, not after the cache expires.
		LeaderboardEngine::invalidate_cache();

		$prefs = self::get( $user_id );

		/**
		 * Fires after a member's gamification preferences are saved.
		 *
		 * @since 1.6.8
		 * @param int   $user_id Member.
		 * @param array $prefs   Saved preferences.
		 */
		do_action( 'wb_gam_member_prefs_saved', $user_id, $prefs );

		return $prefs;
	}

	/**
	 * The member's own controls for these choices, saved on change via REST.
	 *
	 * Shown on the member's own profile page and BuddyPress Achievements tab.
	 *
	 * @param int $user_id Member (the current user).
	 * @return void
	 */
	public static function render_controls( int $user_id ): void {
		if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
			return;
		}

		wp_enqueue_script( 'wb-gam-member-prefs', WB_GAM_URL . 'assets/js/member-prefs.js', array( 'wb-gam-mount', 'wb-gam-rest' ), WB_GAM_VERSION, true );
		wp_enqueue_style( 'wb-gamification' );

		$prefs   = self::get( $user_id );
		$choices = array(
			'show_rank'           => array( $prefs['show_rank'], __( 'Show my level and points on my profile', 'wb-gamification' ) ),
			'leaderboard_opt_out' => array( ! $prefs['leaderboard_opt_out'], __( 'Show me on leaderboards', 'wb-gamification' ) ),
			'notification_mode'   => array( 'none' !== $prefs['notification_mode'], __( 'Email me about my rewards', 'wb-gamification' ) ),
		);

		printf(
			'<section class="wb-gam-profile-page__section wb-gam-profile-privacy wb-gam-member-prefs" data-rest-url="%s" data-rest-nonce="%s" data-error="%s" data-saved="%s">',
			esc_url_raw( rest_url( 'wb-gamification/v1/members/' . $user_id . '/preferences' ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_attr__( 'Could not save. Please try again.', 'wb-gamification' ),
			esc_attr__( 'Saved.', 'wb-gamification' )
		);
		echo '<fieldset class="wb-gam-member-prefs__set"><legend class="wb-gam-profile-privacy__label">' . esc_html__( 'Your gamification choices', 'wb-gamification' ) . '</legend>';
		foreach ( $choices as $key => $choice ) {
			printf(
				'<label class="wb-gam-member-prefs__choice"><input type="checkbox" name="%s" %s> %s</label>',
				esc_attr( $key ),
				checked( $choice[0], true, false ),
				esc_html( $choice[1] )
			);
		}
		echo '</fieldset><p class="wb-gam-profile-privacy__status" role="status" aria-live="polite"></p></section>';
	}
}
