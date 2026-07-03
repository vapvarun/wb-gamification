# BadgeOS 3.7.1.6 — Data-Storage Map for Lossless Import

Analyzed 2026-07-03 from the last free wp.org build (source extracted alongside this
plugin for study — do NOT activate on customer sites). Basecamp epic: card 10061744564.
FIRST import target: BadgeOS refugees (abandoned/premium-forked; institutional removals).

**Import rule:** READ their DB via SQL (incl. unserializing legacy meta), WRITE only via
our API/engine with provenance. No data gaps: every location below is mapped or
skipped-with-reason in the dry-run report.

---

## 1. Storage model — DUAL PATH (the defining complication)

This version uses BOTH a custom-table path AND a legacy user-meta path, decided at
runtime by table existence (`SHOW TABLES LIKE`, `user.php:50-52`). Real sites exist in
three states: legacy-only (old installs where tables never got created), table-only,
and migrated (BOTH copies present — migration does NOT delete legacy meta).

- Table creation: `db_upgrade()` `badgeos.php:137-360`, raw CREATE TABLE guarded by
  SHOW TABLES (not dbDelta). Tables: `{prefix}badgeos_achievements`, `_points`,
  `_ranks` + bundled P2P `{prefix}p2p`/`p2pmeta`.
- Meta→table migration: `includes/meta-to-db.php` — cron
  `cron_migrate_data_from_meta_to_db`; migrated rows get `rec_type='normal_old'` and
  `this_trigger='m2dbold:{entry}:{trigger}'`; flags: user meta
  `updated_achivements_meta_to_db='Yes'`, option `badgeos_all_achievement_db_updated`.

**Importer precedence rule:** table rows win when the table exists and has rows for the
user; if `updated_achivements_meta_to_db='Yes'`, legacy `_badgeos_achievements` is a
stale duplicate of `normal_old` rows — import table, skip meta. Table absent → import
legacy meta.

## 2. Custom tables (PRIMARY KEY only — no user_id indexes; full-scan is fine one-shot)

### `{prefix}badgeos_achievements` (`badgeos.php:144-161`) — earned achievements
entry_id PK AI · ID (achievement post ID) · sub_nom_id (submissions, ALTER-added) ·
post_type (type slug) · achievement_title TEXT (denormalized; may be truncated on
pre-widen sites — fall back to get_the_title) · rec_type ('normal'|'normal_old') ·
points · point_type (point-type POST ID as string) · user_id · this_trigger ·
image · site_id · actual_date_earned ts (ALTER-added) · date_earned ts (canonical).

### `{prefix}badgeos_points` (`badgeos.php:170-183`) — points LEDGER (authoritative)
id PK AI · achievement_id · credit_id (point-type post ID = currency) · step_id ·
user_id · admin_id · type ENUM('Award','Deduct','Utilized') · this_trigger · credit
(amount) · actual_date_earned · dateadded.
Balance is DERIVED: sum Award − (Deduct+Utilized), per credit_id
(`point-rules-engine.php:1037-1101`). Writer: `badgeos_add_credit()` (:626-695).

### `{prefix}badgeos_ranks` (`badgeos.php:190-204`) — rank history
id PK AI · rank_id (post ID) · rank_type (slug) · rank_title TEXT · credit_id ·
credit_amount · user_id · admin_id · this_trigger · priority (= menu_order) ·
actual_date_earned · dateadded. Writer: `badgeos_add_rank()` (`rank-functions.php:1701`).

### `{prefix}p2p` / `p2pmeta` — REQUIREMENT GRAPH
Step→achievement and step→rank links live HERE (Posts-2-Posts), not postmeta.
p2p_type strings like `"{step_post_type}-to-{achievement_type}"` (`steps-ui.php:70,303`),
from=step id, to=achievement id.

## 3. Post types + postmeta

**Slugs are CONFIGURABLE via `badgeos_settings`** (defaults `badgeos.php:259-271`):
achievement_main_post_type=`achievement-type`, achievement_step_post_type=`step`,
ranks_main_post_type=`rank_types`, ranks_step_post_type=`rank_requirement`,
points_main_post_type=`point_type`, points_award_post_type=`point_award`,
points_deduct_post_type=`point_deduct`. Log CPT hardcoded `badgeos-log-entry`.

