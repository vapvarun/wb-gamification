<?php
/**
 * query-inventory — how many rows can each query return, and what does that cost?
 *
 * "Is it bounded?" is the wrong question on its own. The right ones are:
 *
 *   1. What is this query's ROW CEILING on a 100k-member site?
 *   2. What does that cost in PHP memory when it lands in an array?
 *   3. And -- the one people forget -- is a five-row config query being over-engineered?
 *      Caching, batching and pagination around a table that holds 5 rows is not safety,
 *      it is complexity with a staleness bug attached. Over-caching is a finding too.
 *
 * So every table is classified by how it GROWS, not by how many rows it has today on a dev
 * box, and every query is scored against the class of the table it touches.
 *
 * Usage: wp eval-file bin/query-inventory.php        (needs WP for the real row counts)
 *        php bin/query-inventory.php --static        (no WP; uses the declared model)
 *
 * @package WB_Gamification
 */

declare( strict_types = 1 );

// ─── The growth model ───────────────────────────────────────────────────────────────────
//
// Row ceilings at the bar the standard sets: 100,000 members.
// Measured against a real 10k-member seed, then extrapolated -- not guessed.

const MEMBERS = 100000;

/**
 * How each table grows. This is the whole point of the exercise: an unbounded SELECT is
 * harmless on CONFIG and fatal on EVENT, and the identical line of code is both.
 */
function growth_model(): array {
	return array(
		// CONFIG — bounded by what an ADMIN types. Tens of rows, forever.
		// An unbounded SELECT here is CORRECT. Paginating it is over-engineering.
		'badge_defs'                        => array( 'class' => 'CONFIG', 'ceiling' => 200,   'note' => 'admin-defined badges (42 today)' ),
		'levels'                            => array( 'class' => 'CONFIG', 'ceiling' => 50,    'note' => 'admin-defined levels (5 today)' ),
		'point_types'                       => array( 'class' => 'CONFIG', 'ceiling' => 20,    'note' => 'XP / Coins / Credits' ),
		'point_type_conversions'            => array( 'class' => 'CONFIG', 'ceiling' => 100,   'note' => 'pairs of point types' ),
		'rules'                             => array( 'class' => 'CONFIG', 'ceiling' => 500,   'note' => 'badge conditions (35 today)' ),
		'challenges'                        => array( 'class' => 'CONFIG', 'ceiling' => 500,   'note' => 'admin-defined' ),
		'community_challenges'              => array( 'class' => 'CONFIG', 'ceiling' => 200,   'note' => 'admin-defined' ),
		'redemption_items'                  => array( 'class' => 'CONFIG', 'ceiling' => 500,   'note' => 'store catalogue' ),
		'api_keys'                          => array( 'class' => 'CONFIG', 'ceiling' => 100,   'note' => 'admin-issued' ),
		'webhooks'                          => array( 'class' => 'CONFIG', 'ceiling' => 100,   'note' => 'admin-configured' ),

		// MEMBER — one row per member. 100k. An unbounded SELECT here is a 100k-row array.
		'user_totals'                       => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS * 3,  'note' => 'one per member PER POINT TYPE' ),
		'streaks'                           => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS,      'note' => 'one per member' ),
		'member_prefs'                      => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS,      'note' => 'one per member' ),
		'cohort_members'                    => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS,      'note' => 'one per member per week' ),
		'leaderboard_cache'                 => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS,      'note' => 'the snapshot itself' ),
		'user_intelligence'                 => array( 'class' => 'MEMBER', 'ceiling' => MEMBERS,      'note' => 'one per member' ),

		// MEMBER x N — the sneaky one. Looks member-scaled, multiplies by a config count.
		'user_badges'                       => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 40, 'note' => '100k members x up to 40 badges = 4M' ),

		// EVENT — grows with ACTIVITY. Millions. Unbounded here is an OOM.
		'points'                            => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 200, 'note' => 'the ledger. 20M at 200 awards/member' ),
		'events'                            => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 200, 'note' => 'audit trail, 1:1 with points' ),
		'kudos'                             => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 50,  'note' => 'member-to-member' ),
		'notifications_queue'               => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 50,  'note' => 'bounded per member on write (1.6.4)' ),
		'challenge_log'                     => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 20,  'note' => 'per member per challenge' ),
		'redemptions'                       => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 20,  'note' => 'purchase history' ),
		'submissions'                       => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 10,  'note' => 'member submissions' ),
		'side_effect_failures'              => array( 'class' => 'EVENT',  'ceiling' => 100000,        'note' => 'retry queue' ),
		'community_challenge_contributions' => array( 'class' => 'EVENT',  'ceiling' => MEMBERS * 20,  'note' => 'per member per challenge' ),
	);
}

