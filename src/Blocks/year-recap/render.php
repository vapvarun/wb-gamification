<?php
/**
 * Year in Community Recap — Wbcom Block Quality Standard render.
 *
 * Replaces the legacy inline `onclick` share handler with the
 * Interactivity API store wired up in `view.js`.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Privacy;
use WBGam\Engine\RecapEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}


// Privacy gate — see plan/PRIVACY-MODEL.md. T1 (achievement-shaped) data
// is public when the site kill-switch + member toggle are both ON, OR
// when the viewer is the owner / admin. Otherwise zero out the user_id
// so the block falls through to its empty-state path.
if ( $wb_gam_user_id > 0 && ! Privacy::can_view_public_profile( $wb_gam_user_id ) ) {
	$wb_gam_user_id = 0;
}
WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );

$wb_gam_inline = '';
if ( ! empty( $wb_gam_attrs['accentColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-accent: %s;', sanitize_text_field( (string) $wb_gam_attrs['accentColor'] ) );
}
if ( ! empty( $wb_gam_attrs['accent_color'] ) ) {
	$wb_gam_color = sanitize_hex_color( (string) $wb_gam_attrs['accent_color'] );
	if ( $wb_gam_color ) {
		$wb_gam_inline .= sprintf( '--wb-gam-recap-accent: %s;', $wb_gam_color );
	}
}
if ( ! empty( $wb_gam_attrs['cardBackground'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-white: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBackground'] ) );
}
if ( ! empty( $wb_gam_attrs['cardBorderColor'] ) ) {
	$wb_gam_inline .= sprintf( '--wb-gam-color-border: %s;', sanitize_text_field( (string) $wb_gam_attrs['cardBorderColor'] ) );
}

$wb_gam_classes = array_filter( array( 'wb-gam-year-recap', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

if ( ! $wb_gam_user_id ) {
	$wb_gam_wrapper = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	printf(
		'<div %s><p class="wb-gam-year-recap__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your year in review.', 'wb-gamification' )
	);
	return;
}

$wb_gam_year         = (int) ( $wb_gam_attrs['year'] ?? 0 );
$wb_gam_show_share   = ! isset( $wb_gam_attrs['show_share_button'] ) || ! empty( $wb_gam_attrs['show_share_button'] );
$wb_gam_show_badges  = ! isset( $wb_gam_attrs['show_badges'] ) || ! empty( $wb_gam_attrs['show_badges'] );
$wb_gam_show_kudos   = ! isset( $wb_gam_attrs['show_kudos'] ) || ! empty( $wb_gam_attrs['show_kudos'] );

$wb_gam_recap = RecapEngine::get_recap( $wb_gam_user_id, $wb_gam_year );

/**
 * Filter the year-recap data before render.
 *
 * @since 1.0.0
 *
 * @param array $recap      Yearly recap aggregates.
 * @param array $attributes Block attributes (year).
 * @param int   $user_id    Member whose recap is rendered.
 */
$wb_gam_recap = (array) apply_filters( 'wb_gam_block_year_recap_data', $wb_gam_recap, $wb_gam_attrs, $wb_gam_user_id );

// Recap totals are scored in the site's primary currency — resolve
// its label so the "peak week" line reads as the configured default.
$wb_gam_pt_service   = new \WBGam\Services\PointTypeService();
$wb_gam_pt_record    = $wb_gam_pt_service->get( $wb_gam_pt_service->default_slug() );
$wb_gam_points_label = (string) ( $wb_gam_pt_record['label'] ?? __( 'pts', 'wb-gamification' ) );
$wb_gam_user  = get_userdata( $wb_gam_user_id );

if ( ! $wb_gam_user || empty( $wb_gam_recap['total_points'] ) ) {
	$wb_gam_wrapper = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	printf(
		'<div %s><p class="wb-gam-year-recap__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Your year in review will appear here once you start earning points.', 'wb-gamification' )
	);
	return;
}

$wb_gam_display_year = (int) $wb_gam_recap['year'];

$wb_gam_share_title = sprintf(
	/* translators: 1: user display name, 2: year */
	__( '%1$s — %2$d in Community', 'wb-gamification' ),
	$wb_gam_user->display_name,
	$wb_gam_display_year
);

$wb_gam_root_ctx = wp_json_encode(
	array(
		'shareTitle' => $wb_gam_share_title,
		'shareText'  => (string) ( $wb_gam_recap['headline'] ?? '' ),
		'copied'     => false,
	)
);

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class'                => implode( ' ', $wb_gam_classes ),
		'style'                => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		'data-wp-interactive'  => 'wb-gamification/recap',
		'data-wp-context'      => $wb_gam_root_ctx,
	)
);

$wb_gam_fmt_num = static fn( int $n ): string => number_format_i18n( $n );

