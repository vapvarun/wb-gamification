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
#   column in this plugin is written from PHP in UTC (current_time( 'mysql', true ) / gmdate()) --
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
done < <(grep -rnE 'NOW\(\)|UTC_TIMESTAMP\(\)|CURDATE\(\)|CURTIME\(\)|CURRENT_TIMESTAMP|UNIX_TIMESTAMP\(|FROM_UNIXTIME\(' src/ integrations/ templates/ wb-gamification.php --include='*.php' 2>/dev/null \
  | grep -vi 'wp_users\|@clock-ok' \
  | grep -viE 'src/Engine/(Installer|DbUpgrader)\.php.*(DEFAULT CURRENT_TIMESTAMP|CURRENT_TIMESTAMP,)')

if [ "$fail" -eq 1 ]; then
  echo ""
fi

# ─────────────────────────────────────────────────────────────────────────────────────────────
# PART 2 — the PHP side: every stored moment is UTC (1.6.5, card 10344291999)
#
# Before 1.6.5 this plugin wrote site-local wall-clock strings and this part enforced that. The
# contract is now the WordPress one: store UTC, bound through Clock (site calendar -> UTC instant),
# show with wp_date() / get_date_from_gmt(). Three constructs break it, and each is greppable:
#
#   1. current_time( 'mysql' | 'timestamp' | 'U' ) without the gmt flag -- a site-local moment,
#      written or compared against a UTC column. (current_time( 'Y-m-d' ) and other formats are
#      calendar keys and are fine.)
#   2. date_i18n() -- formats a local-as-UTC timestamp; use wp_date() on a real timestamp.
#   3. DATE( / DATE_FORMAT( / YEARWEEK( / HOUR( / DAYOFWEEK( on a *_at column without
#      Clock::sql_utc_to_local() -- that groups a UTC moment by the UTC day, not the site's.
#
# Same override as Part 1: `// @clock-ok: <reason>` within the 16 lines above.
php_fail=0
report() { # $1 = rule text, $2 = grep output
  while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    file="${hit%%:*}"; rest="${hit#*:}"; line="${rest%%:*}"
    code="$(sed -n "${line}p" "$file")"
    case "$(echo "$code" | sed 's/^[[:space:]]*//')" in '//'*|'*'*|'/*'*) continue ;; esac
    start=$(( line - 16 )); [ "$start" -lt 1 ] && start=1
    sed -n "${start},$((line - 1))p" "$file" | grep -q '@clock-ok' && continue
    if [ "$php_fail" -eq 0 ]; then
      echo ""
      echo "✗ A site-local clock in a UTC codebase. Stored moments are UTC: write current_time( 'mysql', true ),"
      echo "  bound with Clock::site_cutoff()/site_day_start(), group with Clock::sql_utc_to_local(), show with"
      echo "  wp_date()/get_date_from_gmt(). Or annotate: // @clock-ok: <reason>"
      echo ""
    fi
    echo "    [$1] ${file}:${line}"
    echo "        $(echo "$code" | sed 's/^[[:space:]]*//')"
    php_fail=1
  done <<< "$2"
}
SRC="src/ integrations/ templates/ wb-gamification.php"
# shellcheck disable=SC2086
report "local moment" "$(grep -rnE "current_time\(\s*'(mysql|timestamp|U)'\s*\)" $SRC --include='*.php' 2>/dev/null)"
# shellcheck disable=SC2086
report "date_i18n" "$(grep -rnE '\bdate_i18n\(' $SRC --include='*.php' 2>/dev/null)"
# shellcheck disable=SC2086
report "UTC grouped by UTC day" "$(grep -rnE "(DATE|DATE_FORMAT|YEARWEEK|HOUR|DAYOFWEEK)\(\s*[a-z_.]*_at\b" $SRC --include='*.php' 2>/dev/null | grep -v 'sql_utc_to_local')"

if [ "$php_fail" -eq 1 ]; then
  echo ""
  fail=1
fi

if [ "$fail" -eq 1 ]; then
  exit 1
fi

echo "✓ Clock contract — UTC storage: no database clock, no site-local moment, no UTC-day grouping"
exit 0
