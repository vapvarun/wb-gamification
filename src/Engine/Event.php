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

final class Event {

	public readonly string $event_id;
	public readonly string $action_id;
	public readonly int $user_id;
	public readonly int $object_id;

	/** @var array<string, mixed> */
	public readonly array $metadata;

	/** ISO-8601 UTC timestamp: 2026-03-17T10:00:00Z */
	public readonly string $created_at;

	/**
	 * @param array{
	 *   action_id: string,
	 *   user_id: int,
	 *   object_id?: int,
	 *   metadata?: array<string, mixed>,
	 *   created_at?: string,
	 *   event_id?: string,
	 * } $args
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

	private static function generate_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // version 4
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // variant bits
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
