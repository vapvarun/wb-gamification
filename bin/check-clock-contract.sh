#!/usr/bin/env bash
# check-clock-contract — no SQL clock may touch a wb_gam_ table without a human saying so.
#
# WHY THIS IS A GREP AND NOT A PARSER
#
# I built the parser first. It tried to attribute each timestamp write to its table so it could
# prove no column is written in two clocks. It gave a confident false POSITIVE (flagging
# kudos.created_at against side_effect_failures.created_at -- different tables, both fine), then,
# once "fixed", a confident false NEGATIVE (its table-matching scattered every column into its own
# bucket, so nothing could ever collide, and it cheerfully reported "0 inconsistent"). It also
# could not see INSERT ... VALUES writes where the value is bound separately.
#
# A checker nobody can trust is worse than no checker -- it is the noisy a11y gate all over again.
# So this does the one thing a grep does reliably: it finds the DANGEROUS CONSTRUCT and makes a
# human justify it.
#
# THE RULE
#
#   SQL NOW() is the DATABASE SERVER's clock. It is a third clock, independent of both the site's
#   timezone and UTC, and on a normal production host it differs from the site's. Every timestamp
#   column in this plugin is written from PHP -- current_time('mysql') (local) or gmdate() (UTC) --
#   so comparing one against NOW() mixes two clocks by construction.
#
#   Five of the seven bugs in this cycle were exactly that:
#     - the leaderboard snapshot deleted the rows it had just written
#     - the kudos cooldown never fired on any site behind UTC
#     - the weekly digest covered the wrong week AND mailed the wrong people
#     - challenges opened on the database's clock, not the owner's
#     - churn-risk scored members on a recency that was off by the site's offset
#
#   It is invisible on a UTC box. That is why it kept shipping.
#
# TO OVERRIDE, put this on the line above, and say why:
#
#   // @clock-ok: <reason>
#
# The only legitimate reason is that BOTH SIDES of the comparison come from the database clock --
# e.g. LeaderboardEngine stamps its snapshot with SELECT NOW() and prunes against that same value.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

fail=0

while IFS= read -r hit; do
  file="${hit%%:*}"
  rest="${hit#*:}"
  line="${rest%%:*}"

  # Comments are not code.
  code="$(sed -n "${line}p" "$file")"
  case "$(echo "$code" | sed 's/^[[:space:]]*//')" in
    '//'*|'*'*|'/*'*) continue ;;
  esac

  # Annotated anywhere in the preceding 16 lines? A NOW() sits inside a multi-line SQL string, so
  # the justification belongs in PHP above the $wpdb call -- NOT on the line directly above, which
  # is INSIDE the string. (I made exactly that mistake and injected "//" into a live INSERT.)
  start=$(( line - 16 )); [ "$start" -lt 1 ] && start=1
  if sed -n "${start},$((line - 1))p" "$file" | grep -q '@clock-ok'; then
    continue
  fi

  if [ "$fail" -eq 0 ]; then
    echo ""
    echo "✗ A DATABASE clock function (NOW/UTC_TIMESTAMP/CURDATE/CURTIME/CURRENT_TIMESTAMP) compared against a PHP-written column."
    echo ""
    echo "  NOW() is the DATABASE SERVER's clock. Every timestamp in this plugin is written from"
    echo "  PHP, so this mixes two clocks and breaks on every site whose database is not in the"
    echo "  site's timezone -- which is most of them. It works perfectly on your laptop."
    echo ""
    echo "  Bind a PHP-computed boundary in the SAME clock the column is written in, or annotate:"
    echo "      // @clock-ok: both sides come from the database clock"
    echo ""
  fi

  echo "    ${file}:${line}"
  echo "        ${code}"
  fail=1
# EVERY spelling of "ask the DATABASE what time it is" -- not just NOW().
#
# This gate was written on this branch to stop the two-clock bug class, and it shipped ANOTHER ONE:
# ChallengeEngine compared starts_at/ends_at against UTC_TIMESTAMP(), so on a site 5.5 hours ahead of
# UTC a challenge scheduled 09:00-17:00 appeared open while the engine refused to award anything
# against it. The gate never saw it, because it only ever looked for NOW().
#
# A guard that catches one spelling of a mistake teaches people the other spellings. So it now catches
# all of them.
#
# DEFAULT CURRENT_TIMESTAMP in a CREATE TABLE is a column default, not a comparison -- the database
# stamping a row it is inserting is not mixing clocks with anything. Installer/DbUpgrader are excluded
# for exactly that reason, and only for that reason.
done < <(grep -rnE 'NOW\(\)|UTC_TIMESTAMP\(\)|CURDATE\(\)|CURTIME\(\)|CURRENT_TIMESTAMP' src/ --include='*.php' 2>/dev/null \
  | grep -vi 'wp_users\|@clock-ok' \
  | grep -viE 'src/Engine/(Installer|DbUpgrader)\.php.*(DEFAULT CURRENT_TIMESTAMP|CURRENT_TIMESTAMP,)')

