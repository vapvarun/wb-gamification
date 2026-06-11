---
journey: badge-expiry-visibility
plugin: wb-gamification
priority: critical
roles: [admin, member]
covers: [basecamp-9985131435, zero-date-expires-at, badge-display-read-path]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one badge definition without validity_days exists (e.g. century_club)"
estimated_runtime_minutes: 4
---

# Badge award → stored expires_at is SQL NULL → badge visible on every read surface

Locks Basecamp 9985131435 (customer-reported, 1.5.0–1.5.3): `award_badge()` passed
PHP null through `$wpdb->prepare()` `%s`, which stores `''` → zero-date
`0000-00-00 00:00:00` in the DATETIME `expires_at` column on non-strict MySQL.
Zero-dates fail the `expires_at IS NULL OR expires_at > now` visibility filter, so
badges existed in `wb_gam_user_badges` but counted 0 and displayed nowhere —
profiles said "No badges to show yet", the Members admin page said Badges: 0,
while re-awarding still answered "already holds" (the UNIQUE key blocked the
INSERT IGNORE, masking the read-path break). If this journey fails, every badge
a customer's members earn silently disappears from the UI.

## Setup

- Site: `$SITE_URL`
- Test user: `qa_member` (autologin via `?autologin=qa_member`)
- Fixtures: pick a never-expiring badge def the test user does NOT hold yet
- DB clean (teardown):
  ```sql
  DELETE FROM wp_wb_gam_user_badges WHERE badge_id = '<TEST_BADGE>' AND user_id = <QA_MEMBER_ID>;
  ```

## Steps

### 1. Award a never-expiring badge through the engine
- **Action**: `wp eval 'var_export( \WBGam\Engine\BadgeEngine::award_badge( <QA_MEMBER_ID>, "<TEST_BADGE>" ) );'`
- **Expect**: `true`
- **On fail**: gates in `src/Engine/BadgeEngine.php::award_badge()` (closes_at / max_earners / filter)

### 2. Stored expires_at is genuine SQL NULL — not a zero-date
- **Action**: `mysql_query "SELECT expires_at IS NULL AS is_null, COALESCE(CAST(expires_at AS CHAR),'SQL-NULL') AS raw FROM wp_wb_gam_user_badges WHERE user_id = <QA_MEMBER_ID> AND badge_id = '<TEST_BADGE>'"`
- **Expect**: `is_null = 1`, `raw = 'SQL-NULL'`
- **On fail**: `src/Engine/BadgeEngine.php` INSERT branch — null must be a literal `NULL`, never bound to `%s`

### 3. Filtered read paths see the badge
- **Action**: `wp eval 'echo \WBGam\Engine\BadgeEngine::count_user_badges( <QA_MEMBER_ID> ) . "|" . (int) \WBGam\Engine\BadgeEngine::has_badge( <QA_MEMBER_ID>, "<TEST_BADGE>" );'`
- **Expect**: count ≥ 1 AND has_badge = 1
- **On fail**: visibility filter in `get_user_earned_badge_ids()` vs stored expires_at values

### 4. Members admin page shows a non-zero badge count
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wb-gamification-members&autologin=1`, search `qa_member`
- **Expect**: Badges column for qa_member ≥ 1 (not 0)
- **On fail**: `src/API/MembersController.php` roster loop (`count( BadgeEngine::get_user_badges() )`)

### 5. No zero-date rows exist table-wide (doctor integrity gate)
- **Action**: `wp wb-gamification doctor | grep -A 2 "Expiry Integrity"`
- **Expect**: `✓ No zero-date expires_at rows on earned badges`
- **On fail**: run `wp wb-gamification doctor --fix` once; if rows reappear, a writer is still binding null through prepare — audit all `wb_gam_user_badges` INSERT/UPDATE sites

## Pass criteria

ALL of the following hold:
1. Step 2: `expires_at IS NULL` is true for the fresh award.
2. Step 3: `count_user_badges` includes the fresh award and `has_badge` is true.
3. Step 4: Members page renders a non-zero Badges count for the test user.
4. Step 5: doctor's Earned-Badge Expiry Integrity check passes with zero broken rows.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| raw = '0000-00-00 00:00:00' on fresh award | null bound through prepare %s again | `src/Engine/BadgeEngine.php` (award_badge INSERT) |
| count 0 but row exists with NULL | cache staleness — award didn't bust `wb_gam_earned_badges_{id}` | `src/Engine/BadgeEngine.php:287` |
| doctor finds broken rows on an upgraded site | migration didn't run | `src/Engine/DbUpgrader.php::upgrade_to_1_5_4` + `wb_gam_db_version` option |
| profile shows badges but Members page 0 | roster JOIN path diverged from engine read | `src/API/MembersController.php` roster loop |
