<?php
/**
 * Points History block — Wbcom Block Quality Standard render.
 *
 * 1.4.0 UX refactor (Basecamp UX feedback 2026-05-26):
 *   - rows grouped under "Today" / "Yesterday" / "May 25, 2026" headers
 *     so 9 awards on the same day stop looking identical
 *   - each row shows a relative time ("3 minutes ago", "2 hours ago")
 *     instead of a bare date that can't tell two same-day rows apart
 *   - daily totals printed in the group header so admins can audit a
 *     day's earning at a glance
 *   - manual-award message persists as a 2nd line below the action so
 *     "+5 Points — Reason: contest winner" reads meaningfully
 *   - empty state has a real call-to-action, not a one-liner
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
//   - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
//     established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
//     Plugin Check auto-detects `wb_gamification` from the text-domain header
//     and doesn't share the .phpcs.xml prefix list; hooks like
//     `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
//   - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
//     functions exported under `wb_gam_*` are documented in `src/Extensions/`.
//   - PluginCheck.Security.DirectDB.UnescapedDBParameter +
//     WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
//     table work. Table names are interpolated from `{$wpdb->prefix}` plus
//     literal constants (no user input); user-supplied values pass through
//     `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
//     interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

// Block render.php files are invoked from inside render_callback by the
// WP block registrar, so every $wb_gam_* in this file is function-scoped,
// not global. PrefixAllGlobals can't tell — its `phpcs:disable` here is
// the WP-standard way to silence the false positive. The plugin's own
// .phpcs.xml already declares `wb_gam` as a valid prefix; this annotation
// extends that signal to Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound


use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Privacy;
use WBGam\Engine\Registry;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}

if ( $wb_gam_user_id > 0 && ! Privacy::can_view_private_history( $wb_gam_user_id ) ) {
	$wb_gam_user_id = 0;
}

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_inline = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-accent: %s;', sanitize_text_field( (string) $wb_gam_attrs['accentColor'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-white: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBorderColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-border: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBorderColor'] ) );
}

$wb_gam_classes = array_filter( array( 'wb-gam-points-history', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_classes[] = 'wb-gam-points-history--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'points-history', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-points-history__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your points history.', 'wb-gamification' )
	);
	BlockHooks::after( 'points-history', $wb_gam_attrs );
	return;
}

$wb_gam_limit      = max( 1, min( 100, (int) ( $wb_gam_attrs['limit'] ?? 20 ) ) );
$wb_gam_show_label = ! empty( $wb_gam_attrs['show_action_label'] );

$wb_gam_point_type = (string) ( $wb_gam_attrs['pointType'] ?? '' );
$wb_gam_rows       = PointsEngine::get_history( $wb_gam_user_id, $wb_gam_limit, $wb_gam_point_type ?: null );

/** This filter is documented in src/Blocks/points-history/render.php (legacy). */
$wb_gam_rows = (array) apply_filters( 'wb_gam_block_points_history_data', $wb_gam_rows, $wb_gam_attrs, $wb_gam_user_id );

// Pre-fetch currency label map once (no N+1).
$wb_gam_pt_service = new \WBGam\Services\PointTypeService();
$wb_gam_label_map  = array();
foreach ( $wb_gam_pt_service->list() as $wb_gam_pt ) {
	$wb_gam_label_map[ (string) $wb_gam_pt['slug'] ] = (string) $wb_gam_pt['label'];
}

// Group rows by local-day so we can render "Today / Yesterday / Date"
// headers + a daily total. Keys are YYYY-MM-DD in the site's timezone.
$wb_gam_tz       = wp_timezone();
$wb_gam_today    = ( new DateTimeImmutable( 'now', $wb_gam_tz ) )->format( 'Y-m-d' );
$wb_gam_yest     = ( new DateTimeImmutable( '-1 day', $wb_gam_tz ) )->format( 'Y-m-d' );
$wb_gam_grouped  = array();
foreach ( $wb_gam_rows as $wb_gam_row ) {
	$wb_gam_ts = strtotime( (string) ( $wb_gam_row['created_at'] ?? '' ) );
	if ( ! $wb_gam_ts ) {
		$wb_gam_ts = time();
	}
	$wb_gam_local = ( new DateTimeImmutable( '@' . $wb_gam_ts ) )->setTimezone( $wb_gam_tz );
	$wb_gam_day   = $wb_gam_local->format( 'Y-m-d' );
	if ( ! isset( $wb_gam_grouped[ $wb_gam_day ] ) ) {
		$wb_gam_grouped[ $wb_gam_day ] = array(
			'rows'     => array(),
			'totals'   => array(),
			'datetime' => $wb_gam_local,
		);
	}
	$wb_gam_grouped[ $wb_gam_day ]['rows'][] = $wb_gam_row + array( '_ts' => $wb_gam_ts );
	$wb_gam_slug = (string) ( $wb_gam_row['point_type'] ?? 'points' );
	$wb_gam_grouped[ $wb_gam_day ]['totals'][ $wb_gam_slug ] = ( $wb_gam_grouped[ $wb_gam_day ]['totals'][ $wb_gam_slug ] ?? 0 ) + (int) ( $wb_gam_row['points'] ?? 0 );
}

/**
 * Format a day key into the displayed header label.
 *
 * @param string             $day_key YYYY-MM-DD.
 * @param DateTimeImmutable  $dt      Sample timestamp inside the day (for date_i18n).
 * @return string
 */
