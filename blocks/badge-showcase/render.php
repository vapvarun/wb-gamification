<?php
/**
 * Badge Showcase block — server-side render.
 *
 * Shows earned badges for a member. When show_locked = true, unearned badges
 * are displayed greyed-out to motivate members toward future achievements.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Unused.
 * @var WP_Block $block      Block object.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\BadgeEngine;

$user_id     = (int) ( $attributes['user_id'] ?? 0 );
$show_locked = (bool) ( $attributes['show_locked'] ?? false );
$category    = sanitize_key( $attributes['category'] ?? '' );
$limit       = (int) ( $attributes['limit'] ?? 0 );

// Fall back to current user.
if ( 0 === $user_id ) {
	$user_id = get_current_user_id();
}

if ( $user_id <= 0 && ! $show_locked ) {
	return '';
}

if ( $show_locked ) {
	// All badges with earned status.
	$badges = BadgeEngine::get_all_badges_for_user( $user_id );
} else {
	// Earned badges only.
	$badges = BadgeEngine::get_user_badges( $user_id );
	// Normalize to same shape as get_all_badges_for_user.
	$badges = array_map(
		static function ( array $b ): array {
			$b['earned'] = true;
			return $b;
		},
		$badges
	);
}

// Filter by category if specified.
if ( '' !== $category ) {
	$badges = array_values(
		array_filter( $badges, fn( $b ) => $b['category'] === $category )
	);
}

// Apply optional limit.
if ( $limit > 0 && count( $badges ) > $limit ) {
	$badges = array_slice( $badges, 0, $limit );
}

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'wb-gam-badge-showcase' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<?php if ( empty( $badges ) ) : ?>
		<p class="wb-gam-badge-showcase__empty">
			<?php esc_html_e( 'No badges earned yet.', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ul class="wb-gam-badge-showcase__list" role="list">
			<?php foreach ( $badges as $badge ) :
				$is_earned = (bool) ( $badge['earned'] ?? false );
				$css_class = 'wb-gam-badge-showcase__badge';
				if ( ! $is_earned ) {
					$css_class .= ' wb-gam-badge-showcase__badge--locked';
				}
				if ( ! empty( $badge['is_credential'] ) ) {
					$css_class .= ' wb-gam-badge-showcase__badge--credential';
				}
			?>
				<li class="<?php echo esc_attr( $css_class ); ?>"
				    title="<?php echo esc_attr( $badge['description'] ?? '' ); ?>">

					<?php if ( ! empty( $badge['image_url'] ) ) : ?>
						<img src="<?php echo esc_url( $badge['image_url'] ); ?>"
						     alt="<?php echo esc_attr( $badge['name'] ); ?>"
						     class="wb-gam-badge-showcase__image"
						     width="56" height="56"
						     loading="lazy" />
					<?php else : ?>
						<span class="wb-gam-badge-showcase__placeholder"
						      aria-hidden="true">🏅</span>
					<?php endif; ?>

					<span class="wb-gam-badge-showcase__name">
						<?php echo esc_html( $badge['name'] ); ?>
					</span>

					<?php if ( $is_earned && ! empty( $badge['earned_at'] ) ) : ?>
						<time class="wb-gam-badge-showcase__earned-at"
						      datetime="<?php echo esc_attr( $badge['earned_at'] ); ?>">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $badge['earned_at'] ) ) ); ?>
						</time>
					<?php elseif ( ! $is_earned ) : ?>
						<span class="wb-gam-badge-showcase__locked-label" aria-label="<?php esc_attr_e( 'Not yet earned', 'wb-gamification' ); ?>">
							<?php esc_html_e( 'Locked', 'wb-gamification' ); ?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
