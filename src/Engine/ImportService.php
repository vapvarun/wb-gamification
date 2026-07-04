<?php
/**
 * WB Gamification — shared import ingestion service.
 *
 * One code path for every historical-event importer (the REST
 * `POST /events/import` endpoint and each competitor importer). Callers hand
 * over already-normalized rows; this service runs them through the canonical
 * write API (`Engine::process()` in import mode) so idempotency, occurred_at
 * backdating, and side-effect suppression behave identically no matter who
 * triggered the import. Importers therefore NEVER write to wb_gam_* tables
 * directly — they READ their source and normalize, then ingest here.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized-row ingestion with idempotency + one-shot recompute.
 *
 * @package WB_Gamification
 */
final class ImportService {

	/**
	 * Ingest normalized import rows.
	 *
	 * Each row: action_id (string, required), user_id (int, required),
	 * points (int|null — explicit value preserved verbatim), point_type
	 * (string|null), object_id (int|null), occurred_at (string ISO-8601|null),
	 * source_key (string|null — de-dup key), metadata (array|null).
	 *
	 * @param array<int, array<string, mixed>> $rows Normalized rows.
	 * @return array{received:int, imported:int, skipped_duplicate:int, failed:int, badges_awarded:int}
	 */
	public static function ingest( array $rows ): array {
		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$users    = array();

		foreach ( $rows as $row ) {
			$row       = (array) $row;
			$user_id   = (int) ( $row['user_id'] ?? 0 );
			$action_id = isset( $row['action_id'] ) ? (string) $row['action_id'] : '';
			if ( $user_id <= 0 || '' === $action_id ) {
				++$failed;
				continue;
			}

			$source_key = isset( $row['source_key'] ) ? substr( (string) $row['source_key'], 0, 191 ) : '';
			if ( '' !== $source_key && Engine::source_key_exists( $source_key ) ) {
				++$skipped;
				continue;
			}

			$metadata            = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array();
			$metadata['_import'] = true;
			if ( isset( $row['points'] ) ) {
				$metadata['points'] = (int) $row['points'];
			}
			if ( ! empty( $row['point_type'] ) ) {
				$metadata['point_type'] = (string) $row['point_type'];
			}

			$occurred   = ! empty( $row['occurred_at'] ) ? strtotime( (string) $row['occurred_at'] ) : false;
			$created_at = false !== $occurred ? gmdate( 'Y-m-d\TH:i:s\Z', $occurred ) : gmdate( 'Y-m-d\TH:i:s\Z' );

			$event = new Event(
				array(
					'action_id'  => $action_id,
					'user_id'    => $user_id,
					'object_id'  => (int) ( $row['object_id'] ?? 0 ) ?: null,
					'metadata'   => $metadata,
					'created_at' => $created_at,
					'source_key' => '' !== $source_key ? $source_key : null,
				)
			);

			if ( Engine::process( $event ) ) {
				++$imported;
				$users[ $user_id ] = true;
			} else {
				++$failed;
			}
		}

		$badges = ! empty( $users ) ? Engine::recompute_users( array_keys( $users ) ) : 0;

		return array(
			'received'          => count( $rows ),
			'imported'          => $imported,
			'skipped_duplicate' => $skipped,
			'failed'            => $failed,
			'badges_awarded'    => $badges,
		);
	}
}
