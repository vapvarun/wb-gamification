<?php
/**
 * Badge Showcase block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Privacy;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_user_id     = (int) ( $wb_gam_attrs['user_id'] ?? 0 );
$wb_gam_show_locked = (bool) ( $wb_gam_attrs['show_locked'] ?? false );
$wb_gam_category    = sanitize_key( (string) ( $wb_gam_attrs['category'] ?? '' ) );
$wb_gam_limit       = (int) ( $wb_gam_attrs['limit'] ?? 0 );

if ( 0 === $wb_gam_user_id ) {
	$wb_gam_user_id = get_current_user_id();
}

// Privacy gate — badges are T1 data and visible only when the public-profile
// rule passes (see plan/PRIVACY-MODEL.md). Locked badges have no privacy
// implication so they remain available when show_locked is set even if the
// target user is private — the block becomes a generic "available badges"
// catalog in that mode.
if ( $wb_gam_user_id > 0 && ! Privacy::can_view_public_profile( $wb_gam_user_id ) ) {
	$wb_gam_user_id = 0;
}

if ( $wb_gam_user_id <= 0 && ! $wb_gam_show_locked ) {
	return '';
}

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

$wb_gam_classes = array_filter( array( 'wb-gam-badge-showcase', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_badges = $wb_gam_show_locked
	? BadgeEngine::get_all_badges_for_user( $wb_gam_user_id )
	: array_map(
		static function ( array $b ): array {
			$b['earned'] = true;
			return $b;
		},
		BadgeEngine::get_user_badges( $wb_gam_user_id )
	);

if ( '' !== $wb_gam_category ) {
	$wb_gam_badges = array_values(
		array_filter( $wb_gam_badges, fn( $b ) => ( $b['category'] ?? '' ) === $wb_gam_category )
	);
}

/**
 * Filter the badge-showcase block badges before render.
 *
 * @since 1.0.0
 *
 * @param array $badges     [{id, name, icon_url, earned, ...}, ...].
 * @param array $attributes Block attributes (show_locked, category, user_id).
 * @param int   $user_id    Member whose showcase is rendered.
 */
$wb_gam_badges = (array) apply_filters( 'wb_gam_block_badge_showcase_data', $wb_gam_badges, $wb_gam_attrs, $wb_gam_user_id );

if ( $wb_gam_limit > 0 && count( $wb_gam_badges ) > $wb_gam_limit ) {
	$wb_gam_badges = array_slice( $wb_gam_badges, 0, $wb_gam_limit );
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'badge-showcase', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( empty( $wb_gam_badges ) ) : ?>
		<p class="wb-gam-badge-showcase__empty"><?php esc_html_e( 'No badges earned yet.', 'wb-gamification' ); ?></p>
	<?php else : ?>
		<ul class="wb-gam-badge-showcase__list" role="list">
			<?php foreach ( $wb_gam_badges as $wb_gam_badge ) :
				$wb_gam_is_earned = (bool) ( $wb_gam_badge['earned'] ?? false );
				$wb_gam_class     = 'wb-gam-badge-showcase__badge';
				if ( ! $wb_gam_is_earned ) {
					$wb_gam_class .= ' wb-gam-badge-showcase__badge--locked';
				}
				if ( ! empty( $wb_gam_badge['is_credential'] ) ) {
					$wb_gam_class .= ' wb-gam-badge-showcase__badge--credential';
				}
				?>
				<li class="<?php echo esc_attr( $wb_gam_class ); ?>" title="<?php echo esc_attr( (string) ( $wb_gam_badge['description'] ?? '' ) ); ?>">
					<?php if ( ! empty( $wb_gam_badge['image_url'] ) ) : ?>
						<img alt="<?php echo esc_attr( (string) ( $wb_gam_badge['name'] ?? '' ) ); ?>" src="<?php echo esc_url( (string) $wb_gam_badge['image_url'] ); ?>"
							class="wb-gam-badge-showcase__image"
							width="56" height="56"
							loading="lazy" />
					<?php else : ?>
						<span class="wb-gam-badge-showcase__placeholder" aria-hidden="true">&#x1F3C5;</span>
					<?php endif; ?>

					<span class="wb-gam-badge-showcase__name"><?php echo esc_html( (string) ( $wb_gam_badge['name'] ?? '' ) ); ?></span>

					<?php if ( $wb_gam_is_earned && ! empty( $wb_gam_badge['earned_at'] ) ) : ?>
						<time class="wb-gam-badge-showcase__earned-at" datetime="<?php echo esc_attr( (string) $wb_gam_badge['earned_at'] ); ?>">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $wb_gam_badge['earned_at'] ) ) ); ?>
						</time>
					<?php elseif ( ! $wb_gam_is_earned ) : ?>
						<span class="wb-gam-badge-showcase__locked-label" aria-label="<?php esc_attr_e( 'Not yet earned', 'wb-gamification' ); ?>">
							<?php esc_html_e( 'Locked', 'wb-gamification' ); ?>
						</span>
						<span class="wb-gam-badge-showcase__hint">
							<?php
							echo esc_html(
								! empty( $wb_gam_badge['description'] )
									? (string) $wb_gam_badge['description']
									: __( 'Keep earning to unlock', 'wb-gamification' )
							);
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'badge-showcase', $wb_gam_attrs );
