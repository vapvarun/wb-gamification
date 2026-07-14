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
done < <(grep -rnE 'NOW\(\)|UTC_TIMESTAMP\(\)|CURDATE\(\)|CURTIME\(\)|CURRENT_TIMESTAMP' src/ integrations/ templates/ wb-gamification.php --include='*.php' 2>/dev/null \
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

# Everything that SHIPS, not just src/. The gate scanned src/ alone, while the code that reaches a
# site is src/ + integrations/ + the root plugin file -- the same set Plugin Check calls "shipped".
# So a whole shipped directory was outside the gate: plant the identical clock bug in
# integrations/buddypress.php and it passed green. A checker whose scope is narrower than the
# thing it certifies is not checking the thing it certifies.
SCAN_FILES="$(find src integrations templates -name '*.php' 2>/dev/null | sort) wb-gamification.php"
php_hits="$(perl - $SCAN_FILES <<'PERL'
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

    # Code with the comments taken out.
    #
    # This exists because of the single best bug anyone found in this gate: the suppression scan
    # below reads the span of a wpdb call and treats the presence of current_time as proof the call
    # is in the right clock. The span was the RAW text, comments and all -- so a COMMENT mentioning
    # current_time suppressed the check for that call, permanently.
    #
    # Which is precisely what happened. The comment written in KudosEngine to explain the daily-limit
    # fix mentions that created_at is stored with current_time, and it sits INSIDE the prepare span.
    # That sentence disarmed the gate guarding the very bug it was describing: reintroduce the bug and
    # the gate stayed green, because the explanation was still sitting there. Strip the comment out
    # and the same gate fails by name.
    #
    # The documentation of a fix must never be able to satisfy the check for the fix. Any gate that
    # greps a span for a looks-safe token has this hole; this is where we close it.
    my $strip_comments = sub {
        my ($l) = @_;
        return '' if $is_comment->($l);      # whole-line comment
        $l =~ s{/\*.*?\*/}{}g;               # inline /* ... */
        $l =~ s{(^|\s)//.*$}{$1};            # trailing // -- leading \s keeps a URL in a string safe
        return $l;
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
            my $code       = '';
            for ( ; $j < $n ; $j++ ) {
                my $l = $lines[$j];
                $span .= $l;
                $code .= $strip_comments->($l);
                for my $ch ( split //, $l ) {
                    if ( $ch eq '(' ) { $depth++; $seen_open = 1; }
                    elsif ( $ch eq ')' ) { $depth--; }
                }
                last if $seen_open && $depth <= 0;
            }

            # @clock-ok is read from the RAW span (an annotation IS a comment, by definition).
            # Everything that decides whether the code is WRONG is read from $code, with the comments
            # taken out -- so no amount of prose about current_time() can vouch for a call that does
            # not use it.
            if ( $span !~ /\@clock-ok/
                && !$annotated_above->($call_start)
                && $code !~ /current_time\s*\(/
                && ( $code =~ /[<>]=?\s*%s/ || $code =~ /%s\s*[<>]=?/ || $code =~ /BETWEEN\s+%s/i )
                && ( $code =~ /(?<![A-Za-z0-9_])gmdate\s*\(/ || $code =~ /(?<![A-Za-z0-9_])time\s*\(\s*\)/ ) )
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
    my %utc_epoch;

    # Vars that were EVER parsed out of a naive string with strtotime(). Unlike %risky this is not
    # cleared by the `$ts = time();` parse-failure fallback -- that fallback makes the FAILURE path
    # safe, it does not make the variable safe, and Check E cares about the normal path where the
    # string parsed fine and the offset is already baked in.
    my %parsed;
    for ( my $i = 0 ; $i < $n ; $i++ ) {
        my $l = $lines[$i];

        if ( $l =~ /\bfunction\b/ ) {
            %risky     = ();
            %utc_epoch = ();
            %parsed    = ();
        }

        if ( $l =~ /\$(\w+)\s*=\s*strtotime\s*\(\s*(.*)$/ ) {
            my ( $var, $rest ) = ( $1, $2 );

            # What makes a strtotime() result dangerous is PARSING A NAIVE STRING that came out of a
            # column: strtotime over a created_at value reads a site-local wall clock as though it were
            # UTC, and comparing that to a real epoch is the bug.
            #
            # strtotime() with a LITERAL first argument is not that. A relative modifier (minus seven
            # days, offset from a base) is arithmetic on an epoch and returns a true epoch; so does a
            # literal date. Treating those as risky is how this check flagged the analytics chart, whose
            # gap-fill loop is correct (it hands a real epoch to wp_date(), which applies the site
            # timezone). A gate that cries wolf is a gate someone switches off, so it must be able to
            # tell the two apart.
            # \x27 = single quote, \x22 = double quote. Written as hex because a literal quote here
            # unbalances the quote count of the heredoc this perl script lives in, and bash 3.2 then
            # fails to find the closing paren of the command substitution -- which kills the script,
            # and a gate that dies exits 0 and passes everything.
            if ( $rest =~ /^\s*(?:\x27|\x22)/ ) {
                delete $risky{$var};
                delete $parsed{$var};
            }
            elsif ( $rest =~ /current_time\s*\(/ ) {
                delete $risky{$var};
                delete $parsed{$var};
            }
            else {
                $risky{$var}  = $i;
                $parsed{$var} = $i;
            }
        }

        # `$var = time();` -- e.g. a strtotime() parse-failure fallback ("if ( ! $ts ) { $ts =
        # time(); }") -- REASSIGNS the variable to a real epoch. It is a write, not a compare,
        # and it flips the variable out of the risky set: whatever compares against it next is
        # now comparing two real epochs, which is correct.
        if ( $l =~ /^\s*\$(\w+)\s*=\s*time\s*\(\s*\)\s*;\s*$/ ) {
            my $v = $1;

            # `$ts = time();` where $ts was ALREADY risky is a strtotime()-failure FALLBACK: it
            # overwrites the suspect value with a real epoch, so whatever compares against it next is
            # comparing two real epochs. That is a write, not a compare, and it clears the flag.
            if ( exists $risky{$v} ) {
                delete $risky{$v};
                next;
            }

            # But a bare time() assigned to a FRESH variable is the bug wearing a coat. QA planted
            # exactly this: hoist the challenges-block comparison into an intermediate variable, and the
            # gate went green, because the comparison line no longer contains the token time().
            # A checker that can be defeated by assigning to a variable first is checking the spelling,
            # not the code -- the same lesson as the comment that disarmed Check A.
            $utc_epoch{$v} = $i;
            next;
        }

        next if $is_comment->($l);

        # `strtotime( $x ) ?: time()` is a parse-failure FALLBACK feeding a value that gets
        # written out (e.g. via gmdate()), not a bound/comparison -- excluded, same as Check A
        # excludes plain writes. Comparisons use <, <=, >, >=, -, or the 2-arg form of
        # human_time_diff(); the elvis operator is neither.
        next if $l =~ /\?\s*:\s*time\s*\(\s*\)/;

        # The hoisted shape: a risky (strtotime-derived) value compared or subtracted against a
        # variable that was assigned a bare time(). No time() token on this line at all.
        if ( %utc_epoch && %risky && $l !~ /current_time\s*\(/ && $l =~ /[-<>=]/ ) {
            my $hit_epoch = 0;
            my $hit_risky = 0;
            for my $v ( keys %utc_epoch ) { $hit_epoch = 1 if $l =~ /\$\Q$v\E\b/; }
            for my $v ( keys %risky )     { $hit_risky = 1 if $l =~ /\$\Q$v\E\b/; }

            if ( $hit_epoch && $hit_risky && !$annotated_above->($i) ) {
                push @violations,
                    [ $i, "a strtotime()-derived value compared against a variable holding a bare time() (hoisted)" ];
            }
        }

        # ---- Check E: the offset applied TWICE ----
        #
        # strtotime() on a naive site-local column string already yields a pseudo-epoch in the
        # local-read-as-UTC frame -- the offset is baked in. Feeding that to DateTimeImmutable( '@'... )
        # and then calling setTimezone() applies the site offset AGAIN.
        #
        # points-history did this to build its day keys, so a point earned at 02:00 local landed under
        # the previous date and rendered under the Yesterday heading. Neither Check B nor Check C can
        # see it: there is no time() on the line and no SQL bound anywhere near it.
        if ( $l =~ /DateTimeImmutable\s*\(\s*.@/ && $l =~ /setTimezone/ && !$annotated_above->($i) ) {
            for my $v ( sort keys %parsed ) {
                if ( $l =~ /\$\Q$v\E\b/ ) {
                    push @violations,
                        [ $i,
                        "\$$v came from strtotime() on a site-local string (offset already applied) and setTimezone() applies it a second time" ];
                    last;
                }
            }
        }

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

    # ---- Check C: a UTC $cutoff built on an EARLIER line, then used as a SQL bound ----
    #
    # Check A only ever caught the INLINE shape -- gmdate written inside the wpdb call itself -- and
    # the header above declares the earlier-line shape deliberately out of scope, on the grounds that
    # attributing an arbitrary variable to the clock it was built in is real data-flow analysis and
    # not a grep.
    #
    # That reasoning was wrong, and expensively so: every one of the four clock bugs still live in
    # src is exactly this shape. In AnalyticsDashboard a since-bound built with gmdate is compared
    # against a site-local created_at, which makes the 7-day window seven hours short -- a day with
    # 777 points renders as an empty column. The gate reported green over all four.
    #
    # And it does not need data-flow analysis. It needs the same thing Check B already does for
    # strtotime(): remember which variables were assigned from a UTC-frame builder in this function,
    # and flag the ones that end up as a bound in a datetime comparison. A variable assigned from
    # current_time() is not in the set; an @clock-ok annotation takes anything out of it. That is a
    # grep with a memory, which is all Check B ever was.
    my %utc_var;
    my %local_var;
    for ( my $i = 0 ; $i < $n ; $i++ ) {
        my $raw  = $lines[$i];
        my $code = $strip_comments->($raw);

        if ( $code =~ /\bfunction\b/ ) {
            %utc_var   = ();
            %local_var = ();
        }

        # $x = current_time(...) -- a value already in the SITE frame. Anything built FROM it is too.
        if ( $code =~ /\$(\w+)\s*=\s*current_time\s*\(/ ) {
            $local_var{$1} = 1;
            delete $utc_var{$1};
        }

        # $x = wp_date( fmt, strtotime( <literal> ) ) -- the shape that made every weekly cap
        # over-permissive by a day.
        #
        # wp_date() takes a TRUE epoch and renders it in the site timezone. A weekday modifier does
        # not produce one: strtotime resolves it against the PHP UTC frame. wp_date() then
        # re-frames that instant into the site zone, which can roll it back across a day boundary --
        # measured on Los Angeles on a Tuesday, the bound came out on a SUNDAY. The formatting was in
        # the right clock and the INSTANT was not, which is why it reads as correct and is not.
        #
        # Check C tracked gmdate and date and never wp_date, so it was green over a live shipping bug
        # for three rounds. The right base is the site clock -- Clock::site_cutoff().
        # Only CALENDAR-FRAME modifiers, not arithmetic.
        #
        # wp_date( fmt, strtotime( '+1 day' ) ) is fine: adding 24 hours to an instant is
        # frame-independent, and wp_date renders the result in the site zone correctly. Measured in
        # Auckland (where the UTC day and the site day differ) it agrees with the site clock exactly.
        #
        # What is NOT fine is a modifier whose MEANING depends on which calendar you are standing in --
        # a weekday, a day name, the start of a period. strtotime() answers those against the PHP UTC
        # frame, and wp_date() then re-frames the instant, so the answer can land on the wrong day.
        # That is the shipping bug (a weekly cap that began on a Sunday), and it is the only shape this
        # flags. Flagging the arithmetic form too would make the gate cry wolf, and a gate that cries
        # wolf gets switched off.
        if ( $code =~ /\$(\w+)\s*=\s*wp_date\s*\(.*strtotime\s*\(\s*(?:\x27|\x22)\s*(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|today|tomorrow|yesterday|midnight|noon|first\s+day|last\s+day|this\s+|next\s+|last\s+)/i
            && $code !~ /current_time\s*\(/
            && !$annotated_above->($i) )
        {
            push @violations,
                [ $i,
                "wp_date() over a strtotime() modifier: strtotime resolves in UTC and wp_date re-frames it, so the weekday/day can roll. Use Clock::site_cutoff()" ];
        }

        # $x = gmdate(...) / date(...) -- a value in the UTC frame, UNLESS it was fed by current_time()
        # (directly, or through a variable that was). gmdate() applied to a current_time('timestamp')
        # or a strtotime( current_time('mysql') ) is the SITE wall clock, which is the one construction
        # that legitimately looks like this -- and is what Clock::site_cutoff() does.
        if ( $code =~ /\$(\w+)\s*=\s*(?:gmdate|date)\s*\(\s*(.*)$/ ) {
            my ( $var, $rest ) = ( $1, $2 );
            my $site_sourced = ( $rest =~ /current_time\s*\(/ ) ? 1 : 0;
            for my $lv ( keys %local_var ) {
                $site_sourced = 1 if $rest =~ /\$\Q$lv\E\b/;
            }
            if ($site_sourced) {
                $local_var{$var} = 1;
                delete $utc_var{$var};
            }
            else {
                $utc_var{$var} = $i;
            }
        }

        # ---- Check D: a UTC value compared against a datetime of UNKNOWN clock, in PHP ----
        #
        # Check C only ever looks inside $wpdb calls, so it cannot see a comparison made in PHP after
        # the rows come back. PointsExpiry does exactly that: it SELECTs MAX(created_at) (site-local),
        # then compares it with >= against a $cutoff built from gmdate() (UTC). No SQL bound anywhere,
        # so the gate reported green over a decay window wrong by the site offset -- members decayed
        # hours early on any site behind UTC.
        #
        # The rule: a value known to be UTC, compared with < <= > >= against a variable whose NAME says
        # it holds a datetime and whose clock we cannot vouch for. Restricted to datetime-looking names
        # so this stays a check and not a noise generator; the annotation is the escape hatch when the
        # other side really is UTC.
        if ( %utc_var && $code =~ /[<>]=?/ && !$annotated_above->($i) && $code !~ /current_time\s*\(/ ) {
            my $utc_hit = '';
            for my $v ( sort keys %utc_var ) {
                $utc_hit = $v if $code =~ /\$\Q$v\E\b/;
            }

            if ( $utc_hit ne '' ) {
                while ( $code =~ /\$(\w*(?:_at|_time|_date|activity|created|earned|expires|when|last)\w*)\b/gi ) {
                    my $other = $1;
                    next if $other eq $utc_hit;
                    next if exists $utc_var{$other};
                    next if exists $local_var{$other};

                    push @violations,
                        [ $i,
                        "UTC value \$$utc_hit compared against \$$other, whose clock is not established (built from gmdate() on line "
                            . ( $utc_var{$utc_hit} + 1 ) . ")" ];
                    last;
                }
            }
        }

        next unless %utc_var;
        next unless $code =~ /\$wpdb->(?:prepare|get_var|get_results|get_col|get_row|query)\s*\(/;

        # Collect the call span (raw for @clock-ok, stripped for the decision).
        my $depth = 0;
        my $seen  = 0;
        my $j_end;
        my ( $span, $cspan ) = ( '', '' );
        for ( my $j = $i ; $j < $n ; $j++ ) {
            my $l = $lines[$j];
            $span  .= $l;
            $cspan .= $strip_comments->($l);
            for my $ch ( split //, $l ) {
                if    ( $ch eq '(' ) { $depth++; $seen = 1; }
                elsif ( $ch eq ')' ) { $depth--; }
            }
            $j_end = $j;
            last if $seen && $depth <= 0;
        }

        next if $span =~ /\@clock-ok/;
        next if $annotated_above->($i);
        next if $cspan =~ /current_time\s*\(/;

        # Is a datetime column actually being COMPARED here (not just selected)?
        next unless ( $cspan =~ /[<>]=?\s*%s/ || $cspan =~ /%s\s*[<>]=?/ || $cspan =~ /BETWEEN\s+%s/i );

        for my $var ( sort keys %utc_var ) {
            if ( $cspan =~ /\$\Q$var\E\b/ ) {
                push @violations,
                    [ $i,
                    "SQL bound \$$var was built with gmdate()/date() (UTC) on line "
                        . ( $utc_var{$var} + 1 )
                        . " and is compared against a datetime column here" ];
                last;
            }
        }

        # One report per CALL, not per line of it. A multi-line $wpdb->prepare() re-enters this loop
        # on each of its own lines and would otherwise report the same bound two or three times --
        # and a gate that says everything twice is one people learn to skim.
        $i = $j_end if defined $j_end && $j_end > $i;
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
