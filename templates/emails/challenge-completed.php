<?php
/**
 * Challenge-completed email template.
 *
 * Override by copying this file to YOUR-THEME/wb-gamification/emails/challenge-completed.php
 *
 * Available variables (extracted into local scope):
 *
 * @var \WP_User $user                  Recipient user object.
 * @var string   $name                  Already-escaped display name.
 * @var string   $site_name             Site name (raw — escape on output).
 * @var string   $site_url              Site home URL.
 * @var string   $challenge_title       Challenge title.
 * @var string   $challenge_description Challenge description.
 * @var string   $reward_label          Reward summary string (e.g. "100 Points") — empty if no reward.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f4f4f5;color:#111827;">
	<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f4f5;padding:24px 0;">
		<tr><td align="center">
			<table cellpadding="0" cellspacing="0" border="0" width="600" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
				<tr><td style="padding:32px 40px;">
					<h1 style="margin:0 0 16px;font-size:22px;color:#0f172a;">
						<?php
						/* translators: %s: recipient display name */
						echo esc_html( sprintf( __( 'Hey %s,', 'wb-gamification' ), $name ) );
						?>
					</h1>
					<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
						<?php
						printf(
							/* translators: %s: challenge title */
							esc_html__( 'You completed the %s challenge!', 'wb-gamification' ),
							'<strong>' . esc_html( $challenge_title ) . '</strong>'
						);
						?>
					</p>
					<?php if ( $challenge_description ) : ?>
						<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#64748b;">
							<?php echo esc_html( $challenge_description ); ?>
						</p>
					<?php endif; ?>
					<?php if ( $reward_label ) : ?>
						<div style="background:#f9fafb;border-radius:8px;padding:16px;margin:16px 0;">
							<p style="margin:0;font-size:13px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'Reward earned', 'wb-gamification' ); ?></p>
							<p style="margin:4px 0 0;font-size:20px;font-weight:700;color:#0f172a;">
								<?php echo esc_html( $reward_label ); ?>
							</p>
						</div>
					<?php endif; ?>
					<p style="margin:24px 0 0;">
						<a href="<?php echo esc_url( $site_url ); ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">
							<?php esc_html_e( 'View your dashboard →', 'wb-gamification' ); ?>
						</a>
					</p>
				</td></tr>
				<tr><td style="padding:14px 40px 28px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;text-align:center;">
					<?php
					/* translators: %s: site name */
					echo esc_html( sprintf( __( '%s gamification', 'wb-gamification' ), $site_name ) );
					?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
