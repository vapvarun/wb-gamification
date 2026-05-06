# Multi-Point Types — Implementation Plan

**Status:** active spec
**Owner:** Varun
**Started:** 2026-05-06
**Driving feature card:** [`9860174792`](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860004458) — v1.0 critical-gap #1

---

## Why this exists

Per `plan/COMPETITIVE-ANALYSIS.md` § Critical Gap #1, GamiPress + myCred both let admins create separate point ledgers (XP for learning, Coins for shopping, Karma for community moderation, etc). WB Gamification shipped with a single ledger; an RFP that asks for "XP for learning + Coins for shop" disqualifies us today.

The infrastructure to fix this is already merged — what's left is wiring every consumer surface to the new currency model so the experience feels uniform end-to-end.

---

## Architecture summary (current state, post-merge)

### Storage

```
{prefix}wb_gam_point_types     ← catalog of currencies (slug PK)
  ├─ slug VARCHAR(60) PK       — e.g. 'points', 'xp', 'coins'
  ├─ label VARCHAR(100)
  ├─ description TEXT
  ├─ icon VARCHAR(100)         — Lucide icon name
  ├─ is_default TINYINT        — exactly one row has 1
  ├─ position INT              — display order
  └─ created_at DATETIME

{prefix}wb_gam_points           ← ledger (existing table + new column)
  └─ point_type VARCHAR(60) NOT NULL DEFAULT 'points'
     KEY idx_user_type_created (user_id, point_type, created_at)

{prefix}wb_gam_events           ← event log (existing table + new column)
  └─ point_type VARCHAR(60) NOT NULL DEFAULT 'points'
     KEY idx_user_type_created (user_id, point_type, created_at)
```

### Layered code (canonical 7-layer per `plan/ARCHITECTURE.md`)

```
src/Repository/PointTypeRepository.php   ← SQL only
    all() / find() / exists() / default_slug() / insert() / update() / delete() / set_default() / normalise_slug()

src/Services/PointTypeService.php        ← business logic
    list() / get() / resolve($input) / create() / update() / delete() / default_slug()

src/Engine/PointsEngine.php              ← extended
    award($user_id, $action_id, $points, $object_id, ?$type)
    debit($user_id, $amount, $action_id, $event_id, ?$type)
    get_total($user_id, ?$type)
    get_totals_by_type($user_id) → array<slug, int>
    get_history($user_id, $limit, ?$type)
    insert_point_row(Event $event, int $points)   ← reads metadata['point_type']

src/Engine/Engine.php                    ← persist_event() records point_type

src/API/PointTypesController.php         ← REST: /point-types CRUD
src/API/PointsController.php             ← /points/award accepts point_type param

src/Admin/PointTypesPage.php             ← submenu: list/create/edit/delete
src/Admin/ManualAwardPage.php            ← Currency picker added
```

### Public extension contract

- `PointTypeService::resolve($input)` is the canonical "coerce to known slug" helper. **Every consumer** must run user input through this before threading into the engine — never trust raw slugs from `$_POST` / block attrs.
- Slug `'points'` is the protected primary type. It's the default for every column on insert. Sites that never create additional types get the existing single-currency experience for free.

---

## Phase plan

### Phase 1 — Earning-side: manifest threading

Currently every action in `integrations/<host>.php` implicitly awards the primary type. Make `point_type` an optional manifest field so a host can declare "this action awards XP, not Points."

| File | Change |
|---|---|
| `integrations/<host>.php` (manifest schema) | New optional key `'point_type' => 'xp'` per action entry. Default = primary type. |
| `src/Engine/Registry.php` | When normalising a manifest entry, copy `point_type` into the action config. Validate via `PointTypeService::resolve()` so unknown slugs fall back gracefully. |
| `src/Engine/Engine.php` (`process()`) | Pull `$action['point_type']` and stamp into the Event's metadata before persistence. `PointsEngine::insert_point_row()` already reads from there. |
| `src/Engine/PointsEngine.php` rate-limit checks | `passes_rate_limits()` cooldown / daily / weekly caps must filter by `(user_id, action_id, point_type)` triple — currently scopes to `(user_id, action_id)`. Add `WHERE point_type = %s` to the helper queries. |

**Acceptance**: a fresh integration `integrations/learndash.php` action declared with `point_type => 'xp'` writes XP-typed rows on completion; legacy actions without the key continue to write the primary type. Verified via wppqa journey + manual smoke.

**Risk**: if rate-limit helpers don't get updated, daily caps cross-contaminate types (e.g. user can't earn XP today because they hit their points cap). The `idx_user_type_created` index makes the new query path fast.

---

### Phase 2 — Display-side: blocks + shortcodes

Blocks currently call `PointsEngine::get_total($user_id)` which now returns the **primary-type** total only. Each surface needs an attribute + scoped read.

#### 2a. Block attribute schema

Add `pointType` (string, default `''` = primary) to:

