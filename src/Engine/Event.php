<?php
/**
 * WB Gamification Event Value Object
 *
 * Immutable typed value object carrying a gamification event
 * through the engine pipeline. Every award path starts here.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable typed value object carrying a gamification event through the engine pipeline.
 *
 * @package WB_Gamification
 */
final class Event {

	/**
	 * Unique UUID v4 identifying this event.
	 *
	 * @var string
	 */
	public readonly string $event_id;

	/**
	 * Registered action identifier (e.g. 'wp_publish_post').
	 *
	 * @var string
	 */
	public readonly string $action_id;

	/**
	 * WordPress user ID of the member who triggered the event.
	 *
	 * @var int
	 */
	public readonly int $user_id;

	/**
	 * Optional related object ID (post ID, comment ID, etc.).
	 *
	 * @var int
	 */
	public readonly int $object_id;

	/**
	 * Arbitrary key/value metadata attached to this event.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $metadata;

	/**
	 * ISO-8601 UTC timestamp at which the event occurred.
	 *
	 * @var string
	 */
	public readonly string $created_at;

	/**
	 * Construct a new Event value object.
	 *
	 * Accepted keys: action_id (string), user_id (int), object_id (int, optional),
	 * metadata (array, optional), created_at (string ISO-8601, optional),
	 * event_id (string UUID, optional — auto-generated when omitted).
	 *
	 * @param array $args Event data array.
	 */
	public function __construct( array $args ) {
		$this->action_id  = (string) ( $args['action_id'] ?? '' );
		$this->user_id    = (int) ( $args['user_id'] ?? 0 );
		$this->object_id  = (int) ( $args['object_id'] ?? 0 );
		$this->metadata   = (array) ( $args['metadata'] ?? array() );
		$this->created_at = isset( $args['created_at'] )
			? (string) $args['created_at']
			: gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->event_id   = isset( $args['event_id'] )
			? (string) $args['event_id']
			: self::generate_uuid();
	}

	/**
	 * Generate a random UUID v4 string.
	 *
	 * @return string UUID v4 formatted string.
	 */
	private static function generate_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Version 4.
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Variant bits.
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
