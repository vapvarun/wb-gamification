# WP-CLI Commands

All commands use the `wb-gamification` command namespace:

```
wp wb-gamification <command> <subcommand> [options]
```

---

## `points award`

Award points to a member. This is a direct admin award — it bypasses cooldown and daily-cap checks.

### Syntax

```
wp wb-gamification points award --user=<id> --points=<n> [--action=<id>] [--message=<msg>]
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--user=<id>` | Yes | User ID, login name, or email address |
| `--points=<n>` | Yes | Number of points to award (positive integer) |
| `--action=<id>` | No | Action ID to record in the ledger. Default: `manual` |
| `--message=<msg>` | No | Optional admin note stored in event metadata |

### Examples

```bash
# Award 100 points to user ID 42.
wp wb-gamification points award --user=42 --points=100

# Award 50 points with a custom action ID and note.
wp wb-gamification points award --user=jane --points=50 --action=speaker_bonus --message="Community hero this month"

# Award by email address.
wp wb-gamification points award --user=jane@example.com --points=200
```

### Expected Output

```
Success: Awarded 100 pts to Jane Smith. New total: 1350.
Success: Awarded 50 pts to Jane Smith (Community hero this month). New total: 1400.
```

---

## `member status`

Show a member's full gamification profile: points, level, progress to the next level, and earned badges.

### Syntax

```
wp wb-gamification member status --user=<id>
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--user=<id>` | Yes | User ID, login name, or email address |

### Examples

```bash
wp wb-gamification member status --user=42
wp wb-gamification member status --user=jane@example.com
```

### Expected Output

```
User:    Jane Smith (ID: 42)
Points:  1350
Level:   Contributor
Next:    Regular (1500 pts) — 90% there
Badges:  4
         century_club, welcome, first_post, first_update
```

---

## `actions list`

List all registered gamification actions with their current point values, daily cap, cooldown, and enabled state.

### Syntax

```
wp wb-gamification actions list [--format=<format>] [--category=<cat>]
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--format=<format>` | No | Output format: `table`, `csv`, `json`, `count`. Default: `table` |
| `--category=<cat>` | No | Filter by category slug (e.g. `buddypress`, `wordpress`, `commerce`) |

### Examples

```bash
# Table output (default).
wp wb-gamification actions list

# JSON output for scripting.
wp wb-gamification actions list --format=json

# Filter to BuddyPress actions only.
wp wb-gamification actions list --category=buddypress
```

### Expected Output (table)

```
+------------------------+-----------------------------+------------+--------+-----------+----------+---------+
| id                     | label                       | category   | points | daily_cap | cooldown | enabled |
+------------------------+-----------------------------+------------+--------+-----------+----------+---------+
| bp_activity_update     | Posted an activity update   | buddypress | 5      | 10        | —        | yes     |
| bp_friends_accepted    | Made a new friend           | buddypress | 10     | ∞         | —        | yes     |
| publish_post           | Published a post            | wordpress  | 15     | ∞         | —        | yes     |
+------------------------+-----------------------------+------------+--------+-----------+----------+---------+
```

---

## `logs prune`

Remove old entries from the event log (`wb_gam_events`). The points ledger, badges, levels, and leaderboard are **not** affected — only the raw audit trail is trimmed.

### Syntax

```
wp wb-gamification logs prune --before=<timespan> [--dry-run]
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--before=<timespan>` | Yes | Delete entries older than this. Formats: `6months`, `1year`, `90days` |
| `--dry-run` | No | Show the row count that would be deleted without deleting anything |

### Examples

```bash
# Preview: how many rows would be deleted?
wp wb-gamification logs prune --before=6months --dry-run

# Delete entries older than one year.
wp wb-gamification logs prune --before=1year

# Delete entries older than 90 days.
wp wb-gamification logs prune --before=90days
```

### Expected Output

```
[dry-run] Would delete 4,832 event log entries older than 2025-10-01 00:00:00.
Success: Deleted 4,832 event log entries older than 2025-10-01 00:00:00.
```

---

## `export user`

Export all gamification data for a member as JSON. Use for GDPR data portability requests.

### Syntax

```
wp wb-gamification export user --user=<id> [--format=<fmt>]
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--user=<id>` | Yes | User ID, login name, or email address |
| `--format=<fmt>` | No | Only `json` is supported. Default: `json` |

### Examples

```bash
# Print JSON to stdout.
wp wb-gamification export user --user=42

# Redirect to a file for delivery to the member.
wp wb-gamification export user --user=jane@example.com > export.json
```

### Expected Output

```json
{
  "user_id": 42,
  "display_name": "Jane Smith",
  "email": "jane@example.com",
  "exported_at": "2026-04-01T09:00:00+00:00",
  "points_total": 1350,
  "points_history": [...],
  "badges": [...],
  "level": { "id": 3, "name": "Contributor", "min_points": 500 }
}
```

---

## `doctor`

Run a comprehensive system health check. Validates database tables, default levels, default badges, registered actions, settings, cron jobs, REST API routes, and pro addon compatibility. Reports pass/warn/fail for each check.

### Syntax

```
wp wb-gamification doctor [--verbose] [--fix]
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--verbose` | No | Show details for passing checks, not just warnings and failures |
| `--fix` | No | Auto-fix issues that can be repaired (re-seed levels, badges; clean up orphaned options) |

### Examples

```bash
# Standard check.
wp wb-gamification doctor

# Show all check results including passing ones.
wp wb-gamification doctor --verbose

# Auto-fix what can be fixed.
wp wb-gamification doctor --fix
```

### Expected Output

```
WB Gamification Doctor v1.0.0
────────────────────────────────────────────────────────────

► Database Tables
  ✓ 20 tables present
  ✓ DB version: 1.0.0

► Default Levels
  ✓ 5 levels defined
  ✓ Starting level (0 points) exists

► Default Badges
  ✓ 30 badges defined
  ✓ 23 badges with auto-award conditions

► Registered Actions
  ✓ 14 actions registered
  ✓ All actions enabled

► REST API
  ✓ 42 REST routes registered
  ✓ All core endpoints present

► Cron Jobs
  ⚠ Log pruner (wb_gam_prune_logs) not scheduled

────────────────────────────────────────────────────────────
Results: 18 pass, 1 warn, 0 fail
Warning: Plugin has warnings — review before release.
```

The `--fix` flag re-seeds missing levels and badges by running `Installer::install()`, and cleans up any orphaned option keys from previous plugin versions.
