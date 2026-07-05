# BadgeOS → WB Gamification data map

> **Verified against BadgeOS 3.7.1.6 (installed + seeded + reconciled, 2026-07-04).**
> The authoritative, executable version of this map is
> `src/Integrations/Importers/BadgeOSImporter.php`. This doc explains the model;
> the code is what runs. An earlier revision of this file described a `_badgeos_*`
> user-meta model — that was **wrong for 3.7**, which moved to custom tables.

## Storage model (custom tables, NOT user meta)

BadgeOS 3.7 stores everything in three custom tables:

| Table | Holds | Key columns |
|---|---|---|
| `{prefix}badgeos_points` | Credit ledger (points) | `id, user_id, credit_id, type ENUM('Award','Deduct','Utilized'), credit, this_trigger, actual_date_earned` |
| `{prefix}badgeos_achievements` | Earned achievements | `entry_id, ID (achievement post), post_type, achievement_title, user_id, points, point_type, date_earned` |
| `{prefix}badgeos_ranks` | Earned ranks | `id, rank_id, rank_type, rank_title, user_id, credit_amount, priority, actual_date_earned` |

Point types are `point_type` CPT posts (`credit_id` → post id). Achievement
types + rank types are CPTs; their slugs come from
`badgeos_get_achievement_types_slugs()` and, for ranks, the DISTINCT
`badgeos_ranks.rank_type` column (do NOT use the shared `rank-type` CPT — on a
multi-plugin site it also holds other plugins' rank types).

## Mapping

### Points → WB points ledger (via ImportService, import mode)
| BadgeOS | WB row | Notes |
|---|---|---|
| `badgeos_points.credit` | `points` | **ABSOLUTE amount**; the sign comes from `type` |
| `type` = Award | `+credit` | Deduct + Utilized → `-credit` |
| `credit_id` | `point_type` | mapped via `point_type` post slug + `wb_gam_import_point_type_map` |
| `this_trigger` | `action_id` = `badgeos_{trigger}` | explicit `points` preserved (unregistered action) |
| `actual_date_earned` | `occurred_at` | backdated |
| `id` | `source_key` = `badgeos:points:{id}` | idempotency |

**Reconcile:** our imported ledger sum per user == sum of
`badgeos_get_points_by_type($pt, $user)` over all point types.
(`badgeos_get_users_points()` reads a legacy meta and returns 0 on 3.7 — do not
use it.)

### Achievements → WB badges
- Read `badgeos_achievements` where `post_type` is an achievement type (minus
  the structural `step`), **deduped by `(user_id, ID)`** — a re-earnable
  achievement writes multiple rows.
- `badge_id = badgeos-achievement-{ID}`, name = `achievement_title`, image = post
  thumbnail, earned = `MIN(date_earned)` (backdated award).
- **Reconcile:** our unique imported badges == `COUNT(DISTINCT ID)` (the
  `badgeos_get_user_earned_achievement_ids` getter counts re-earn rows, not
  uniques).

### Ranks → WB levels
- Rank posts (post_type from `badgeos_ranks.rank_type`) carry a points threshold
  in post meta `_ranks_points`; order in `menu_order`. Recreated as WB levels.
- Current rank = highest-`priority` row in `badgeos_ranks` for the user
  (`badgeos_get_user_rank()` was unreliable on the test install).
- **Reconcile:** WB level derived from imported points == the member's BadgeOS
  rank name. On a target site that already has levels, imported tiers can
  collide — surfaced as a warning, not a hard failure; a fresh migration
  reconciles cleanly.

## Seeding note (for re-verification)

BadgeOS's award functions load in CLI/eval. Seed authentically with:
`badgeos_add_credit($pt_id, $user, 'Award'|'Deduct', $amount, $trigger, 1,0,0,false)`,
`badgeos_award_achievement_to_user($ach_id, $user, 'admin')`, and
`badgeos_add_rank(['new_rank'=>$rank_post,'user_id'=>$user,'credit_id'=>$pt_id,'credit_amount'=>$threshold,'this_trigger'=>'seed'])`.
Achievement/rank types must be registered on a prior request (create the type
post, then award on the next request).

## Usage

`wp wb-gamification import badgeos [--dry-run]`, or the admin **Import** screen
(auto-detects the source, previews the reconciliation, runs the migration).