if [ "$fail" -eq 1 ]; then
  echo ""
fi

# ─────────────────────────────────────────────────────────────────────────────────────────────
# PART 2 — the PHP-side half of the same bug: gmdate()/time() vs current_time()
#
# Part 1 only ever needed to grep for literal text, because NOW()/CURDATE()/etc. are SQL --
# they cannot appear as valid PHP outside a string, so any hit IS the dangerous construct.
# gmdate() and time() are real PHP functions used for a hundred unrelated, harmless things
# (cron schedules, cache keys, nonces, log timestamps) in this codebase, so grepping for their
# literal text would drown the two constructs that actually matter in noise -- exactly the
# false-negative failure mode the header above describes for the abandoned table-attributing
# parser, just approached from the other direction. So Part 2 narrows to the two SHAPES that
# actually shipped bugs this cycle, instead of the raw keyword:
#
#   A) gmdate()/time() used INLINE as a bound argument to a $wpdb query call whose SQL compares
#      a %s placeholder with <, <=, >, >=, or BETWEEN -- i.e. it is a WHERE-clause bound, not a
#      value being written -- and current_time() does not also appear anywhere in that same
#      call. This is exactly KudosEngine::get_daily_sent_count()'s bug: gmdate('Y-m-d') . '
#      00:00:00' bound straight into `created_at >= %s`, no current_time() in sight, while the
#      column three lines away is written with current_time('mysql'). Its sibling four lines
#      down, has_recent_kudos_to_receiver(), does the equivalent comparison correctly --
#      strtotime( current_time( 'mysql' ) ) -- and is NOT flagged, because current_time()
#      appears in the same $wpdb->prepare() call.
#
#      A $cutoff variable computed on an earlier line and only referenced by name inside the
#      call is deliberately OUT of scope here, for the same reason the abandoned parser in the
#      header above failed: attributing an arbitrary variable to the clock it was built in is
#      real data-flow analysis, not a grep. This catches the INLINE shape, which is what both
#      of this cycle's bugs actually were.
#
#   B) a bare time() compared or subtracted against a value that came straight out of
#      strtotime() -- either on the same line, or via a $var assigned `$var = strtotime(...)`
#      earlier in the same function -- where that strtotime() call was not itself given a
#      current_time()-sourced string. This is the challenges block's bug: $ts = strtotime(
#      $ends_at ) reads a site-local wall-clock string as if it were UTC (PHP's default
#      timezone, which WP never changes), producing a pseudo-timestamp in the SAME "local read
#      as UTC" frame current_time('timestamp') produces -- then $ts - time() compared that
#      frame against a REAL UTC epoch. `$var = time();` (e.g. a strtotime()-failed fallback) is
#      a write, not a compare, and clears the variable's risky status -- whatever it's compared
#      against next is two real epochs, which is correct. So is `strtotime(...) ?: time()`
#      feeding a value that then gets written out.
#
# Same override as Part 1, same requirement: `// @clock-ok: <reason>` on the line, or anywhere
# in the 16 lines above it -- even for a construct that turns out to be safe (e.g. two columns
# that are BOTH always written with gmdate(), never current_time()). The annotation is cheap;
# a human deciding it isn't dangerous without writing that down is how this class of bug keeps
# reappearing four files over from the one that already got fixed.
# ─────────────────────────────────────────────────────────────────────────────────────────────

php_hits="$(perl - $(find src -name '*.php' 2>/dev/null | sort) <<'PERL'
use strict;
use warnings;

my $any_violation = 0;

