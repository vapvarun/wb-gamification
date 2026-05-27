<?php
/**
 * Static-analysis gate for plugin boot integrity.
 *
 * Catches the bug class where a file-scope `class_exists($name, false)`
 * guard preceding a top-level class declaration always triggers because
 * PHP hoists top-level class declarations into the symbol table at
 * compile time. The guard never sees `false` — execution returns before
 * any add_action() / register_*() call past the guard ever runs.
 *
 * Background: 2026-05-27, commit 06d811c added a class_exists() guard
 * to wb-gamification.php to "prevent double-load fatal in sandboxed
 * contexts." The guard sat ABOVE the class declaration in the same
 * file. PHP's class hoisting made class_exists() always return true,
 * so the file always returned early, the plugins_loaded@0 boot
 * closure was never registered, the singleton constructor never
 * ran, no admin pages registered, no menu rendered. Symptom: plugin
 * active but no admin menu.
 *
 * Rule: a file MUST NOT execute `class_exists( <name>, false )` at file
 * scope when <name> is declared in the SAME file at top level. PHP
 * class hoisting makes the guard always trigger.
 *
 * Exit codes:
 *   0 — no violations
 *   1 — at least one violation found
 *
 * @package WB_Gamification
 */

$root       = realpath( __DIR__ . '/..' );
$violations = array();

$rii = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
		static function ( $f ) {
			$skip = array( 'vendor', 'node_modules', 'tests', 'build', 'dist', 'audit', 'examples', 'languages', '.git' );
			if ( $f->isDir() && in_array( $f->getBasename(), $skip, true ) ) {
				return false;
			}
			return true;
		}
	)
);

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}

	$src = @file_get_contents( $file->getPathname() );
	if ( false === $src || false === strpos( $src, 'class_exists' ) ) {
		continue;
	}

	$tokens = @token_get_all( $src );
	if ( ! $tokens ) {
		continue;
	}

	// Pass 1: collect top-level class declarations (depth == 0 at the moment of T_CLASS).
	$declared = array();
	$depth    = 0;
	for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_string( $t ) ) {
			if ( '{' === $t ) {
				$depth++;
			} elseif ( '}' === $t ) {
				$depth--;
			}
			continue;
		}
		if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
			$depth++;
			continue;
		}
		if ( T_CLASS === $t[0] && 0 === $depth ) {
			$prev = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $prev ) && in_array( $prev[0], array( T_DOUBLE_COLON, T_NEW ), true ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$declared[ strtolower( $tokens[ $j ][1] ) ] = $tokens[ $j ][1];
					break;
				}
			}
		}
	}
	if ( ! $declared ) {
		continue;
	}

	// Pass 2: look for top-level class_exists( '<name>', false ) where <name> matches a same-file declaration.
	$depth = 0;
	for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_string( $t ) ) {
			if ( '{' === $t ) {
				$depth++;
			} elseif ( '}' === $t ) {
				$depth--;
			}
			continue;
		}
		if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
			$depth++;
			continue;
		}
		if ( 0 !== $depth ) {
			continue;
		}
		if ( T_STRING !== $t[0] || 'class_exists' !== strtolower( $t[1] ) ) {
			continue;
		}

		// Expect: class_exists ( <encapsed string> , <bool> )
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( ! isset( $tokens[ $j ] ) || '(' !== $tokens[ $j ] ) {
			continue;
		}
		$j++;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}

		if ( ! isset( $tokens[ $j ] ) || ! is_array( $tokens[ $j ] ) ) {
			continue;
		}
		if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
			continue;
		}
		$raw  = $tokens[ $j ][1];
		$name = trim( $raw, "\"'" );
		$name = ltrim( $name, '\\' );
		$last = strtolower( substr( strrchr( '\\' . $name, '\\' ), 1 ) );

		if ( ! isset( $declared[ $last ] ) ) {
			continue;
		}

		// Walk to the comma, then the second arg.
		$j++;
		while ( $j < $n && ',' !== $tokens[ $j ] ) {
			$j++;
		}
		if ( ! isset( $tokens[ $j ] ) ) {
			continue;
		}
		$j++;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}

		// Second arg must be the bareword `false`. `true` (autoload allowed) isn't this bug class.
		if ( ! isset( $tokens[ $j ] ) || ! is_array( $tokens[ $j ] ) ) {
			continue;
		}
		if ( T_STRING !== $tokens[ $j ][0] || 'false' !== strtolower( $tokens[ $j ][1] ) ) {
			continue;
		}

		$violations[] = array(
			'file' => str_replace( $root . '/', '', $file->getPathname() ),
			'line' => $t[2],
			'name' => $declared[ $last ],
		);
		break;
	}
}

if ( $violations ) {
	echo 'FAIL: ' . count( $violations ) . " boot-guard self-reference violation(s):\n";
	foreach ( $violations as $v ) {
		printf( "  %s:%d  class_exists(\"%s\", false) guard precedes own class declaration\n", $v['file'], $v['line'], $v['name'] );
	}
	echo "\nFix: remove the class_exists() guard. PHP class hoisting registers the\n";
	echo "     class in the symbol table at compile phase — BEFORE any line of code\n";
	echo "     in the file executes. The guard ALWAYS sees the class as declared,\n";
	echo "     so the file ALWAYS returns early, and any add_action() / register_*\n";
	echo "     calls placed AFTER the class declaration NEVER register.\n\n";
	echo "     include_once already protects against double-include fatals for\n";
	echo "     plugin files loaded via WordPress.\n";
	exit( 1 );
}

echo "PASS: no boot-guard self-reference patterns found.\n";
exit( 0 );
