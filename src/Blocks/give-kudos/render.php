<?php
/**
 * Give Kudos block — server render.
 *
 * Renders a small REST-driven form a logged-in member uses to send kudos
 * to another member. Form POSTs to `/wb-gamification/v1/kudos`; no direct
 * DB queries.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

// Block render.php files are invoked from inside render_callback by the
// WP block registrar, so every $wb_gam_* in this file is function-scoped,
// not global. PrefixAllGlobals can't tell — its `phpcs:disable` here is
// the WP-standard way to silence the false positive. The plugin's own
// .phpcs.xml already declares `wb_gam` as a valid prefix; this annotation
// extends that signal to Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound


/**
 * Injected by block render:
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Inner HTML (unused).
 */
$wb_gam_attrs = is_array( $attributes ) ? $attributes : array();

// Every other block wraps its markup in get_block_wrapper_attributes(); this one echoed the
// shared HTML bare. That silently dropped everything the editor puts on the wrapper -- the
// owner's custom CSS class, alignment, and the block supports -- so a give-kudos block set
// to "wide" or given a class in the editor simply ignored it. It also never emitted
// `wp-block-wb-gamification-give-kudos` or the `wb-gam-block-{hash}` hook that per-instance
// styles are keyed to.
//
// The inner markup keeps its own `.wb-gam-give-kudos` class and is shared with the
// shortcode, which correctly has no block wrapper. Only the block needs this.
$wb_gam_unique = ! empty( $wb_gam_attrs['uniqueId'] )
	? sanitize_html_class( (string) $wb_gam_attrs['uniqueId'] )
	: substr( md5( (string) wp_json_encode( $wb_gam_attrs ) ), 0, 8 );

$wb_gam_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'wb-gam-block-' . $wb_gam_unique,
	)
);

printf(
	'<div %s>%s</div>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped attribute markup.
	$wb_gam_wrapper,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds HTML via esc_attr/esc_html internally and is the canonical source for the give-kudos UI consumed by both the block and the shortcode (see ShortcodeHandler::give_kudos_html).
	\WBGam\Engine\ShortcodeHandler::give_kudos_html( $wb_gam_attrs )
);
