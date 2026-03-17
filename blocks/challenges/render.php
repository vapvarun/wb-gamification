<?php
/**
 * Block: Challenges
 *
 * @package WB_Gamification
 * @since   0.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\ChallengeEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}

$show_completed = ! empty( $attributes['show_completed'] );
$show_bar       = ! empty( $attributes['show_progress_bar'] );
$limit          = (int) ( $attributes['limit'] ?? 0 );

$challenges = ChallengeEngine::get_active_challenges( (int) $user_id );

if ( ! $show_completed ) {
	$challenges = array_filter( $challenges, static fn( $ch ) => ! $ch['completed'] );
}

if ( $limit > 0 ) {
	$challenges = array_slice( $challenges, 0, $limit );
}

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-challenges' ] );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $challenges ) ) : ?>
		<p class="wb-gam-challenges__empty"><?php esc_html_e( 'No active challenges at the moment.', 'wb-gamification' ); ?></p>
	<?php else : ?>
		<ul class="wb-gam-challenges__list">
			<?php foreach ( $challenges as $ch ) : ?>
				<li class="wb-gam-challenges__item<?php echo $ch['completed'] ? ' wb-gam-challenges__item--completed' : ''; ?>">
					<div class="wb-gam-challenges__header">
						<span class="wb-gam-challenges__title"><?php echo esc_html( $ch['title'] ); ?></span>
						<?php if ( 'team' === $ch['type'] ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--team"><?php esc_html_e( 'Team', 'wb-gamification' ); ?></span>
						<?php endif; ?>
						<?php if ( $ch['completed'] ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--done" aria-label="<?php esc_attr_e( 'Completed', 'wb-gamification' ); ?>">&#10003;</span>
						<?php endif; ?>
					</div>

					<div class="wb-gam-challenges__meta">
						<span class="wb-gam-challenges__progress-text">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1 = current progress, 2 = target */
									__( '%1$d / %2$d', 'wb-gamification' ),
									$ch['progress'],
									$ch['target']
								)
							);
							?>
						</span>
						<?php if ( $ch['bonus_points'] > 0 ) : ?>
							<span class="wb-gam-challenges__bonus">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d = bonus points */
										__( '+%d pts', 'wb-gamification' ),
										$ch['bonus_points']
									)
								);
								?>
							</span>
						<?php endif; ?>
						<?php if ( $ch['ends_at'] ) : ?>
							<span class="wb-gam-challenges__deadline">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s = human readable time */
										__( 'Ends %s', 'wb-gamification' ),
										human_time_diff( strtotime( $ch['ends_at'] ) )
									)
								);
								?>
							</span>
						<?php endif; ?>
					</div>

					<?php if ( $show_bar ) : ?>
						<div
							class="wb-gam-challenges__bar-wrap"
							role="progressbar"
							aria-valuenow="<?php echo esc_attr( $ch['progress_pct'] ); ?>"
							aria-valuemin="0"
							aria-valuemax="100"
						>
							<div class="wb-gam-challenges__bar" style="width: <?php echo esc_attr( min( 100, $ch['progress_pct'] ) ); ?>%"></div>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
