<?php
/**
 * WB Gamification: the contract every historical-import source implements.
 *
 * An importer READS another plugin's data and hands back normalized records, one keyset page at a
 * time. It never writes to wb_gam_* tables and it never loops over the whole source: the
 * ImportRunner (src/Engine/ImportRunner.php) owns paging, background jobs, checkpoints, import mode
 * and the write path (ImportService::ingest). That split is what lets a 100k-member, 1M-row source
 * migrate in bounded jobs and resume where it stopped.
 *
 * Paging is by KEYSET on the source's own key, never OFFSET: we cannot add an index to another
 * plugin's table, and OFFSET re-reads everything it skips. A cursor is an int (a row id, a user id):
 * `read_*( $after, $limit )` returns the rows strictly after it and the cursor to pass next, 0 when
 * the source is exhausted.
 *
 * Every event an importer produces carries `source_key = KEY_PREFIX . {source row id}` and every
 * badge it creates uses `BADGE_PREFIX . {source badge id}`. Those two prefixes are the ONLY way
 * imported data is recognised afterwards: idempotent re-runs, reconciliation and undo all match on
 * them, so a prefix must be unique to one source and must never change.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Integrations\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * A readable source of historical gamification data.
 */
interface ImportSource {

	/**
	 * Prefix of every event key this source writes (`mycred:log:`). Each importer MUST override it.
	 * It is how imported events are recognised for idempotency, reconciliation and undo, so it must
	 * be unique to this source and must never change. An importer that forgets to override it is
	 * refused, because an empty prefix would match every event on the site.
	 */
	public const KEY_PREFIX = '';

	/**
	 * Prefix of every badge id this source creates (`mycred-badge-`). Same rules as KEY_PREFIX.
	 */
	public const BADGE_PREFIX = '';

	/**
	 * Is this source's data present on the site?
	 *
	 * @return bool
	 */
	public static function is_available(): bool;

	/**
	 * How many point-ledger rows the source holds (the total the progress bar counts against).
	 *
	 * @return int
	 */
	public static function count_points(): int;

	/**
	 * One page of the source's point ledger as normalized ImportService rows.
	 *
	 * Each row: action_id, user_id, points (signed), point_type, object_id, occurred_at (UTC ISO-8601),
	 * source_key (KEY_PREFIX . id), metadata. Ordered by the source's own id ascending.
	 *
	 * @param int $after Row id to start strictly after (0 = the beginning).
	 * @param int $limit Maximum rows.
	 * @return array{rows: array<int, array<string, mixed>>, next: int} `next` is the cursor to pass next, and
	 *         0 ONLY when the source is exhausted (a page shorter than $limit and past the last row).
	 */
	public static function read_points( int $after, int $limit ): array;

	/**
	 * How many badge / achievement awards the source holds.
	 *
	 * @return int
	 */
	public static function count_awards(): int;

	/**
	 * One page of earned badges.
	 *
	 * Each record: user_id, badge_id (BADGE_PREFIX . id), name, image, earned_at (UTC `Y-m-d H:i:s`).
	 * The keyset cursor is whatever monotonic int the source offers (a row id, or a user id when the
	 * source has no single row key); it only has to advance and never repeat.
	 *
	 * A page may return FEWER records than $limit and still not be the last (a source row that is not a
	 * valid badge is skipped after the query), so exhaustion is signalled by `next` alone.
	 *
	 * @param int $after Cursor to start strictly after (0 = the beginning).
	 * @param int $limit Maximum source rows scanned (a page of users may hold more than one record per user).
	 * @return array{rows: array<int, array<string, mixed>>, next: int} `next` is 0 ONLY when exhausted.
	 */
	public static function read_awards( int $after, int $limit ): array;

	/**
	 * The small, unpaged definitions: rank tiers (become levels). A source has a handful.
	 *
	 * Each rank: name, min_points (int), order (int).
	 *
	 * @return array<int, array{name: string, min_points: int, order: int}>
	 */
	public static function read_ranks(): array;

	/**
	 * A member's balance according to the source itself (the reconciliation authority).
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_balance( int $user_id ): int;

	/**
	 * How many badges the source says a member has earned.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_badge_count( int $user_id ): int;

	/**
	 * A member's current rank name in the source, empty when it has none.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public static function source_rank_name( int $user_id ): string;
}
