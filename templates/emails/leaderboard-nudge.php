<?php
/**
 * Leaderboard nudge email template.
 *
 * Override by copying this file to YOUR-THEME/wb-gamification/emails/leaderboard-nudge.php
 * — see the Email::render() helper for resolution order.
 *
 * Available variables (extracted into local scope):
 *
 * @var \WP_User $user      Recipient user object.
 * @var string   $name      Escaped display name.
 * @var string   $site_name Site name (raw — escape on output).
 * @var string   $site_url  Site home URL.
 * @var string   $message   Pre-built nudge sentence (e.g. "You're #3 this week with 240 points.").
 * @var int|null $rank      Current leaderboard rank, null if not ranked.
 * @var int      $points    Points earned in the relevant window.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;">
	<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:24px 0;">
		<tr>
			<td align="center">
				<table cellpadding="0" cellspacing="0" border="0" width="600" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
					<tr>
						<td style="padding:32px 40px 24px;">
							<h1 style="margin:0 0 16px;font-size:22px;color:#0f172a;">
								<?php
								/* translators: %s: recipient display name */
								echo esc_html( sprintf( __( 'Hey %s,', 'wb-gamification' ), $name ) );
								?>
							</h1>
							<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
								<?php echo esc_html( $message ); ?>
							</p>
							<p style="margin:24px 0 0;">
								<a href="<?php echo esc_url( $site_url ); ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">
									<?php esc_html_e( 'View leaderboard →', 'wb-gamification' ); ?>
								</a>
							</p>
						</td>
					</tr>
					<tr>
						<td style="padding:16px 40px 32px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;text-align:center;">
							<?php
							/* translators: %s: site name */
							echo esc_html( sprintf( __( '%s gamification', 'wb-gamification' ), $site_name ) );
							?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
