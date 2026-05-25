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
echo \WBGam\Engine\ShortcodeHandler::give_kudos_html( $attributes ?? array() );
