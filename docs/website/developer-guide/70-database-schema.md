# Database Schema

All tables use the WordPress table prefix (default `wp_`). The current schema version is tracked by `get_option('wb_gam_db_version')`.

Migrations live in `src/Engine/DbUpgrader.php`. Each version gets its own `upgrade_to_X_Y_Z()` method. Tables are created on activation via `src/Engine/Installer.php` using `dbDelta()`.

All stored times are UTC: every `DATETIME` column (and the `wb_gam_decayed_at` / `wb_gam_last_retention_nudge` user meta) holds a UTC `Y-m-d H:i:s` value written from PHP, never SQL `NOW()`. Windows such as "today" or "the last 7 days" are resolved in the site time zone by `WBGam\Engine\Clock` and converted to UTC bounds; display converts back with `wp_date()` / `get_date_from_gmt()`. Pure calendar keys (`wb_gam_streaks.last_active`, cohort week keys) stay site-calendar values. Sites upgrading from before 1.6.5 have their existing rows converted once by the 1.6.5 upgrade.

---

## Core Tables

### `wb_gam_events`

Immutable event log. This is the source of truth for all gamification state. Events are never deleted except during GDPR erasure. All other tables are derived from this one and can be replayed.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(36) PK | UUID generated at event creation time |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `action_id` | VARCHAR(100) | Action identifier (e.g. `publish_post`) |
| `object_id` | BIGINT UNSIGNED NULL | Optional related object (e.g. post ID) |
| `metadata` | LONGTEXT | JSON-encoded metadata bag (quality signals, word counts, etc.) |
| `site_id` | VARCHAR(100) | Remote site identifier for cross-site events (empty for local) |
| `created_at` | DATETIME | Event timestamp (UTC) |

**Indexes:** `idx_user_action (user_id, action_id)`, `idx_user_created (user_id, created_at)`, `idx_created (created_at)`, `idx_site_id (site_id)`

### `wb_gam_points`

Points ledger. Derived from events. Each row represents one point award transaction and links back to the event that caused it via `event_id`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | Ledger row ID |
| `event_id` | VARCHAR(36) NULL | FK to `wb_gam_events.id` |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `action_id` | VARCHAR(100) | Action identifier |
| `points` | INT | Points awarded (positive integer) |
| `object_id` | BIGINT UNSIGNED NULL | Optional related object |
| `created_at` | DATETIME | Transaction timestamp (UTC) |

**Indexes:** `idx_event (event_id)`, `idx_user_created (user_id, created_at)`, `idx_user_action_created (user_id, action_id, created_at)` (sargable for leaderboard queries), `idx_action (action_id)`, `idx_created (created_at)`

---

## Member Tables

### `wb_gam_user_badges`

Earned badges. One row per member per badge. The `UNIQUE KEY user_badge (user_id, badge_id)` prevents duplicate awards.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `badge_id` | VARCHAR(100) | Badge identifier (FK to `wb_gam_badge_defs.id`) |
| `earned_at` | DATETIME | Award timestamp (UTC) |
| `expires_at` | DATETIME NULL | Expiry timestamp (UTC, added v0.3.0). NULL = never expires |

**Indexes:** `UNIQUE user_badge (user_id, badge_id)`, `idx_expires_at (expires_at)`

### `wb_gam_levels`

Level definitions. Configurable per community. Seeded with 5 default levels on fresh install.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED PK AUTO_INCREMENT | |
| `name` | VARCHAR(255) | Display name (e.g. "Contributor") |
| `min_points` | BIGINT UNSIGNED | Minimum points required to reach this level |
| `icon_url` | VARCHAR(500) NULL | Optional level icon URL |
| `sort_order` | INT | Display order in admin UI |

**Index:** `min_points (min_points)` - used in level-up queries

**Default levels:** Newcomer (0), Member (100), Contributor (500), Regular (1500), Champion (5000)

### `wb_gam_streaks`

Streak state per member. One row per user, updated on every point-earning activity.

| Column | Type | Description |
|--------|------|-------------|
| `user_id` | BIGINT UNSIGNED PK | WordPress user ID |
| `current_streak` | INT UNSIGNED | Current consecutive day/week count |
| `longest_streak` | INT UNSIGNED | All-time best streak |
| `last_active` | DATE | Last day the member earned points, as a site-calendar date (Settings > General time zone), not UTC |
| `timezone` | VARCHAR(50) | Member timezone for day boundary calculations. Default `UTC` |
| `grace_used` | TINYINT(1) | Whether the one-time grace day has been used |
| `updated_at` | DATETIME | Set on each write (UTC) |

### `wb_gam_member_prefs`

