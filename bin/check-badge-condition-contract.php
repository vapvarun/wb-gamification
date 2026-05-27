<?php
/**
 * Static-analysis gate: seeded badge conditions must match badge names.
 *
 * Asserts that every auto-awarded badge whose name/description promises a
 * literal action ("First Post", "Published 10 posts", "Left 10 comments")
 * is seeded with condition_type=action_count and a matching action_id +
 * count — NEVER point_milestone.
 *
 * Background: 2026-05-27. Five badges (first_post, prolific_writer,
 * content_creator, first_comment, engaged_reader) were originally seeded
 * with point_milestone conditions. A user could reach 50 points by any
 * combination of actions (kudos, profile completion, daily login) and
 * earn the "First Comment" badge without ever commenting. The defensive
 * justification — "ensures they work on both standalone WP and BuddyPress
 * installs, regardless of which action IDs are registered" — was wrong:
 * integrations/wordpress.php registers wp_publish_post and wp_leave_comment
 * unconditionally on every install. Basecamp cards #9933063928 +
 * #9933079634 walked the audit. This gate is the regression sentinel.
 *
 * Heuristic:
 *   - badge id starts with "first_"  → must be action_count, count=1
 *   - description matches "/\b(\d+) (posts?|comments?|activity|updates?|friends?|connections?|groups?|reactions?|kudos)\b/i"
 *     → must be action_count with that count
 *   - points milestone badges (description literally says "points") are exempt
 *
 * Exit codes:
 *   0 — every "literal action" badge is action_count + correct
 *   1 — at least one violation
 *
 * @package WB_Gamification
 */

$root = realpath( __DIR__ . '/..' );
$src  = file_get_contents( $root . '/src/Engine/Installer.php' );
if ( false === $src ) {
	fwrite( STDERR, "FAIL: cannot read src/Engine/Installer.php\n" );
	exit( 1 );
}

// Locate the $conditions = array(...) block inside seed_default_badges().
if ( ! preg_match( '/\$conditions\s*=\s*array\s*\(/s', $src, $m, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "FAIL: cannot find \$conditions array literal in Installer.php\n" );
	exit( 1 );
}
$start = $m[0][1] + strlen( $m[0][0] ) - 1; // points at the opening (

// Walk parentheses to find matching close.
$depth = 0;
$len   = strlen( $src );
$end   = -1;
for ( $i = $start; $i < $len; $i++ ) {
	$ch = $src[ $i ];
	if ( '(' === $ch ) {
		$depth++;
	} elseif ( ')' === $ch ) {
		$depth--;
		if ( 0 === $depth ) {
			$end = $i;
			break;
		}
	}
}
if ( $end < 0 ) {
	fwrite( STDERR, "FAIL: unbalanced parens in \$conditions array\n" );
	exit( 1 );
}

$block = substr( $src, $start, $end - $start + 1 );

// Also pull the $badges = array(...) block to get descriptions.
if ( ! preg_match( '/\$badges\s*=\s*array\s*\(/s', $src, $bm, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "FAIL: cannot find \$badges array literal\n" );
	exit( 1 );
}
$bstart = $bm[0][1] + strlen( $bm[0][0] ) - 1;
$bdepth = 0;
$bend   = -1;
for ( $i = $bstart; $i < $len; $i++ ) {
	$ch = $src[ $i ];
	if ( '(' === $ch ) {
		$bdepth++;
	} elseif ( ')' === $ch ) {
		$bdepth--;
		if ( 0 === $bdepth ) {
			$bend = $i;
			break;
		}
	}
}
$badges_block = substr( $src, $bstart, $bend - $bstart + 1 );

// Extract (id, name, description) tuples from the $badges block.
preg_match_all(
	"/array\(\s*'([a-z_]+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'/",
	$badges_block,
	$badge_rows,
	PREG_SET_ORDER
);
$descriptions = array();
foreach ( $badge_rows as $row ) {
	$descriptions[ $row[1] ] = array(
		'name'        => $row[2],
		'description' => $row[3],
	);
}

// Extract (id => condition) tuples from the $conditions block.
// We split per top-level entry: "'<id>'<ws>=><ws>array(...)," repeating.
preg_match_all(
	"/'([a-z_]+)'\s*=>\s*array\s*\(\s*'condition_type'\s*=>\s*'([a-z_]+)'(.*?)\)\s*,/s",
	$block,
	$cond_rows,
	PREG_SET_ORDER
);