for my $file (@ARGV) {
    open( my $fh, '<', $file ) or do { warn "skip $file: $!\n"; next; };
    my @lines = <$fh>;
    close $fh;
    my $n = scalar @lines;

    my $is_comment = sub {
        my ($l) = @_;
        my $t = $l;
        $t =~ s/^\s+//;
        return ( $t =~ m{^//} || $t =~ m{^\*} || $t =~ m{^/\*} );
    };

    my $annotated_above = sub {
        my ($idx) = @_;
        my $start = $idx - 16;
        $start = 0 if $start < 0;
        for my $i ( $start .. $idx ) {
            return 1 if $lines[$i] =~ /\@clock-ok/;
        }
        return 0;
    };

    my @violations;

    # ---- Check A: gmdate()/time() used INLINE as a bound arg to a $wpdb query call ----
    for ( my $i = 0 ; $i < $n ; $i++ ) {
        if ( $lines[$i] =~ /\$wpdb->(?:prepare|get_var|get_results|get_col|get_row|query)\s*\(/ ) {
            my $call_start = $i;
            my $depth      = 0;
            my $seen_open  = 0;
            my $span       = '';
            my $j          = $i;
            for ( ; $j < $n ; $j++ ) {
                my $l = $lines[$j];
                $span .= $l;
                for my $ch ( split //, $l ) {
                    if ( $ch eq '(' ) { $depth++; $seen_open = 1; }
                    elsif ( $ch eq ')' ) { $depth--; }
                }
                last if $seen_open && $depth <= 0;
            }

            if ( $span !~ /\@clock-ok/
                && !$annotated_above->($call_start)
                && $span !~ /current_time\s*\(/
                && ( $span =~ /[<>]=?\s*%s/ || $span =~ /%s\s*[<>]=?/ || $span =~ /BETWEEN\s+%s/i )
                && ( $span =~ /(?<![A-Za-z0-9_])gmdate\s*\(/ || $span =~ /(?<![A-Za-z0-9_])time\s*\(\s*\)/ ) )
            {
                push @violations,
                    [ $call_start,
                    "SQL bound built with gmdate()/time() (not current_time()) in a \$wpdb call" ];
            }
            $i = $j;
        }
    }

    # ---- Check B: bare time() compared/subtracted against a strtotime()-derived value ----
    my %risky;
    for ( my $i = 0 ; $i < $n ; $i++ ) {
        my $l = $lines[$i];

        %risky = () if $l =~ /\bfunction\b/;

        if ( $l =~ /\$(\w+)\s*=\s*strtotime\s*\(\s*(.*)$/ ) {
            my ( $var, $rest ) = ( $1, $2 );
            if ( $rest =~ /current_time\s*\(/ ) {
                delete $risky{$var};
            }
            else {
                $risky{$var} = $i;
            }
        }

        # `$var = time();` -- e.g. a strtotime() parse-failure fallback ("if ( ! $ts ) { $ts =
        # time(); }") -- REASSIGNS the variable to a real epoch. It is a write, not a compare,
        # and it flips the variable out of the risky set: whatever compares against it next is
        # now comparing two real epochs, which is correct.
        if ( $l =~ /^\s*\$(\w+)\s*=\s*time\s*\(\s*\)\s*;\s*$/ ) {
            delete $risky{$1};
            next;
        }

        next if $is_comment->($l);

        # `strtotime( $x ) ?: time()` is a parse-failure FALLBACK feeding a value that gets
        # written out (e.g. via gmdate()), not a bound/comparison -- excluded, same as Check A
        # excludes plain writes. Comparisons use <, <=, >, >=, -, or the 2-arg form of
        # human_time_diff(); the elvis operator is neither.
        next if $l =~ /\?\s*:\s*time\s*\(\s*\)/;

        if ( $l =~ /(?<![A-Za-z0-9_])time\s*\(\s*\)/ && $l !~ /current_time\s*\(/ ) {
            my $flag = 0;

            if ( $l =~ /strtotime\s*\(\s*(.*)$/ ) {
                my $arg = $1;
                $flag = 1 unless $arg =~ /current_time\s*\(/;
            }

            for my $var ( keys %risky ) {
                $flag = 1 if $l =~ /\$\Q$var\E\b/;
            }

            if ( $flag && !$annotated_above->($i) ) {
                push @violations,
                    [ $i, "bare time() compared against a strtotime()-derived value (not current_time())" ];
            }
        }
    }

    for my $v ( sort { $a->[0] <=> $b->[0] } @violations ) {
        my ( $idx, $msg ) = @$v;
        $any_violation = 1;
        print "    ${file}:" . ( $idx + 1 ) . "\n";
        print "        " . $lines[$idx];
        print "        >>> $msg\n";
    }
}

exit( $any_violation ? 1 : 0 );
PERL
)"
php_rc=$?

if [ -n "$php_hits" ]; then
  echo ""
  echo "✗ A PHP clock function (gmdate()/time()) compared against a current_time()-written value."
  echo ""
  echo "  gmdate() and time() are UTC/epoch. Every *_at column in this plugin that current_time()"
  echo "  writes is site-local. Comparing one against the other mixes clocks and is invisible on"
  echo "  a UTC box -- which is exactly why both of this cycle's PHP-side bugs shipped and passed"
  echo "  review: KudosEngine's daily-limit boundary, and the challenges block's countdown label."
  echo ""
  echo "  Derive the bound from current_time(), or annotate:"
  echo "      // @clock-ok: <reason>"
  echo ""
  echo "$php_hits"
  echo ""
  fail=1
elif [ "$php_rc" -gt 1 ]; then
  echo ""
  echo "✗ Part 2 (PHP clock check) errored -- treat as a failure, don't ship past an error you didn't read."
  fail=1
fi

if [ "$fail" -eq 1 ]; then
  exit 1
fi

echo "✓ Clock contract — no unannotated NOW()/UTC_TIMESTAMP()/gmdate()/time() against a current_time()-written value"
exit 0