BlockHooks::before( 'year-recap', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-recap__header">
		<div class="wb-gam-recap__avatar">
			<?php echo get_avatar( $wb_gam_user_id, 72, '', '', array( 'class' => 'wb-gam-recap__avatar-img' ) ); ?>
		</div>
		<div class="wb-gam-recap__header-text">
			<h2 class="wb-gam-recap__name"><?php echo esc_html( $wb_gam_user->display_name ); ?></h2>
			<p class="wb-gam-recap__subtitle">
				<?php
				/* translators: %d = year */
				printf( esc_html__( 'Your %d in Community', 'wb-gamification' ), (int) $wb_gam_display_year );
				?>
			</p>
		</div>
	</div>

	<div class="wb-gam-recap__headline">
		<p><?php echo esc_html( (string) ( $wb_gam_recap['headline'] ?? '' ) ); ?></p>
	</div>

	<div class="wb-gam-recap__stats">
		<div class="wb-gam-recap__stat wb-gam-recap__stat--points">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $wb_gam_fmt_num( (int) ( $wb_gam_recap['points_this_year'] ?? 0 ) ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Points Earned', 'wb-gamification' ); ?></span>
		</div>
		<div class="wb-gam-recap__stat wb-gam-recap__stat--events">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $wb_gam_fmt_num( (int) ( $wb_gam_recap['total_events'] ?? 0 ) ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></span>
		</div>
		<div class="wb-gam-recap__stat wb-gam-recap__stat--challenges">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( (string) (int) ( $wb_gam_recap['challenges_completed'] ?? 0 ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Challenges', 'wb-gamification' ); ?></span>
		</div>
		<div class="wb-gam-recap__stat wb-gam-recap__stat--badges">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( (string) (int) ( $wb_gam_recap['badges_earned']['count'] ?? 0 ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Badges', 'wb-gamification' ); ?></span>
		</div>
		<?php if ( (int) ( $wb_gam_recap['percentile'] ?? 0 ) > 0 ) : ?>
			<div class="wb-gam-recap__stat wb-gam-recap__stat--percentile">
				<span class="wb-gam-recap__stat-value">
					<?php
					/* translators: %d = percentile (0-100) */
					printf( esc_html__( 'Top %d%%', 'wb-gamification' ), absint( max( 1, 100 - (int) $wb_gam_recap['percentile'] ) ) );
					?>
				</span>
				<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Community Rank', 'wb-gamification' ); ?></span>
			</div>
		<?php endif; ?>
		<?php if ( $wb_gam_show_kudos ) : ?>
			<div class="wb-gam-recap__stat wb-gam-recap__stat--kudos">
				<span class="wb-gam-recap__stat-value"><?php echo esc_html( (string) (int) ( $wb_gam_recap['kudos']['received'] ?? 0 ) ); ?></span>
				<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Kudos Received', 'wb-gamification' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $wb_gam_recap['peak_week'] ) ) : ?>
		<div class="wb-gam-recap__peak-week">
			<span class="wb-gam-recap__peak-week-label"><?php esc_html_e( 'Peak Week', 'wb-gamification' ); ?></span>
			<span class="wb-gam-recap__peak-week-week"><?php echo esc_html( (string) ( $wb_gam_recap['peak_week']['week'] ?? '' ) ); ?></span>
			<span class="wb-gam-recap__peak-week-pts">
				<?php
				printf(
					/* translators: 1: amount, 2: currency label. */
					esc_html__( '%1$s %2$s', 'wb-gamification' ),
					esc_html( $wb_gam_fmt_num( (int) ( $wb_gam_recap['peak_week']['points'] ?? 0 ) ) ),
					esc_html( $wb_gam_points_label )
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $wb_gam_recap['top_actions'] ) ) : ?>
		<div class="wb-gam-recap__top-actions">
			<h3 class="wb-gam-recap__section-title"><?php esc_html_e( 'Most Active', 'wb-gamification' ); ?></h3>
			<ol class="wb-gam-recap__top-actions-list">
				<?php foreach ( $wb_gam_recap['top_actions'] as $wb_gam_action ) : ?>
					<li class="wb-gam-recap__top-action">
						<span class="wb-gam-recap__top-action-id"><?php echo esc_html( (string) ( $wb_gam_action['action_id'] ?? '' ) ); ?></span>
						<span class="wb-gam-recap__top-action-count">
							<?php
							$wb_gam_count = (int) ( $wb_gam_action['event_count'] ?? 0 );
							printf(
								/* translators: %d: number of times the action was performed */
								esc_html( _n( '%d time', '%d times', $wb_gam_count, 'wb-gamification' ) ),
								(int) $wb_gam_count
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>

	<?php if ( $wb_gam_show_badges && (int) ( $wb_gam_recap['badges_earned']['count'] ?? 0 ) > 0 ) : ?>
		<div class="wb-gam-recap__badges">
			<h3 class="wb-gam-recap__section-title">
				<?php
				$wb_gam_count = (int) $wb_gam_recap['badges_earned']['count'];
				/* translators: %d: number of badges earned */
				printf( esc_html( _n( '%d Badge Earned', '%d Badges Earned', $wb_gam_count, 'wb-gamification' ) ), (int) $wb_gam_count );
				?>
			</h3>
			<div class="wb-gam-recap__badges-list">
				<?php foreach ( $wb_gam_recap['badges_earned']['badges'] as $wb_gam_badge ) : ?>
					<span class="wb-gam-recap__badge-pill" title="<?php echo esc_attr( (string) ( $wb_gam_badge['earned_at'] ?? '' ) ); ?>">
						<?php echo esc_html( (string) ( $wb_gam_badge['name'] ?? '' ) ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $wb_gam_show_share && get_current_user_id() === $wb_gam_user_id ) : ?>
		<div class="wb-gam-recap__footer">
			<button type="button"
				class="wb-gam-recap__share-btn"
				data-wp-on--click="actions.share"
			>
				<span data-wp-bind--hidden="state.copied"><?php esc_html_e( 'Share Your Year', 'wb-gamification' ); ?></span>
				<span data-wp-bind--hidden="!state.copied" hidden><?php esc_html_e( 'Link copied!', 'wb-gamification' ); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'year-recap', $wb_gam_attrs );
