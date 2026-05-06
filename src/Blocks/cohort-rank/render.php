<?php
/**
 * Cohort Rank block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\CohortEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}
$wb_gam_limit = max( 1, min( 50, (int) ( $wb_gam_attrs['limit'] ?? 5 ) ) );

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

$wb_gam_classes = array_filter( array( 'wb-gam-cohort-rank', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

if ( ! $wb_gam_user_id ) {
	$wb_gam_classes[] = 'wb-gam-cohort-rank--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	printf(
		'<div %s><p class="wb-gam-cohort-rank__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your cohort league.', 'wb-gamification' )
	);
	return;
}

$wb_gam_standing = CohortEngine::get_user_standing( $wb_gam_user_id );

if ( null === $wb_gam_standing ) {
	$wb_gam_classes[] = 'wb-gam-cohort-rank--unassigned';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	printf(
		'<div %s><p class="wb-gam-cohort-rank__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'You have not been assigned to a cohort yet. New cohorts are formed every Monday.', 'wb-gamification' )
	);
	return;
}

$wb_gam_standings    = array_slice( (array) ( $wb_gam_standing['standings'] ?? array() ), 0, $wb_gam_limit );

/**
 * Filter the cohort-rank standings before render.
 *
 * @since 1.0.0
 *
 * @param array $standings  Cohort standings rows.
 * @param array $attributes Block attributes (limit).
 * @param int   $user_id    Member whose cohort is rendered.
 */
$wb_gam_standings = (array) apply_filters( 'wb_gam_block_cohort_rank_data', $wb_gam_standings, $wb_gam_attrs, $wb_gam_user_id );

// Cohort standings are scored in the site's primary currency — resolve
// its label so the per-row figure says "240 Coins" on a coins-default
// site instead of always "240 pts".
$wb_gam_pt_service   = new \WBGam\Services\PointTypeService();
$wb_gam_pt_record    = $wb_gam_pt_service->get( $wb_gam_pt_service->default_slug() );
$wb_gam_points_label = (string) ( $wb_gam_pt_record['label'] ?? __( 'pts', 'wb-gamification' ) );
$wb_gam_current_rank = null;
foreach ( $wb_gam_standing['standings'] ?? array() as $wb_gam_entry ) {
	if ( (int) ( $wb_gam_entry['user_id'] ?? 0 ) === $wb_gam_user_id ) {
		$wb_gam_current_rank = (int) ( $wb_gam_entry['rank'] ?? 0 );
		break;
	}
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before(
	'cohort-rank',
	$wb_gam_attrs,
	array(
		'user_id'      => $wb_gam_user_id,
		'tier'         => (int) ( $wb_gam_standing['tier'] ?? 0 ),
		'current_rank' => $wb_gam_current_rank,
	)
);
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-cohort-rank__header">
		<span class="wb-gam-cohort-rank__tier-name">
			<?php echo esc_html( (string) ( $wb_gam_standing['tier_name'] ?? __( 'Cohort', 'wb-gamification' ) ) ); ?>
		</span>
		<?php if ( null !== $wb_gam_current_rank ) : ?>
			<span class="wb-gam-cohort-rank__current-rank">
				<?php
				/* translators: %d = the member's current rank within their cohort */
				printf( esc_html__( "You're #%d", 'wb-gamification' ), (int) $wb_gam_current_rank );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $wb_gam_standings ) ) : ?>
		<p class="wb-gam-cohort-rank__empty">
			<?php esc_html_e( 'No standings available yet — your cohort will populate as members earn points this week.', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ol class="wb-gam-cohort-rank__list" role="list">
			<?php foreach ( $wb_gam_standings as $wb_gam_entry ) :
				$wb_gam_is_self = ( (int) ( $wb_gam_entry['user_id'] ?? 0 ) === $wb_gam_user_id );
				?>
				<li class="wb-gam-cohort-rank__item<?php echo $wb_gam_is_self ? ' wb-gam-cohort-rank__item--self' : ''; ?>">
					<span class="wb-gam-cohort-rank__rank">#<?php echo esc_html( (string) ( $wb_gam_entry['rank'] ?? '' ) ); ?></span>
					<span class="wb-gam-cohort-rank__name"><?php echo esc_html( (string) ( $wb_gam_entry['display_name'] ?? '' ) ); ?></span>
					<span class="wb-gam-cohort-rank__points">
						<?php
						printf(
							/* translators: 1: amount earned, 2: currency label. */
							esc_html__( '%1$s %2$s', 'wb-gamification' ),
							esc_html( number_format_i18n( (int) ( $wb_gam_entry['week_pts'] ?? 0 ) ) ),
							esc_html( $wb_gam_points_label )
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
<?php
BlockHooks::after(
	'cohort-rank',
	$wb_gam_attrs,
	array(
		'user_id'      => $wb_gam_user_id,
		'tier'         => (int) ( $wb_gam_standing['tier'] ?? 0 ),
		'current_rank' => $wb_gam_current_rank,
	)
);
