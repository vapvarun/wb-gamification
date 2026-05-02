<?php
/**
 * Block: Cohort Rank
 *
 * Shows the current member's cohort league standing — tier name,
 * their rank within the cohort, and the top-N members of that cohort.
 *
 * @package WB_Gamification
 * @since   1.1.0
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Engine\BlockHooks;
use WBGam\Engine\CohortEngine;

$user_id = (int) ( $attributes['user_id'] ?? 0 );
if ( $user_id <= 0 ) {
	$user_id = get_current_user_id();
}
$limit = max( 1, min( 50, (int) ( $attributes['limit'] ?? 5 ) ) );

if ( ! $user_id ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-cohort-rank wb-gam-cohort-rank--guest' ) );
	printf(
		'<div %s><p class="wb-gam-cohort-rank__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to see your cohort league.', 'wb-gamification' )
	);
	return;
}

$standing = CohortEngine::get_user_standing( $user_id );

if ( null === $standing ) {
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-cohort-rank wb-gam-cohort-rank--unassigned' ) );
	printf(
		'<div %s><p class="wb-gam-cohort-rank__empty">%s</p></div>',
		$wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'You have not been assigned to a cohort yet. New cohorts are formed every Monday.', 'wb-gamification' )
	);
	return;
}

$standings = array_slice( (array) ( $standing['standings'] ?? array() ), 0, $limit );

// Find the current member's rank within the standings array.
$current_rank = null;
foreach ( $standing['standings'] ?? array() as $entry ) {
	if ( (int) $entry['user_id'] === $user_id ) {
		$current_rank = (int) ( $entry['rank'] ?? 0 );
		break;
	}
}

$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wb-gam-cohort-rank' ) );

BlockHooks::before( 'cohort-rank', $attributes, array(
	'user_id'      => $user_id,
	'tier'         => $standing['tier'] ?? 0,
	'current_rank' => $current_rank,
) );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns sanitized output. ?>>
	<div class="wb-gam-cohort-rank__header">
		<span class="wb-gam-cohort-rank__tier-name">
			<?php echo esc_html( $standing['tier_name'] ?? __( 'Cohort', 'wb-gamification' ) ); ?>
		</span>
		<?php if ( null !== $current_rank ) : ?>
			<span class="wb-gam-cohort-rank__current-rank">
				<?php
				/* translators: %d = the member's current rank within their cohort */
				printf( esc_html__( "You're #%d", 'wb-gamification' ), (int) $current_rank );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $standings ) ) : ?>
		<p class="wb-gam-cohort-rank__empty">
			<?php esc_html_e( 'No standings available yet — your cohort will populate as members earn points this week.', 'wb-gamification' ); ?>
		</p>
	<?php else : ?>
		<ol class="wb-gam-cohort-rank__list" role="list">
			<?php foreach ( $standings as $entry ) :
				$is_self = ( (int) $entry['user_id'] === $user_id );
			?>
				<li class="wb-gam-cohort-rank__item<?php echo $is_self ? ' wb-gam-cohort-rank__item--self' : ''; ?>">
					<span class="wb-gam-cohort-rank__rank">
						#<?php echo esc_html( (string) ( $entry['rank'] ?? '' ) ); ?>
					</span>
					<span class="wb-gam-cohort-rank__name">
						<?php echo esc_html( $entry['display_name'] ?? '' ); ?>
					</span>
					<span class="wb-gam-cohort-rank__points">
						<?php
						/* translators: %s = points earned this week */
						printf( esc_html__( '%s pts', 'wb-gamification' ),
							esc_html( number_format_i18n( (int) ( $entry['week_pts'] ?? 0 ) ) )
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>

<?php BlockHooks::after( 'cohort-rank', $attributes, array(
	'user_id'      => $user_id,
	'tier'         => $standing['tier'] ?? 0,
	'current_rank' => $current_rank,
) ); ?>
