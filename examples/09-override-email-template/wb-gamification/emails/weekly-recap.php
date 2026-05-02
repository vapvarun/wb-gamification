<?php
/**
 * Theme override for the WB Gamification weekly recap email.
 *
 * Place this file at:
 *   YOUR-THEME/wb-gamification/emails/weekly-recap.php
 *
 * The next weekly email send picks it up automatically. Child themes
 * win over parent themes (locate_template() handles that).
 *
 * Available variables (extracted into local scope by Email::render):
 *
 * @var \WP_User $user                  Recipient user object.
 * @var string   $name                  Escaped display name.
 * @var string   $site_name             Site name (raw — escape on output).
 * @var string   $site_url              Site home URL.
 * @var string   $unsub_url             Already-escaped unsubscribe URL.
 * @var int      $points_this_week      Points earned in the last 7 days.
 * @var int      $total_points          Lifetime point total.
 * @var bool     $is_best               True if this week beats personal best.
 * @var int      $best_week             Previous personal-best weekly total.
 * @var array    $badges_this_week      [{name, description}, ...].
 * @var array    $challenges_this_week  [{title}, ...].
 * @var array    $streak                ['current_streak' => int, 'longest_streak' => int].
 * @var int|null $rank                  Leaderboard rank, or null if user opted out.
 *
 * @package YourTheme
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $site_name ); ?></title>
<style>
/* Branded variant — your theme's colour, your theme's typography. */
body { margin:0; padding:0; font-family: 'Inter', sans-serif; background:#ffffff; color:#1a1a1a; }
.wrap { max-width:640px; margin:2rem auto; border:1px solid #e5e5e5; border-radius:12px; overflow:hidden; }
.header { background:#000; padding:3rem; text-align:center; color:#fff; }
.header h1 { margin:0; font-size:1.75rem; letter-spacing:-0.02em; }
.body { padding:3rem; line-height:1.6; }
.cta { display:inline-block; padding:.875rem 2.5rem; background:#000; color:#fff; border-radius:4px; text-decoration:none; font-weight:600; }
.footer { padding:1.5rem 3rem; font-size:.8125rem; color:#666; border-top:1px solid #e5e5e5; }
</style>
</head>
<body>
<div class="wrap">
	<div class="header">
		<h1><?php echo esc_html( $site_name ); ?></h1>
		<p style="margin:.5rem 0 0; opacity:.7;"><?php esc_html_e( 'Your week in review', 'your-theme' ); ?></p>
	</div>
	<div class="body">
		<p><?php
			echo wp_kses_post( sprintf(
				/* translators: %s = member display name */
				__( 'Hi %s,', 'your-theme' ),
				'<strong>' . $name . '</strong>'
			) );
		?></p>

		<p>
			<?php
			echo wp_kses_post( sprintf(
				/* translators: 1 = points earned, 2 = total points */
				__( 'You earned <strong>%1$s points</strong> this week — bringing your total to %2$s.', 'your-theme' ),
				number_format_i18n( $points_this_week ),
				number_format_i18n( $total_points )
			) );
			if ( $is_best ) {
				echo ' <strong>' . esc_html__( 'A new personal best!', 'your-theme' ) . '</strong>';
			}
			?>
		</p>

		<?php if ( $streak['current_streak'] >= 3 ) : ?>
			<p><?php
				echo esc_html( sprintf(
					/* translators: %d = streak days */
					__( 'You\'re on a %d-day streak.', 'your-theme' ),
					$streak['current_streak']
				) );
			?></p>
		<?php endif; ?>

		<?php if ( ! empty( $badges_this_week ) ) : ?>
			<p><strong><?php esc_html_e( 'New badges:', 'your-theme' ); ?></strong></p>
			<ul>
				<?php foreach ( $badges_this_week as $badge ) : ?>
					<li><?php echo esc_html( $badge['name'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p style="text-align:center; margin:2rem 0;">
			<a class="cta" href="<?php echo esc_url( $site_url ); ?>">
				<?php esc_html_e( 'Visit the site', 'your-theme' ); ?>
			</a>
		</p>
	</div>
	<div class="footer">
		<?php echo esc_html( $site_name ); ?> ·
		<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $unsub_url is already esc_url'd. */ ?>
		<a href="<?php echo $unsub_url; ?>" style="color:#666;"><?php esc_html_e( 'Unsubscribe', 'your-theme' ); ?></a>
	</div>
</div>
</body>
</html>