| Block | Behaviour with attr |
|---|---|
| `member-points` | scope balance to type |
| `points-history` | filter history rows |
| `level-progress` | level pulled from per-type levels (deferred to Phase 4 — for now show primary) |
| `leaderboard` | scope to type — needs Phase 3 cache key change |
| `top-members` | same as leaderboard |
| `cohort-rank` | same |
| `hub` | NEW: shows ALL active types as tiles (not just one) — major UX shift |

Edit pattern in `src/Blocks/<slug>/block.json`:

```json
"attributes": {
    "pointType": { "type": "string", "default": "" }
}
```

Edit pattern in `src/Blocks/<slug>/render.php`:

```php
$wb_gam_type = (string) ( $attributes['pointType'] ?? '' );
$wb_gam_pts  = (int) PointsEngine::get_total( $wb_gam_user_id, $wb_gam_type );
```

Edit pattern in `src/Blocks/<slug>/edit.js`: add a SelectControl populated from `wp.apiFetch('/wb-gamification/v1/point-types')` so editors pick the currency without typing the slug.

#### 2b. Shortcode parity

`WBGam\Engine\ShortcodeHandler` already exists — extend each shortcode handler to accept `type=""` attribute mirroring the block:

```
[wb_gam_member_points type="xp"]
[wb_gam_leaderboard type="coins" period="month"]
```

#### 2c. Hub block — multi-currency aware

Hub currently shows a single point total. Convert to per-type tile loop:

```php
$wb_gam_totals = PointsEngine::get_totals_by_type( $wb_gam_user_id );
// $wb_gam_totals = [ 'points' => 1349, 'xp' => 420, 'coins' => 50 ]
foreach ( $wb_gam_totals as $type_slug => $balance ) {
    $type_meta = $service->get( $type_slug );
    // render tile with $type_meta['label'] + $type_meta['icon'] + $balance
}
```

Sites that never create extra types render exactly one tile (the primary) — zero visible regression for current users.

**Acceptance**: 
- Toggle-test: change a block's `pointType` to `'xp'` in the editor, save, view front-end → balance reflects XP not Points.
- Hub block on a multi-currency site shows all 3 currencies as tiles.
- All 15 blocks render identically when no extra types exist (back-compat smoke).

---

### Phase 3 — Cross-currency surfaces

#### 3a. Members REST endpoint

`GET /members/{id}/points` today returns `{ total, history }`. Extend response shape to include per-type breakdown:

```json
{
  "total": 1349,
  "history": [...],
  "by_type": {
    "points": 1349,
    "xp": 420,
    "coins": 50
  },
  "primary": "points"
}
```

`total` continues to mean primary-type total (back-compat). Existing JS consumers (block render, mobile SDK) keep working.

#### 3b. Leaderboard cache

`{prefix}wb_gam_leaderboard_cache` is keyed by `(user_id, period)` today. Need to add `point_type` to the composite key:

```sql
ALTER TABLE wb_gam_leaderboard_cache
  ADD COLUMN point_type VARCHAR(60) NOT NULL DEFAULT 'points',
  DROP INDEX user_period,
  ADD UNIQUE KEY user_period_type (user_id, period, point_type);
```

Migration in `DbUpgrader::ensure_leaderboard_cache_v2()` (idempotent). Back-fill existing rows with `'points'` (the primary) so current rankings stay intact.

`LeaderboardEngine::rebuild()` cron iterates over all active types × all periods. For sites with N types and 4 periods, that's 4N cache passes — acceptable given each pass is one SUM query.

#### 3c. Redemption Store currency

Each reward in `wb_gam_redemption_items` currently has `points_cost`. Add `point_type` column (default `'points'`). Update:

- `RedemptionStorePage` admin form: Currency dropdown next to "Cost" (mirrors Manual Award)
- `RedemptionController::redeem()`: debit from the matching type
- Redemption-store block: show "Cost: 50 ⭐ Coins" with the type's icon + label
- Insufficient-balance check uses `PointsEngine::get_total($user_id, $reward['point_type'])` not the global total

**Acceptance**: 
- Create an XP-priced reward — "Insufficient" check uses XP balance, not Points balance
- Member with 5000 Points + 100 XP can buy the 50-XP reward; cannot buy the 200-XP reward

---

### Phase 4 — Long-tail (deferred to v1.0.x or v1.1)

| Item | Notes |
|---|---|
| **Levels per-type** | Add `point_type` to `wb_gam_levels`. Member can be Level 5 in Points and Level 2 in XP. Member-level lookup becomes `(user_id, point_type)` keyed. Big UX shift on level-progress block (3 progress bars instead of 1). |
| **Badges per-type** | Badge condition rule already supports `points_threshold` — add an optional `point_type` field to the rule config. Existing badges (no type → primary) keep working. |
| **Webhook payload** | Schema documentation update only — `point_type` is already in the event log, just expose it explicitly in the outbound payload + audit/ROLE_MATRIX.md. |
| **WP-CLI** | `wp wb-gamification points get --user=1 --type=xp` and `points award --user=1 --type=xp --amount=25`. |
| **Analytics** | Per-type filter chips on the Analytics dashboard (Last 30 days, Points / XP / Coins toggle). |
| **OpenBadges credentials** | Already type-agnostic; no change. |
| **WPDS frontend (member profile)** | BuddyPress profile widget per-type tile loop (mirrors Hub block). |

