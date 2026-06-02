<?php
/**
 * Badge Showcase block — Wbcom Block Quality Standard render.
 *
 * 1.4.0 UX refactor (Basecamp follow-up: hub flyout / "My Badges" panel):
 *   - earned badges bubble to the top with a celebratory check pip
 *   - locked badges are visibly dimmed + carry a padlock so a non-tech
 *     member can see "haven't earned yet" at a glance
 *   - header carries "N of M earned" with a real progress bar
 *   - segmented filter (All / Earned / Locked) toggles client-side
 *   - relative dates ("3 days ago") on earned within 30 days; absolute
 *     month-day on older
 *   - description shown inline (no more line-clamped tooltip-only text)
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
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Privacy;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id     = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
$wb_gam_show_locked = (bool) ( $wb_gam_attrs['show_locked'] ?? false );
$wb_gam_category    = sanitize_key( (string) ( $wb_gam_attrs['category'] ?? '' ) );
$wb_gam_limit       = (int) ( $wb_gam_attrs['limit'] ?? 0 );

if ( 0 === $wb_gam_user_id ) {
	$wb_gam_user_id = get_current_user_id();
}

// Privacy gate — see plan/PRIVACY-MODEL.md.
if ( $wb_gam_user_id > 0 && ! Privacy::can_view_public_profile( $wb_gam_user_id ) ) {
	$wb_gam_user_id = 0;
}

if ( $wb_gam_user_id <= 0 && ! $wb_gam_show_locked ) {
	return '';
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

$wb_gam_classes = array_filter( array( 'wb-gam-badge-showcase', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_badges = $wb_gam_show_locked
	? BadgeEngine::get_all_badges_for_user( $wb_gam_user_id )
	: array_map(
		static function ( array $b ): array {
			$b['earned'] = true;
			return $b;
		},
		BadgeEngine::get_user_badges( $wb_gam_user_id )
	);

if ( '' !== $wb_gam_category ) {
	$wb_gam_badges = array_values(
		array_filter( $wb_gam_badges, fn( $b ) => $b['category'] === $wb_gam_category )
	);
}

/**
 * Filter the badge-showcase block badges before render.
 *
 * @since 1.0.0
 *
 * @param array $badges     Badge rows including earned flag + earned_at.
 * @param array $attributes Block attributes.
 * @param int   $user_id    Member whose showcase is rendered.
 */
$wb_gam_badges = (array) apply_filters( 'wb_gam_block_badge_showcase_data', $wb_gam_badges, $wb_gam_attrs, $wb_gam_user_id );

// Sort earned first, then by earned_at desc; locked at end alphabetically.
usort(
	$wb_gam_badges,
	static function ( array $a, array $b ): int {
		$a_earned = ! empty( $a['earned'] );
		$b_earned = ! empty( $b['earned'] );
		if ( $a_earned !== $b_earned ) {
			return $a_earned ? -1 : 1;
		}
		if ( $a_earned ) {
			return strcmp( (string) ( $b['earned_at'] ?? '' ), (string) ( $a['earned_at'] ?? '' ) );
		}
		return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
	}
);

if ( $wb_gam_limit > 0 && count( $wb_gam_badges ) > $wb_gam_limit ) {
	$wb_gam_badges = array_slice( $wb_gam_badges, 0, $wb_gam_limit );
}

// Totals for the header chip + filter pill counts.
$wb_gam_total_count  = count( $wb_gam_badges );
$wb_gam_earned_count = count(
	array_filter(
		$wb_gam_badges,
		static fn( $b ) => ! empty( $b['earned'] )
	)
);
$wb_gam_locked_count = $wb_gam_total_count - $wb_gam_earned_count;
$wb_gam_progress_pct = $wb_gam_total_count > 0
	? (int) round( ( $wb_gam_earned_count / $wb_gam_total_count ) * 100 )
	: 0;

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

/**
 * Build a "3 days ago" / "May 10" date label for a given earned_at.
 *
 * Relative for the last 30 days (more meaningful to a member who's
 * actively earning), compact absolute for older. Falls back to
 * date_format option if both calculations fail.
 *
 * @param string $iso Earned_at timestamp (string from DB).
 * @return string
 */
$wb_gam_format_date = static function ( string $iso ): string {
	$ts = strtotime( $iso );
	if ( ! $ts ) {
		return '';
	}
	$age = time() - $ts;
	if ( $age >= 0 && $age < DAY_IN_SECONDS * 30 ) {
		/* translators: %s: human-readable time difference. */
		return sprintf( esc_html__( '%s ago', 'wb-gamification' ), human_time_diff( $ts ) );
	}
	return date_i18n( get_option( 'date_format' ) ?: 'M j, Y', $ts );
};

