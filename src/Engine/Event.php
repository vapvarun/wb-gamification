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
	 * Resolved point-type slug this event lands in. Null until
	 * {@see \WBGam\Engine\Engine::process()} stamps the resolved currency
	 * (after Registry resolution + manifest override + metadata fallback
	 * all run). Downstream consumers (BadgeEngine, NotificationBridge,
	 * LevelEngine) read this in preference to digging back through
	 * `$metadata['point_type']` or re-running Registry resolution.
	 *
	 * Stamping the resolved type as a first-class field closes the
	 * multi-currency drift hole the 2026-05-27 audit found
	 * (DATA-FLOW-AWARD-2026-05-27.md §G5/G6/G14): every internal callsite
	 * that synthesised an Event lost the currency context because the
	 * metadata-injection path only ran for Registry-discovered actions.
	 *
	 * @since 1.4.1
	 * @var string|null
	 */
	public readonly ?string $point_type;

	/**
	 * Construct a new Event value object.
	 *
	 * Accepted keys: action_id (string), user_id (int), object_id (int, optional),
	 * metadata (array, optional), created_at (string ISO-8601, optional),
	 * event_id (string UUID, optional — auto-generated when omitted),
	 * point_type (string|null, optional — set after Engine::process resolves).
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
		$this->event_id   = isset( $args['event_id'] ) && '' !== $args['event_id']
			? (string) $args['event_id']
			: self::generate_uuid();
		$this->point_type = isset( $args['point_type'] ) && '' !== (string) $args['point_type']
			? (string) $args['point_type']
			: null;
	}

	/**
	 * Return a clone of this Event with the resolved point_type stamped in.
	 *
	 * Used by {@see \WBGam\Engine\Engine::process()} to publish the resolved
	 * currency as a first-class field once Registry resolution has run.
	 * Re-emits all other fields verbatim — strict value-object semantics.
	 * Also stamps `metadata['point_type']` so listeners that already read
	 * the metadata path stay correct.
	 *
	 * @since 1.4.1
	 *
	 * @param string $point_type Resolved point-type slug.
	 * @return Event Brand-new instance, original unchanged.
	 */
	public function with_point_type( string $point_type ): Event {
		return new Event(
			array(
				'action_id'  => $this->action_id,
				'user_id'    => $this->user_id,
				'object_id'  => $this->object_id,
				'metadata'   => array_merge( $this->metadata, array( 'point_type' => $point_type ) ),
				'created_at' => $this->created_at,
				'event_id'   => $this->event_id,
				'point_type' => $point_type,
			)
		);
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
