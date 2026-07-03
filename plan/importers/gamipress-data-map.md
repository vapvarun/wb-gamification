# GamiPress 7.9.5 — Data-Storage Map for Lossless Import

Analyzed 2026-07-03 from a pristine wp.org download (source extracted alongside this
plugin for study — do NOT activate on customer sites). Basecamp epic: card 10061744564.
Companion docs: `mycred-data-map.md`, `badgeos-data-map.md`, `_import-architecture.md`.

**Import rule (owner directive):** the importer READS the source plugin's DB via SQL
and WRITES exclusively through our API/engine (provenance events) — never direct
inserts into `wb_gam_*` tables. No data gaps allowed: every source location below must
be either mapped or explicitly reported as skipped-with-reason in the dry-run report.

GamiPress stores data in **4 places**: custom CT tables (logs + earnings + their meta),
posts + postmeta (ALL definitions), user_meta (balances / ranks / trigger counts), and
one `gamipress_settings` option.

---

## 1. Custom DB tables (CT library; blog prefix, or base_prefix when network-active)

Registered via `ct_register_table()` at `includes/custom-tables.php:30-155`; dbDelta in
`libraries/ct/includes/class-ct-database.php:371-385`.

### `wp_gamipress_user_earnings` (schema v4, `custom-tables.php:51-86`) — the ledger
One row per earned thing (achievement, step, points-award, points-deduct, rank,
rank-requirement).

| Column | Type | Semantics |
|---|---|---|
| user_earning_id | bigint PK AI | Row id |
| title | text | Snapshot of earned item's title |
| user_id | bigint KEY | Earner |
| post_id | bigint KEY | Earned post ID |
| post_type | varchar(50) | achievement-type slug / `step` / `points-award` / `points-deduct` / rank-type slug / `rank-requirement` |
| points | bigint | Points tied to this earning (0 if none) |
| points_type | varchar(50) | Points-type slug |
| date | datetime | When earned |

Insert path (reference): `gamipress_insert_user_earning()` `includes/functions/user-earnings.php:23-142`.

### `wp_gamipress_user_earnings_meta`
`meta_id` / `user_earning_id` KEY / `meta_key` / `meta_value`. Known key:
`_gamipress_parent_post_type` — parent reward's post_type (or points-type slug for
awards/deducts); set `user-earnings.php:99-116`.

### `wp_gamipress_logs` (schema v6, `custom-tables.php:108-152`) — activity stream (BIGGEST)
| Column | Notes |
|---|---|
| log_id | bigint PK AI |
| title | rendered from pattern |
| type KEY | `event_trigger`, `achievement_earn`, `achievement_award`, `points_earn`, `points_award`, `points_revoke`, `rank_earn`, `rank_award` |
| trigger_type KEY | trigger hook slug (e.g. `gamipress_login`) |
| access | public/private |
| user_id KEY | |
| points KEY | delta (column since 6.9.4; legacy sites: meta `_gamipress_points`) |
| points_type KEY | |
| date | datetime |

