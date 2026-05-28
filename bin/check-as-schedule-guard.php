<?php
/**
 * bin/check-as-schedule-guard.php
 *
 * Closes the bug class that caused the LeaderboardNudge Action Scheduler
 * infinite-recursion runaway in 1.4.0 (3.5M-row queue on one production
 * install). The pattern was: a handler for hook H called
 * `as_enqueue_async_action()` for hook H itself, with no check for an
 * already-scheduled job — cron then re-fired the handler in a loop.
 *
 * Invariant: every `as_schedule_*` / `as_enqueue_*` call must be either
 *
 *   1. preceded in the SAME METHOD BODY by an `as_has_scheduled_action()`
 *      call (the runtime guard — proves a deliberate "don't enqueue
 *      twice" choice), OR
 *
 *   2. annotated with `@as-fire-once` in the enclosing method's docblock
 *      (the static guarantee — the author has reasoned about it and
 *      concluded the call site cannot re-enter itself; e.g. shutdown-time
 *      queue drain, one-shot per-event delivery, etc.).
 *
 * Either is acceptable. Neither = a fail in coding-rules-check.sh (stage 2.1).
 *
 * Why a token-walker instead of a PHPStan rule:
 *
 *   The PHPStan binary is silently non-functional in this Local-by-Flywheel
 *   PHP build (exits 0 with no output regardless of input — see
 *   `audit/STABILITY-*` if added). Cache directory in $TMPDIR/phpstan
 *   returns stale results, and bootstrap files don't load. Token-walker
 *   matches the existing patterns (bin/check-boot-invariants.php,
 *   bin/check-badge-condition-contract.php) and runs in any PHP.
 *
 * Wired into bin/coding-rules-check.sh as a new rule function (no new
 * local-CI stage — the rule lives inside stage 2.1).
 *
 * Usage:
 *     php bin/check-as-schedule-guard.php          Scan all src/ + integrations/
 *     php bin/check-as-schedule-guard.php --json   Machine-readable report
 *
 * Exit codes:
 *   0  ok — every AS-schedule call is either guarded or annotated
 *   1  one or more unguarded, unannotated call sites found
 *   2  unexpected error
 */

declare( strict_types = 1 );

$root      = dirname( __DIR__ );
$json_mode = in_array( '--json', $argv, true );

$enqueue_funcs = array(
	'as_schedule_single_action',
	'as_schedule_recurring_action',
	'as_schedule_cron_action',
	'as_enqueue_async_action',
);
$guard_func    = 'as_has_scheduled_action';
$annotation    = '@as-fire-once';

// Walk src/ + integrations/.
$dirs = array( $root . '/src', $root . '/integrations' );
$violations = array();

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
			continue;
		}
		$rel = ltrim( str_replace( $root, '', (string) $file->getRealPath() ), '/' );
		$violations = array_merge(
			$violations,
			scan_file( (string) $file->getRealPath(), $rel, $enqueue_funcs, $guard_func, $annotation )
		);
	}
}

if ( $json_mode ) {
	echo json_encode( array( 'violations' => $violations ), JSON_PRETTY_PRINT ) . "\n";
	exit( empty( $violations ) ? 0 : 1 );
}

if ( empty( $violations ) ) {
	echo "AS-schedule guard check: every as_schedule_* / as_enqueue_* call is either guarded or annotated.\n";
	exit( 0 );
}

fwrite( STDERR, "AS-schedule guard check FAILED. Unguarded, unannotated call site(s):\n\n" );
foreach ( $violations as $v ) {
	fwrite(
		STDERR,
		sprintf(
			"  %s:%d — %s() inside %s::%s()\n",
			$v['file'],
			$v['line'],
			$v['func'],
			$v['class'],
			$v['method']
		)
	);
}
fwrite( STDERR, "\nFix each by adding ONE of the following IN THE METHOD CONTAINING the call:\n\n" );
fwrite(
	STDERR,
	"  (a) Runtime guard — for handlers that COULD re-enter themselves via cron:\n"
		. "        if ( ! as_has_scheduled_action( 'wb_gam_my_hook', \$args, 'wb_gamification' ) ) {\n"
		. "            as_enqueue_async_action( 'wb_gam_my_hook', \$args, 'wb_gamification' );\n"
		. "        }\n\n"
		. "  (b) Fire-once annotation — for methods that cannot recurse into themselves\n"
		. "      (e.g. shutdown drain, per-event delivery already deduped upstream):\n"
		. "        /**\n"
		. "         * Whatever the method does.\n"
		. "         *\n"
		. "         * @as-fire-once Reason: this fires once per event award; PointsEngine\n"
		. "         *               guarantees idempotency upstream.\n"
		. "         */\n"
		. "        public function deliver(...) { as_enqueue_async_action(...); }\n\n"
		. "See bin/check-as-schedule-guard.php (top-of-file comment) for full contract.\n"
);
exit( 1 );


/**
 * Walks one PHP file. For each ClassMethod / Function found, collects
 * the body's `as_schedule_*` calls + presence of `as_has_scheduled_action`
 * + docblock annotation. Reports any enqueue without a guard/annotation.
 *
 * @param array<int, string> $enqueue_funcs
 * @return array<int, array<string, mixed>>
 */
