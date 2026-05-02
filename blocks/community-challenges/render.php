<?php
/**
 * Block: Community Challenges
 *
 * Lists active community challenges (engine: CommunityChallengeEngine,
 * data: wb_gam_community_challenges table). Each challenge shows
 * title + global progress + target + bonus + time remaining.
 *
 * @package WB_Gamification
 * @since   1.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\BlockHooks;
use WBGam\Engine\CommunityChallengeEngine;

$limit             = (int) ( $attributes['limit'] ?? 0 );
$show_progress_bar = ! empty( $attributes['show_progress_bar'] );

$challenges = CommunityChallengeEngine::get_active();

if ( $limit > 0 ) {
	$challenges = array_slice( $challenges, 0, $limit );
}

$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-community-challenges' ) );

BlockHooks::before( 'community-challenges', $attributes, array( 'count' => count( $challenges ) ) );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns sanitized output. ?>>
	<div class="wb-gam-community-challenges__header">
		<h3 class="wb-gam-community-challenges__title">
			<?php esc_html_e( 'Community Challenges', 'wb-gamification' ); ?>
		</h3>
	</div>

	<?php if ( empty( $challenges ) ) : ?>
		<p class="wb-gam-community-challenges__empty">
			<?php esc_html_e( 'No active community challenges right now. Check back soon!', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-community-challenges__list" role="list">
			<?php foreach ( $challenges as $challenge ) :
				$progress    = (int) ( $challenge['global_progress'] ?? 0 );
				$target      = max( 1, (int) ( $challenge['target_count'] ?? 1 ) );
				$pct         = min( 100, (int) round( ( $progress / $target ) * 100 ) );
				$bonus       = (int) ( $challenge['bonus_points'] ?? 0 );
				$ends_at_ts  = strtotime( $challenge['ends_at'] ?? 'now' );
				$time_left   = $ends_at_ts > time()
					? human_time_diff( time(), $ends_at_ts )
					: __( 'ended', 'wb-gamification' );
			?>
				<li class="wb-gam-community-challenges__item">
					<div class="wb-gam-community-challenges__row">
						<span class="wb-gam-community-challenges__challenge-title">
							<?php echo esc_html( $challenge['title'] ); ?>
						</span>
						<span class="wb-gam-community-challenges__bonus">
							<?php
							/* translators: %d = bonus points awarded on completion */
							printf( esc_html__( '+%d pts', 'wb-gamification' ), $bonus );
							?>
						</span>
					</div>

					<?php if ( $show_progress_bar ) : ?>
						<div class="wb-gam-community-challenges__progress" role="progressbar"
						     aria-valuemin="0"
						     aria-valuemax="<?php echo esc_attr( (string) $target ); ?>"
						     aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>">
							<div class="wb-gam-community-challenges__progress-bar"
							     style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
						</div>
					<?php endif; ?>

					<div class="wb-gam-community-challenges__meta">
						<span class="wb-gam-community-challenges__count">
							<?php
							/* translators: 1 = current progress, 2 = target count */
							printf( esc_html__( '%1$s / %2$s', 'wb-gamification' ),
								esc_html( number_format_i18n( $progress ) ),
								esc_html( number_format_i18n( $target ) )
							);
							?>
						</span>
						<span class="wb-gam-community-challenges__time-left">
							<?php
							/* translators: %s = human-readable time remaining */
							printf( esc_html__( '%s left', 'wb-gamification' ), esc_html( $time_left ) );
							?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<?php BlockHooks::after( 'community-challenges', $attributes, array( 'count' => count( $challenges ) ) ); ?>