---

### Phase 5 — Migration UX

- **Onboarding wizard** asks "Do you want a single currency, or multiple (e.g. XP + Coins)?" — single → no change, multiple → wizard creates the second type and links to the Point Types admin page.
- **Settings re-organization**: Per `admin-ux-rulebook` Rule 1, Point Types is a CRUD page (DATA) so the submenu stays. *But* there's an open question on whether to also surface a "Currencies" tab inside the main Settings page sidebar so admins discover it. Decision: keep submenu as canonical entry; add a card link from the dashboard.
- **Docs**: `docs/website/getting-started/multi-currency.md` walks through creating an XP currency, scoping a LearnDash action to it, displaying it in the Hub.

---

## Sequencing recommendation

```
Sprint 1.0a (v1.0 launch-blocking)
├── Phase 1   — manifest + rate-limit (1-2 days)
├── Phase 2a  — block attributes (member-points, points-history, leaderboard, hub) (2 days)
├── Phase 2b  — shortcodes parity (0.5 day)
└── Phase 2c  — hub multi-currency tiles (1 day)

Sprint 1.0b (still v1.0 — needed for redemption parity)
├── Phase 3a  — members REST per-type breakdown (0.5 day)
├── Phase 3c  — redemption store currency (1 day)
└── Phase 5   — migration UX + docs (1 day)

Defer to v1.0.x / v1.1
└── Phase 3b  — leaderboard cache schema change (timing-sensitive)
└── Phase 4   — levels / badges / webhooks / WP-CLI / analytics
```

Total v1.0-blocking work: **~7 dev-days** for one engineer, less in parallel.

---

## Risks + back-compat invariants

1. **Default-type protection**: deleting the primary type via REST returns 409. Repository layer enforces — verified in unit smoke.
2. **Slug normalisation**: any user input passes through `PointTypeService::resolve()` before reaching the engine. Unknown / malformed slugs silently fall back to primary instead of erroring — Rule 0.5 of admin-ux-rulebook (no surprises).
3. **Schema column default**: every new column defaults to `'points'`. SELECT queries that don't filter by type continue to see all rows, just like before.
4. **Cache busting**: `PointsEngine::cache_key_total()` already keys on `(user_id, type)` — invalidations after a write are scoped, not global. Single-currency sites see no extra cache pressure.
5. **REST contract**: existing routes that returned `total` keep returning it (= primary type total). New `by_type` field is additive.
6. **Block back-compat**: `pointType=""` (the default) is treated as "primary" by every renderer — existing block instances persisted with no `pointType` attribute keep working.

---

## Acceptance criteria for "uniformly wired"

- [ ] An admin can create three currencies (Points, XP, Coins) in the Point Types admin page
- [ ] An action declared with `point_type: 'xp'` writes XP-typed rows
- [ ] A `wb-gamification/leaderboard` block with `pointType: 'coins'` shows the coins-only ranking
- [ ] `[wb_gam_member_points type="xp"]` shortcode renders the member's XP balance
- [ ] The Hub block shows three tiles (Points / XP / Coins) when three types exist; one tile when only the primary exists
- [ ] `GET /members/1/points` returns `by_type: { points, xp, coins }` AND `total` (= primary)
- [ ] Manual Award form lets admin pick currency before granting
- [ ] Redemption store reward priced in Coins debits Coins, not Points
- [ ] All 15 blocks render identically on a single-currency site (back-compat smoke)
- [ ] `wppqa_audit_plugin` returns `failed=0` after each phase ships
- [ ] `bin/coding-rules-check.sh` passes (no inline JS/CSS, no admin_post handlers)

---

## References

- Schema + migration: `src/Engine/Installer.php`, `src/Engine/DbUpgrader.php` (`ensure_point_types_schema()`)
- Repository + Service: `src/Repository/PointTypeRepository.php`, `src/Services/PointTypeService.php`
- Engine reads: `src/Engine/PointsEngine.php` (since 1.0.0 — `$type` parameter on every read/write)
- REST CRUD: `src/API/PointTypesController.php`
- Admin: `src/Admin/PointTypesPage.php`, `src/Admin/ManualAwardPage.php` (Currency picker)
- Competitive context: `plan/COMPETITIVE-ANALYSIS.md` § Critical Gap #1
- Architecture conventions: `plan/ARCHITECTURE.md`
- Release plan: `plan/v1.0-release-plan.md`

Updated by Varun — 2026-05-06.
