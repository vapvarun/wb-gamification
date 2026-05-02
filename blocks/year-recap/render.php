<?php
/**
 * Year in Community Recap block — server-side render.
 *
 * @var array    $attributes  Block attributes.
 * @var string   $content     Inner blocks HTML (unused).
 * @var WP_Block $block       Block instance.
 *
 * @package WB_Gamification
 */

use WBGam\Engine\RecapEngine;

defined( 'ABSPATH' ) || exit;


use WBGam\Engine\BlockHooks;
// Resolve user.
$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}
if ( ! $user_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-year-recap' ) );
	printf(
		'<div %s><p class="wb-gam-year-recap__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your year in review.', 'wb-gamification' )
	);
	return;
}

$year         = (int) ( $attributes['year'] ?? 0 );
$show_share   = (bool) ( $attributes['show_share_button'] ?? true );
$show_badges  = (bool) ( $attributes['show_badges'] ?? true );
$show_kudos   = (bool) ( $attributes['show_kudos'] ?? true );
$accent_color = sanitize_hex_color( $attributes['accent_color'] ?? '' );

$recap = RecapEngine::get_recap( $user_id, $year );
$user  = get_userdata( $user_id );

if ( ! $user || empty( $recap['total_points'] ) ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-year-recap' ) );
	printf(
		'<div %s><p class="wb-gam-year-recap__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Your year in review will appear here once you start earning points.', 'wb-gamification' )
	);
	return;
}

$display_year = (int) $recap['year'];

// Build accent style string.
$accent_style = $accent_color
	? ' style="--wb-gam-recap-accent:' . esc_attr( $accent_color ) . ';"'
	: '';

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'wb-gam-year-recap' ] );



BlockHooks::before( 'year-recap', $attributes );
// Helpers for stat display.
$fmt_num = static fn( int $n ): string => number_format_i18n( $n );

