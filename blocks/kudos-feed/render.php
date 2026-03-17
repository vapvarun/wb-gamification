<?php
/**
 * Kudos Feed block — server-side render.
 *
 * Displays a reverse-chronological feed of recent peer kudos.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Unused.
 * @var WP_Block $block      Block object.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\KudosEngine;

$limit        = max( 1, min( 50, (int) ( $attributes['limit'] ?? 10 ) ) );
$show_messages = (bool) ( $attributes['show_messages'] ?? true );

$kudos = KudosEngine::get_recent( $limit );

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'wb-gam-kudos-feed' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<div class="wb-gam-kudos-feed__header">
		<h3 class="wb-gam-kudos-feed__title"><?php esc_html_e( 'Kudos', 'wb-gamification' ); ?></h3>
	</div>

	<?php if ( empty( $kudos ) ) : ?>
		<p class="wb-gam-kudos-feed__empty">
			<?php esc_html_e( 'No kudos given yet — be the first!', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-kudos-feed__list" role="list">
			<?php foreach ( $kudos as $item ) :
				$giver_url    = function_exists( 'bp_core_get_user_domain' )
					? bp_core_get_user_domain( $item['giver_id'] )
					: '';
				$receiver_url = function_exists( 'bp_core_get_user_domain' )
					? bp_core_get_user_domain( $item['receiver_id'] )
					: '';
			?>
				<li class="wb-gam-kudos-feed__item">
					<span class="wb-gam-kudos-feed__giver">
						<?php echo get_avatar( $item['giver_id'], 32 ); ?>
						<?php if ( $giver_url ) : ?>
							<a href="<?php echo esc_url( $giver_url ); ?>"><?php echo esc_html( $item['giver_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item['giver_name'] ); ?>
						<?php endif; ?>
					</span>

					<span class="wb-gam-kudos-feed__arrow" aria-hidden="true">→</span>

					<span class="wb-gam-kudos-feed__receiver">
						<?php echo get_avatar( $item['receiver_id'], 32 ); ?>
						<?php if ( $receiver_url ) : ?>
							<a href="<?php echo esc_url( $receiver_url ); ?>"><?php echo esc_html( $item['receiver_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item['receiver_name'] ); ?>
						<?php endif; ?>
					</span>

					<?php if ( $show_messages && ! empty( $item['message'] ) ) : ?>
						<q class="wb-gam-kudos-feed__message"><?php echo esc_html( $item['message'] ); ?></q>
					<?php endif; ?>

					<time class="wb-gam-kudos-feed__time"
					      datetime="<?php echo esc_attr( $item['created_at'] ); ?>"
					      title="<?php echo esc_attr( $item['created_at'] ); ?>">
						<?php echo esc_html( human_time_diff( strtotime( $item['created_at'] ), time() ) . ' ' . __( 'ago', 'wb-gamification' ) ); ?>
					</time>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
