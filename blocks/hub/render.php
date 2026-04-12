<?php
/**
 * Gamification Hub block render callback.
 *
 * Renders the full member dashboard — nudge bar, stats row,
 * card grid, template tags for sub-blocks, and slide-in panel.
 *
 * @package WB_Gamification
 * @since   1.0.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

use WBGam\Engine\NudgeEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\StreakEngine;
use WBGam\Engine\ChallengeEngine;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\KudosEngine;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

wp_enqueue_style( 'wb-gamification-hub' );
wp_enqueue_script_module( 'wb-gamification-hub' );

$user_id = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only panel pre-open from URL.
$pre_open = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : '';

/*
 * ─── Guest state ───
 */
if ( 0 === $user_id ) {
	$wrapper_attrs = get_block_wrapper_attributes(
		array( 'class' => 'gam-page gam-page--guest' )
	);
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="gam-nudge">
			<span class="gam-nudge__icon"><i class="icon-log-in"></i></span>
			<div class="gam-nudge__body">
				<span class="gam-nudge__label"><?php esc_html_e( 'Join the community', 'wb-gamification' ); ?></span>
				<span class="gam-nudge__text"><?php esc_html_e( 'Log in to track your points, earn badges, and climb the leaderboard.', 'wb-gamification' ); ?></span>
			</div>
			<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="gam-nudge__action">
				<?php esc_html_e( 'Log in', 'wb-gamification' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
}

/*
 * ─── Data collection ───
 */
$nudge     = NudgeEngine::get_nudge( $user_id );
$total_pts = PointsEngine::get_total( $user_id );
$level     = LevelEngine::get_level_for_user( $user_id );
$next_lvl  = LevelEngine::get_next_level( $user_id );
$progress  = LevelEngine::get_progress_percent( $user_id );
$badges    = BadgeEngine::get_user_badges( $user_id );
$streak    = StreakEngine::get_streak( $user_id );
$challenges = ChallengeEngine::get_active_challenges( $user_id );
$rank_data  = LeaderboardEngine::get_user_rank( $user_id, 'week' );
$rank       = (int) ( $rank_data['rank'] ?? 0 );
$kudos_recv = KudosEngine::get_received_count( $user_id );

$badge_count = count( $badges );

// Total badge definitions for locked count.
global $wpdb;
$total_badge_defs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$locked_count     = max( 0, $total_badge_defs - $badge_count );

$current_streak = (int) ( $streak['current_streak'] ?? 0 );
$longest_streak = (int) ( $streak['longest_streak'] ?? 0 );
$active_count   = count( $challenges );

$level_name = $level['name'] ?? __( 'Newcomer', 'wb-gamification' );

/*
 * ─── Card definitions ───
 */
$cards = array(
	'badges'      => array(
		'icon'       => 'award',
		'title'      => __( 'My Badges', 'wb-gamification' ),
		'desc'       => sprintf(
			/* translators: 1: earned badge count, 2: locked badge count */
			__( '%1$d earned, %2$d locked', 'wb-gamification' ),
			$badge_count,
			$locked_count
		),
		'pill'       => $badge_count > 0
			? array(
				'text'  => (string) $badge_count,
				'class' => 'gam-pill--success',
			)
			: null,
		'block_slug' => 'badge-showcase',
		'block_attrs' => array( 'show_locked' => true ),
	),
	'challenges'  => array(
		'icon'       => 'target',
		'title'      => __( 'Challenges', 'wb-gamification' ),
		'desc'       => sprintf(
			/* translators: %d: number of active challenges */
			_n( '%d active challenge', '%d active challenges', $active_count, 'wb-gamification' ),
			$active_count
		),
		'pill'       => $active_count > 0
			? array(
				'text'  => (string) $active_count,
				'class' => 'gam-pill--warning',
			)
			: null,
		'block_slug' => 'challenges',
		'block_attrs' => array(),
	),
	'leaderboard' => array(
		'icon'       => 'trophy',
		'title'      => __( 'Leaderboard', 'wb-gamification' ),
		'desc'       => $rank
			? sprintf(
				/* translators: %s: rank position (e.g. "#3") */
				__( 'You\'re ranked %s this week', 'wb-gamification' ),
				'#' . number_format_i18n( $rank )
			)
			: __( 'Not ranked yet this week', 'wb-gamification' ),
		'pill'       => $rank
			? array(
				'text'  => '#' . number_format_i18n( $rank ),
				'class' => 'gam-pill--accent',
			)
			: null,
		'block_slug' => 'leaderboard',
		'block_attrs' => array( 'period' => 'week' ),
	),
	'earning'     => array(
		'icon'       => 'lightbulb',
		'title'      => __( 'How to Earn', 'wb-gamification' ),
		'desc'       => sprintf(
			/* translators: %d: number of available actions */
			__( '%d ways to earn points', 'wb-gamification' ),
			count( Registry::get_actions() )
		),
		'pill'       => null,
		'block_slug' => 'earning-guide',
		'block_attrs' => array(),
	),
	'kudos'       => array(
		'icon'       => 'heart-handshake',
		'title'      => __( 'Kudos', 'wb-gamification' ),
		'desc'       => sprintf(
			/* translators: %d: number of kudos received */
			_n( '%d kudos received', '%d kudos received', $kudos_recv, 'wb-gamification' ),
			$kudos_recv
		),
		'pill'       => $kudos_recv > 0
			? array(
				'text'  => (string) $kudos_recv,
				'class' => 'gam-pill--info',
			)
			: null,
		'block_slug' => 'kudos-feed',
		'block_attrs' => array(),
	),
	'history'     => array(
		'icon'       => 'history',
		'title'      => __( 'Activity', 'wb-gamification' ),
		'desc'       => __( 'Your recent point activity', 'wb-gamification' ),
		'pill'       => null,
		'block_slug' => 'points-history',
		'block_attrs' => array(),
	),
);

/*
 * ─── Interactivity API context ───
 */
$context = wp_json_encode( array( 'preOpen' => $pre_open ) );

$wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class'               => 'gam-page',
		'data-wp-interactive' => 'wb-gamification/hub',
		'data-wp-context'     => $context,
		'data-wp-init'        => 'callbacks.init',
	)
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php
	/*
	 * ─── Nudge bar ───
	 */
	if ( ! empty( $nudge['message'] ) ) :
		$nudge_icon  = $nudge['icon'] ?? 'sparkles';
		$nudge_panel = $nudge['panel'] ?? '';
		?>
		<div class="gam-nudge">
			<span class="gam-nudge__icon"><i class="icon-<?php echo esc_attr( $nudge_icon ); ?>"></i></span>
			<div class="gam-nudge__body">
				<span class="gam-nudge__label"><?php esc_html_e( 'Suggested for you', 'wb-gamification' ); ?></span>
				<span class="gam-nudge__text"><?php echo esc_html( $nudge['message'] ); ?></span>
			</div>
			<?php if ( $nudge_panel ) : ?>
				<button
					class="gam-nudge__action"
					data-wp-on--click="actions.openPanel"
					data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'panel' => $nudge_panel ) ) ); ?>"
				>
					<?php esc_html_e( 'Let\'s go', 'wb-gamification' ); ?>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	/*
	 * ─── Stats row ───
	 */
	?>
	<div class="gam-stats">
		<?php // Stat 1: Total Points. ?>
		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-star"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $total_pts ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Total Points', 'wb-gamification' ); ?></span>
		</div>

		<?php // Stat 2: Current Level + progress bar. ?>
		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-trending-up"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( $level_name ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Current Level', 'wb-gamification' ); ?></span>
			<div class="gam-stat__bar">
				<div class="gam-stat__bar-fill" style="width:<?php echo esc_attr( $progress ); ?>%"></div>
			</div>
			<?php if ( $next_lvl ) : ?>
				<span class="gam-stat__sub">
					<?php
					printf(
						/* translators: %s: next level name */
						esc_html__( 'Next: %s', 'wb-gamification' ),
						esc_html( $next_lvl['name'] )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php // Stat 3: Badges Earned. ?>
		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-award"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $badge_count ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Badges Earned', 'wb-gamification' ); ?></span>
		</div>

		<?php // Stat 4: Day Streak. ?>
		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-flame"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $current_streak ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Day Streak', 'wb-gamification' ); ?></span>
			<span class="gam-stat__sub">
				<?php
				printf(
					/* translators: %s: longest streak number */
					esc_html__( 'Best: %s days', 'wb-gamification' ),
					esc_html( number_format_i18n( $longest_streak ) )
				);
				?>
			</span>
		</div>
	</div>

	<?php
	/*
	 * ─── Card grid ───
	 */
	?>
	<div class="gam-cards">
		<?php foreach ( $cards as $key => $card ) : ?>
			<div
				class="gam-card"
				data-wp-on--click="actions.openPanel"
				data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'panel' => $key ) ) ); ?>"
			>
				<div class="gam-card__head">
					<span class="gam-card__icon"><i class="icon-<?php echo esc_attr( $card['icon'] ); ?>"></i></span>
					<span class="gam-card__title"><?php echo esc_html( $card['title'] ); ?></span>
					<?php if ( ! empty( $card['pill'] ) ) : ?>
						<span class="gam-pill <?php echo esc_attr( $card['pill']['class'] ); ?>">
							<?php echo esc_html( $card['pill']['text'] ); ?>
						</span>
					<?php endif; ?>
				</div>
				<p class="gam-card__desc"><?php echo esc_html( $card['desc'] ); ?></p>
				<span class="gam-card__link"><?php echo wp_kses_post( sprintf( __( 'View %s', 'wb-gamification' ), '&rarr;' ) ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php
	/*
	 * ─── Template tags (sub-block rendered content for panel injection) ───
	 */
	foreach ( $cards as $key => $card ) :
		$block_markup = render_block(
			array(
				'blockName' => 'wb-gamification/' . $card['block_slug'],
				'attrs'     => $card['block_attrs'],
			)
		);
		?>
		<template id="gam-tpl-<?php echo esc_attr( $key ); ?>">
			<?php echo $block_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output is already escaped by each block's render callback. ?>
		</template>
	<?php endforeach; ?>

	<?php
	/*
	 * ─── Panel markup (slide-in) ───
	 */
	?>
	<div
		class="gam-panel-backdrop"
		data-wp-class--active="state.panelOpen"
		data-wp-on--click="actions.closePanel"
	>
		<div
			class="gam-panel"
			role="dialog"
			aria-modal="true"
			data-wp-on--click="actions.stopPropagation"
		>
			<div class="gam-panel__header">
				<button class="gam-panel__back" data-wp-on--click="actions.closePanel">
					<i class="icon-arrow-left"></i>
					<span class="screen-reader-text"><?php esc_html_e( 'Close panel', 'wb-gamification' ); ?></span>
				</button>
				<span class="gam-panel__title" data-wp-text="state.panelTitle"></span>
			</div>
			<div class="gam-panel__body" id="gam-panel-body"></div>
		</div>
	</div>

</div>