?>
<div <?php echo $wrapper_attrs; // phpcs:ignore ?><?php echo $accent_style; // phpcs:ignore ?>>

	<!-- ── Header ─────────────────────────────────────────────────────── -->
	<div class="wb-gam-recap__header">
		<div class="wb-gam-recap__avatar">
			<?php echo get_avatar( $user_id, 72, '', '', [ 'class' => 'wb-gam-recap__avatar-img' ] ); ?>
		</div>
		<div class="wb-gam-recap__header-text">
			<h2 class="wb-gam-recap__name"><?php echo esc_html( $user->display_name ); ?></h2>
			<p class="wb-gam-recap__subtitle">
				<?php
				printf(
					/* translators: %d = year */
					esc_html__( 'Your %d in Community', 'wb-gamification' ),
					$display_year
				);
				?>
			</p>
		</div>
	</div>

	<!-- ── Headline ───────────────────────────────────────────────────── -->
	<div class="wb-gam-recap__headline">
		<p><?php echo esc_html( $recap['headline'] ); ?></p>
	</div>

	<!-- ── Stats grid ─────────────────────────────────────────────────── -->
	<div class="wb-gam-recap__stats">

		<div class="wb-gam-recap__stat wb-gam-recap__stat--points">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $fmt_num( $recap['points_this_year'] ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Points Earned', 'wb-gamification' ); ?></span>
		</div>

		<div class="wb-gam-recap__stat wb-gam-recap__stat--events">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $fmt_num( $recap['total_events'] ) ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></span>
		</div>

		<div class="wb-gam-recap__stat wb-gam-recap__stat--challenges">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $recap['challenges_completed'] ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Challenges', 'wb-gamification' ); ?></span>
		</div>

		<div class="wb-gam-recap__stat wb-gam-recap__stat--badges">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $recap['badges_earned']['count'] ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Badges', 'wb-gamification' ); ?></span>
		</div>

		<?php if ( $recap['percentile'] > 0 ) : ?>
		<div class="wb-gam-recap__stat wb-gam-recap__stat--percentile">
			<span class="wb-gam-recap__stat-value">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d = percentile (0-100) */
						__( 'Top %d%%', 'wb-gamification' ),
						max( 1, 100 - $recap['percentile'] )
					)
				);
				?>
			</span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Community Rank', 'wb-gamification' ); ?></span>
		</div>
		<?php endif; ?>

		<?php if ( $show_kudos ) : ?>
		<div class="wb-gam-recap__stat wb-gam-recap__stat--kudos">
			<span class="wb-gam-recap__stat-value"><?php echo esc_html( $recap['kudos']['received'] ); ?></span>
			<span class="wb-gam-recap__stat-label"><?php esc_html_e( 'Kudos Received', 'wb-gamification' ); ?></span>
		</div>
		<?php endif; ?>

	</div>

	<!-- ── Peak week ──────────────────────────────────────────────────── -->
	<?php if ( ! empty( $recap['peak_week'] ) ) : ?>
	<div class="wb-gam-recap__peak-week">
		<span class="wb-gam-recap__peak-week-label"><?php esc_html_e( 'Peak Week', 'wb-gamification' ); ?></span>
		<span class="wb-gam-recap__peak-week-week"><?php echo esc_html( $recap['peak_week']['week'] ); ?></span>
		<span class="wb-gam-recap__peak-week-pts">
			<?php
			printf(
				/* translators: %s = formatted points number */
				esc_html__( '%s pts', 'wb-gamification' ),
				esc_html( $fmt_num( $recap['peak_week']['points'] ) )
			);
			?>
		</span>
	</div>
	<?php endif; ?>

	<!-- ── Top actions ────────────────────────────────────────────────── -->
	<?php if ( ! empty( $recap['top_actions'] ) ) : ?>
	<div class="wb-gam-recap__top-actions">
		<h3 class="wb-gam-recap__section-title"><?php esc_html_e( 'Most Active', 'wb-gamification' ); ?></h3>
		<ol class="wb-gam-recap__top-actions-list">
			<?php foreach ( $recap['top_actions'] as $action ) : ?>
			<li class="wb-gam-recap__top-action">
				<span class="wb-gam-recap__top-action-id"><?php echo esc_html( $action['action_id'] ); ?></span>
				<span class="wb-gam-recap__top-action-count">
					<?php
					printf(
						/* translators: %d = event count */
						esc_html( _n( '%d time', '%d times', $action['event_count'], 'wb-gamification' ) ),
						$action['event_count']
					);
					?>
				</span>
			</li>
			<?php endforeach; ?>
		</ol>
	</div>
	<?php endif; ?>

	<!-- ── Badges earned ──────────────────────────────────────────────── -->
	<?php if ( $show_badges && $recap['badges_earned']['count'] > 0 ) : ?>
	<div class="wb-gam-recap__badges">
		<h3 class="wb-gam-recap__section-title">
			<?php
			printf(
				/* translators: %d = badge count */
				esc_html( _n( '%d Badge Earned', '%d Badges Earned', $recap['badges_earned']['count'], 'wb-gamification' ) ),
				$recap['badges_earned']['count']
			);
			?>
		</h3>
		<div class="wb-gam-recap__badges-list">
			<?php foreach ( $recap['badges_earned']['badges'] as $badge ) : ?>
			<span class="wb-gam-recap__badge-pill" title="<?php echo esc_attr( $badge['earned_at'] ); ?>">
				<?php echo esc_html( $badge['name'] ); ?>
			</span>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- ── Share button ───────────────────────────────────────────────── -->
	<?php if ( $show_share && get_current_user_id() === $user_id ) : ?>
	<div class="wb-gam-recap__footer">
		<button
			class="wb-gam-recap__share-btn"
			type="button"
			data-recap-user="<?php echo esc_attr( $user_id ); ?>"
			data-recap-year="<?php echo esc_attr( $display_year ); ?>"
			onclick="
				if (navigator.share) {
					navigator.share({
						title: <?php echo wp_json_encode( sprintf( __( '%s — %d in Community', 'wb-gamification' ), $user->display_name, $display_year ) ); ?>,
						text: <?php echo wp_json_encode( $recap['headline'] ); ?>,
						url: window.location.href
					});
				} else {
					navigator.clipboard.writeText(window.location.href).then(function() {
						this.textContent = <?php echo wp_json_encode( __( 'Link copied!', 'wb-gamification' ) ); ?>;
					}.bind(this));
				}
			"
		>
			<?php esc_html_e( 'Share Your Year', 'wb-gamification' ); ?>
		</button>
	</div>
	<?php endif; ?>

</div>

<?php BlockHooks::after( 'year-recap', $attributes ); ?>
