<?php
/**
 * Member Points block — server-side render.
 *
 * Defaults to the currently logged-in user when user_id = 0.
 * On non-member pages (user_id = 0, not logged in), renders nothing.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Unused.
 * @var WP_Block $block      Block object.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );

// Fall back to current user.
if ( 0 === $user_id ) {
	$user_id = get_current_user_id();
}

if ( $user_id <= 0 ) {
	// Not logged in and no specific user — render nothing.
	return '';
}

$user = get_userdata( $user_id );
if ( ! $user ) {
	return '';
}

$show_level       = (bool) ( $attributes['show_level'] ?? true );
$show_progress    = (bool) ( $attributes['show_progress_bar'] ?? true );

$points           = PointsEngine::get_total( $user_id );
$level            = $show_level ? LevelEngine::get_level_for_user( $user_id ) : null;
$next_level       = $show_level ? LevelEngine::get_next_level( $user_id ) : null;
$progress_pct     = $show_progress ? LevelEngine::get_progress_percent( $user_id ) : 0;

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'wb-gam-member-points' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<div class="wb-gam-member-points__total">
		<span class="wb-gam-member-points__number"><?php echo esc_html( number_format_i18n( $points ) ); ?></span>
		<span class="wb-gam-member-points__label"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></span>
	</div>

	<?php if ( $level && $show_level ) : ?>
		<div class="wb-gam-member-points__level">
			<?php if ( ! empty( $level['icon_url'] ) ) : ?>
				<img src="<?php echo esc_url( $level['icon_url'] ); ?>"
				     alt="<?php echo esc_attr( $level['name'] ); ?>"
				     class="wb-gam-member-points__level-icon"
				     width="24" height="24" />
			<?php endif; ?>
			<span class="wb-gam-member-points__level-name"><?php echo esc_html( $level['name'] ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( $show_progress && $level ) : ?>
		<div class="wb-gam-member-points__progress" role="progressbar"
		     aria-valuenow="<?php echo esc_attr( $progress_pct ); ?>"
		     aria-valuemin="0" aria-valuemax="100"
		     aria-label="<?php esc_attr_e( 'Level progress', 'wb-gamification' ); ?>">
			<div class="wb-gam-member-points__progress-bar"
			     style="width: <?php echo esc_attr( $progress_pct ); ?>%"></div>
		</div>

		<?php if ( $next_level ) : ?>
			<p class="wb-gam-member-points__next">
				<?php
				$pts_to_next = max( 0, $next_level['min_points'] - $points );
				printf(
					/* translators: 1: points to next level, 2: next level name */
					esc_html__( '%1$s pts to %2$s', 'wb-gamification' ),
					esc_html( number_format_i18n( $pts_to_next ) ),
					esc_html( $next_level['name'] )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
