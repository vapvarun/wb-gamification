<?php
/**
 * Challenges block — Wbcom Block Quality Standard render.
 *
 * 1.4.0 UX refactor (Basecamp UX feedback 2026-05-26):
 *   - description line tells the member what action earns progress
 *     (was missing — every challenge looked identical from outside)
 *   - "Ends 7 months" fixed to "Ends in 7 months" / absolute date for
 *     near-deadline challenges (sub-7-day)
 *   - progress bar always rendered (even at 0%) so the member sees
 *     the track they're filling
 *   - empty state has presence
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
use WBGam\Engine\Registry;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
if ( $wb_gam_user_id <= 0 ) {
	$wb_gam_user_id = get_current_user_id();
}

if ( $wb_gam_user_id > 0 && ! Privacy::can_view_public_profile( $wb_gam_user_id ) ) {
	$wb_gam_user_id = 0;
}
$wb_gam_show_completed = ! empty( $wb_gam_attrs['show_completed'] );
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

/**
 * Format a challenge deadline for display.
 *
 * Sub-7-day deadlines read better as an absolute date ("Ends May 31"),
 * everything else as relative ("Ends in 2 weeks"). The bare
 * human_time_diff string ("2 weeks") was reading as "Ends 2 weeks"
 * which is grammatically broken.
 *
 * @param string $iso Ends_at from the engine.
 * @return string
 */
$wb_gam_format_deadline = static function ( string $iso ): string {
	$ts = strtotime( $iso );
	if ( ! $ts ) {
		return '';
	}
	$delta = $ts - time();
	if ( $delta <= 0 ) {
		return __( 'Ended', 'wb-gamification' );
	}
	if ( $delta < DAY_IN_SECONDS ) {
		return sprintf(
			/* translators: %s: human-readable time difference (e.g. "5 hours") */
			__( 'Ends in %s', 'wb-gamification' ),
			human_time_diff( time(), $ts )
		);
	}
	if ( $delta < WEEK_IN_SECONDS ) {
		return sprintf(
			/* translators: %s: human-readable time difference (e.g. "3 days") */
			__( 'Ends in %s', 'wb-gamification' ),
			human_time_diff( time(), $ts )
		);
	}
	return sprintf(
		/* translators: %s: absolute date (e.g. "May 31") */
		__( 'Ends %s', 'wb-gamification' ),
		date_i18n( __( 'M j', 'wb-gamification' ), $ts )
	);
};

BlockHooks::before( 'challenges', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $wb_gam_challenges ) ) : ?>
		<div class="wb-gam-challenges__empty">
			<?php echo \WBGam\Admin\Icon::svg( 'flag', array( 'size' => 28, 'class' => 'wb-gam-challenges__empty-icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'No active challenges right now. New ones appear when admins schedule them.', 'wb-gamification' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="wb-gam-challenges__list">
			<?php foreach ( $wb_gam_challenges as $wb_gam_ch ) :
				$wb_gam_action_label = Registry::label_for( (string) ( $wb_gam_ch['action_id'] ?? '' ) );
				$wb_gam_target       = (int) ( $wb_gam_ch['target'] ?? 0 );
				$wb_gam_progress     = (int) ( $wb_gam_ch['progress'] ?? 0 );
				$wb_gam_pct          = max( 0, min( 100, (int) ( $wb_gam_ch['progress_pct'] ?? 0 ) ) );
				$wb_gam_remaining    = max( 0, $wb_gam_target - $wb_gam_progress );
				?>
				<li class="wb-gam-challenges__item<?php echo ! empty( $wb_gam_ch['completed'] ) ? ' wb-gam-challenges__item--completed' : ''; ?>">
					<div class="wb-gam-challenges__header">
						<span class="wb-gam-challenges__title"><?php echo esc_html( (string) ( $wb_gam_ch['title'] ?? '' ) ); ?></span>
						<?php if ( 'team' === ( $wb_gam_ch['type'] ?? '' ) ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--team"><?php esc_html_e( 'Team', 'wb-gamification' ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $wb_gam_ch['completed'] ) ) : ?>
							<span class="wb-gam-challenges__badge wb-gam-challenges__badge--done" aria-label="<?php esc_attr_e( 'Completed', 'wb-gamification' ); ?>">
								<?php echo \WBGam\Admin\Icon::svg( 'check-circle', array( 'size' => 14 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						<?php endif; ?>
					</div>

					<?php if ( '' !== $wb_gam_action_label && empty( $wb_gam_ch['completed'] ) ) : ?>
						<p class="wb-gam-challenges__hint">
							<?php
							printf(
								esc_html(
									/* translators: 1: action label e.g. "Comment on a post", 2: remaining count */
									_n(
										'%2$d more &middot; %1$s',
										'%2$d more &middot; %1$s',
										$wb_gam_remaining,
										'wb-gamification'
									)
								),
								esc_html( $wb_gam_action_label ),
								(int) $wb_gam_remaining
							);
							?>
						</p>
					<?php endif; ?>

					<div class="wb-gam-challenges__bar-wrap"
						role="progressbar"
						aria-valuenow="<?php echo esc_attr( (string) $wb_gam_pct ); ?>"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-label="<?php
							/* translators: 1: current progress, 2: target */
							echo esc_attr( sprintf( __( 'Progress: %1$d of %2$d', 'wb-gamification' ), $wb_gam_progress, $wb_gam_target ) );
						?>">
						<div class="wb-gam-challenges__bar" style="--wb-gam-fill: <?php echo esc_attr( (string) $wb_gam_pct ); ?>%"></div>
					</div>

					<div class="wb-gam-challenges__meta">
						<span class="wb-gam-challenges__progress-text">
							<?php
							printf(
								/* translators: 1: current progress, 2: target */
								esc_html__( '%1$d / %2$d', 'wb-gamification' ),
								(int) $wb_gam_progress,
								(int) $wb_gam_target
							);
							?>
						</span>
						<?php if ( (int) ( $wb_gam_ch['bonus_points'] ?? 0 ) > 0 ) : ?>
							<span class="wb-gam-challenges__bonus">
								<?php echo \WBGam\Admin\Icon::svg( 'sparkles', array( 'size' => 12 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php
								/* translators: %d: bonus points */
								printf( esc_html__( '+%d pts', 'wb-gamification' ), (int) $wb_gam_ch['bonus_points'] );
								?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $wb_gam_ch['ends_at'] ) ) : ?>
							<span class="wb-gam-challenges__deadline">
								<?php echo esc_html( $wb_gam_format_deadline( (string) $wb_gam_ch['ends_at'] ) ); ?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'challenges', $wb_gam_attrs );