**Dynamic CPTs:** badge posts live under per-type slugs =
`sanitize_title(substr(strtolower(type post_title),0,20))` (`post-types.php:223`) —
20-char truncation can collide; ALWAYS enumerate via the type CPTs, never assume slugs.
Rank posts: slug = rank-type post_name (`ranks/post-types.php:140`).

**Badge definition meta** (`meta-boxes.php:77-213`): `_badgeos_points` (ARRAY
`['_badgeos_points'=>v,'_badgeos_points_type'=>type_post_id]` — or bare int on old
sites, see §8), `_badgeos_points_required` (same dual shape), `_badgeos_earned_by`
(triggers|points|admin), `_badgeos_sequential`, `_badgeos_show_earners`,
`_badgeos_congratulations_text`, `_badgeos_revoke_badge_point_loss`,
`_badgeos_maximum_earnings` (default 1, -1 unlimited), `_badgeos_award_by_one_step_out_of_many`,
`_badgeos_hidden` (show|hidden). Type-definition meta: `_badgeos_plural_name`,
`_badgeos_singular_name`, `_badgeos_show_in_menu`.

**Step meta:** `_badgeos_trigger_type`, `_badgeos_count`, `_badgeos_achievement_post`,
`_badgeos_achievement_type`, `_badgeos_visit_post`, `_badgeos_visit_page`,
`_badgeos_num_of_days/_months/_years`, `_badgeos_num_of_days_login`,
`_badgeos_x_number_of_users(_date)`, `_badgeos_subtrigger_id/_value`,
`_badgeos_fields_data`, `_badgeos_last_login`, `_badgeos_date_of_birth`.

**Point type meta:** `_point_plural_name` (singular = post title!), `_point_image`.
Point award/deduct steps: `_point_value`, `_point_type`, `_point_trigger_type` /
`_deduct_trigger_type`, `_badgeos_count`, `_badgeos_visit_page`.

**Rank meta:** `_ranks_plural_name`, `_ranks_show_in_menu`,
`_ranks_congratulations_text`, `_ranks_points` (array w/ type),
`_ranks_unlock_with_points`, `_ranks_points_to_unlock`; ladder order = menu_order.

## 4. User data (CRITICAL)

**Legacy `_badgeos_achievements`** — serialized `array($site_id => array(stdClass...))`
(`user.php:139`). Object fields: ID, title (may be empty → get_the_title), the_trigger
+ trigger, post_type, image, rec_type, points (int OR array — dual shape),
point_type, **date_earned = Unix epoch INT** (`achievement-functions.php:387`);
started-context objects carry date_started/last_activity_date instead.

**`_badgeos_active_achievements`** — in-progress achievements (keyed by achievement_id,
date_started/last_activity_date). Always meta; never migrated.

**Points:** `_badgeos_points` user meta = cached grand-total across ALL types —
NOT authoritative, can drift; ledger is truth. Per-type balances are computed on
demand, never stored.

**Ranks:** `_badgeos_{rank_type}_rank` = current rank ENTRY_ID in badgeos_ranks table
(`rank-functions.php:1720`) — an entry id, not a post id! Plus
`_badgeos_{rank_type}_rank_earned_time`.

**Full user-meta key list:** _badgeos_achievements, _badgeos_active_achievements,
_badgeos_points, _badgeos_{rank_type}_rank, _badgeos_{rank_type}_rank_earned_time,
_rank_earned_time, _badgeos_ranks_triggers, _ranks_triggers, _badgeos_ranks_filter,
_badgeos_achievement_filter, _badgeos_triggered_triggers, _point_award_triggers,
_point_deduct_triggers, _badgeos_can_notify_user, _badgeos_last_login,
_badgeos_date_of_birth, badgeos_date_of_birth, badgeos_daily_visits,
badgeos_daily_visit_date, updated_achivements_meta_to_db, _badgeos_ob_achievements,
_badgeos_validate_open_badge.

## 5. Options

`badgeos_settings` (defaults `badgeos.php:254-281`): the 7 post-type slug keys,
`default_point_type` (fallback currency post id), minimum_role,
submission_manager_role, submission_email, debug_mode, **`log_entries`**
(enabled|disabled — if disabled, NO activity history exists; ledger tables are the only
timeline), badgeos_not_earned_image, ms_show_all_achievements, image-size keys,
shortcode default views, remove_data_on_uninstall.
`badgeos_admin_tools` — ALL email templates/subjects/colors (defaults :284-356).
Open Badges: badgeos_assertion_url / issuer_url / json_url / evidence_url (+page ids,
issuer name). Migration flags: badgeos_db_update_v_338, badgeos_rec_title_updated,
badgeos_all_achievement_db_updated, p2p_storage.

