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

/**
 * Injected by block render:
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Inner HTML (unused).
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds HTML via esc_attr/esc_html internally and is the canonical source for the give-kudos UI consumed by both the block and the shortcode (see ShortcodeHandler::give_kudos_html).
echo \WBGam\Engine\ShortcodeHandler::give_kudos_html( $attributes ?? array() );
