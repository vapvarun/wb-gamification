<?php
/**
 * Streak block — Wbcom Block Quality Standard render.
 *
 * Phase D.1 migration: per-instance scoped CSS via
 * `WBGam\Blocks\CSS::add()`, design tokens through `wb-gam-tokens`,
 * `.wb-gam-block-{uniqueId}` wrapper for spacing / visibility / shadow.
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
use WBGam\Engine\Privacy;
use WBGam\Engine\StreakEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}

// Privacy gate. T1 fields (current/longest streak) need a public profile.
// T2 field (heatmap — daily activity timeline) needs self/admin only.
// See plan/PRIVACY-MODEL.md.
$wb_gam_can_t1 = $wb_gam_user_id > 0 && Privacy::can_view_public_profile( $wb_gam_user_id );
$wb_gam_can_t2 = $wb_gam_user_id > 0 && Privacy::can_view_private_history( $wb_gam_user_id );
if ( ! $wb_gam_can_t1 ) {
	$wb_gam_user_id = 0;
}

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-streak',
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

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

wp_enqueue_style( 'wb-gam-tokens' );

if ( ! $wb_gam_user_id ) {
	$wb_gam_classes[] = 'wb-gam-streak--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'streak', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-streak__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your streak.', 'wb-gamification' )
	);
	BlockHooks::after( 'streak', $wb_gam_attrs );
	return;
}

$wb_gam_show_longest = ! empty( $wb_gam_attrs['show_longest'] );
$wb_gam_show_heatmap = ! empty( $wb_gam_attrs['show_heatmap'] ) && $wb_gam_can_t2;
$wb_gam_heatmap_days = max( 1, min( 365, (int) ( $wb_gam_attrs['heatmap_days'] ?? 90 ) ) );

$wb_gam_streak  = StreakEngine::get_streak( $wb_gam_user_id );
$wb_gam_heatmap = $wb_gam_show_heatmap ? StreakEngine::get_contribution_data( $wb_gam_user_id, $wb_gam_heatmap_days ) : array();

/**
 * Filter the streak block data before render.
 *
 * @since 1.0.0
 *
 * @param array $data       ['streak', 'heatmap'].
 * @param array $attributes Block attributes (show_heatmap, heatmap_days).
 * @param int   $user_id    Member whose streak is rendered.
 */
$wb_gam_block_data = (array) apply_filters(
	'wb_gam_block_streak_data',
	array(
		'streak'  => $wb_gam_streak,
		'heatmap' => $wb_gam_heatmap,
	),
	$wb_gam_attrs,
	$wb_gam_user_id
);
$wb_gam_streak  = $wb_gam_block_data['streak'] ?? $wb_gam_streak;
$wb_gam_heatmap = (array) ( $wb_gam_block_data['heatmap'] ?? $wb_gam_heatmap );

$wb_gam_current = (int) ( $wb_gam_streak['current_streak'] ?? 0 );
$wb_gam_longest = (int) ( $wb_gam_streak['longest_streak'] ?? 0 );

