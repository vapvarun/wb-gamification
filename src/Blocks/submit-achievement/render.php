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

// Block render.php files are invoked from inside render_callback by the
// WP block registrar, so every $wb_gam_* in this file is function-scoped,
// not global. PrefixAllGlobals can't tell — its `phpcs:disable` here is
// the WP-standard way to silence the false positive. The plugin's own
// .phpcs.xml already declares `wb_gam` as a valid prefix; this annotation
// extends that signal to Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound


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

// Frontend wp_editor() requires the editor scripts. wp_enqueue_editor()
// loads tinymce-core + quicktags + media (if available). Members without
// upload_files capability still get the rich-text toolbar; the "Add Media"
// button is hidden for them automatically by the media_buttons flag below.
if ( is_user_logged_in() ) {
	wp_enqueue_editor();
}

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
	// i18n strings consumed by view.js. Same pattern as the redemption-store
	// block — render-time translations flow through the PHP textdomain so
	// view.js stays string-free and ships to every locale unchanged.
	'data-i18n-success'    => __( 'Submitted! A reviewer will look at it soon.', 'wb-gamification' ),
	'data-i18n-failed'     => __( 'Submission failed.', 'wb-gamification' ),
	'data-i18n-network'    => __( 'Network error.', 'wb-gamification' ),
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

		<div class="wb-gam-submit-achievement__field wb-gam-submit-achievement__field--editor">
			<span class="wb-gam-submit-achievement__label"><?php esc_html_e( 'Tell us about it', 'wb-gamification' ); ?></span>
			<?php
			// wp_editor() in frontend — teeny toolbar (bold/italic/list/link
			// only) so the form stays compact. Media button visibility is
			// gated by user capability: editors / authors get image upload;
			// members without upload_files see formatting buttons only.
			// The data-wb-gam-editor-id attr is read by view.js to pull
			// content via tinyMCE.get( id ).getContent() at submit time.
			$wb_gam_editor_id = 'wb-gam-submit-evidence-' . $wb_gam_unique;
			wp_editor(
				'',
				$wb_gam_editor_id,
				array(
					'textarea_name' => 'evidence',
					'textarea_rows' => 4,
					'media_buttons' => current_user_can( 'upload_files' ),
					'teeny'         => true,
					'quicktags'     => true,
					'tinymce'       => array(
						'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
						'toolbar2' => '',
						'toolbar3' => '',
						'paste_as_text' => true,
					),
				)
			);
			?>
			<?php if ( ! current_user_can( 'upload_files' ) ) : ?>
				<p class="wb-gam-submit-achievement__hint">
					<?php esc_html_e( 'Tip: paste a media link in the optional URL field below to attach evidence.', 'wb-gamification' ); ?>
				</p>
			<?php endif; ?>
		</div>

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