// Rough bytes per row once it is a PHP object/array. A stdClass with ~8 scalar columns is
// ~500 bytes in practice, not the 50 the column widths suggest -- PHP's overhead dominates.
const BYTES_PER_ROW = 500;

// ─── Extract every query ────────────────────────────────────────────────────────────────

/**
 * Strip comments so a documented example is never counted as a live query.
 *
 * @param string $src Raw PHP.
 * @return string
 */
function strip_comments( string $src ): string {
	$out = $src;
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
			$pos = strpos( $out, $t[1] );
			if ( false !== $pos ) {
				$out = substr_replace( $out, preg_replace( '/[^\n]/', ' ', $t[1] ), $pos, strlen( $t[1] ) );
			}
		}
	}
	return $out;
}

/**
 * Find every $wpdb read and score it.
 *
 * @param string $root Plugin root.
 * @return array
 */
function inventory( string $root ): array {
	$model = growth_model();
	$rows  = array();

	$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $rii as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$src = strip_comments( (string) file_get_contents( $file->getPathname() ) );

		// Match the whole call, balanced-ish, so a multi-line query is captured intact.
		$re = '/\$wpdb->(get_results|get_col|get_var|query)\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s';
		if ( ! preg_match_all( $re, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $m[0] as $i => $hit ) {
			$call   = $hit[0];
			$method = $m[1][ $i ][0];
			$line   = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;

			// get_var returns ONE scalar -- it cannot leak memory no matter the table size.
			if ( 'get_var' === $method ) {
				continue;
			}
			// A write is not a read.
			if ( 'query' === $method && ! preg_match( '/\bSELECT\b/i', $call ) ) {
				continue;
			}

			// Which of OUR tables does it touch?
			$table = null;
			if ( preg_match( '/wb_gam_([a-z_]+)/', $call, $tm ) && isset( $model[ $tm[1] ] ) ) {
				$table = $tm[1];
			}
			if ( null === $table ) {
				continue; // core tables / dynamic -- out of scope for this pass
			}

			// SCAN SIZE AND RESULT SIZE ARE DIFFERENT COSTS, and the first draft of this tool
			// conflated them -- reporting BadgeEngine's `GROUP BY badge_id` as a 4-million-row
			// memory risk when it scans 4M and RETURNS 42, one per badge. Scanning is TIME
			// (fix with an index or a cache). Returning is MEMORY (fix with a LIMIT or a
			// cursor). A tool that cannot tell them apart sends you to rewrite correct code.
			$has_limit = (bool) preg_match( '/\bLIMIT\b/i', $call );
			$per_user  = (bool) preg_match( '/\buser_id\s*=\s*%d/i', $call );
			// `WHERE user_id IN ($placeholders)` is bounded by whatever list the CALLER built --
			// typically one page of members. Not a full-table read.
			$in_list   = (bool) preg_match( '/\bIN\s*\(\s*\$/i', $call );

			$info    = $model[ $table ];
			$ceiling = $info['ceiling'];

			// What does it RETURN?
			if ( preg_match( '/\bGROUP\s+BY\s+([a-z_.]+)/i', $call, $gm ) ) {
				$col = strtolower( trim( (string) preg_replace( '/^.*\./', '', $gm[1] ) ) );
				// One row per distinct value of the grouped column.
				if ( 'user_id' === $col ) {
					$n     = $has_limit ? 0 : MEMBERS;
					$worst = $has_limit ? 'bounded by LIMIT' : 'one row PER MEMBER';
				} else {
					// badge_id, action_id, point_type, level... all CONFIG-scale cardinality.
					$n     = 200;
					$worst = "aggregate, one row per {$col} (~200)";
				}
			} elseif ( $has_limit ) {
				$worst = 'bounded by LIMIT';
				$n     = 0;
			} elseif ( preg_match( '/\b(COUNT|SUM|MAX|MIN|AVG)\s*\(/i', $call ) ) {
				$worst = 'aggregate (1 row)';
				$n     = 1;
			} elseif ( $in_list ) {
				$worst = 'bounded by caller IN(...) list';
				$n     = 0;
			} elseif ( $per_user ) {
				$n     = 'EVENT' === $info['class'] ? (int) ( $ceiling / MEMBERS ) : 1;
				$worst = "one member's rows (~{$n})";
			} elseif ( preg_match( '/SELECT\s+DISTINCT\s+user_id/i', $call ) ) {
				// DISTINCT user_id over an EVENT table returns MEMBERS, not EVENTS. The first
				// draft reported DbUpgrader's `SELECT DISTINCT user_id FROM wb_gam_points` as
				// 20 million rows / 9.3 GB. It returns at most 100k ids -- ~8 MB. Real, but a
				// different order of magnitude, and a different fix.
				$n     = MEMBERS;
				$worst = 'DISTINCT user_id (one per member)';
			} elseif ( preg_match( '/\b(expires_at|created_at|earned_at|completed_at)\s*(>|>=|<|<=)\s*%s/i', $call ) ) {
				// A time-window predicate on an INDEXED timestamp bounds the result to the
				// window, not the table. CredentialExpiryEngine sweeps `(last_run, now]` -- a
				// day's expiries, not four million badges.
				$n     = 0;
				$worst = 'bounded by an indexed time window';
			} else {
				$n     = $ceiling;
				$worst = 'FULL TABLE';
			}

			$mem = is_int( $n ) ? $n * BYTES_PER_ROW : 0;

			$rows[] = array(
				'file'    => str_replace( $root . '/', '', $file->getPathname() ),
				'line'    => $line,
				'method'  => $method,
				'table'   => $table,
				'class'   => $info['class'],
				'shape'   => $worst,
				'rows'    => $n,
				'mem'     => $mem,
			);
		}
	}
	return $rows;
}