$wb_gam_milestones = array( 7, 14, 30, 60, 100, 180, 365 );
$wb_gam_next_mile  = null;
foreach ( $wb_gam_milestones as $wb_gam_ms ) {
	if ( $wb_gam_current < $wb_gam_ms ) {
		$wb_gam_next_mile = $wb_gam_ms;
		break;
	}
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'streak', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-streak__stats">
		<div class="wb-gam-streak__stat wb-gam-streak__stat--current">
			<span class="wb-gam-streak__flame" aria-hidden="true">&#x1F525;</span>
			<span class="wb-gam-streak__number"><?php echo esc_html( number_format_i18n( $wb_gam_current ) ); ?></span>
			<span class="wb-gam-streak__label"><?php esc_html_e( 'Day streak', 'wb-gamification' ); ?></span>
		</div>

		<?php if ( $wb_gam_show_longest ) : ?>
			<div class="wb-gam-streak__stat wb-gam-streak__stat--longest">
				<span class="wb-gam-streak__number wb-gam-streak__number--longest"><?php echo esc_html( number_format_i18n( $wb_gam_longest ) ); ?></span>
				<span class="wb-gam-streak__label"><?php esc_html_e( 'Best streak', 'wb-gamification' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $wb_gam_next_mile ) :
		// Progress bar percent — kept inside [0,100] so a 0-day current
		// streak still shows a flat track and a partial week reads as a
		// proportional fill. Added in 1.4.0 (Basecamp 9919406312) so
		// members get a visual cue, not just a sentence, of how close
		// they are to the next milestone.
		$wb_gam_progress_pct = (int) round( max( 0, min( 100, ( $wb_gam_current / $wb_gam_next_mile ) * 100 ) ) );
		?>
		<div class="wb-gam-streak__progress" role="progressbar"
			aria-valuenow="<?php echo esc_attr( (string) $wb_gam_current ); ?>"
			aria-valuemin="0"
			aria-valuemax="<?php echo esc_attr( (string) $wb_gam_next_mile ); ?>"
			aria-label="<?php
				/* translators: 1 = current streak, 2 = milestone target */
				echo esc_attr( sprintf( __( 'Streak progress: %1$d of %2$d days', 'wb-gamification' ), $wb_gam_current, $wb_gam_next_mile ) );
			?>">
			<div class="wb-gam-streak__progress-track">
				<div class="wb-gam-streak__progress-fill" style="width: <?php echo esc_attr( (string) $wb_gam_progress_pct ); ?>%;"></div>
			</div>
			<div class="wb-gam-streak__progress-meta">
				<span class="wb-gam-streak__progress-current">
					<?php
					/* translators: 1 = current streak, 2 = milestone target */
					printf( esc_html__( '%1$d / %2$d days', 'wb-gamification' ), (int) $wb_gam_current, (int) $wb_gam_next_mile );
					?>
				</span>
				<span class="wb-gam-streak__progress-nudge">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1 = days remaining, 2 = milestone target */
							_n(
								'%1$d more day to reach the %2$d-day milestone!',
								'%1$d more days to reach the %2$d-day milestone!',
								$wb_gam_next_mile - $wb_gam_current,
								'wb-gamification'
							),
							$wb_gam_next_mile - $wb_gam_current,
							$wb_gam_next_mile
						)
					);
					?>
				</span>
			</div>
		</div>
	<?php else : ?>
		<p class="wb-gam-streak__nudge wb-gam-streak__nudge--elite">
			<?php esc_html_e( 'Amazing — you have hit every milestone! Keep it up!', 'wb-gamification' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $wb_gam_show_heatmap && ! empty( $wb_gam_heatmap ) ) : ?>
		<div class="wb-gam-streak__heatmap" aria-label="<?php esc_attr_e( 'Contribution heatmap', 'wb-gamification' ); ?>">
			<?php
			$wb_gam_end_ts   = (int) current_time( 'timestamp' );
			$wb_gam_start_ts = (int) strtotime( "-{$wb_gam_heatmap_days} days", $wb_gam_end_ts );
			$wb_gam_max_pts  = max( $wb_gam_heatmap );

			for ( $wb_gam_ts = $wb_gam_start_ts; $wb_gam_ts <= $wb_gam_end_ts; $wb_gam_ts += DAY_IN_SECONDS ) {
				$wb_gam_date  = gmdate( 'Y-m-d', $wb_gam_ts );
				$wb_gam_pts   = (int) ( $wb_gam_heatmap[ $wb_gam_date ] ?? 0 );
				$wb_gam_level = $wb_gam_pts > 0 ? (int) ceil( ( $wb_gam_pts / $wb_gam_max_pts ) * 4 ) : 0;

				$wb_gam_formatted_date = date_i18n( 'M j', $wb_gam_ts );
				$wb_gam_title          = $wb_gam_pts > 0
					? sprintf( /* translators: 1 = formatted date, 2 = points */ __( '%1$s: %2$s points', 'wb-gamification' ), $wb_gam_formatted_date, number_format_i18n( $wb_gam_pts ) )
					: sprintf( /* translators: %s = formatted date */ __( '%s: No activity', 'wb-gamification' ), $wb_gam_formatted_date );

				printf(
					'<span class="wb-gam-streak__cell wb-gam-streak__cell--%d" title="%s" aria-label="%s"></span>',
					(int) $wb_gam_level,
					esc_attr( $wb_gam_title ),
					esc_attr( $wb_gam_title )
				);
			}
			?>
		</div>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'streak', $wb_gam_attrs );
