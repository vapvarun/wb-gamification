<?php
/**
 * Challenges block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Privacy;
use WBGam\Engine\ChallengeEngine;

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
$wb_gam_show_completed = ! empty( $wb_gam_attrs['show_completed'] );
$wb_gam_show_bar       = ! empty( $wb_gam_attrs['show_progress_bar'] );
$wb_gam_limit          = (int) ( $wb_gam_attrs['limit'] ?? 0 );

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

$wb_gam_classes = array_filter( array( 'wb-gam-challenges', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_challenges = ChallengeEngine::get_active_challenges( (int) $wb_gam_user_id );

/**
 * Filter the challenges block list before render.
 *
 * @since 1.0.0
 *
 * @param array $challenges Active challenges for the user.
 * @param array $attributes Block attributes.
 * @param int   $user_id    Member whose challenges are rendered.
 */
$wb_gam_challenges = (array) apply_filters( 'wb_gam_block_challenges_data', $wb_gam_challenges, $wb_gam_attrs, (int) $wb_gam_user_id );

if ( ! $wb_gam_show_completed ) {
	$wb_gam_challenges = array_filter( $wb_gam_challenges, static fn( $ch ) => empty( $ch['completed'] ) );
}

if ( $wb_gam_limit > 0 ) {
	$wb_gam_challenges = array_slice( $wb_gam_challenges, 0, $wb_gam_limit );
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'challenges', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $wb_gam_challenges ) ) : ?>
		<p class="wb-gam-challenges__empty"><?php esc_html_e( 'No active challenges at the moment.', 'wb-gamification' ); ?></p>
	<?php else : ?>
		<ul class="wb-gam-challenges__list">
			<?php foreach ( $wb_gam_challenges as $wb_gam_ch ) : ?>
				<li class="wb-gam-challenges__item<?php echo ! empty( $wb_gam_ch['completed'] ) ? ' wb-gam-challenges__item--completed' : ''; ?>">
					<div class="wb-gam-challenges__header">
						<span class="wb-gam-challenges__title"><?php echo esc_html( (string) ( $wb_gam_ch['title'] ?? '' ) ); ?></span>
						<?php if ( 'team' === ( $wb_gam_ch['type'] ?? '' ) ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--team"><?php esc_html_e( 'Team', 'wb-gamification' ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $wb_gam_ch['completed'] ) ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--done" aria-label="<?php esc_attr_e( 'Completed', 'wb-gamification' ); ?>">&#10003;</span>
						<?php endif; ?>
					</div>

					<div class="wb-gam-challenges__meta">
						<span class="wb-gam-challenges__progress-text">
							<?php
							/* translators: 1 = current progress, 2 = target */
							printf( esc_html__( '%1$d / %2$d', 'wb-gamification' ), (int) ( $wb_gam_ch['progress'] ?? 0 ), (int) ( $wb_gam_ch['target'] ?? 0 ) );
							?>
						</span>
						<?php if ( (int) ( $wb_gam_ch['bonus_points'] ?? 0 ) > 0 ) : ?>
							<span class="wb-gam-challenges__bonus">
								<?php
								/* translators: %d = bonus points */
								printf( esc_html__( '+%d pts', 'wb-gamification' ), (int) $wb_gam_ch['bonus_points'] );
								?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $wb_gam_ch['ends_at'] ) ) : ?>
							<span class="wb-gam-challenges__deadline">
								<?php
								/* translators: %s = human readable time */
								printf( esc_html__( 'Ends %s', 'wb-gamification' ), esc_html( human_time_diff( strtotime( (string) $wb_gam_ch['ends_at'] ) ) ) );
								?>
							</span>
						<?php endif; ?>
					</div>

					<?php if ( $wb_gam_show_bar ) : ?>
						<div class="wb-gam-challenges__bar-wrap"
							role="progressbar"
							aria-valuenow="<?php echo esc_attr( (string) (int) ( $wb_gam_ch['progress_pct'] ?? 0 ) ); ?>"
							aria-valuemin="0"
							aria-valuemax="100"
						>
							<div class="wb-gam-challenges__bar" style="--wb-gam-fill: <?php echo esc_attr( (string) min( 100, (int) ( $wb_gam_ch['progress_pct'] ?? 0 ) ) ); ?>%"></div>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'challenges', $wb_gam_attrs );