### `wp_gamipress_logs_meta` — keys written via `gamipress_insert_log()` `logs.php:503-578`:
`_gamipress_achievement_id` (earned/awarded post ID — semantic type derives from
JOINing this post's post_type, `logs.php:277-304`), `_gamipress_trigger_type`,
`_gamipress_achievement_post` (+ `_site_id`), `_gamipress_post_id`,
`_gamipress_admin_id` (admin awards/revokes), `_gamipress_points` +
`_gamipress_points_type` (legacy), `_gamipress_pattern`, `_gamipress_count`,
`_gamipress_legacy_log_id` (1.2.8 migration relic).

---

## 2. Post types + postmeta (`includes/post-types.php:282-682`)

Type definitions ARE posts; the type's `post_name` (slug) is interpolated into user
meta keys and becomes a dynamic CPT slug for instances.

**Static CPTs:** `points-type` (:291), `points-award` (:328, post_parent=points-type),
`points-deduct` (:362), `achievement-type` (:394), `step` (:431,
post_parent=achievement), `rank-type` (:463), `rank-requirement` (:500,
post_parent=rank). Requirement order = `menu_order`; rank LADDER order = `menu_order`
(`ranks.php:303-408`).

**Dynamic CPTs:** one per achievement-type slug (:533-604) = the badges; one per
rank-type slug (:608-679) = the ranks.

**Postmeta (exhaustive):**
- points-type: `_gamipress_plural_name`, `_gamipress_label_position`,
  `_gamipress_thousands_separator`, `_gamipress_html_display` (+ thumbnail icon)
- achievement-type / rank-type: `_gamipress_plural_name`
- Achievement instances (`meta-boxes/achievements.php`): `_gamipress_earned_by`
  (triggers|points|rank|admin), `_gamipress_points`, `_gamipress_points_type`,
  `_gamipress_points_required`, `_gamipress_points_type_required`,
  `_gamipress_rank_type_required`, `_gamipress_rank_required`,
  `_gamipress_congratulations_text`, `_gamipress_maximum_earnings`,
  `_gamipress_global_maximum_earnings`, `_gamipress_hidden`,
  `_gamipress_unlock_with_points`, `_gamipress_points_to_unlock`,
  `_gamipress_points_type_to_unlock`, `_gamipress_show_times_earned`,
  `_gamipress_show_global_times_earned`, `_gamipress_show_earners`,
  `_gamipress_maximum_earners`, `_gamipress_layout`, `_gamipress_align`
- Rank instances (`meta-boxes/ranks.php`): `_gamipress_congratulations_text`,
  `_gamipress_unlock_with_points` (+ points/type to unlock), `_gamipress_show_earners`,
  `_gamipress_maximum_earners`, `_gamipress_layout`, `_gamipress_align`,
  `_gamipress_next_rank` / `_gamipress_prev_rank` (cached ladder pointers)
- Requirements (rules engine; full read map `requirements.php:82-152`):
  `_gamipress_trigger_type`, `_gamipress_count`, `_gamipress_limit` +
  `_gamipress_limit_type`, `_gamipress_achievement_type`,
  `_gamipress_achievement_post` (+ `_site_id`), `_gamipress_points` +
  `_gamipress_points_type`, `_gamipress_maximum_earnings`, `_gamipress_optional`,
  `_gamipress_url`, `_gamipress_points_condition`, `_gamipress_points_required` +
  `_gamipress_points_type_required`, `_gamipress_rank_required` +
  `_gamipress_rank_type_required`, `_gamipress_post_type_required`,
  `_gamipress_user_role_required`, `_gamipress_meta_key_required` +
  `_gamipress_meta_value_required`

---

## 3. User data (user_meta; `includes/users.php:17-70`)

Per points-type slug `{pt}` (`includes/functions/points.php`):
- **`_gamipress_{pt}_points` — CURRENT BALANCE (authoritative)**; legacy fallback
  `_gamipress_points`. Read :66-73, written :560-573.
- `_gamipress_{pt}_points_awarded` / `_deducted` / `_expended` — lifetime aggregates
  (lazily recomputed from logs if missing, :115-143)
- `_gamipress_{pt}_new_points` — last-delta notification flag (skip)

Per rank-type slug `{rt}` (`includes/functions/ranks.php`):
- **`_gamipress_{rt}_rank` — current rank post ID (authoritative)**; fallback
  `_gamipress_rank`. Written :675-678.
- `_gamipress_{rt}_rank_earned_time` — Unix ts (:679)
- `_gamipress_{rt}_previous_rank` (:684-685)

Rules-engine state:
- `_gamipress_triggered_triggers` — nested `[site_id][trigger] => count`
  (`triggers.php:1499,1647,1686`). Prevents re-awards; replaying raw events without it
  would DOUBLE-AWARD → we import materialized state, never replay `event_trigger` logs.

Earned achievements/ranks are NOT in user_meta — they live in the earnings table
(`users.php:112-227`).

---

## 4. Options

One array option **`gamipress_settings`** via `gamipress_get_option()`
(`functions.php:37-52`; network → get_site_option). Sub-keys: `minimum_role`,
`{points|achievement|rank}_image_size`, `debug_mode`, `disable_css/js/admin_bar_menu/
shortcodes_editor`, email engine (`email_template`, `email_from_name`,
`email_from_address`, `email_footer_text`, per-event `*_subject`/`*_content`/
`disable_*` for achievement_earned / step_completed / points_award_completed /
points_deduct_completed / rank_earned / rank_requirement_completed), social
(`enable_share`, `enable_open_graph_tags`, `social_networks`, `social_button_style`,
`twitter_achievement_text`, `twitter_rank_text`).

Bookkeeping (skip): `gamipress_install_date`, `gamipress_version`,
`gamipress_completed_upgrades`, `wpdb_gamipress_*_version`, `gamipress_cache_*`.

---

## 5. Their APIs (read reference for our SQL)

PHP: `gamipress_get_user_points`, `gamipress_get_user_rank(_id)`,
`gamipress_get_user_achievements`, `gamipress_get_earnings_count`,
`gamipress_get_user_trigger_count`; write-side equivalents
`gamipress_award_points_to_user`, `gamipress_award_achievement_to_user`,
`gamipress_update_user_rank`, `gamipress_insert_user_earning`, `gamipress_insert_log`.

REST: CT tables auto-expose under `wp/v2` — `/wp-json/wp/v2/gamipress-logs` and
`/wp-json/wp/v2/gamipress-user-earnings` (`class-ct-rest-controller.php:57,75-91`);
type posts have `show_in_rest=true`.

Their own CSV tools (correctness reference): `includes/admin/tools/
import-export-{points,earnings,achievements,ranks,settings,setup}.php` — points CSV is
(user, points, points_type slug) `:142-145`. **No BadgeOS/myCred importer in free core**
(those are paid add-ons; not in this zip).

---

## 6. Mapping → wb-gamification (write via OUR API only)

| Source | Our concept | Write path | Idempotency key |
|---|---|---|---|
| `points-type` post + meta | Point type | `POST /point-types` | source slug |
| `_gamipress_{pt}_points` user_meta | Balance | `POST /points/bulk` import-mode, reason "Imported from GamiPress" | user+pt "balance" |
| `achievement-type` + instance posts | Badge | `POST /badges` | source post ID |
| earnings rows (post_type ∈ achievement slugs) | Badge award | `POST /badges/{id}/award` w/ `date` | user_earning_id |
| `rank-type` + rank posts (menu_order) | Level ladder | `POST /levels` | source post ID |
| `_gamipress_{rt}_rank` (+earned_time) | Current level | level award backdated | user+rt |
| points-flavored logs (`points_earn/award/revoke`, `achievement_award`, `rank_award`) | Provenance events (optional mode) | `POST /events` import-mode | log_id |
| `gamipress_settings` | Config report | dry-run report note | — |

**Decision rule (prevents double-count):** import EITHER balance-snapshot (default)
OR full ledger-replay from earnings/logs — never both. Ledger-replay mode must end by
reconciling final computed balance against `_gamipress_{pt}_points` and reporting any
mismatch.

### GAPs (skip-with-report, decided)
- Requirements/rules engine (steps, points-awards/deducts, rank-requirements + ~24
  condition metas) → import as human-readable config report; do NOT recreate
  automation. Owner re-creates earning rules in our Settings > Points/Rules.
- `_gamipress_triggered_triggers` → skip (rules-engine state; meaningless without it).
- Raw `event_trigger` logs → skip by default (archive-export option only).
- Display metas (layout/align/html_display/congrats text/unlock-with-points) → per-item
  config report; unlock-with-points maps loosely to redemption but semantics differ —
  report, don't guess.
- Multisite network-global tables / `achievement_post_site_id` → flag when source is
  multisite; our v1 target is single-site.

### Receiving-side API gaps THIS analysis confirms we must build first
1. `POST /events` has no `occurred_at` — `EventsController` args are only
   action_id/user_id/object_id/metadata; `PointsEngine` stamps
   `created_at = current_time('mysql')` (src/Engine/PointsEngine.php:331).
2. No import mode: historical writes must bypass rate limits/caps/cooldowns and
   suppress side-effects (emails, toasts, webhooks, BP notifications).
3. Badge award backdating — verify/extend award path to accept an earned-at timestamp.
4. Idempotency: import-mode writes need a provenance/external-id uniqueness check so
   re-running a crashed import never double-awards.

---

## 7. Scale + batching

- `wp_gamipress_logs`(+meta) is the monster table (millions of rows on active sites;
  GamiPress does NOT auto-prune — manual clean-up tool only). Batch by
  `log_id BETWEEN ? AND ?` ranges, never OFFSET. Indexed: type, trigger_type, user_id,
  points, points_type; date range-filterable.
- Earnings: batch by `user_earning_id` ranges.
- Pre-import sizing: `SELECT COUNT(*)` per table +
  `SELECT post_type, COUNT(*) FROM wp_gamipress_user_earnings GROUP BY post_type`.
- Import order: settings-report → types (slug→ID map) → requirements-report →
  balances (/points/bulk) → current ranks → badge awards → optional provenance events.
  All via Action Scheduler batches, resumable, with WP-CLI parallel path.