function scan_file(
	string $abs_path,
	string $rel_path,
	array $enqueue_funcs,
	string $guard_func,
	string $annotation
): array {
	$source = file_get_contents( $abs_path );
	if ( false === $source ) {
		return array();
	}
	$tokens = token_get_all( $source );

	$current_class = '<global>';
	$violations    = array();

	$i     = 0;
	$count = count( $tokens );
	while ( $i < $count ) {
		$tok = $tokens[ $i ];

		// Track current class for richer violation messages.
		if ( is_array( $tok ) && T_CLASS === $tok[0] ) {
			// Find class name on subsequent T_STRING.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$peek = $tokens[ $j ];
				if ( is_array( $peek ) && T_STRING === $peek[0] ) {
					$current_class = (string) $peek[1];
					break;
				}
				if ( '{' === $peek ) {
					break;
				}
			}
		}

		// Detect a function/method declaration.
		if ( is_array( $tok ) && T_FUNCTION === $tok[0] ) {
			// Look backwards for the method's docblock (T_DOC_COMMENT preceding
			// the function keyword, separated only by whitespace + visibility
			// modifiers).
			$docblock_text = '';
			for ( $back = $i - 1; $back >= 0; $back-- ) {
				$prev = $tokens[ $back ];
				if ( ! is_array( $prev ) ) {
					break;
				}
				if ( T_DOC_COMMENT === $prev[0] ) {
					$docblock_text = (string) $prev[1];
					break;
				}
				if ( in_array( $prev[0], array( T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT ), true ) ) {
					continue;
				}
				break;
			}

			// Find the method name (T_STRING after T_FUNCTION).
			$method_name = '<closure>';
			$start_brace = -1;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$peek = $tokens[ $j ];
				if ( '<closure>' === $method_name && is_array( $peek ) && T_STRING === $peek[0] ) {
					$method_name = (string) $peek[1];
					continue;
				}
				if ( '{' === $peek ) {
					$start_brace = $j;
					break;
				}
				if ( ';' === $peek ) {
					// Interface / abstract method — no body.
					break;
				}
			}

			if ( -1 === $start_brace ) {
				$i = $j;
				continue;
			}

			// Find matching close brace. T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES
			// are PHP's tokens for `{` inside double-quoted string interpolation
			// (e.g. "...{$wpdb->prefix}..."). They look like structural braces
			// but the tokenizer flags them separately. Their matching `}` is a
			// plain `}` char — so we MUST count the opens, otherwise depth goes
			// negative on the close and we exit the method body too early.
			$depth      = 1;
			$end_brace  = $count;
			for ( $j = $start_brace + 1; $j < $count; $j++ ) {
				$peek = $tokens[ $j ];
				if ( '{' === $peek ) {
					$depth++;
				} elseif ( is_array( $peek ) && ( T_CURLY_OPEN === $peek[0] || T_DOLLAR_OPEN_CURLY_BRACES === $peek[0] ) ) {
					$depth++;
				} elseif ( '}' === $peek ) {
					$depth--;
					if ( 0 === $depth ) {
						$end_brace = $j;
						break;
					}
				}
			}

			// Scan body for enqueue + guard calls.
			$enqueues_found = array(); // list<['line'=>int,'func'=>string]>
			$has_guard      = false;
			for ( $j = $start_brace + 1; $j < $end_brace; $j++ ) {
				$peek = $tokens[ $j ];
				if ( is_array( $peek ) && T_STRING === $peek[0] ) {
					$name = (string) $peek[1];
					if ( in_array( $name, $enqueue_funcs, true ) ) {
						// Confirm it's a function call: must be followed by `(`
						// (modulo whitespace) AND not preceded by `::` or `->`
						// (a method call with the same name doesn't count).
						$prev_idx = $j - 1;
						while ( $prev_idx >= 0 && is_array( $tokens[ $prev_idx ] ) && T_WHITESPACE === $tokens[ $prev_idx ][0] ) {
							$prev_idx--;
						}
						if ( $prev_idx >= 0 ) {
							$prev_tok = $tokens[ $prev_idx ];
							if ( is_array( $prev_tok ) && in_array( $prev_tok[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
								continue;
							}
						}
						$next_idx = $j + 1;
						while ( $next_idx < $count && is_array( $tokens[ $next_idx ] ) && T_WHITESPACE === $tokens[ $next_idx ][0] ) {
							$next_idx++;
						}
						if ( $next_idx < $count && '(' === $tokens[ $next_idx ] ) {
							$enqueues_found[] = array(
								'line' => (int) $peek[2],
								'func' => $name,
							);
						}
					} elseif ( $guard_func === $name ) {
						$has_guard = true;
					}
				}
			}

			if ( ! empty( $enqueues_found ) ) {
				$is_fire_once = ( '' !== $docblock_text && false !== strpos( $docblock_text, $annotation ) );
				if ( ! $has_guard && ! $is_fire_once ) {
					foreach ( $enqueues_found as $eq ) {
						$violations[] = array(
							'file'   => $rel_path,
							'line'   => $eq['line'],
							'func'   => $eq['func'],
							'class'  => $current_class,
							'method' => $method_name,
						);
					}
				}
			}

			$i = $end_brace + 1;
			continue;
		}

		$i++;
	}

	return $violations;
}
