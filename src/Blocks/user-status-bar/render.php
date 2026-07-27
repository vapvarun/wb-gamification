<?php
/**
 * User Status Bar block — server render.
 *
 * Renders the markup; the heartbeat broker (assets/js/heartbeat.js) feeds
 * live data into the data-* hooks at runtime. Empty values are server-
 * rendered too so the bar paints something useful on first request,
 * before the first heartbeat tick arrives.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

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
use WBGam\Engine\LevelEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\StreakEngine;

$wb_gam_attrs = is_array( $attributes ) ? $attributes : array();

$wb_gam_user_id = (int) get_current_user_id();
$wb_gam_hide_guests = ! isset( $wb_gam_attrs['hideForGuests'] ) || ! empty( $wb_gam_attrs['hideForGuests'] );
if ( $wb_gam_user_id <= 0 && $wb_gam_hide_guests ) {
	return '';
}

$wb_gam_unique = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_layout       = in_array( $wb_gam_attrs['layout'] ?? 'floating', array( 'floating', 'sticky-top', 'inline' ), true )
	? (string) $wb_gam_attrs['layout']
	: 'floating';
$wb_gam_position     = in_array( $wb_gam_attrs['position'] ?? 'top-right', array( 'top-right', 'top-left', 'bottom-right', 'bottom-left' ), true )
	? (string) $wb_gam_attrs['position']
	: 'top-right';
$wb_gam_show_level    = ! empty( $wb_gam_attrs['showLevel'] );
$wb_gam_show_badges   = ! empty( $wb_gam_attrs['showBadges'] );
$wb_gam_show_streak   = ! empty( $wb_gam_attrs['showStreak'] );
$wb_gam_show_progress = ! empty( $wb_gam_attrs['showProgress'] );
$wb_gam_collapsible   = ! empty( $wb_gam_attrs['collapsible'] );

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

// Seed initial values from the engines so the bar isn't blank for the
// ~15s between page paint and the first heartbeat tick.
$wb_gam_pt_service   = new \WBGam\Services\PointTypeService();
$wb_gam_primary_slug = (string) $wb_gam_pt_service->default_slug();
$wb_gam_primary_rec  = $wb_gam_pt_service->get( $wb_gam_primary_slug );
$wb_gam_primary_lab  = (string) ( $wb_gam_primary_rec['label'] ?? __( 'pts', 'wb-gamification' ) );
$wb_gam_points_total = $wb_gam_user_id > 0 ? (int) PointsEngine::get_total( $wb_gam_user_id, $wb_gam_primary_slug ) : 0;
$wb_gam_level        = $wb_gam_user_id > 0 ? LevelEngine::get_level_for_user( $wb_gam_user_id ) : null;
$wb_gam_progress     = $wb_gam_user_id > 0 ? (int) LevelEngine::get_progress_percent( $wb_gam_user_id ) : 0;
$wb_gam_badge_count  = $wb_gam_user_id > 0 ? (int) BadgeEngine::count_user_badges( $wb_gam_user_id ) : 0;
$wb_gam_streak       = $wb_gam_user_id > 0 ? StreakEngine::get_streak( $wb_gam_user_id ) : array();
$wb_gam_streak_curr  = (int) ( $wb_gam_streak['current_streak'] ?? 0 );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-status-bar',
		'wb-gam-status-bar--' . $wb_gam_layout,
		'wb-gam-status-bar--pos-' . $wb_gam_position,
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
	)
);

wp_enqueue_style( 'wb-gam-tokens' );

// The shared top-strip measurement. A floating bar has to be told what is already at the top of the
// page — it used to hardcode `top: 48px` (the admin-bar height) and expose a CSS variable inviting
// THEMES to fix our positioning for us. None do, including our own: on BuddyX the bar landed on top
// of the site's nav. view.js now measures and sets that variable itself, and this is what it needs.
wp_enqueue_script( 'wb-gamification-top-offset' );

BlockHooks::before( 'user-status-bar', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	role="region"
	aria-label="<?php esc_attr_e( 'Your gamification status', 'wb-gamification' ); ?>"
	data-wb-gam-status-bar
	data-collapsible="<?php echo $wb_gam_collapsible ? '1' : '0'; ?>"
	data-show-level="<?php echo $wb_gam_show_level ? '1' : '0'; ?>"
	data-show-badges="<?php echo $wb_gam_show_badges ? '1' : '0'; ?>"
	data-show-streak="<?php echo $wb_gam_show_streak ? '1' : '0'; ?>"
	data-show-progress="<?php echo $wb_gam_show_progress ? '1' : '0'; ?>">

	<div class="wb-gam-status-bar__inner">
		<?php if ( $wb_gam_collapsible ) : ?>
			<button type="button"
				class="wb-gam-status-bar__toggle"
				aria-expanded="true"
				aria-controls="wb-gam-status-bar-body-<?php echo esc_attr( $wb_gam_unique ); ?>"
				aria-label="<?php esc_attr_e( 'Collapse status bar', 'wb-gamification' ); ?>"
				data-wb-gam-status-bar-toggle>
				<span class="wb-gam-status-bar__toggle-icon" aria-hidden="true"></span>
			</button>
		<?php endif; ?>

		<div id="wb-gam-status-bar-body-<?php echo esc_attr( $wb_gam_unique ); ?>" class="wb-gam-status-bar__body">
			<div class="wb-gam-status-bar__stat wb-gam-status-bar__stat--points">
				<?php echo \WBGam\Admin\Icon::svg( 'sparkles', array( 'size' => 16, 'class' => 'wb-gam-status-bar__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="wb-gam-status-bar__value" data-wb-gam-bind="primary_total"><?php echo esc_html( number_format_i18n( $wb_gam_points_total ) ); ?></span>
				<span class="wb-gam-status-bar__label" data-wb-gam-bind="primary_label"><?php echo esc_html( $wb_gam_primary_lab ); ?></span>
			</div>

			<?php if ( $wb_gam_show_level ) : ?>
				<div class="wb-gam-status-bar__stat wb-gam-status-bar__stat--level">
					<?php echo \WBGam\Admin\Icon::svg( 'trophy', array( 'size' => 16, 'class' => 'wb-gam-status-bar__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="wb-gam-status-bar__value" data-wb-gam-bind="level.name"><?php echo esc_html( (string) ( $wb_gam_level['name'] ?? __( 'Newcomer', 'wb-gamification' ) ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $wb_gam_show_badges ) : ?>
				<div class="wb-gam-status-bar__stat wb-gam-status-bar__stat--badges">
					<?php echo \WBGam\Admin\Icon::svg( 'medal', array( 'size' => 16, 'class' => 'wb-gam-status-bar__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="wb-gam-status-bar__value" data-wb-gam-bind="badges_count"><?php echo esc_html( number_format_i18n( $wb_gam_badge_count ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $wb_gam_show_streak ) : ?>
				<div class="wb-gam-status-bar__stat wb-gam-status-bar__stat--streak">
					<?php echo \WBGam\Admin\Icon::svg( 'flame', array( 'size' => 16, 'class' => 'wb-gam-status-bar__icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="wb-gam-status-bar__value" data-wb-gam-bind="current_streak"><?php echo esc_html( number_format_i18n( $wb_gam_streak_curr ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $wb_gam_show_progress ) : ?>
				<div class="wb-gam-status-bar__progress" aria-hidden="true">
					<div class="wb-gam-status-bar__progress-fill" data-wb-gam-bind-style="progress_percent" style="width: <?php echo esc_attr( (string) $wb_gam_progress ); ?>%"></div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
BlockHooks::after( 'user-status-bar', $wb_gam_attrs );