Per-user notification and privacy preferences. One row per user; missing row = all defaults.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `user_id` | BIGINT UNSIGNED PK | | WordPress user ID |
| `leaderboard_opt_out` | TINYINT(1) | 0 | `1` = hidden from public leaderboard |
| `show_rank` | TINYINT(1) | 1 | `0` = hide rank badge on profile and directory |
| `notification_mode` | VARCHAR(20) | `smart` | `smart`, `all`, `none` |

**Index:** `idx_opt_out (leaderboard_opt_out)`

---

## Engagement Tables

### `wb_gam_challenges`

Individual challenge definitions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `title` | VARCHAR(255) | Challenge display name |
| `type` | VARCHAR(20) | `individual` or `team` |
| `team_group_id` | BIGINT UNSIGNED NULL | BuddyPress group ID (for team challenges) |
| `action_id` | VARCHAR(100) | Action this challenge tracks |
| `target` | INT UNSIGNED | Target count to complete the challenge |
| `bonus_points` | INT | Bonus points awarded on completion |
| `period` | VARCHAR(20) | `none`, `day`, `week`, `month` |
| `starts_at` | DATETIME NULL | Challenge start time (UTC) |
| `ends_at` | DATETIME NULL | Challenge end time (UTC) |
| `status` | VARCHAR(20) | `active`, `inactive`, `completed` |

**Indexes:** `status (status)`, `idx_status_action (status, action_id)`

### `wb_gam_challenge_log`

Per-user challenge progress tracking.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `user_id` | BIGINT UNSIGNED | |
| `challenge_id` | BIGINT UNSIGNED | |
| `progress` | INT UNSIGNED | Current progress count |
| `completed_at` | DATETIME NULL | When the challenge was completed (UTC) |
| `created_at` | DATETIME | UTC |

**Key:** `UNIQUE user_challenge (user_id, challenge_id)`

### `wb_gam_kudos`

Peer kudos log. One row per kudos transaction.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `giver_id` | BIGINT UNSIGNED | User who gave kudos |
| `receiver_id` | BIGINT UNSIGNED | User who received kudos |
| `message` | VARCHAR(255) NULL | Optional message |
| `created_at` | DATETIME | UTC |

**Indexes:** `giver_date (giver_id, created_at)`, `receiver_id (receiver_id)`

### `wb_gam_community_challenges`

Community-wide (Pokémon GO-style) challenges where all members contribute to a shared goal.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `title` | VARCHAR(255) | |
| `description` | TEXT NULL | |
| `target_action` | VARCHAR(100) | Action being counted site-wide |
| `target_count` | BIGINT UNSIGNED | Global target |
| `global_progress` | BIGINT UNSIGNED | Current community-wide count |
| `bonus_points` | INT | Points awarded to each contributor on completion |
| `status` | VARCHAR(20) | `active`, `completed` |
| `starts_at` | DATETIME NULL | UTC |
| `ends_at` | DATETIME NULL | UTC |
| `completed_at` | DATETIME NULL | UTC |

### `wb_gam_community_challenge_contributions`

Per-user contribution counts for community challenges.

| Column | Type | Description |
|--------|------|-------------|
| `challenge_id` | BIGINT UNSIGNED | |
| `user_id` | BIGINT UNSIGNED | |
| `contribution_count` | BIGINT UNSIGNED | Number of qualifying actions this user performed |

**PK:** `(challenge_id, user_id)`

---

## Rules Tables

### `wb_gam_rules`

All rule configurations: badge conditions, point multipliers, and other rule types.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `rule_type` | VARCHAR(50) | `badge_condition`, `point_multiplier`, etc. |
| `target_id` | VARCHAR(100) NULL | Badge ID (for `badge_condition`) or other target |
| `rule_config` | LONGTEXT | JSON-encoded rule parameters |
| `is_active` | TINYINT(1) | `1` = active, `0` = disabled |
| `created_at` | DATETIME | UTC |

**Indexes:** `rule_type (rule_type)`, `target_id (target_id)`

Badge condition types stored in `rule_config.condition_type`:
- `point_milestone` - fires when `total_points >= config.points`
- `action_count` - fires when a specific action has been performed N times
- `admin_awarded` - no automatic condition; admin awards manually

### `wb_gam_badge_defs`

Badge definitions (catalog). Award conditions live in `wb_gam_rules`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(100) PK | Badge identifier slug (e.g. `top_contributor`) |
| `name` | VARCHAR(255) | Display name |
| `description` | TEXT NULL | |
| `image_url` | VARCHAR(500) NULL | Badge image URL |
| `is_credential` | TINYINT(1) | `1` = issued as an OpenBadges 3.0 credential |
| `validity_days` | INT UNSIGNED NULL | Badge expiry in days. NULL = never expires |
| `closes_at` | DATETIME NULL | Date after which the badge can no longer be earned (UTC) |
| `max_earners` | INT UNSIGNED NULL | Maximum members who can hold this badge |
| `category` | VARCHAR(50) | `points`, `wordpress`, `buddypress`, `special` |
| `created_at` | DATETIME | UTC |