$wb_gam_day_label = static function ( string $day_key, DateTimeImmutable $dt ) use ( $wb_gam_today, $wb_gam_yest ): string {
	if ( $day_key === $wb_gam_today ) {
		return __( 'Today', 'wb-gamification' );
	}
	if ( $day_key === $wb_gam_yest ) {
		return __( 'Yesterday', 'wb-gamification' );
	}
	$age_days = (int) ( ( time() - $dt->getTimestamp() ) / DAY_IN_SECONDS );
	if ( $age_days < 7 ) {
		// Show weekday + date for recent: "Sat, May 24".
		return date_i18n( __( 'l, M j', 'wb-gamification' ), $dt->getTimestamp() );
	}
	return date_i18n( get_option( 'date_format' ) ?: 'M j, Y', $dt->getTimestamp() );
};

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'points-history', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $wb_gam_rows ) ) : ?>
		<div class="wb-gam-points-history__empty">
			<?php echo \WBGam\Admin\Icon::svg( 'sparkles', array( 'size' => 28, 'class' => 'wb-gam-points-history__empty-icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'No point activity yet — earn your first points by participating in the community.', 'wb-gamification' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $wb_gam_grouped as $wb_gam_day => $wb_gam_group ) :
			$wb_gam_total_parts = array();
			foreach ( $wb_gam_group['totals'] as $wb_gam_slug => $wb_gam_sum ) {
				$wb_gam_total_parts[] = sprintf(
					'%s %s',
					( $wb_gam_sum >= 0 ? '+' : '' ) . number_format_i18n( $wb_gam_sum ),
					$wb_gam_label_map[ $wb_gam_slug ] ?? __( 'pts', 'wb-gamification' )
				);
			}
			$wb_gam_total_line = implode( ' · ', $wb_gam_total_parts );
			?>
			<section class="wb-gam-points-history__group" aria-label="<?php echo esc_attr( $wb_gam_day_label( $wb_gam_day, $wb_gam_group['datetime'] ) ); ?>">
				<header class="wb-gam-points-history__group-head">
					<h4 class="wb-gam-points-history__group-title">
						<?php echo esc_html( $wb_gam_day_label( $wb_gam_day, $wb_gam_group['datetime'] ) ); ?>
					</h4>
					<span class="wb-gam-points-history__group-total"><?php echo esc_html( $wb_gam_total_line ); ?></span>
				</header>
				<ul class="wb-gam-points-history__list" role="list">
					<?php foreach ( $wb_gam_group['rows'] as $wb_gam_row ) :
						$wb_gam_pts          = (int) ( $wb_gam_row['points'] ?? 0 );
						$wb_gam_pos_neg      = $wb_gam_pts >= 0 ? 'positive' : 'negative';
						$wb_gam_row_type     = (string) ( $wb_gam_row['point_type'] ?? '' );
						$wb_gam_row_label    = $wb_gam_label_map[ $wb_gam_row_type ] ?? __( 'pts', 'wb-gamification' );
						$wb_gam_row_action   = (string) ( $wb_gam_row['action_id'] ?? '' );
						$wb_gam_action_label = Registry::label_for( $wb_gam_row_action );
						$wb_gam_message      = (string) ( $wb_gam_row['message'] ?? '' );
						$wb_gam_time_label   = human_time_diff( (int) $wb_gam_row['_ts'], time() );
						?>
						<li class="wb-gam-points-history__item wb-gam-points-history__item--<?php echo esc_attr( $wb_gam_pos_neg ); ?>">
							<span class="wb-gam-points-history__icon" aria-hidden="true">
								<?php
								$wb_gam_icon = $wb_gam_pts >= 0 ? 'sparkles' : 'arrow-right';
								echo \WBGam\Admin\Icon::svg( $wb_gam_icon, array( 'size' => 18 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</span>

							<div class="wb-gam-points-history__body">
								<?php if ( $wb_gam_show_label ) : ?>
									<span class="wb-gam-points-history__action" title="<?php echo esc_attr( $wb_gam_row_action ); ?>">
										<?php echo esc_html( $wb_gam_action_label ); ?>
									</span>
								<?php endif; ?>
								<?php if ( '' !== $wb_gam_message ) : ?>
									<span class="wb-gam-points-history__message"><?php echo esc_html( $wb_gam_message ); ?></span>
								<?php endif; ?>
								<time class="wb-gam-points-history__time" datetime="<?php echo esc_attr( (string) ( $wb_gam_row['created_at'] ?? '' ) ); ?>">
									<?php
									/* translators: %s: human time difference (e.g. "5 minutes") */
									printf( esc_html__( '%s ago', 'wb-gamification' ), esc_html( $wb_gam_time_label ) );
									?>
									<?php
									// Append an exact time on hover/long-press via title attr.
									$wb_gam_exact = date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										(int) $wb_gam_row['_ts']
									);
									?>
									<span class="screen-reader-text"><?php echo esc_html( ' (' . $wb_gam_exact . ')' ); ?></span>
								</time>
							</div>

							<span class="wb-gam-points-history__points">
								<?php
								printf(
									/* translators: 1: signed integer with sign, 2: currency label. */
									esc_html__( '%1$s %2$s', 'wb-gamification' ),
									esc_html( ( $wb_gam_pts >= 0 ? '+' : '' ) . number_format_i18n( $wb_gam_pts ) ),
									esc_html( $wb_gam_row_label )
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'points-history', $wb_gam_attrs );
