<?php
/**
 * Redemption-confirmed email template.
 *
 * Override by copying this file to YOUR-THEME/wb-gamification/emails/redemption-confirmed.php
 *
 * Available variables (extracted into local scope):
 *
 * @var \WP_User $user           Recipient user object.
 * @var string   $name           Already-escaped display name.
 * @var string   $site_name      Site name (raw — escape on output).
 * @var string   $site_url       Site home URL.
 * @var int      $redemption_id  Row id in `wb_gam_redemptions`.
 * @var string   $reward_title   Reward item title.
 * @var string   $reward_type    discount_pct | discount_fixed | free_shipping | free_product | wbcom_credits | custom.
 * @var int      $points_spent   Points debited from the member.
 * @var string   $points_label   Localised plural label (e.g. "XP", "Points").
 * @var string   $coupon_code    Generated WooCommerce coupon code, or empty for non-WC rewards.
 * @var int      $remaining      Member's balance AFTER the debit (in the same point type).
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
						echo esc_html( sprintf( __( 'Thanks for redeeming, %s!', 'wb-gamification' ), $name ) );
						?>
					</h1>
					<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
						<?php
						printf(
							/* translators: %s: reward title */
							esc_html__( 'Your redemption of %s is confirmed.', 'wb-gamification' ),
							'<strong>' . esc_html( $reward_title ) . '</strong>'
						);
						?>
					</p>

					<div style="background:#f9fafb;border-radius:8px;padding:18px 20px;margin:16px 0;">
						<p style="margin:0 0 6px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'Receipt', 'wb-gamification' ); ?></p>
						<p style="margin:0 0 4px;font-size:14px;color:#0f172a;">
							<?php
							printf(
								/* translators: 1: points spent, 2: localised label, 3: reward title */
								esc_html__( '%1$d %2$s spent on %3$s', 'wb-gamification' ),
								(int) $points_spent,
								esc_html( $points_label ),
								esc_html( $reward_title )
							);
							?>
						</p>
						<p style="margin:0;font-size:13px;color:#64748b;">
							<?php
							printf(
								/* translators: 1: remaining points, 2: localised label */
								esc_html__( 'Remaining balance: %1$d %2$s', 'wb-gamification' ),
								(int) $remaining,
								esc_html( $points_label )
							);
							?>
						</p>
					</div>

					<?php if ( '' !== $coupon_code ) : ?>
						<div style="background:#ecfdf5;border:1px dashed #34d399;border-radius:8px;padding:18px 20px;margin:16px 0;text-align:center;">
							<p style="margin:0 0 6px;font-size:12px;color:#059669;text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'Use this code at checkout', 'wb-gamification' ); ?></p>
							<p style="margin:0;font-family:'SFMono-Regular',Menlo,Consolas,monospace;font-size:22px;font-weight:700;letter-spacing:.08em;color:#065f46;">
								<?php echo esc_html( $coupon_code ); ?>
							</p>
						</div>
					<?php elseif ( in_array( $reward_type, array( 'custom' ), true ) ) : ?>
						<p style="margin:8px 0 0;font-size:14px;line-height:1.6;color:#64748b;">
							<?php esc_html_e( 'Our team will follow up with fulfilment details shortly.', 'wb-gamification' ); ?>
						</p>
					<?php endif; ?>

					<p style="margin:24px 0 0;">
						<a href="<?php echo esc_url( $site_url ); ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">
							<?php esc_html_e( 'View your dashboard →', 'wb-gamification' ); ?>
						</a>
					</p>
				</td></tr>
				<tr><td style="padding:14px 40px 28px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;text-align:center;">
					<?php
					/* translators: 1: site name, 2: redemption id */
					echo esc_html( sprintf( __( '%1$s gamification · ref #%2$d', 'wb-gamification' ), $site_name, (int) $redemption_id ) );
					?>
				</td></tr>
			</table>
		</td></tr>
	</table>
				<?php
				/**
				 * Fires just before the end of a gamification email body, for
				 * appending footer content (unsubscribe line, agency branding).
				 * The template's variables are available via the second arg.
				 *
				 * @since 1.6.2
				 * @param string $wb_gam_email_slug Template slug.
				 * @param array  $wb_gam_email_vars Template variables in scope.
				 */
				do_action( 'wb_gam_email_footer', basename( __FILE__, '.php' ), get_defined_vars() );
				?>
</body>
</html>
