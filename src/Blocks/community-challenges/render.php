<?php
/**
 * Community Challenges block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

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

$wb_gam_challenges = CommunityChallengeEngine::get_active();
if ( $wb_gam_limit > 0 ) {
	$wb_gam_challenges = array_slice( $wb_gam_challenges, 0, $wb_gam_limit );
}

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
				$wb_gam_progress   = (int) ( $wb_gam_challenge['global_progress'] ?? 0 );
				$wb_gam_target     = max( 1, (int) ( $wb_gam_challenge['target_count'] ?? 1 ) );
				$wb_gam_pct        = min( 100, (int) round( ( $wb_gam_progress / $wb_gam_target ) * 100 ) );
				$wb_gam_bonus      = (int) ( $wb_gam_challenge['bonus_points'] ?? 0 );
				$wb_gam_ends_ts    = (int) strtotime( (string) ( $wb_gam_challenge['ends_at'] ?? 'now' ) );
				$wb_gam_time_left  = $wb_gam_ends_ts > time()
					? human_time_diff( time(), $wb_gam_ends_ts )
					: __( 'ended', 'wb-gamification' );
				?>
				<li class="wb-gam-community-challenges__item">
					<div class="wb-gam-community-challenges__row">
						<span class="wb-gam-community-challenges__challenge-title">
							<?php echo esc_html( (string) ( $wb_gam_challenge['title'] ?? '' ) ); ?>
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
								style="width:<?php echo esc_attr( (string) $wb_gam_pct ); ?>%"></div>
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
							/* translators: %s = human-readable time remaining */
							printf( esc_html__( '%s left', 'wb-gamification' ), esc_html( (string) $wb_gam_time_left ) );
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
