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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

/**
 * Renders the Gamification Analytics admin dashboard page.
 *
 * @package WB_Gamification
 */
final class AnalyticsDashboard {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = 600; // 10 minutes

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register admin_menu and admin_enqueue_scripts hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the Analytics submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Analytics', 'wb-gamification' ),
			__( 'Analytics', 'wb-gamification' ),
			'wb_gam_view_analytics',
			'wb-gamification-analytics',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue analytics CSS on the analytics admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification-analytics' ) ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-page-analytics',
			plugins_url( 'assets/css/admin/pages/analytics.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gam-admin-analytics',
			WB_GAM_URL . 'assets/css/admin-analytics.css',
			array( 'wb-gam-page-analytics' ),
			WB_GAM_VERSION
		);
	}

	// ── Page render ─────────────────────────────────────────────────────────────

	/**
	 * Render the analytics dashboard page HTML.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// Handler-level cap guard. The admin menu already gates visibility on
		// wb_gam_view_analytics, but any direct-URL access path (quick links
		// from another plugin, bookmarks shared across sites) bypasses the
		// menu cap. Mirror the pattern from BadgeAdminPage / ChallengeManagerPage
		// so the cap actually enforces on every render entry.
		if ( ! current_user_can( 'wb_gam_view_analytics' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to view analytics.', 'wb-gamification' ),
				esc_html__( 'Forbidden', 'wb-gamification' ),
				array( 'response' => 403 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET param validated against allowlist, read-only analytics display.
		$period = isset( $_GET['period'] ) && in_array( $_GET['period'], array( '7', '30', '90' ), true )
			? (int) $_GET['period']
			: 30;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$stats = self::get_stats( $period );
		?>
		<div class="wrap wbgam-wrap wb-gam-analytics">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Gamification Analytics', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Engagement at a glance - points awarded, badges earned, streaks, kudos and challenge completion across your community.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<!-- Period selector -->
			<div class="wb-gam-analytics__period-bar wbgam-stack-block">
				<?php
				foreach ( array(
					7  => __( '7 days', 'wb-gamification' ),
					30 => __( '30 days', 'wb-gamification' ),
					90 => __( '90 days', 'wb-gamification' ),
				) as $d => $label ) :
					?>
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
					'icon-star'
				);
				self::kpi_card(
					__( 'Active Members', 'wb-gamification' ),
					number_format_i18n( $stats['active_members'] ),
					sprintf(
						/* translators: %d = total member count */
						__( '%d total members', 'wb-gamification' ),
						$stats['total_members']
					),
					'icon-users'
				);
				self::kpi_card(
					__( 'Badges Earned', 'wb-gamification' ),
					number_format_i18n( $stats['badges_earned'] ),
					sprintf(
						/* translators: %s: percentage of active members. */
						__( '%s%% of active members', 'wb-gamification' ),
						$stats['badge_earner_pct']
					),
					'icon-medal'
				);
				self::kpi_card(
					__( 'Challenges Completed', 'wb-gamification' ),
					number_format_i18n( $stats['challenges_completed'] ),
					sprintf(
						/* translators: %s: completion rate percentage. */
						__( '%s%% completion rate', 'wb-gamification' ),
						$stats['challenge_completion_pct']
					),
					'icon-target'
				);
				self::kpi_card(
					__( 'Active Streaks', 'wb-gamification' ),
					number_format_i18n( $stats['active_streaks'] ),
					sprintf(
						/* translators: %s: streak health percentage. */
						__( '%s%% streak health', 'wb-gamification' ),
						$stats['streak_health_pct']
					),
					'icon-flame'
				);
				self::kpi_card(
					__( 'Kudos Given', 'wb-gamification' ),
					number_format_i18n( $stats['kudos_given'] ),
					sprintf(
						/* translators: %d = number of days */
						__( 'Last %d days', 'wb-gamification' ),
						$period
					),
					'icon-heart-handshake'
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
									<?php
									$_action_def   = \WBGam\Engine\Registry::get_action( $row['action_id'] );
									$_action_label = $_action_def ? $_action_def['label'] : self::fallback_action_label( $row['action_id'] );
									?>
									<tr>
										<td><?php echo esc_html( $_action_label ); ?></td>
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
								__( 'Top Earners - Last %d Days', 'wb-gamification' ),
								$period
							)
						);
						?>
					</h2>
					<?php if ( empty( $stats['top_earners'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No data yet.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<?php
						// Resolve the default currency label so the analytics
						// header reads e.g. "Coins" on coins-default sites.
						$pt_service          = new \WBGam\Services\PointTypeService();
						$pt_record_analytics = $pt_service->get( $pt_service->default_slug() );
						$points_label_admin  = (string) ( $pt_record_analytics['label'] ?? __( 'Points', 'wb-gamification' ) );
						?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
									<th><?php echo esc_html( $points_label_admin ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $stats['top_earners'] as $row ) :
									$user = get_userdata( (int) $row['user_id'] );
									if ( ! $user ) {
										continue;
									}
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

			<!-- Behavioural intelligence (v2.5 / AI v1 projection) -->
			<?php self::render_intelligence_panels(); ?>

			<!-- Integration drift (unknown action_ids fired in the last 24h) -->
			<?php self::render_unknown_actions_panel(); ?>

		</div><!-- .wrap -->
		<?php
	}

	// ── Data queries ─────────────────────────────────────────────────────────────

	/**
	 * Fetch and cache all analytics stats for a given time period.
	 *
	 * @param int $period Number of days to look back (7, 30, or 90).
	 * @return array<string, mixed> Associative array of stat values.
	 */
	public static function get_stats( int $period ): array {
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
		//
		// EVERY member count on this dashboard joins wp_users, and that is not defensive noise.
		//
		// Nothing cleaned up after a deleted member before 1.6.4, so these tables are full of rows
		// belonging to people who no longer exist -- 11,378 orphaned streak rows against 152 real ones
		// on the dev site. Counting those rows against a denominator of LIVE members is what produced
		// "6822.5% streak health" and "125.8% of active members" on a screen an owner is supposed to
		// make decisions from. The purge (MemberData) stops new orphans; this makes the dashboard
		// truthful on the sites that already have them, today, without deleting anything.
		$active_members = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.user_id)
				   FROM {$wpdb->prefix}wb_gam_points p
				   JOIN {$wpdb->users} u ON u.ID = p.user_id
				  WHERE p.created_at >= %s",
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

		// Badge earners, as a share of active members.
		//
		// The label says "% of active members", so the numerator has to BE a subset of active members
		// -- otherwise the figure is not a percentage of anything. It was counting every member who
		// earned a badge in the window (including ghosts, and including members who earned a tenure
		// badge without being active at all) over a denominator of active members, so it could sail
		// past 100% and did: 125.8%.
		$badge_earners    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT b.user_id)
				   FROM {$wpdb->prefix}wb_gam_user_badges b
				   JOIN {$wpdb->users} u ON u.ID = b.user_id
				  WHERE b.earned_at >= %s
				    AND EXISTS (
				        SELECT 1 FROM {$wpdb->prefix}wb_gam_points p
				         WHERE p.user_id = b.user_id AND p.created_at >= %s
				    )",
				$since,
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
		$challenges_started       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_challenge_log WHERE created_at >= %s",
				$since
			)
		);
		$challenge_completion_pct = $challenges_started > 0
			? round( ( $challenges_completed / $challenges_started ) * 100, 1 )
			: 0;

		// Active streaks (current_streak > 0), counting only members who still exist.
		//
		// This is the one that printed 6822.5%: 11,530 streak rows over 169 live members, because
		// 11,378 of those rows belonged to members who had been deleted.
		$active_streaks    = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT s.user_id)
			   FROM {$wpdb->prefix}wb_gam_streaks s
			   JOIN {$wpdb->users} u ON u.ID = s.user_id
			  WHERE s.current_streak > 0"
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
		) ?: array();

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
		) ?: array();

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
		) ?: array();

		$daily_points = array();
		foreach ( $daily_rows as $row ) {
			$daily_points[ $row['day'] ] = (int) $row['pts'];
		}

		$data = compact(
			'points_total',
			'active_members',
			'total_members',
			'badges_earned',
			'badge_earner_pct',
			'challenges_completed',
			'challenge_completion_pct',
			'active_streaks',
			'streak_health_pct',
			'kudos_given',
			'top_actions',
			'top_earners',
			'daily_points'
		);

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	// ── Render helpers ───────────────────────────────────────────────────────────

	/**
	 * Render a single KPI card widget.
	 *
	 * @param string $title Card heading.
	 * @param string $value Formatted primary metric value.
	 * @param string $sub   Sub-label / contextual description.
	 * @param string $icon  Emoji or icon character for the card.
	 * @return void
	 */
	public static function kpi_card( string $title, string $value, string $sub, string $icon ): void {
		?>
		<div class="wb-gam-analytics__kpi-card wb-gam-admin-kpi-card">
			<span class="wb-gam-analytics__kpi-icon wb-gam-admin-kpi-icon <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="wb-gam-analytics__kpi-value wb-gam-admin-kpi-value"><?php echo esc_html( $value ); ?></span>
			<span class="wb-gam-analytics__kpi-title wb-gam-admin-kpi-title"><?php echo esc_html( $title ); ?></span>
			<span class="wb-gam-analytics__kpi-sub wb-gam-admin-kpi-sub"><?php echo esc_html( $sub ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render an inline SVG-style bar sparkline for daily points data.
	 *
	 * @param array<string, int> $daily_points Map of Y-m-d date strings to point totals.
	 * @param int                $period       Number of days the sparkline covers.
	 * @return void
	 */
	/**
	 * Return a human-readable label for action IDs not in the Registry
	 * (e.g., BuddyPress integration IDs loaded via manifests).
	 *
	 * @param string $action_id Raw action ID from the points ledger.
	 * @return string           Human-readable label, or the raw ID if unknown.
	 */
	private static function fallback_action_label( string $action_id ): string {
		static $map = array(
			'bp_activity_update'       => 'Posted activity update',
			'bp_activity_comment'      => 'Commented on activity',
			'bp_friends_accepted'      => 'Made a new friend',
			'bp_receive_kudos'         => 'Received kudos',
			'bp_reactions_received'    => 'Received a reaction',
			'bp_groups_join'           => 'Joined a group',
			'bp_groups_create'         => 'Created a group',
			'bp_profile_photo_updated' => 'Updated profile photo',
			'wp_publish_post'          => 'Published a post',
			'wp_comment_approved'      => 'Comment approved',
			'wp_login'                 => 'Logged in',
			'wc_order_completed'       => 'Completed an order',
			'ld_course_completed'      => 'Completed a course',
			'ld_lesson_completed'      => 'Completed a lesson',
			'ld_quiz_completed'        => 'Passed a quiz',
			'manual_award'             => 'Admin manual award',
			'manual_admin'             => 'Admin manual award (legacy)',
			'manual_admin_deduct'      => 'Admin manual deduction',
		);
		return $map[ $action_id ] ?? $action_id;
	}

	/**
	 * Render two intelligence panels — high churn-risk + anomaly flagged.
	 *
	 * Surfaces the wb_gam_user_intelligence projection that
	 * IntelligenceProjector populates on the daily cron. Without these
	 * panels the projection is invisible to admins — useful for
	 * scripts and the SDK, but hidden from the people who'd actually
	 * use the data (community managers, growth team).
	 *
	 * Read-only listing. Sorts by churn_risk DESC for the risk panel,
	 * by events_30d DESC for the anomaly panel. Caps at 25 rows per
	 * panel — power users with a big install can build their own
	 * reporting on top of the REST endpoint.
	 */
	private static function render_intelligence_panels(): void {
		if ( ! get_option( 'wb_gam_feature_user_intelligence_v1' ) ) {
			return; // Projection table not migrated yet — silent skip.
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$at_risk = $wpdb->get_results(
			"SELECT i.user_id, i.churn_risk, i.engagement_score, i.recency_days, i.events_30d, u.display_name, u.user_login
			   FROM {$wpdb->prefix}wb_gam_user_intelligence i
			   JOIN {$wpdb->users} u ON u.ID = i.user_id
			  WHERE i.churn_risk >= 0.7
			  ORDER BY i.churn_risk DESC
			  LIMIT 25",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$anomalies = $wpdb->get_results(
			"SELECT i.user_id, i.events_30d, i.action_diversity, i.engagement_score, u.display_name, u.user_login
			   FROM {$wpdb->prefix}wb_gam_user_intelligence i
			   JOIN {$wpdb->users} u ON u.ID = i.user_id
			  WHERE i.anomaly_flag = 1
			  ORDER BY i.events_30d DESC
			  LIMIT 25",
			ARRAY_A
		);

		$last_computed = (string) $wpdb->get_var(
			"SELECT computed_at FROM {$wpdb->prefix}wb_gam_user_intelligence ORDER BY computed_at DESC LIMIT 1"
		);
		?>
		<div class="wb-gam-analytics__two-col wbgam-mt-md">
			<div class="wb-gam-analytics__panel">
				<h2 class="wbgam-flex-row">
					<span class="icon-trending-down" aria-hidden="true"></span>
					<?php esc_html_e( 'Members at churn risk', 'wb-gamification' ); ?>
				</h2>
				<p class="description">
					<?php esc_html_e( 'Members whose engagement score has fallen far enough that they\'re likely to drift away. Re-engage with a personalised nudge.', 'wb-gamification' ); ?>
				</p>
				<?php if ( empty( $at_risk ) ) : ?>
					<p class="description"><?php esc_html_e( 'No members above the high-risk threshold (0.7). Either your community is exceptionally engaged or the projection cron hasn\'t finished its first pass - check back tomorrow.', 'wb-gamification' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Risk', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Last seen', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( '30d events', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $at_risk as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $row['display_name'] ?: $row['user_login'] ); ?></strong></td>
									<td><?php echo esc_html( number_format( (float) $row['churn_risk'], 2 ) ); ?></td>
									<td>
										<?php
										$days = (int) $row['recency_days'];
										echo esc_html(
											$days >= 999
												? __( 'never', 'wb-gamification' )
												: sprintf(
													/* translators: %d: days */
													_n( '%d day ago', '%d days ago', $days, 'wb-gamification' ),
													$days
												)
										);
										?>
									</td>
									<td><?php echo (int) $row['events_30d']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="wb-gam-analytics__panel">
				<h2 class="wbgam-flex-row">
					<span class="icon-triangle-alert" aria-hidden="true"></span>
					<?php esc_html_e( 'Possible gaming activity', 'wb-gamification' ); ?>
				</h2>
				<p class="description">
					<?php esc_html_e( 'High event volume with very low action diversity - the "bot grinding one action" pattern. Review before assuming abuse: some legitimate members really do only do one thing.', 'wb-gamification' ); ?>
				</p>
				<?php if ( empty( $anomalies ) ) : ?>
					<p class="description"><?php esc_html_e( 'No members flagged. Anomaly detection requires >500 events in 30 days AND <3 distinct actions; communities below those volumes simply won\'t trip the heuristic.', 'wb-gamification' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( '30d events', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions used', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $anomalies as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $row['display_name'] ?: $row['user_login'] ); ?></strong></td>
									<td><?php echo (int) $row['events_30d']; ?></td>
									<td><?php echo (int) $row['action_diversity']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! empty( $last_computed ) ) : ?>
			<p class="description wbgam-mt-sm">
				<?php
				printf(
					/* translators: %s: timestamp */
					esc_html__( 'Intelligence signals last computed: %s. Cron runs daily; force a refresh per-user via the REST endpoint or `wp eval` if you need fresher data.', 'wb-gamification' ),
					'<code>' . esc_html( $last_computed ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the "unknown action_ids" diagnostic panel.
	 *
	 * Surfaces events that called `Engine::process()` with an action_id
	 * that is not in the Registry — the silent failure class Engine
	 * v1.5.0 started logging via `wb_gam_unknown_action`. The dashboard
	 * is the production-facing surface; `debug.log` only works in dev.
	 *
	 * The buffer is a 24-hour transient (see {@see \WBGam\Engine\Engine}
	 * UNKNOWN_ACTIONS_TRANSIENT), so it self-clears between runs and
	 * doesn't grow unbounded. Empty buffer renders nothing — no panel,
	 * no noise — so healthy installs see a clean dashboard.
	 */
	private static function render_unknown_actions_panel(): void {
		$buffer = \WBGam\Engine\Engine::get_unknown_actions_recent();
		if ( empty( $buffer ) ) {
			return;
		}
		?>
		<div class="wb-gam-analytics__panel wbgam-mt-md">
			<h2 class="wbgam-flex-row">
				<span class="icon-triangle-alert" aria-hidden="true"></span>
				<?php esc_html_e( 'Unknown action IDs fired (last 24h)', 'wb-gamification' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'These events were rejected because the action_id was not registered with the engine. Usually means a typo in custom code, a deactivated integration plugin, or a manifest that did not load. Each row aggregates all firings of that ID.', 'wb-gamification' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Action ID', 'wb-gamification' ); ?></th>
						<th><?php esc_html_e( 'Events', 'wb-gamification' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'wb-gamification' ); ?></th>
						<th><?php esc_html_e( 'Did you mean?', 'wb-gamification' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $buffer as $action_id => $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $action_id ); ?></code></td>
							<td><?php echo (int) ( $row['count'] ?? 0 ); ?></td>
							<td>
								<?php
								$seen = (int) ( $row['last_seen'] ?? 0 );
								echo esc_html(
									$seen > 0
										? sprintf(
											/* translators: %s: human-readable time difference. */
											__( '%s ago', 'wb-gamification' ),
											human_time_diff( $seen )
										)
										: __( 'unknown', 'wb-gamification' )
								);
								?>
							</td>
							<td>
								<?php
								$suggestions = isset( $row['suggestions'] ) && is_array( $row['suggestions'] )
									? $row['suggestions']
									: array();
								if ( empty( $suggestions ) ) {
									echo '<span class="description">' . esc_html__( 'No close match in registered actions.', 'wb-gamification' ) . '</span>';
								} else {
									$mapped = array_map(
										static fn( $s ) => '<code>' . esc_html( (string) $s ) . '</code>',
										$suggestions
									);
									echo wp_kses( implode( ', ', $mapped ), array( 'code' => array() ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description wbgam-mt-sm">
				<?php esc_html_e( 'Rows auto-expire after 24 hours. If a suggestion looks right, update the calling code to use that exact ID. If there is no suggestion, the owning integration plugin is probably not active or its manifest did not load.', 'wb-gamification' ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_sparkline( array $daily_points, int $period ): void {
		if ( empty( $daily_points ) ) {
			echo '<p class="description">' . esc_html__( 'No data yet.', 'wb-gamification' ) . '</p>';
			return;
		}

		// Fill gaps so every day in range has a value.
		$end_ts   = time();
		$start_ts = strtotime( "-{$period} days", $end_ts );
		$filled   = array();
		for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
			$day            = gmdate( 'Y-m-d', $ts );
			$filled[ $day ] = $daily_points[ $day ] ?? 0;
		}

		$max = max( $filled ) ?: 1;
		$w   = 100 / count( $filled ); // % width per bar

		echo '<div class="wb-gam-analytics__sparkline" aria-hidden="true">';
		foreach ( $filled as $day => $pts ) {
			$h         = (int) round( ( $pts / $max ) * 100 );
			$bar_title = esc_attr( $day . ': ' . number_format_i18n( $pts ) . ' pts' );
			$bar_width = esc_attr( number_format( $w, 4 ) );
			echo '<div class="wb-gam-analytics__spark-bar" style="--bar-h:' . $h . '%;--bar-w:' . $bar_width . '%" title="' . $bar_title . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $h is int, $bar_width and $bar_title are esc_attr()-escaped.
		}
		echo '</div>';
	}
}
