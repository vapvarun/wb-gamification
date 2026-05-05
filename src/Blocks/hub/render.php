<?php
/**
 * Gamification Hub block — Wbcom Block Quality Standard render.
 *
 * Phase D.5 migration: per-instance scoped CSS, design tokens via
 * `wb-gam-tokens`, `.wb-gam-block-{uniqueId}` wrapper class — without
 * disturbing the existing Interactivity API store wired up in
 * `assets/interactivity/index.js` (preserved until Phase E).
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\ChallengeEngine;
use WBGam\Engine\KudosEngine;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\NudgeEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;
use WBGam\Engine\StreakEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

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

$wb_gam_classes = array_filter(
	array(
		'gam-page',
		'wb-gam-hub',
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

wp_enqueue_style( 'wb-gam-tokens' );
wp_enqueue_style( 'wb-gamification-hub' );
wp_enqueue_script_module( 'wb-gamification-hub' );

$wb_gam_user_id = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only panel pre-open from URL.
$wb_gam_pre_open = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : '';

if ( 0 === $wb_gam_user_id ) {
	$wb_gam_classes[] = 'gam-page--guest';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	?>
	<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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

$wb_gam_nudge      = NudgeEngine::get_nudge( $wb_gam_user_id );
$wb_gam_total_pts  = (int) PointsEngine::get_total( $wb_gam_user_id );
$wb_gam_level      = LevelEngine::get_level_for_user( $wb_gam_user_id );
$wb_gam_next_lvl   = LevelEngine::get_next_level( $wb_gam_user_id );
$wb_gam_progress   = (int) LevelEngine::get_progress_percent( $wb_gam_user_id );
$wb_gam_badges     = BadgeEngine::get_user_badges( $wb_gam_user_id );
$wb_gam_streak     = StreakEngine::get_streak( $wb_gam_user_id );
$wb_gam_challenges = ChallengeEngine::get_active_challenges( $wb_gam_user_id );
$wb_gam_rank_data  = LeaderboardEngine::get_user_rank( $wb_gam_user_id, 'week' );
$wb_gam_rank       = (int) ( $wb_gam_rank_data['rank'] ?? 0 );
$wb_gam_kudos_recv = (int) KudosEngine::get_received_count( $wb_gam_user_id );
$wb_gam_badge_cnt  = count( $wb_gam_badges );

global $wpdb;
$wb_gam_total_defs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wb_gam_locked     = max( 0, $wb_gam_total_defs - $wb_gam_badge_cnt );

$wb_gam_current_streak = (int) ( $wb_gam_streak['current_streak'] ?? 0 );
$wb_gam_longest_streak = (int) ( $wb_gam_streak['longest_streak'] ?? 0 );
$wb_gam_active_count   = count( $wb_gam_challenges );
$wb_gam_level_name     = (string) ( $wb_gam_level['name'] ?? __( 'Newcomer', 'wb-gamification' ) );

$wb_gam_cards = array(
	'badges'      => array(
		'icon'        => 'award',
		'title'       => __( 'My Badges', 'wb-gamification' ),
		'desc'        => sprintf(
			/* translators: 1: earned badge count, 2: locked badge count */
			__( '%1$d earned, %2$d locked', 'wb-gamification' ),
			$wb_gam_badge_cnt,
			$wb_gam_locked
		),
		'pill'        => $wb_gam_badge_cnt > 0
			? array( 'text' => (string) $wb_gam_badge_cnt, 'class' => 'gam-pill--success' )
			: null,
		'block_slug'  => 'badge-showcase',
		'block_attrs' => array( 'show_locked' => true ),
	),
	'challenges'  => array(
		'icon'        => 'target',
		'title'       => __( 'Challenges', 'wb-gamification' ),
		'desc'        => sprintf(
			/* translators: %d: number of active challenges */
			_n( '%d active challenge', '%d active challenges', $wb_gam_active_count, 'wb-gamification' ),
			$wb_gam_active_count
		),
		'pill'        => $wb_gam_active_count > 0
			? array( 'text' => (string) $wb_gam_active_count, 'class' => 'gam-pill--warning' )
			: null,
		'block_slug'  => 'challenges',
		'block_attrs' => array(),
	),
	'leaderboard' => array(
		'icon'        => 'trophy',
		'title'       => __( 'Leaderboard', 'wb-gamification' ),
		'desc'        => $wb_gam_rank
			? sprintf(
				/* translators: %s: rank position (e.g. "#3") */
				__( "You're ranked %s this week", 'wb-gamification' ),
				'#' . number_format_i18n( $wb_gam_rank )
			)
			: __( 'Not ranked yet this week', 'wb-gamification' ),
		'pill'        => $wb_gam_rank
			? array( 'text' => '#' . number_format_i18n( $wb_gam_rank ), 'class' => 'gam-pill--accent' )
			: null,
		'block_slug'  => 'leaderboard',
		'block_attrs' => array( 'period' => 'week' ),
	),
	'earning'     => array(
		'icon'        => 'lightbulb',
		'title'       => __( 'How to Earn', 'wb-gamification' ),
		'desc'        => sprintf(
			/* translators: %d: number of available actions */
			__( '%d ways to earn points', 'wb-gamification' ),
			count( Registry::get_actions() )
		),
		'pill'        => null,
		'block_slug'  => 'earning-guide',
		'block_attrs' => array(),
	),
	'kudos'       => array(
		'icon'        => 'heart-handshake',
		'title'       => __( 'Kudos', 'wb-gamification' ),
		'desc'        => sprintf(
			/* translators: %d: number of kudos received */
			_n( '%d kudos received', '%d kudos received', $wb_gam_kudos_recv, 'wb-gamification' ),
			$wb_gam_kudos_recv
		),
		'pill'        => $wb_gam_kudos_recv > 0
			? array( 'text' => (string) $wb_gam_kudos_recv, 'class' => 'gam-pill--info' )
			: null,
		'block_slug'  => 'kudos-feed',
		'block_attrs' => array(),
	),
	'history'     => array(
		'icon'        => 'history',
		'title'       => __( 'Activity', 'wb-gamification' ),
		'desc'        => __( 'Your recent point activity', 'wb-gamification' ),
		'pill'        => null,
		'block_slug'  => 'points-history',
		'block_attrs' => array(),
	),
);

