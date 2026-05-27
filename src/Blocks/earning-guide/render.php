<?php
/**
 * Earning Guide block — Wbcom Block Quality Standard render.
 *
 * Phase D.1 migration: per-instance scoped CSS via
 * `WBGam\Blocks\CSS::add()`, design tokens via `wb-gam-tokens`,
 * `.wb-gam-block-{uniqueId}` wrapper class.
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
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Registry;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_columns = max( 1, min( 4, (int) ( $wb_gam_attrs['columns'] ?? 3 ) ) );
$wb_gam_show_h  = ! isset( $wb_gam_attrs['show_category_headers'] ) || ! empty( $wb_gam_attrs['show_category_headers'] );

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-earning-guide',
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

$wb_gam_actions = Registry::get_actions();

$wb_gam_grouped = array();
if ( ! empty( $wb_gam_actions ) ) {
	foreach ( $wb_gam_actions as $wb_gam_id => $wb_gam_action ) {
		$wb_gam_enabled = (bool) get_option( 'wb_gam_enabled_' . $wb_gam_id, true );
		if ( ! $wb_gam_enabled ) {
			continue;
		}

		$wb_gam_category = (string) ( $wb_gam_action['category'] ?? 'general' );
		$wb_gam_pts      = (int) get_option( 'wb_gam_points_' . $wb_gam_id, $wb_gam_action['default_points'] ?? 0 );

		if ( $wb_gam_pts <= 0 ) {
			continue;
		}

		$wb_gam_grouped[ $wb_gam_category ][] = array(
			'label'     => (string) ( $wb_gam_action['label'] ?? $wb_gam_id ),
			'icon'      => (string) ( $wb_gam_action['icon'] ?? 'icon-star' ),
			'points'    => $wb_gam_pts,
			// Registry::get_actions() resolves admin overrides; manifest
			// defaults to 0 ("unlimited") for both keys. Surface them in
			// the guide so members aren't surprised by silent caps.
			'cooldown'  => (int) ( $wb_gam_action['cooldown'] ?? 0 ),
			'daily_cap' => (int) ( $wb_gam_action['daily_cap'] ?? 0 ),
		);
	}
}

/**
 * Filter the earning-guide grouped action map before render.
 *
 * Map shape: [ category => [ ['label','icon','points'], ... ] ].
 *
 * @since 1.0.0
 *
 * @param array $grouped    Category-keyed action list.
 * @param array $attributes Block attributes.
 */
$wb_gam_grouped = (array) apply_filters( 'wb_gam_block_earning_guide_data', $wb_gam_grouped, $wb_gam_attrs );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

if ( empty( $wb_gam_grouped ) ) {
	BlockHooks::before( 'earning-guide', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-earning-guide__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'No earning opportunities available yet.', 'wb-gamification' )
	);
	BlockHooks::after( 'earning-guide', $wb_gam_attrs );
	return;
}

ksort( $wb_gam_grouped );

BlockHooks::before( 'earning-guide', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php foreach ( $wb_gam_grouped as $wb_gam_category => $wb_gam_items ) : ?>
		<?php if ( $wb_gam_show_h ) : ?>
			<h3 class="wb-gam-earning-guide__category"><?php echo esc_html( ucfirst( $wb_gam_category ) ); ?></h3>
		<?php endif; ?>
		<div class="wb-gam-earning-guide__grid" data-cols="<?php echo (int) $wb_gam_columns; ?>">
			<?php foreach ( $wb_gam_items as $wb_gam_item ) : ?>
				<?php
				$wb_gam_meta_parts = array();
				if ( $wb_gam_item['daily_cap'] > 0 ) {
					$wb_gam_meta_parts[] = sprintf(
						/* translators: %s: per-day cap count */
						esc_html__( 'max %s/day', 'wb-gamification' ),
						esc_html( number_format_i18n( $wb_gam_item['daily_cap'] ) )
					);
				}
				if ( $wb_gam_item['cooldown'] > 0 ) {
					$wb_gam_cd = $wb_gam_item['cooldown'];
					if ( $wb_gam_cd >= 3600 ) {
						$wb_gam_meta_parts[] = sprintf(
							/* translators: %s: hours between earnings */
							esc_html__( '%sh cooldown', 'wb-gamification' ),
							esc_html( number_format_i18n( $wb_gam_cd / 3600, 1 ) )
						);
					} elseif ( $wb_gam_cd >= 60 ) {
						$wb_gam_meta_parts[] = sprintf(
							/* translators: %s: minutes between earnings */
							esc_html__( '%sm cooldown', 'wb-gamification' ),
							esc_html( number_format_i18n( (int) ( $wb_gam_cd / 60 ) ) )
						);
					} else {
						$wb_gam_meta_parts[] = sprintf(
							/* translators: %s: seconds between earnings */
							esc_html__( '%ss cooldown', 'wb-gamification' ),
							esc_html( number_format_i18n( $wb_gam_cd ) )
						);
					}
				}
				?>
				<div class="wb-gam-earning-guide__card">
					<span class="wb-gam-earning-guide__icon <?php echo esc_attr( (string) $wb_gam_item['icon'] ); ?>"></span>
					<span class="wb-gam-earning-guide__body">
						<span class="wb-gam-earning-guide__label"><?php echo esc_html( (string) $wb_gam_item['label'] ); ?></span>
						<?php if ( ! empty( $wb_gam_meta_parts ) ) : ?>
							<span class="wb-gam-earning-guide__meta">
								<?php echo esc_html( implode( ' · ', $wb_gam_meta_parts ) ); ?>
							</span>
						<?php endif; ?>
					</span>
					<span class="wb-gam-earning-guide__pts">
						<?php
						/* translators: %s: point value */
						printf( esc_html__( '+%s pts', 'wb-gamification' ), esc_html( number_format_i18n( (int) $wb_gam_item['points'] ) ) );
						?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php
BlockHooks::after( 'earning-guide', $wb_gam_attrs );
