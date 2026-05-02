<?php
/**
 * Plugin Name: Redemption — Manual fulfilment queue
 * Description: For physical goods, gift cards, or anything that needs human action. Emails the site admin on each redemption + adds an admin page where they can mark items shipped. Drop this file into wp-content/plugins/redemption-manual-queue/your-plugin.php.
 * Version: 1.0.0
 *
 * Why: not every reward has an API to call. Branded swag, Amazon gift cards
 * delivered by hand, mentor calls — all need a human in the loop. This
 * listener gives the admin a queue to work off of.
 *
 * What it does:
 *
 *   1. On every `custom`-type redemption it emails the site admin with the
 *      member's details + the reward title.
 *   2. Adds `Tools → Manual Redemption Queue` showing all redemptions with
 *      status `pending_fulfillment`. Admin clicks "Mark as fulfilled" once
 *      the gift card is sent / package is shipped.
 *
 * No new tables — uses the engine's existing `wb_gam_redemptions` table.
 */

defined( 'ABSPATH' ) || exit;

const RMQ_NONCE = 'rmq_mark_fulfilled';

// 1. Notify on redemption.
add_action(
	'wb_gamification_points_redeemed',
	function ( $redemption_id, $user_id, $item, $coupon_code ) {
		if ( 'custom' !== ( $item['reward_type'] ?? '' ) ) {
			return;
		}

		$user  = get_userdata( $user_id );
		$admin = get_option( 'admin_email' );
		if ( ! $user || ! $admin ) {
			return;
		}

		$queue_url = admin_url( 'tools.php?page=rmq-queue' );
		$body      = sprintf(
			"%s redeemed \"%s\" (%d points).\n\nMember email: %s\nRedemption ID: #%d\n\nMark fulfilled here:\n%s",
			$user->display_name,
			$item['title'],
			(int) $item['points_cost'],
			$user->user_email,
			(int) $redemption_id,
			$queue_url
		);

		wp_mail( $admin, sprintf( '[Redemption] %s', $item['title'] ), $body );
	},
	10,
	4
);

// 2. Admin queue page.
add_action( 'admin_menu', function () {
	add_management_page(
		__( 'Manual Redemption Queue', 'rmq' ),
		__( 'Redemption Queue', 'rmq' ),
		'manage_options',
		'rmq-queue',
		'rmq_render_page'
	);
} );

// 3. Render.
function rmq_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'rmq' ) );
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT r.id, r.user_id, r.points_cost, r.status, r.created_at,
		        i.title
		   FROM {$wpdb->prefix}wb_gam_redemptions r
		   JOIN {$wpdb->prefix}wb_gam_redemption_items i ON i.id = r.item_id
		  WHERE r.status = 'pending_fulfillment'
		  ORDER BY r.created_at ASC",
		ARRAY_A
	) ?: array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Manual Redemption Queue', 'rmq' ); ?></h1>
		<p><?php esc_html_e( 'Items here are paid for and waiting for human fulfilment (mailing, manual gift-card send, etc).', 'rmq' ); ?></p>

		<?php if ( empty( $rows ) ) : ?>
			<p><em><?php esc_html_e( 'Queue is empty.', 'rmq' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'rmq' ); ?></th>
						<th><?php esc_html_e( 'Member', 'rmq' ); ?></th>
						<th><?php esc_html_e( 'Reward', 'rmq' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'rmq' ); ?></th>
						<th><?php esc_html_e( 'Action', 'rmq' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$user = get_userdata( (int) $row['user_id'] );
					$mark_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=rmq_mark_fulfilled&id=' . (int) $row['id'] ),
						RMQ_NONCE
					);
					?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?> (<?php echo esc_html( $user ? $user->user_email : '' ); ?>)</td>
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td><?php echo (int) $row['points_cost']; ?> pts</td>
						<td><a class="button button-primary" href="<?php echo esc_url( $mark_url ); ?>"><?php esc_html_e( 'Mark fulfilled', 'rmq' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// 4. Mark-fulfilled handler.
add_action( 'admin_post_rmq_mark_fulfilled', function () {
	check_admin_referer( RMQ_NONCE );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'rmq' ) );
	}
	$id = absint( $_GET['id'] ?? 0 );
	if ( $id > 0 ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_redemptions',
			array( 'status' => 'fulfilled' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
	wp_safe_redirect( admin_url( 'tools.php?page=rmq-queue' ) );
	exit;
} );