BlockHooks::before( 'badge-showcase', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wb-gam-badge-showcase
	data-filter="all">

	<?php if ( empty( $wb_gam_badges ) ) : ?>
		<p class="wb-gam-badge-showcase__empty">
			<?php echo \WBGam\Admin\Icon::svg( 'medal', array( 'size' => 28, 'class' => 'wb-gam-badge-showcase__empty-icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'No badges to show yet - keep going!', 'wb-gamification' ); ?></span>
		</p>
	<?php else : ?>
		<header class="wb-gam-badge-showcase__header">
			<div class="wb-gam-badge-showcase__counter">
				<span class="wb-gam-badge-showcase__count-num">
					<?php
					printf(
						/* translators: 1: current count, 2: total count. */
						esc_html__( '%1$d / %2$d', 'wb-gamification' ),
						(int) $wb_gam_earned_count,
						(int) $wb_gam_total_count
					);
					?>
				</span>
				<span class="wb-gam-badge-showcase__count-label"><?php esc_html_e( 'badges earned', 'wb-gamification' ); ?></span>
			</div>

			<div class="wb-gam-badge-showcase__progress" role="progressbar"
				aria-valuemin="0"
				aria-valuemax="<?php echo esc_attr( (string) $wb_gam_total_count ); ?>"
				aria-valuenow="<?php echo esc_attr( (string) $wb_gam_earned_count ); ?>">
				<div class="wb-gam-badge-showcase__progress-fill" style="width: <?php echo esc_attr( (string) $wb_gam_progress_pct ); ?>%"></div>
			</div>

			<?php if ( $wb_gam_show_locked && $wb_gam_locked_count > 0 && $wb_gam_earned_count > 0 ) : ?>
				<nav class="wb-gam-badge-showcase__filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter badges', 'wb-gamification' ); ?>">
					<button type="button" class="wb-gam-badge-showcase__filter is-active"
						role="tab" aria-selected="true" data-wb-gam-filter="all">
						<?php esc_html_e( 'All', 'wb-gamification' ); ?>
						<span class="wb-gam-badge-showcase__filter-count"><?php echo esc_html( number_format_i18n( $wb_gam_total_count ) ); ?></span>
					</button>
					<button type="button" class="wb-gam-badge-showcase__filter"
						role="tab" aria-selected="false" data-wb-gam-filter="earned">
						<?php esc_html_e( 'Earned', 'wb-gamification' ); ?>
						<span class="wb-gam-badge-showcase__filter-count"><?php echo esc_html( number_format_i18n( $wb_gam_earned_count ) ); ?></span>
					</button>
					<button type="button" class="wb-gam-badge-showcase__filter"
						role="tab" aria-selected="false" data-wb-gam-filter="locked">
						<?php esc_html_e( 'Locked', 'wb-gamification' ); ?>
						<span class="wb-gam-badge-showcase__filter-count"><?php echo esc_html( number_format_i18n( $wb_gam_locked_count ) ); ?></span>
					</button>
				</nav>
			<?php endif; ?>
		</header>

		<ul class="wb-gam-badge-showcase__list" role="list">
			<?php foreach ( $wb_gam_badges as $wb_gam_badge ) :
				$wb_gam_is_earned = (bool) ( $wb_gam_badge['earned'] ?? false );
				$wb_gam_state     = $wb_gam_is_earned ? 'earned' : 'locked';
				$wb_gam_class     = 'wb-gam-badge-showcase__badge wb-gam-badge-showcase__badge--' . $wb_gam_state;
				if ( ! empty( $wb_gam_badge['is_credential'] ) ) {
					$wb_gam_class .= ' wb-gam-badge-showcase__badge--credential';
				}
				$wb_gam_name = (string) ( $wb_gam_badge['name'] ?? '' );
				$wb_gam_desc = (string) ( $wb_gam_badge['description'] ?? '' );
				?>
				<li class="<?php echo esc_attr( $wb_gam_class ); ?>"
					data-state="<?php echo esc_attr( $wb_gam_state ); ?>">
					<div class="wb-gam-badge-showcase__icon-wrap" aria-hidden="true">
						<?php if ( ! empty( $wb_gam_badge['image_url'] ) ) : ?>
							<img
								alt="<?php echo esc_attr( $wb_gam_name ); ?>"
								src="<?php echo esc_url( (string) $wb_gam_badge['image_url'] ); ?>"
								class="wb-gam-badge-showcase__image"
								width="56" height="56"
								loading="lazy" />
						<?php else : ?>
							<span class="wb-gam-badge-showcase__placeholder">&#x1F3C5;</span>
						<?php endif; ?>

						<?php if ( $wb_gam_is_earned ) : ?>
							<span class="wb-gam-badge-showcase__pip wb-gam-badge-showcase__pip--earned" aria-hidden="true">
								<?php echo \WBGam\Admin\Icon::svg( 'check-circle', array( 'size' => 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						<?php else : ?>
							<span class="wb-gam-badge-showcase__pip wb-gam-badge-showcase__pip--locked" aria-hidden="true">
								<?php echo \WBGam\Admin\Icon::svg( 'shield', array( 'size' => 14 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						<?php endif; ?>
					</div>

					<span class="wb-gam-badge-showcase__name"><?php echo esc_html( $wb_gam_name ); ?></span>

					<?php if ( $wb_gam_is_earned && ! empty( $wb_gam_badge['earned_at'] ) ) : ?>
						<time class="wb-gam-badge-showcase__earned-at"
							datetime="<?php echo esc_attr( (string) $wb_gam_badge['earned_at'] ); ?>">
							<?php echo esc_html( $wb_gam_format_date( (string) $wb_gam_badge['earned_at'] ) ); ?>
						</time>
					<?php elseif ( ! $wb_gam_is_earned ) : ?>
						<span class="wb-gam-badge-showcase__hint">
							<?php
							echo esc_html(
								'' !== $wb_gam_desc
									? $wb_gam_desc
									: __( 'Keep earning to unlock', 'wb-gamification' )
							);
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'badge-showcase', $wb_gam_attrs );