$wb_gam_context = wp_json_encode( array( 'preOpen' => $wb_gam_pre_open ) );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class'               => implode( ' ', $wb_gam_classes ),
		'style'               => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		'data-wp-interactive' => 'wb-gamification/hub',
		'data-wp-context'     => $wb_gam_context,
		'data-wp-init'        => 'callbacks.init',
	)
);

BlockHooks::before( 'hub', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( ! empty( $wb_gam_nudge['message'] ) ) :
		$wb_gam_nudge_icon  = (string) ( $wb_gam_nudge['icon'] ?? 'sparkles' );
		$wb_gam_nudge_panel = (string) ( $wb_gam_nudge['panel'] ?? '' );
		?>
		<div class="gam-nudge">
			<span class="gam-nudge__icon"><i class="icon-<?php echo esc_attr( $wb_gam_nudge_icon ); ?>"></i></span>
			<div class="gam-nudge__body">
				<span class="gam-nudge__label"><?php esc_html_e( 'Suggested for you', 'wb-gamification' ); ?></span>
				<span class="gam-nudge__text"><?php echo esc_html( (string) $wb_gam_nudge['message'] ); ?></span>
			</div>
			<?php if ( $wb_gam_nudge_panel ) : ?>
				<button class="gam-nudge__action"
					data-wp-on--click="actions.openPanel"
					data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'panel' => $wb_gam_nudge_panel ) ) ); ?>">
					<?php esc_html_e( "Let's go", 'wb-gamification' ); ?>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="gam-stats">
		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-star"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $wb_gam_total_pts ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Total Points', 'wb-gamification' ); ?></span>
		</div>

		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-trending-up"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( $wb_gam_level_name ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Current Level', 'wb-gamification' ); ?></span>
			<div class="gam-stat__bar">
				<div class="gam-stat__bar-fill" style="width:<?php echo esc_attr( (string) $wb_gam_progress ); ?>%"></div>
			</div>
			<?php if ( $wb_gam_next_lvl ) : ?>
				<span class="gam-stat__sub">
					<?php
					/* translators: %s: next level name */
					printf( esc_html__( 'Next: %s', 'wb-gamification' ), esc_html( (string) $wb_gam_next_lvl['name'] ) );
					?>
				</span>
			<?php endif; ?>
		</div>

		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-award"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $wb_gam_badge_cnt ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Badges Earned', 'wb-gamification' ); ?></span>
		</div>

		<div class="gam-stat">
			<span class="gam-stat__icon"><i class="icon-flame"></i></span>
			<span class="gam-stat__value"><?php echo esc_html( number_format_i18n( $wb_gam_current_streak ) ); ?></span>
			<span class="gam-stat__label"><?php esc_html_e( 'Day Streak', 'wb-gamification' ); ?></span>
			<span class="gam-stat__sub">
				<?php
				/* translators: %s: longest streak number */
				printf( esc_html( _n( 'Best: %s day', 'Best: %s days', $wb_gam_longest_streak, 'wb-gamification' ) ), esc_html( number_format_i18n( $wb_gam_longest_streak ) ) );
				?>
			</span>
		</div>
	</div>

	<div class="gam-cards">
		<?php foreach ( $wb_gam_cards as $wb_gam_key => $wb_gam_card ) : ?>
			<div class="gam-card"
				data-wp-on--click="actions.openPanel"
				data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'panel' => $wb_gam_key ) ) ); ?>">
				<div class="gam-card__head">
					<span class="gam-card__icon"><i class="icon-<?php echo esc_attr( (string) $wb_gam_card['icon'] ); ?>"></i></span>
					<span class="gam-card__title"><?php echo esc_html( (string) $wb_gam_card['title'] ); ?></span>
					<?php if ( ! empty( $wb_gam_card['pill'] ) ) : ?>
						<span class="gam-pill <?php echo esc_attr( (string) $wb_gam_card['pill']['class'] ); ?>">
							<?php echo esc_html( (string) $wb_gam_card['pill']['text'] ); ?>
						</span>
					<?php endif; ?>
				</div>
				<p class="gam-card__desc"><?php echo esc_html( (string) $wb_gam_card['desc'] ); ?></p>
				<span class="gam-card__link">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: HTML right-arrow entity displayed inline as the "view" affordance */
							__( 'View %s', 'wb-gamification' ),
							'&rarr;'
						)
					);
					?>
				</span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $wb_gam_cards as $wb_gam_key => $wb_gam_card ) :
		$wb_gam_block_markup = render_block(
			array(
				'blockName' => 'wb-gamification/' . $wb_gam_card['block_slug'],
				'attrs'     => $wb_gam_card['block_attrs'],
			)
		);
		?>
		<template id="gam-tpl-<?php echo esc_attr( (string) $wb_gam_key ); ?>">
			<?php echo $wb_gam_block_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output is already escaped by each block's render callback. ?>
		</template>
	<?php endforeach; ?>

	<div class="gam-panel-backdrop"
		data-wp-class--active="state.panelOpen"
		data-wp-on--click="actions.closePanel">
		<div class="gam-panel"
			role="dialog"
			aria-modal="true"
			data-wp-on--click="actions.stopPropagation">
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
<?php
BlockHooks::after( 'hub', $wb_gam_attrs );
