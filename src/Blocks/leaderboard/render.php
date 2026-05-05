<?php
/**
 * Leaderboard block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\LeaderboardEngine;

$wb_gam_attrs    = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique   = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_period       = (string) ( $wb_gam_attrs['period'] ?? 'all' );
$wb_gam_limit        = max( 1, min( 100, (int) ( $wb_gam_attrs['limit'] ?? 10 ) ) );
$wb_gam_scope_type   = sanitize_key( (string) ( $wb_gam_attrs['scope_type'] ?? '' ) );
$wb_gam_scope_id     = (int) ( $wb_gam_attrs['scope_id'] ?? 0 );
$wb_gam_show_avatars = ! isset( $wb_gam_attrs['show_avatars'] ) || ! empty( $wb_gam_attrs['show_avatars'] );

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

$wb_gam_classes = array_filter( array( 'wb-gam-leaderboard', 'wb-gam-block-' . $wb_gam_unique, $wb_gam_visibility ) );

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_rows = LeaderboardEngine::get_leaderboard( $wb_gam_period, $wb_gam_limit, $wb_gam_scope_type, $wb_gam_scope_id );

$wb_gam_period_labels = array(
	'all'   => __( 'All Time', 'wb-gamification' ),
	'month' => __( 'This Month', 'wb-gamification' ),
	'week'  => __( 'This Week', 'wb-gamification' ),
	'day'   => __( 'Today', 'wb-gamification' ),
);
$wb_gam_period_label  = $wb_gam_period_labels[ $wb_gam_period ] ?? $wb_gam_period_labels['all'];

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'leaderboard', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wb-gam-leaderboard__header">
		<h3 class="wb-gam-leaderboard__title">
			<?php
			/* translators: %s: period label e.g. "This Week" */
			printf( esc_html__( 'Leaderboard — %s', 'wb-gamification' ), esc_html( $wb_gam_period_label ) );
			?>
		</h3>
	</div>

	<?php if ( empty( $wb_gam_rows ) ) : ?>
		<p class="wb-gam-leaderboard__empty">
			<?php esc_html_e( 'No members on the leaderboard yet.', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ol class="wb-gam-leaderboard__list">
			<?php
			foreach ( $wb_gam_rows as $wb_gam_row ) :
				$wb_gam_rank_num   = (int) ( $wb_gam_row['rank'] ?? 0 );
				$wb_gam_rank_label = '';
				if ( 1 === $wb_gam_rank_num ) {
					$wb_gam_rank_label = __( '1st place', 'wb-gamification' );
				} elseif ( 2 === $wb_gam_rank_num ) {
					$wb_gam_rank_label = __( '2nd place', 'wb-gamification' );
				} elseif ( 3 === $wb_gam_rank_num ) {
					$wb_gam_rank_label = __( '3rd place', 'wb-gamification' );
				}
				$wb_gam_rank_aria = $wb_gam_rank_label ? $wb_gam_rank_label : __( 'Rank', 'wb-gamification' );
				?>
				<li class="wb-gam-leaderboard__entry wb-gam-rank-<?php echo (int) $wb_gam_rank_num; ?>">
					<span class="wb-gam-leaderboard__rank" aria-label="<?php echo esc_attr( $wb_gam_rank_aria ); ?>">
						<?php echo (int) $wb_gam_rank_num; ?>
						<?php if ( $wb_gam_rank_label ) : ?>
							<span class="wb-gam-leaderboard__rank-ordinal"><?php echo esc_html( $wb_gam_rank_label ); ?></span>
						<?php endif; ?>
					</span>

					<?php if ( $wb_gam_show_avatars ) : ?>
						<span class="wb-gam-leaderboard__avatar">
							<?php echo get_avatar( (int) ( $wb_gam_row['user_id'] ?? 0 ), 40, '', esc_attr( (string) ( $wb_gam_row['display_name'] ?? '' ) ) ); ?>
						</span>
					<?php endif; ?>

					<span class="wb-gam-leaderboard__name">
						<?php
						$wb_gam_uid = (int) ( $wb_gam_row['user_id'] ?? 0 );
						$wb_gam_dn  = (string) ( $wb_gam_row['display_name'] ?? '' );
						if ( function_exists( 'bp_core_get_user_domain' ) ) {
							printf(
								'<a href="%1$s">%2$s</a>',
								esc_url( bp_core_get_user_domain( $wb_gam_uid ) ),
								esc_html( $wb_gam_dn )
							);
						} else {
							echo esc_html( $wb_gam_dn );
						}
						?>
					</span>

					<span class="wb-gam-leaderboard__points" aria-label="<?php esc_attr_e( 'Points', 'wb-gamification' ); ?>">
						<?php
						/* translators: %s: formatted points number */
						printf( esc_html__( '%s pts', 'wb-gamification' ), esc_html( number_format_i18n( (int) ( $wb_gam_row['points'] ?? 0 ) ) ) );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php
	$wb_gam_current_uid = get_current_user_id();
	if ( $wb_gam_current_uid > 0 && ! empty( $wb_gam_rows ) ) {
		$wb_gam_visible_ids = array_column( $wb_gam_rows, 'user_id' );
		if ( ! in_array( $wb_gam_current_uid, $wb_gam_visible_ids, true ) ) {
			$wb_gam_my_rank = LeaderboardEngine::get_user_rank( $wb_gam_current_uid, $wb_gam_period, $wb_gam_scope_type, $wb_gam_scope_id );
			if ( ! empty( $wb_gam_my_rank ) && (int) ( $wb_gam_my_rank['points'] ?? 0 ) > 0 ) :
				?>
				<div class="wb-gam-leaderboard__my-rank">
					<span class="wb-gam-leaderboard__my-rank-label"><?php esc_html_e( 'Your rank', 'wb-gamification' ); ?></span>
					<span class="wb-gam-leaderboard__my-rank-position">
						<?php
						/* translators: %s = rank number */
						printf( esc_html__( '#%s', 'wb-gamification' ), esc_html( number_format_i18n( (int) ( $wb_gam_my_rank['rank'] ?? 0 ) ) ) );
						?>
					</span>
					<span class="wb-gam-leaderboard__my-rank-points">
						<?php
						/* translators: %s = formatted points total */
						printf( esc_html__( '%s pts', 'wb-gamification' ), esc_html( number_format_i18n( (int) ( $wb_gam_my_rank['points'] ?? 0 ) ) ) );
						?>
					</span>
					<?php if ( null !== ( $wb_gam_my_rank['points_to_next'] ?? null ) ) : ?>
						<span class="wb-gam-leaderboard__my-rank-gap">
							<?php
							/* translators: %s = points needed to move up one rank */
							printf( esc_html__( '%s pts from the next rank', 'wb-gamification' ), esc_html( number_format_i18n( (int) $wb_gam_my_rank['points_to_next'] ) ) );
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php
			endif;
		}
	}
	?>
</div>
<?php
BlockHooks::after( 'leaderboard', $wb_gam_attrs );