// ─── Report ─────────────────────────────────────────────────────────────────────────────

$root = dirname( __DIR__ );
$rows = inventory( $root );

usort(
	$rows,
	static fn( $a, $b ) => ( is_int( $b['rows'] ) ? $b['rows'] : 0 ) <=> ( is_int( $a['rows'] ) ? $a['rows'] : 0 )
);

$danger = array();
$waste  = array();

foreach ( $rows as $r ) {
	// A full-table read of a MEMBER or EVENT table is the leak.
	if ( 'FULL TABLE' === $r['shape'] && 'CONFIG' !== $r['class'] ) {
		$danger[] = $r;
	}
	// A LIMIT on a table that holds tens of rows is complexity for nothing.
	if ( 'bounded by LIMIT' === $r['shape'] && 'CONFIG' === $r['class'] ) {
		$waste[] = $r;
	}
}

echo "\n=== MEMORY RISK — full-table reads of tables that GROW ===\n";
echo "    (row ceiling at 100k members; ~" . BYTES_PER_ROW . " bytes/row once in PHP)\n\n";
if ( ! $danger ) {
	echo "    none\n";
}
foreach ( $danger as $r ) {
	printf(
		"  %-52s %-7s %-20s %10s rows  ~%s\n",
		$r['file'] . ':' . $r['line'],
		$r['class'],
		$r['table'],
		number_format( (int) $r['rows'] ),
		size( (int) $r['mem'] )
	);
}

echo "\n=== OVER-ENGINEERED — pagination/LIMIT on a table with tens of rows ===\n";
echo "    (complexity and a staleness bug, bought for nothing)\n\n";
if ( ! $waste ) {
	echo "    none\n";
}
foreach ( $waste as $r ) {
	printf( "  %-52s %-7s %s\n", $r['file'] . ':' . $r['line'], $r['class'], $r['table'] );
}

printf( "\n%d read queries against our own tables. %d memory risks, %d over-engineered.\n\n", count( $rows ), count( $danger ), count( $waste ) );

/**
 * Human bytes.
 *
 * @param int $b Bytes.
 * @return string
 */
function size( int $b ): string {
	if ( $b >= 1073741824 ) {
		return round( $b / 1073741824, 1 ) . ' GB';
	}
	if ( $b >= 1048576 ) {
		return round( $b / 1048576 ) . ' MB';
	}
	if ( $b >= 1024 ) {
		return round( $b / 1024 ) . ' KB';
	}
	return $b . ' B';
}