---

## Advanced Tables

### `wb_gam_webhooks`

Registered outbound webhook endpoints.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `url` | VARCHAR(500) | Webhook target URL |
| `secret` | VARCHAR(255) | HMAC-SHA256 signing secret |
| `events` | TEXT | JSON array of event types to forward |
| `is_active` | TINYINT(1) | |
| `created_at` | DATETIME | UTC |

### `wb_gam_redemption_items`

Rewards catalog for the points redemption store.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `title` | VARCHAR(255) | Reward display name |
| `description` | TEXT NULL | |
| `points_cost` | INT UNSIGNED | Points required to redeem |
| `reward_type` | VARCHAR(50) | e.g. `coupon`, `download`, `manual` |
| `reward_config` | LONGTEXT NULL | JSON-encoded reward delivery config |
| `stock` | INT UNSIGNED NULL | Available quantity. NULL = unlimited |
| `is_active` | TINYINT(1) | |
| `created_at` | DATETIME | UTC |

### `wb_gam_redemptions`

Redemption transaction log.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `user_id` | BIGINT UNSIGNED | |
| `item_id` | BIGINT UNSIGNED | |
| `points_cost` | INT UNSIGNED | Points deducted at time of redemption |
| `status` | VARCHAR(30) | `pending`, `fulfilled`, `cancelled` |
| `coupon_code` | VARCHAR(100) NULL | Generated coupon code if applicable |
| `created_at` | DATETIME | UTC |

### `wb_gam_cohort_members`

Cohort league tracking (Duolingo-style weekly leagues).

| Column | Type | Description |
|--------|------|-------------|
| `user_id` | BIGINT UNSIGNED | |
| `cohort_id` | VARCHAR(50) | Cohort group identifier |
| `tier` | TINYINT UNSIGNED | Current league tier |
| `tier_end` | TINYINT UNSIGNED NULL | Tier at end of week (set during resolution) |
| `outcome` | VARCHAR(20) NULL | `promoted`, `demoted`, `retained` |
| `week` | VARCHAR(10) | ISO week identifier (e.g. `2026-W14`) |
| `pts_start` | INT UNSIGNED | Points at the start of the week |

**PK:** `(user_id, week)`

### `wb_gam_leaderboard_cache`

Leaderboard snapshot. Written by `wb_gam_leaderboard_snapshot` cron job; read by `LeaderboardEngine`. Note: `rank` is backtick-escaped because it is a MySQL 8.0 reserved word.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `user_id` | BIGINT UNSIGNED | |
| `period` | VARCHAR(20) | `all`, `month`, `week`, `day` |
| `total_points` | BIGINT | Points total for this period |
| `rank` | INT UNSIGNED | Position in the leaderboard |
| `updated_at` | DATETIME | Snapshot timestamp (UTC) |

**Indexes:** `idx_period_rank (period, rank)`, `idx_user_period (user_id, period)`

---

## Additional Tables

The tables above cover the primary state. The full schema also includes these supporting tables (see `src/Engine/Installer.php` for their exact columns):

| Table | Purpose |
|---|---|
| `wb_gam_point_types` | Currency definitions (Points, XP, Coins, etc.); seeds the default `points` currency |
| `wb_gam_point_type_conversions` | Currency-to-currency conversion rules |
| `wb_gam_user_totals` | Per-user cached point totals (avoids `SUM()` over the ledger on every read) |
| `wb_gam_submissions` | Member-submitted (UGC) achievement queue awaiting admin approval |
| `wb_gam_api_keys` | API key credentials (hashed; prefix/suffix stored for display) |
| `wb_gam_notifications_queue` | Durable per-member notification/toast queue |
| `wb_gam_side_effect_failures` | Dead-letter queue for failed async side-effects (retried by cron) |
| `wb_gam_user_intelligence` | Per-user engagement/intelligence scores (computed daily) |

The `wb_gam_points`, `wb_gam_events`, and `wb_gam_redemption_items` tables also carry a `point_type` column so every currency shares one ledger.

---

## Version Tracking

```php
// Check current DB version.
$version = get_option( 'wb_gam_db_version', '0.0.0' );

// After Installer::install() or DbUpgrader, the version is set to WB_GAM_VERSION.
update_option( 'wb_gam_db_version', WB_GAM_VERSION );
```

Run `wp wb-gamification doctor` to check whether your database schema matches the installed plugin version.
