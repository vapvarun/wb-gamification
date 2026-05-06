<?php
/**
 * Top Members block — Wbcom Block Quality Standard render.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\LevelEngine;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_limit       = max( 1, min( 20, (int) ( $wb_gam_attrs['limit'] ?? 3 ) ) );
$wb_gam_period_map  = array(
	'all_time'   => 'all',
	'this_week'  => 'week',
	'this_month' => 'month',
);
$wb_gam_period      = $wb_gam_period_map[ $wb_gam_attrs['period'] ?? 'all_time' ] ?? 'all';
$wb_gam_show_badges = ! empty( $wb_gam_attrs['show_badges'] );
$wb_gam_show_level  = ! empty( $wb_gam_attrs['show_level'] );
$wb_gam_layout      = in_array( $wb_gam_attrs['layout'] ?? 'podium', array( 'podium', 'list' ), true )
	? (string) $wb_gam_attrs['layout']
	: 'podium';

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

wp_enqueue_style( 'wb-gam-tokens' );

$wb_gam_point_type = (string) ( $wb_gam_attrs['pointType'] ?? '' );
$wb_gam_rows       = LeaderboardEngine::get_leaderboard( $wb_gam_period, $wb_gam_limit, '', 0, $wb_gam_point_type );

$wb_gam_classes = array_filter(
	array(
		'wb-gam-top-members',
		'wb-gam-top-members--' . $wb_gam_layout,
		'wb-gam-block-' . $wb_gam_unique,
		$wb_gam_visibility,
	)
);

if ( empty( $wb_gam_rows ) ) {
	$wb_gam_classes[] = 'wb-gam-top-members--empty';
	$wb_gam_wrapper   = get_block_wrapper_attributes(
		array(
			'class' => implode( ' ', $wb_gam_classes ),
			'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
		)
	);
	BlockHooks::before( 'top-members', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-top-members__empty">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Be the first to earn points and claim the top spot!', 'wb-gamification' )
	);
	BlockHooks::after( 'top-members', $wb_gam_attrs );
	return;
}

$wb_gam_user_ids        = array_column( $wb_gam_rows, 'user_id' );
$wb_gam_badge_count_map = array();
if ( $wb_gam_show_badges && ! empty( $wb_gam_user_ids ) ) {
	global $wpdb;
	$wb_gam_placeholders = implode( ',', array_fill( 0, count( $wb_gam_user_ids ), '%d' ) );
	$wb_gam_badge_rows   = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT user_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id IN ($wb_gam_placeholders) GROUP BY user_id",
			...$wb_gam_user_ids
		),
		ARRAY_A
	);
	foreach ( $wb_gam_badge_rows ?: array() as $wb_gam_br ) {
		$wb_gam_badge_count_map[ (int) $wb_gam_br['user_id'] ] = (int) $wb_gam_br['cnt'];
	}
}

$wb_gam_level_map = array();
if ( $wb_gam_show_level ) {
	foreach ( $wb_gam_user_ids as $wb_gam_uid ) {
		$wb_gam_lvl = LevelEngine::get_level_for_user( (int) $wb_gam_uid );
		if ( $wb_gam_lvl ) {
			$wb_gam_level_map[ (int) $wb_gam_uid ] = (string) ( $wb_gam_lvl['name'] ?? '' );
		}
	}
}

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wb_gam_classes ),
		'style' => '' !== $wb_gam_inline ? $wb_gam_inline : null,
	)
);

BlockHooks::before( 'top-members', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( 'podium' === $wb_gam_layout ) : ?>
		<?php
		$wb_gam_podium = array();
		foreach ( array( 1, 0, 2 ) as $wb_gam_idx ) {
			if ( isset( $wb_gam_rows[ $wb_gam_idx ] ) ) {
				$wb_gam_podium[] = array( 'row' => $wb_gam_rows[ $wb_gam_idx ], 'rank' => $wb_gam_idx + 1 );
			}
		}
		?>
		<div class="wb-gam-top-members__podium">
			<?php foreach ( $wb_gam_podium as $wb_gam_entry ) :
				$wb_gam_row  = $wb_gam_entry['row'];
				$wb_gam_rank = (int) $wb_gam_entry['rank'];
				$wb_gam_uid  = (int) ( $wb_gam_row['user_id'] ?? 0 );
				$wb_gam_url  = function_exists( 'bp_core_get_user_domain' )
					? bp_core_get_user_domain( $wb_gam_uid )
					: get_author_posts_url( $wb_gam_uid );
				?>
				<div class="wb-gam-top-members__podium-slot wb-gam-top-members__podium-slot--<?php echo (int) $wb_gam_rank; ?>">
					<div class="wb-gam-top-members__podium-crown">
						<?php if ( 1 === $wb_gam_rank ) : ?><span aria-hidden="true">&#x1F451;</span><?php endif; ?>
					</div>
					<a href="<?php echo esc_url( (string) $wb_gam_url ); ?>" class="wb-gam-top-members__avatar-link">
						<?php echo get_avatar( $wb_gam_uid, 72, '', esc_attr( (string) ( $wb_gam_row['display_name'] ?? '' ) ) ); ?>
					</a>
					<span class="wb-gam-top-members__rank-badge wb-gam-top-members__rank-badge--<?php echo (int) $wb_gam_rank; ?>">
						<?php echo esc_html( '#' . $wb_gam_rank ); ?>
					</span>
					<a href="<?php echo esc_url( (string) $wb_gam_url ); ?>" class="wb-gam-top-members__name">
						<?php echo esc_html( (string) ( $wb_gam_row['display_name'] ?? '' ) ); ?>
					</a>
					<span class="wb-gam-top-members__points">
						<?php echo esc_html( number_format_i18n( (int) ( $wb_gam_row['points'] ?? 0 ) ) ); ?>
						<span class="wb-gam-top-members__pts-label"><?php esc_html_e( 'pts', 'wb-gamification' ); ?></span>
					</span>
					<?php if ( $wb_gam_show_level && isset( $wb_gam_level_map[ $wb_gam_uid ] ) ) : ?>
						<span class="wb-gam-top-members__level"><?php echo esc_html( $wb_gam_level_map[ $wb_gam_uid ] ); ?></span>
					<?php endif; ?>
					<?php if ( $wb_gam_show_badges && isset( $wb_gam_badge_count_map[ $wb_gam_uid ] ) ) : ?>
						<span class="wb-gam-top-members__badges">&#x1F3C5; <?php echo esc_html( number_format_i18n( $wb_gam_badge_count_map[ $wb_gam_uid ] ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( count( $wb_gam_rows ) > 3 ) : ?>
			<ol class="wb-gam-top-members__rest" start="4">
				<?php foreach ( array_slice( $wb_gam_rows, 3 ) as $wb_gam_i => $wb_gam_row ) :
					$wb_gam_uid  = (int) ( $wb_gam_row['user_id'] ?? 0 );
					$wb_gam_rank = (int) $wb_gam_i + 4;
					$wb_gam_url  = function_exists( 'bp_core_get_user_domain' )
						? bp_core_get_user_domain( $wb_gam_uid )
						: get_author_posts_url( $wb_gam_uid );
					?>
					<li class="wb-gam-top-members__rest-item">
						<span class="wb-gam-top-members__rest-rank"><?php echo esc_html( '#' . $wb_gam_rank ); ?></span>
						<?php echo get_avatar( $wb_gam_uid, 32, '', esc_attr( (string) ( $wb_gam_row['display_name'] ?? '' ) ) ); ?>
						<a href="<?php echo esc_url( (string) $wb_gam_url ); ?>" class="wb-gam-top-members__rest-name">
							<?php echo esc_html( (string) ( $wb_gam_row['display_name'] ?? '' ) ); ?>
						</a>
						<span class="wb-gam-top-members__rest-points"><?php echo esc_html( number_format_i18n( (int) ( $wb_gam_row['points'] ?? 0 ) ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

	<?php else : ?>
		<ol class="wb-gam-top-members__list">
			<?php foreach ( $wb_gam_rows as $wb_gam_rank_0 => $wb_gam_row ) :
				$wb_gam_uid  = (int) ( $wb_gam_row['user_id'] ?? 0 );
				$wb_gam_rank = (int) $wb_gam_rank_0 + 1;
				$wb_gam_url  = function_exists( 'bp_core_get_user_domain' )
					? bp_core_get_user_domain( $wb_gam_uid )
					: get_author_posts_url( $wb_gam_uid );
				?>
				<li class="wb-gam-top-members__list-item">
					<span class="wb-gam-top-members__list-rank"><?php echo esc_html( '#' . $wb_gam_rank ); ?></span>
					<a href="<?php echo esc_url( (string) $wb_gam_url ); ?>"><?php echo get_avatar( $wb_gam_uid, 40 ); ?></a>
					<div class="wb-gam-top-members__list-info">
						<a href="<?php echo esc_url( (string) $wb_gam_url ); ?>" class="wb-gam-top-members__list-name">
							<?php echo esc_html( (string) ( $wb_gam_row['display_name'] ?? '' ) ); ?>
						</a>
						<?php if ( $wb_gam_show_level && isset( $wb_gam_level_map[ $wb_gam_uid ] ) ) : ?>
							<span class="wb-gam-top-members__list-level"><?php echo esc_html( $wb_gam_level_map[ $wb_gam_uid ] ); ?></span>
						<?php endif; ?>
					</div>
					<span class="wb-gam-top-members__list-points">
						<?php echo esc_html( number_format_i18n( (int) ( $wb_gam_row['points'] ?? 0 ) ) ); ?>
						<span class="wb-gam-top-members__pts-label"><?php esc_html_e( 'pts', 'wb-gamification' ); ?></span>
					</span>
					<?php if ( $wb_gam_show_badges && isset( $wb_gam_badge_count_map[ $wb_gam_uid ] ) ) : ?>
						<span class="wb-gam-top-members__list-badges">&#x1F3C5; <?php echo esc_html( $wb_gam_badge_count_map[ $wb_gam_uid ] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
<?php
BlockHooks::after( 'top-members', $wb_gam_attrs );
