<?php
/**
 * Badge-earned email template.
 *
 * Override by copying this file to YOUR-THEME/wb-gamification/emails/badge-earned.php
 *
 * Available variables (extracted into local scope):
 *
 * @var \WP_User $user              Recipient user object.
 * @var string   $name              Already-escaped display name.
 * @var string   $site_name         Site name (raw — escape on output).
 * @var string   $site_url          Site home URL.
 * @var string   $badge_id          Badge slug.
 * @var string   $badge_name        Badge name.
 * @var string   $badge_description Badge description.
 * @var string   $badge_image_url   Badge image URL.
 * @var string   $share_url         Public share URL for the earned badge.
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
							/* translators: %s: badge name */
							esc_html__( 'You just earned the %s badge!', 'wb-gamification' ),
							'<strong>' . esc_html( $badge_name ) . '</strong>'
						);
						?>
					</p>
					<?php if ( $badge_image_url ) : ?>
						<div style="text-align:center;margin:24px 0;">
							<img src="<?php echo esc_url( $badge_image_url ); ?>" alt="<?php echo esc_attr( $badge_name ); ?>" width="120" height="120" style="border-radius:8px;">
						</div>
					<?php endif; ?>
					<?php if ( $badge_description ) : ?>
						<p style="margin:16px 0;font-size:14px;line-height:1.6;color:#64748b;text-align:center;">
							<?php echo esc_html( $badge_description ); ?>
						</p>
					<?php endif; ?>
					<p style="margin:24px 0 0;text-align:center;">
						<a href="<?php echo esc_url( $share_url ); ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">
							<?php esc_html_e( 'Share your badge', 'wb-gamification' ); ?>
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
