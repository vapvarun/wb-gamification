<?php
/**
 * Weekly recap email template.
 *
 * Override by copying this file to YOUR-THEME/wb-gamification/emails/weekly-recap.php
 * — see the Email::render() helper for resolution order.
 *
 * Available variables (extracted into local scope):
 *
 * @var \WP_User $user                  Recipient user object.
 * @var string   $name                  Escaped display name.
 * @var string   $site_name             Site name (raw — escape on output).
 * @var string   $site_url              Site home URL.
 * @var string   $unsub_url             Already-escaped one-tap unsubscribe URL.
 * @var int      $points_this_week      Points earned in the last 7 days.
 * @var int      $total_points          Lifetime point total.
 * @var string   $points_label          Currency label resolved from the site's default point type (e.g. "Points", "Coins").
 * @var bool     $is_best               True if this week beats the personal best.
 * @var int      $best_week             Previous personal-best weekly total.
 * @var array    $badges_this_week      [{name, description}, ...] earned this week.
 * @var array    $challenges_this_week  [{title}, ...] completed this week.
 * @var array    $streak                ['current_streak' => int, 'longest_streak' => int].
 * @var int|null $rank                  Leaderboard rank, or null if user opted out.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

$points_line = sprintf(
	/* translators: 1: amount this week, 2: lower-cased currency label, 3: total amount, 4: lower-cased currency label */
	__( 'You earned <strong>%1$s %2$s</strong> this week (total: %3$s %4$s)', 'wb-gamification' ),
	number_format_i18n( $points_this_week ),
	esc_html( strtolower( $points_label ) ),
	number_format_i18n( $total_points ),
	esc_html( strtolower( $points_label ) )
);

if ( $is_best ) {
	$points_line .= ' — <strong style="color:#f59e0b;">🏆 ' . esc_html__( 'Personal best!', 'wb-gamification' ) . '</strong>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $site_name ); ?></title>
<style>
body { margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; color:#111827; }
.wrap { max-width:560px; margin:0 auto; }
.header { background:#6c63ff; padding:2rem; text-align:center; }
.header img { height:40px; }
.header h1 { color:#fff; margin:.5rem 0 0; font-size:1.25rem; }
.body { background:#fff; padding:2rem; }
.greeting { font-size:1.0625rem; margin-bottom:1.5rem; }
.stat { padding:1rem; border-radius:8px; background:#f9fafb; margin-bottom:1rem; }
.stat-label { font-size:0.75rem; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; margin:0 0 .25rem; }
.stat-value { font-size:1.25rem; font-weight:700; margin:0; }
.badge-list, .challenge-list { padding-left:1.25rem; margin:.5rem 0 0; }
.badge-list li, .challenge-list li { margin-bottom:.25rem; font-size:.9375rem; }
.streak { display:flex; align-items:center; gap:.5rem; }
.cta { display:block; text-align:center; margin:1.5rem 0; }
.cta a { display:inline-block; padding:.75rem 2rem; background:#6c63ff; color:#fff; border-radius:999px; text-decoration:none; font-weight:600; font-size:.9375rem; }
.footer { padding:1.5rem 2rem; font-size:.8125rem; color:#9ca3af; text-align:center; border-top:1px solid #f3f4f6; }
.footer a { color:#9ca3af; }
hr { border:none; border-top:1px solid #f3f4f6; margin:1rem 0; }
</style>
</head>
<body>
<div class="wrap">
	<div class="header">
		<h1><?php echo esc_html( $site_name ); ?></h1>
	</div>
	<div class="body">
		<p class="greeting">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s = member display name */
					__( 'Hey %s, here\'s your weekly gamification summary:', 'wb-gamification' ),
					'<strong>' . $name . '</strong>'
				)
			);
			?>
		</p>

		<!-- Points (or whatever the site's default currency is). -->
		<div class="stat">
			<p class="stat-label">⭐ <?php
				printf(
					/* translators: %s: currency label (e.g. "Points", "Coins"). */
					esc_html__( '%s this week', 'wb-gamification' ),
					esc_html( $points_label )
				);
			?></p>
			<p class="stat-value"><?php echo wp_kses_post( $points_line ); ?></p>
		</div>

		<!-- Streak -->
		<div class="stat">
			<p class="stat-label">🔥 <?php esc_html_e( 'Activity streak', 'wb-gamification' ); ?></p>
			<p class="stat-value">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1 = current streak, 2 = longest streak */
						__( '%1$d-day streak (best: %2$d days)', 'wb-gamification' ),
						$streak['current_streak'],
						$streak['longest_streak']
					)
				);
				?>
			</p>
		</div>

		<?php if ( null !== $rank ) : ?>
		<!-- Rank -->
		<div class="stat">
			<p class="stat-label">🏅 <?php esc_html_e( 'Your leaderboard rank', 'wb-gamification' ); ?></p>
			<p class="stat-value">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d = rank number */
						__( '#%d overall', 'wb-gamification' ),
						$rank
					)
				);
				?>
			</p>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $badges_this_week ) ) : ?>
		<!-- Badges -->
		<div class="stat">
			<p class="stat-label">🎖 <?php esc_html_e( 'Badges earned this week', 'wb-gamification' ); ?></p>
			<ul class="badge-list">
				<?php foreach ( $badges_this_week as $badge ) : ?>
					<li><strong><?php echo esc_html( $badge['name'] ); ?></strong><?php echo $badge['description'] ? ' — ' . esc_html( $badge['description'] ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $challenges_this_week ) ) : ?>
		<!-- Challenges -->
		<div class="stat">
			<p class="stat-label">🎯 <?php esc_html_e( 'Challenges completed', 'wb-gamification' ); ?></p>
			<ul class="challenge-list">
				<?php foreach ( $challenges_this_week as $ch ) : ?>
					<li><?php echo esc_html( $ch['title'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<div class="cta">
			<a href="<?php echo esc_url( $site_url ); ?>"><?php esc_html_e( 'Keep the momentum going →', 'wb-gamification' ); ?></a>
		</div>
	</div><!-- .body -->

	<div class="footer">
		<p>
			<?php echo esc_html( $site_name ); ?> &bull;
			<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $unsub_url is already esc_url'd by the engine. */ ?>
			<a href="<?php echo $unsub_url; ?>"><?php esc_html_e( 'Unsubscribe from weekly emails', 'wb-gamification' ); ?></a>
		</p>
	</div>
</div>
</body>
</html>