## 6. Their APIs

PHP read refs: badgeos_get_user_achievements (`user.php:19`), badgeos_get_users_points
(`point-functions.php:19`), badgeos_get_points_by_type, badgeos_get_user_ranks,
badgeos_get_user_rank_id, badgeos_get_rank_earned_time.
**REST: 8 read-only PUBLIC (`__return_true`) block-editor helper routes under
`badgeos` namespace only — NO per-user earned data, NO write API.** Importer MUST read
the DB directly; no supported export exists.
**Activity log:** CPT `badgeos-log-entry` (post_author=user, post_date=time,
post_title=message; meta `_badgeos_log_achievement_id`, `_badgeos_awarded_points`,
`_badgeos_total_user_points`, `_badgeos_admin_awarded`, `_badgeos_rank_id`,
`_badgeos_action`, `_badgeos_admin_id`) — only if log_entries was enabled.

## 7. Mapping → wb-gamification (write via OUR API only)

| Source | Our concept | Write path | Idempotency key |
|---|---|---|---|
| point_type posts + `_point_plural_name` | Point type | `POST /point-types` | source post ID |
| badgeos_points ledger rows | Points ledger | `POST /points/award` import-mode (Deduct/Utilized = negative), backdated to dateadded | ledger id |
| `_badgeos_points` cached total | — | SKIP; recompute from ledger, reconcile + report drift | — |
| achievement-type + badge posts + meta | Badge defs | `POST /badges` | source post ID |
| badgeos_achievements rows OR legacy meta objects (per §1 precedence) | Badge awards | `POST /badges/{id}/award` backdated to date_earned | entry_id / meta-hash |
| rank types + rank posts (menu_order) | Levels | `POST /levels` | source post ID |
| badgeos_ranks rows + `_badgeos_{t}_rank`(+earned_time) | User level | level award backdated (NOTE: current-rank meta stores ENTRY id → resolve via table) | ranks.id |
| badgeos-log-entry posts | Provenance events (optional) | `POST /events` import-mode | log post ID |
| steps + P2P graph + trigger meta | Rules | config REPORT (best-effort recreate later); log unmapped trigger types | — |

### GAPs / flags
1. **Backdating API gap (confirmed on our side):** every earned record carries a real
   timestamp; our POST /events, /points/award, /badges/{id}/award, level-set have no
   timestamp param today → prerequisite card (see `_import-architecture.md`).
2. Steps/requirements in P2P → outcomes imported faithfully; rules as report.
3. Submissions/nominations (sub_nom_id, roles) → out of scope; keep sub_nom_id in provenance.
4. Credly/Open Badges definition flags + `_badgeos_ob_achievements` → badge metadata
   provenance only; never re-issue external credentials. (Our OpenBadges 3.0
   CredentialController can issue FRESH credentials post-import — marketing angle.)
5. Dynamic CPT slugs truncate at 20 chars and can collide → enumerate via type CPTs.

## 8. Version drift the importer MUST tolerate (1.4 → 3.7.1.6)

- **No tables at all** on old installs → legacy-meta-only branch.
- `_badgeos_points` badge meta: bare int (old) vs array (new) — branch on is_array();
  scalar resolves type via `badgeos_settings['default_point_type']`
  (migration ref: `achievement-upgrade.php:46-108`).
- Legacy date_earned = Unix epoch int vs table datetime — convert
  (`meta-to-db.php:116` pattern).
- `actual_date_earned` ALTER-added → prefer date_earned/dateadded as canonical.
- Truncated denormalized titles pre-TEXT-widen → fall back to get_the_title(ID).
- `sub_nom_id` may be absent on old rows → treat as 0.
- `rec_type='normal_old'` + legacy meta = SAME earn twice → dedupe per §1 rule.
- Definitive shape record: `includes/meta-to-db.php`, `achievement-upgrade.php`,
  `achievement-upgrade-fix.php`, `db_upgrade()` ALTERs (`badgeos.php:208-359`).
