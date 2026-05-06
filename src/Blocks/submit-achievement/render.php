<?php
/**
 * Submit Achievement block — render.
 *
 * Renders a guest-blocked submission form. Member picks an action,
 * provides text + optional URL evidence, submits via REST. The view.js
 * handles the apiFetch and inline success/error display.
 *
 * @package WB_Gamification
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

use WBGam\Blocks\CSS as WB_Gam_Block_CSS;
use WBGam\Engine\BlockHooks;
use WBGam\Engine\Registry;

$wb_gam_attrs   = is_array( $attributes ) ? $attributes : array();
$wb_gam_unique  = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

WB_Gam_Block_CSS::add( $wb_gam_unique, $wb_gam_attrs );
$wb_gam_visibility = WB_Gam_Block_CSS::get_visibility_classes( $wb_gam_attrs );
$wb_gam_classes    = array_filter( array(
	'wb-gam-submit-achievement',
	'wb-gam-block-' . $wb_gam_unique,
	$wb_gam_visibility,
) );

wp_enqueue_style( 'wb-gam-tokens' );
wp_enqueue_style( 'lucide-icons' );
wp_enqueue_script( 'wp-api-fetch' );

if ( ! is_user_logged_in() ) {
	$wb_gam_wrapper = get_block_wrapper_attributes( array( 'class' => implode( ' ', $wb_gam_classes ) ) );
	BlockHooks::before( 'submit-achievement', $wb_gam_attrs );
	printf(
		'<div %s><p class="wb-gam-submit-achievement__guest">%s</p></div>',
		$wb_gam_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Log in to submit an achievement.', 'wb-gamification' )
	);
	BlockHooks::after( 'submit-achievement', $wb_gam_attrs );
	return;
}

// Pass REST URL + nonce + actions list as data attrs so view.js can use them
// without a separate wp_localize_script — keeps the block self-contained.
$wb_gam_actions = array_map(
	static fn( $a ) => array(
		'id'    => (string) $a['id'],
		'label' => (string) ( $a['label'] ?? $a['id'] ),
	),
	array_values( Registry::get_actions() )
);

$wb_gam_wrapper = get_block_wrapper_attributes( array(
	'class'                => implode( ' ', $wb_gam_classes ),
	'data-rest-url'        => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
	'data-rest-nonce'      => wp_create_nonce( 'wp_rest' ),
	'data-actions'         => wp_json_encode( $wb_gam_actions ),
) );

BlockHooks::before( 'submit-achievement', $wb_gam_attrs );
?>
<div <?php echo $wb_gam_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<form class="wb-gam-submit-achievement__form" data-wb-gam-submit-form>
		<label class="wb-gam-submit-achievement__field">
			<span><?php esc_html_e( 'What did you do?', 'wb-gamification' ); ?></span>
			<select name="action_id" required>
				<option value=""><?php esc_html_e( '— pick an action —', 'wb-gamification' ); ?></option>
				<?php foreach ( $wb_gam_actions as $wb_gam_a ) : ?>
					<option value="<?php echo esc_attr( $wb_gam_a['id'] ); ?>"><?php echo esc_html( $wb_gam_a['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<label class="wb-gam-submit-achievement__field">
			<span><?php esc_html_e( 'Tell us about it', 'wb-gamification' ); ?></span>
			<textarea name="evidence" rows="3" placeholder="<?php esc_attr_e( 'What happened? Add details for the reviewer.', 'wb-gamification' ); ?>"></textarea>
		</label>

		<label class="wb-gam-submit-achievement__field">
			<span><?php esc_html_e( 'Link (optional)', 'wb-gamification' ); ?></span>
			<input type="url" name="evidence_url" placeholder="https://...">
		</label>

		<button type="submit" class="wb-gam-submit-achievement__submit">
			<?php esc_html_e( 'Submit for review', 'wb-gamification' ); ?>
		</button>

		<p class="wb-gam-submit-achievement__status" data-wb-gam-submit-status aria-live="polite"></p>
	</form>
</div>
<?php
BlockHooks::after( 'submit-achievement', $wb_gam_attrs );
