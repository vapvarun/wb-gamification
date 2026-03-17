<?php
/**
 * Admin Analytics Dashboard
 *
 * Adds a "Gamification → Analytics" admin page showing:
 *   - Points awarded over time (7d / 30d / 90d)
 *   - Top actions by volume
 *   - Active member count vs total
 *   - Badge earn rate
 *   - Challenge completion rate
 *   - Streak health (% of members with streak > 0)
 *
 * All queries are read-only aggregations with object-cache backing.
 * Dashboard renders server-side HTML — no JS build required.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

final class AnalyticsDashboard {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = 600; // 10 minutes

	// ── Boot ────────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Analytics', 'wb-gamification' ),
			__( 'Analytics', 'wb-gamification' ),
			'manage_options',
			'wb-gamification-analytics',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'wb-gamification_page_wb-gamification-analytics' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-admin-analytics',
			WB_GAM_URL . 'assets/css/admin-analytics.css',
			[],
			WB_GAM_VERSION
		);
	}

	// ── Page render ─────────────────────────────────────────────────────────────

	public static function render_page(): void {
		$period = isset( $_GET['period'] ) && in_array( $_GET['period'], [ '7', '30', '90' ], true )
			? (int) $_GET['period']
			: 30;

		$stats = self::get_stats( $period );
		?>
		<div class="wrap wb-gam-analytics">
			<h1><?php esc_html_e( 'Gamification Analytics', 'wb-gamification' ); ?></h1>

			<!-- Period selector -->
			<div class="wb-gam-analytics__period-bar">
				<?php foreach ( [ 7 => __( '7 days', 'wb-gamification' ), 30 => __( '30 days', 'wb-gamification' ), 90 => __( '90 days', 'wb-gamification' ) ] as $d => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'period', $d ) ); ?>"
						class="button<?php echo $d === $period ? ' button-primary' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<!-- KPI cards -->
			<div class="wb-gam-analytics__kpi-grid">
				<?php
				self::kpi_card(
					__( 'Points Awarded', 'wb-gamification' ),
					number_format_i18n( $stats['points_total'] ),
					sprintf(
						/* translators: %d = number of days */
						__( 'Last %d days', 'wb-gamification' ),
						$period
					),
					'⭐'
				);
				self::kpi_card(
					__( 'Active Members', 'wb-gamification' ),
					number_format_i18n( $stats['active_members'] ),
					sprintf(
						/* translators: %d = total member count */
						__( '%d total members', 'wb-gamification' ),
						$stats['total_members']
					),
					'👥'
				);
				self::kpi_card(
					__( 'Badges Earned', 'wb-gamification' ),
					number_format_i18n( $stats['badges_earned'] ),
					sprintf(
						/* translators: %s = % of members who have earned any badge */
						__( '%s%% of active members', 'wb-gamification' ),
						$stats['badge_earner_pct']
					),
					'🏅'
				);
				self::kpi_card(
					__( 'Challenges Completed', 'wb-gamification' ),
					number_format_i18n( $stats['challenges_completed'] ),
					sprintf(
						/* translators: %s = % completion rate */
						__( '%s%% completion rate', 'wb-gamification' ),
						$stats['challenge_completion_pct']
					),
					'🎯'
				);
				self::kpi_card(
					__( 'Active Streaks', 'wb-gamification' ),
					number_format_i18n( $stats['active_streaks'] ),
					sprintf(
						/* translators: %s = % of members with active streak */
						__( '%s%% streak health', 'wb-gamification' ),
						$stats['streak_health_pct']
					),
					'🔥'
				);
				self::kpi_card(
					__( 'Kudos Given', 'wb-gamification' ),
					number_format_i18n( $stats['kudos_given'] ),
					sprintf(
						/* translators: %d = number of days */
						__( 'Last %d days', 'wb-gamification' ),
						$period
					),
					'👏'
				);
				?>
			</div>

			<div class="wb-gam-analytics__two-col">

				<!-- Top actions -->
				<div class="wb-gam-analytics__panel">
					<h2><?php esc_html_e( 'Top Actions by Points', 'wb-gamification' ); ?></h2>
					<?php if ( empty( $stats['top_actions'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No data yet.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Events', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Total pts', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats['top_actions'] as $row ) : ?>
									<tr>
										<td><code><?php echo esc_html( $row['action_id'] ); ?></code></td>
										<td><?php echo esc_html( number_format_i18n( (int) $row['events'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (int) $row['pts'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Top earners -->
				<div class="wb-gam-analytics__panel">
					<h2>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d = number of days */
								__( 'Top Earners — Last %d Days', 'wb-gamification' ),
								$period
							)
						);
						?>
					</h2>
					<?php if ( empty( $stats['top_earners'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No data yet.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Points', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats['top_earners'] as $row ) :
									$user = get_userdata( (int) $row['user_id'] );
									if ( ! $user ) continue;
								?>
									<tr>
										<td>
											<?php echo get_avatar( (int) $row['user_id'], 24 ); ?>
											<?php echo esc_html( $user->display_name ); ?>
										</td>
										<td><?php echo esc_html( number_format_i18n( (int) $row['pts'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

			</div><!-- .wb-gam-analytics__two-col -->

			<!-- Daily points sparkline (simple bar chart via inline SVG) -->
			<div class="wb-gam-analytics__panel">
				<h2><?php esc_html_e( 'Daily Points Trend', 'wb-gamification' ); ?></h2>
				<?php self::render_sparkline( $stats['daily_points'], $period ); ?>
			</div>

		</div><!-- .wrap -->
		<?php
	}

	// ── Data queries ─────────────────────────────────────────────────────────────

	private static function get_stats( int $period ): array {
		$cache_key = "wb_gam_analytics_{$period}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );

		// Points total.
		$points_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points WHERE created_at >= %s",
				$since
			)
		);

		// Active members (at least 1 point in period).
		$active_members = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_points WHERE created_at >= %s",
				$since
			)
		);

		// Total members.
		$total_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		// Badges earned in period.
		$badges_earned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE earned_at >= %s",
				$since
			)
		);

		// Badge earner pct (unique users who earned any badge in period vs active members).
		$badge_earners = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_user_badges WHERE earned_at >= %s",
				$since
			)
		);
		$badge_earner_pct = $active_members > 0
			? round( ( $badge_earners / $active_members ) * 100, 1 )
			: 0;

		// Challenges completed in period.
		$challenges_completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_challenge_log WHERE completed_at >= %s",
				$since
			)
		);

		// Challenge completion rate (completed / total started).
		$challenges_started = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_challenge_log WHERE created_at >= %s",
				$since
			)
		);
		$challenge_completion_pct = $challenges_started > 0
			? round( ( $challenges_completed / $challenges_started ) * 100, 1 )
			: 0;

		// Active streaks (current_streak > 0).
		$active_streaks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_streaks WHERE current_streak > 0"
		);
		$streak_health_pct = $total_members > 0
			? round( ( $active_streaks / $total_members ) * 100, 1 )
			: 0;

		// Kudos given in period.
		$kudos_given = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos WHERE created_at >= %s",
				$since
			)
		);

		// Top 10 actions by total points.
		$top_actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, COUNT(*) AS events, SUM(points) AS pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at >= %s
				  GROUP BY action_id
				  ORDER BY pts DESC
				  LIMIT 10",
				$since
			),
			ARRAY_A
		) ?: [];

		// Top 10 earners in period.
		$top_earners = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, SUM(points) AS pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at >= %s
				  GROUP BY user_id
				  ORDER BY pts DESC
				  LIMIT 10",
				$since
			),
			ARRAY_A
		) ?: [];

		// Daily points for sparkline.
		$daily_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, SUM(points) AS pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at >= %s
				  GROUP BY DATE(created_at)
				  ORDER BY day ASC",
				$since
			),
			ARRAY_A
		) ?: [];

		$daily_points = [];
		foreach ( $daily_rows as $row ) {
			$daily_points[ $row['day'] ] = (int) $row['pts'];
		}

		$data = compact(
			'points_total', 'active_members', 'total_members',
			'badges_earned', 'badge_earner_pct',
			'challenges_completed', 'challenge_completion_pct',
			'active_streaks', 'streak_health_pct',
			'kudos_given', 'top_actions', 'top_earners', 'daily_points'
		);

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	// ── Render helpers ───────────────────────────────────────────────────────────

	private static function kpi_card( string $title, string $value, string $sub, string $icon ): void {
		?>
		<div class="wb-gam-analytics__kpi-card">
			<span class="wb-gam-analytics__kpi-icon"><?php echo esc_html( $icon ); ?></span>
			<span class="wb-gam-analytics__kpi-value"><?php echo esc_html( $value ); ?></span>
			<span class="wb-gam-analytics__kpi-title"><?php echo esc_html( $title ); ?></span>
			<span class="wb-gam-analytics__kpi-sub"><?php echo esc_html( $sub ); ?></span>
		</div>
		<?php
	}

	private static function render_sparkline( array $daily_points, int $period ): void {
		if ( empty( $daily_points ) ) {
			echo '<p class="description">' . esc_html__( 'No data yet.', 'wb-gamification' ) . '</p>';
			return;
		}

		// Fill gaps so every day in range has a value.
		$end_ts   = current_time( 'timestamp' );
		$start_ts = strtotime( "-{$period} days", $end_ts );
		$filled   = [];
		for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
			$day          = gmdate( 'Y-m-d', $ts );
			$filled[$day] = $daily_points[ $day ] ?? 0;
		}

		$max = max( $filled ) ?: 1;
		$w   = 100 / count( $filled ); // % width per bar

		echo '<div class="wb-gam-analytics__sparkline" aria-hidden="true">';
		foreach ( $filled as $day => $pts ) {
			$h     = round( ( $pts / $max ) * 100 );
			$title = esc_attr( $day . ': ' . number_format_i18n( $pts ) . ' pts' );
			printf(
				'<div class="wb-gam-analytics__spark-bar" style="height:%d%%;width:%s%%" title="%s"></div>',
				$h,
				esc_attr( number_format( $w, 4 ) ),
				$title
			);
		}
		echo '</div>';
	}
}
