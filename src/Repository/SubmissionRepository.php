<?php
/**
 * SubmissionRepository
 *
 * SQL-only data access for `wb_gam_submissions`. Per the canonical
 * 7-layer architecture, this class owns every read and write to the
 * submissions table and exposes nothing else.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the achievement-submission queue.
 */
final class SubmissionRepository {

	/**
	 * Insert a pending submission.
	 *
	 * @param array $row Submission fields (user_id, action_id, evidence, evidence_url).
	 * @return int Insert ID, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_submissions',
			array(
				'user_id'      => (int) ( $row['user_id'] ?? 0 ),
				'action_id'    => (string) ( $row['action_id'] ?? '' ),
				'evidence'     => isset( $row['evidence'] ) ? (string) $row['evidence'] : null,
				'evidence_url' => isset( $row['evidence_url'] ) ? (string) $row['evidence_url'] : null,
				'status'       => 'pending',
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Read a single submission row.
	 *
	 * @param int $id Submission ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_submissions WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * List submissions filtered by status.
	 *
	 * @param string $status   'pending' | 'approved' | 'rejected' | '' (all).
	 * @param int    $limit    Page size.
	 * @param int    $offset   Pagination offset.
	 * @return array<int, array<string,mixed>>
	 */
	public function list( string $status = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$where  = '';
		$values = array();
		if ( '' !== $status ) {
			$where    = 'WHERE status = %s';
			$values[] = $status;
		}
		$values[] = $limit;
		$values[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_submissions $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$values
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Update status + reviewer fields after admin decides.
	 *
	 * @param int    $id          Submission ID.
	 * @param string $status      New status (approved | rejected).
	 * @param int    $reviewer_id Admin user ID.
	 * @param string $notes       Optional reviewer note.
	 * @return bool
	 */
	public function set_status( int $id, string $status, int $reviewer_id, string $notes = '' ): bool {
		global $wpdb;
		$ok = $wpdb->update(
			$wpdb->prefix . 'wb_gam_submissions',
			array(
				'status'      => $status,
				'reviewer_id' => $reviewer_id,
				'notes'       => $notes ?: null,
				'reviewed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Count submissions a user has filed today (for rate-limiting).
	 *
	 * @param int $user_id User ID.
	 */
	public function count_today_for_user( int $user_id ): int {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_submissions WHERE user_id = %d AND DATE(created_at) = %s",
				$user_id,
				$today
			)
		);
	}

	/**
	 * Count submissions in the pending queue. Used by the admin
	 * sidebar badge.
	 */
	public function count_pending(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_submissions WHERE status = 'pending'"
		);
	}
}
