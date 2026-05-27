<?php
/**
 * Community Challenges block — Wbcom Block Quality Standard render.
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
use WBGam\Engine\CommunityChallengeEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_limit    = (int) ( $wb_gam_attrs['limit'] ?? 0 );
$wb_gam_show_bar = ! empty( $wb_gam_attrs['show_progress_bar'] );

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

$wb_gam_classes = array_filter( array( 'wb-gam-community-challenges', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

// get_visible() returns active challenges PLUS completed-but-not-expired
// ones so a challenge that just hit its target stays listed (with a
// "Completed!" badge) until its end date passes — gives members the full
// celebration window. Pre-fix used get_active() which dropped completed
// challenges immediately, leaving members with "no active challenges"
// the second they hit the goal. Closes Basecamp #9932994598.
$wb_gam_challenges = CommunityChallengeEngine::get_visible();
if ( $wb_gam_limit > 0 ) {
	$wb_gam_challenges = array_slice( $wb_gam_challenges, 0, $wb_gam_limit );
}

/**
 * Filter the community-challenges block list before render.
 *
 * @since 1.0.0
 *
 * @param array $challenges Active community challenges.
 * @param array $attributes Block attributes (limit).
 */
$wb_gam_challenges = (array) apply_filters( 'wb_gam_block_community_challenges_data', $wb_gam_challenges, $wb_gam_attrs );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'community-challenges', $wb_gam_attrs, array( 'count' => count( $wb_gam_challenges ) ) );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-community-challenges__header">
		<h3 class="wb-gam-community-challenges__title">
			<?php esc_html_e( 'Community Challenges', 'wb-gamification' ); ?>
		</h3>
	</div>

	<?php if ( empty( $wb_gam_challenges ) ) : ?>
		<p class="wb-gam-community-challenges__empty">
			<?php esc_html_e( 'No active community challenges right now. Check back soon!', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-community-challenges__list" role="list">
			<?php foreach ( $wb_gam_challenges as $wb_gam_challenge ) :
				$wb_gam_progress      = (int) ( $wb_gam_challenge['global_progress'] ?? 0 );
				$wb_gam_target        = max( 1, (int) ( $wb_gam_challenge['target_count'] ?? 1 ) );
				$wb_gam_pct           = min( 100, (int) round( ( $wb_gam_progress / $wb_gam_target ) * 100 ) );
				$wb_gam_bonus         = (int) ( $wb_gam_challenge['bonus_points'] ?? 0 );
				$wb_gam_ends_ts       = (int) strtotime( (string) ( $wb_gam_challenge['ends_at'] ?? 'now' ) );
				$wb_gam_status        = (string) ( $wb_gam_challenge['status'] ?? 'active' );
				$wb_gam_is_completed  = ( 'completed' === $wb_gam_status );
				$wb_gam_time_left     = $wb_gam_ends_ts > time()
					? human_time_diff( time(), $wb_gam_ends_ts )
					: __( 'ended', 'wb-gamification' );
				$wb_gam_item_classes  = 'wb-gam-community-challenges__item';
				if ( $wb_gam_is_completed ) {
					$wb_gam_item_classes .= ' wb-gam-community-challenges__item--completed';
				}
				?>
				<li class="<?php echo esc_attr( $wb_gam_item_classes ); ?>"<?php echo $wb_gam_is_completed ? ' data-status="completed"' : ''; ?>>
					<div class="wb-gam-community-challenges__row">
						<span class="wb-gam-community-challenges__challenge-title">
							<?php echo esc_html( (string) ( $wb_gam_challenge['title'] ?? '' ) ); ?>
							<?php if ( $wb_gam_is_completed ) : ?>
								<span class="wb-gam-community-challenges__status-badge wb-gam-community-challenges__status-badge--completed">
									<span class="icon-circle-check" aria-hidden="true"></span>
									<?php esc_html_e( 'Completed', 'wb-gamification' ); ?>
								</span>
							<?php endif; ?>
						</span>
						<span class="wb-gam-community-challenges__bonus">
							<?php
							/* translators: %d = bonus points awarded on completion */
							printf( esc_html__( '+%d pts', 'wb-gamification' ), (int) $wb_gam_bonus );
							?>
						</span>
					</div>

					<?php if ( $wb_gam_show_bar ) : ?>
						<div class="wb-gam-community-challenges__progress" role="progressbar"
							aria-valuemin="0"
							aria-valuemax="<?php echo esc_attr( (string) $wb_gam_target ); ?>"
							aria-valuenow="<?php echo esc_attr( (string) $wb_gam_progress ); ?>">
							<div class="wb-gam-community-challenges__progress-bar"
								style="width:<?php echo esc_attr( $wb_gam_is_completed ? '100' : (string) $wb_gam_pct ); ?>%"></div>
						</div>
					<?php endif; ?>

					<div class="wb-gam-community-challenges__meta">
						<span class="wb-gam-community-challenges__count">
							<?php
							/* translators: 1 = current progress, 2 = target count */
							printf( esc_html__( '%1$s / %2$s', 'wb-gamification' ),
								esc_html( number_format_i18n( $wb_gam_progress ) ),
								esc_html( number_format_i18n( $wb_gam_target ) )
							);
							?>
						</span>
						<span class="wb-gam-community-challenges__time-left">
							<?php
							if ( $wb_gam_is_completed ) {
								/* translators: %s = human-readable time until the window closes (members keep seeing the win until then). */
								printf( esc_html__( 'Celebrating for %s', 'wb-gamification' ), esc_html( (string) $wb_gam_time_left ) );
							} else {
								/* translators: %s = human-readable time remaining */
								printf( esc_html__( '%s left', 'wb-gamification' ), esc_html( (string) $wb_gam_time_left ) );
							}
							?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'community-challenges', $wb_gam_attrs, array( 'count' => count( $wb_gam_challenges ) ) );