// Collect every registered action_id across integrations/*.php for action_id sanity.
$registered_actions = array();
foreach ( glob( $root . '/integrations/*.php' ) as $f ) {
	$txt = file_get_contents( $f );
	if ( $txt && preg_match_all( "/'id'\s*(?:=>|=>)\s*'([a-z_]+)'/", $txt, $im ) ) {
		foreach ( $im[1] as $aid ) {
			$registered_actions[ $aid ] = true;
		}
	}
}

$violations = array();

foreach ( $cond_rows as $row ) {
	$id   = $row[1];
	$type = $row[2];
	$body = $row[3];

	if ( ! isset( $descriptions[ $id ] ) ) {
		continue; // Condition without a matching badge def — flag separately if it ever appears.
	}
	$name = $descriptions[ $id ]['name'];
	$desc = $descriptions[ $id ]['description'];

	// Admin-awarded badges are always exempt.
	if ( 'admin_awarded' === $type ) {
		continue;
	}

	// Pure points-milestone badges (name/description literally says "points") are correct as point_milestone.
	if ( preg_match( '/\bpoints?\b/i', $name . ' ' . $desc ) ) {
		// Verify it IS point_milestone.
		if ( 'point_milestone' !== $type ) {
			$violations[] = "$id: name/description references 'points' but condition_type is $type";
		}
		continue;
	}

	// Otherwise — name/description promises a literal action. Must be action_count.
	if ( 'action_count' !== $type ) {
		$violations[] = sprintf(
			"%s: badge \"%s\" — \"%s\" — has condition_type=%s; expected action_count",
			$id,
			$name,
			$desc,
			$type
		);
		continue;
	}

	// Pull action_id + count from the body of this condition entry.
	if ( ! preg_match( "/'action_id'\s*=>\s*'([a-z_]+)'/", $body, $am ) ) {
		$violations[] = "$id: action_count condition missing action_id";
		continue;
	}
	$action_id = $am[1];

	if ( ! isset( $registered_actions[ $action_id ] ) ) {
		$violations[] = "$id: action_id '$action_id' is not registered by any integration in integrations/*.php";
		continue;
	}

	// If the description carries an explicit count ("10 posts", "25 comments", "3 groups"), assert count matches.
	if ( preg_match( '/\b(\d+)\b/', $desc, $nm ) ) {
		$desc_count = (int) $nm[1];
		if ( ! preg_match( "/'count'\s*=>\s*(\d+)/", $body, $cm ) ) {
			$violations[] = "$id: description says \"$desc_count\" but condition has no count";
			continue;
		}
		$seed_count = (int) $cm[1];
		if ( $seed_count !== $desc_count ) {
			$violations[] = sprintf(
				"%s: description says \"%d\" but seed count is %d (description: \"%s\")",
				$id,
				$desc_count,
				$seed_count,
				$desc
			);
		}
		continue;
	}

	// "First X" — description doesn't carry a digit but starts with "First" / "Your first"
	if ( preg_match( '/\b(first|your first|made your first|posted your first|published your very first|left your first|created your first|earned your first)\b/i', $desc ) ) {
		if ( ! preg_match( "/'count'\s*=>\s*(\d+)/", $body, $cm ) || 1 !== (int) $cm[1] ) {
			$violations[] = "$id: description implies first-time action but count is not 1";
		}
	}
}

if ( $violations ) {
	echo 'FAIL: ' . count( $violations ) . " badge-condition contract violation(s):\n";
	foreach ( $violations as $v ) {
		echo "  • $v\n";
	}
	echo "\nFix: edit src/Engine/Installer.php seed_default_badges() so each badge's\n";
	echo "     condition_type matches what its name/description promises:\n";
	echo "       • \"First X\" / \"Your first X\" → action_count of the matching action, count=1\n";
	echo "       • \"Published 10 posts\" → action_count of wp_publish_post, count=10\n";
	echo "       • \"Left 10 comments\"  → action_count of wp_leave_comment, count=10\n";
	echo "       • \"Earned 100 points\" → point_milestone, points=100\n";
	exit( 1 );
}

echo "PASS: all auto-award badge conditions match their badge names/descriptions.\n";
exit( 0 );
