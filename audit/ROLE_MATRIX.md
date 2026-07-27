# WB Gamification — Role / Permission Matrix

**Generated**: 2026-05-06 (v1.0.0 release-candidate refresh)
**Source**: [`audit/manifest.json`](manifest.json)

> **TL;DR — granular caps available alongside `manage_options`.** Every REST controller's permission method accepts `manage_options` (default WP admin gate) OR the corresponding granular plugin cap below. Administrators keep working without reconfiguration. Site owners can delegate individual surfaces to non-admin roles by granting the granular cap via User Role Editor / Members / programmatic `$role->add_cap()`. The admin pages still use `manage_options` as their menu cap (granular menu caps are a future follow-up).

---

## v1.0.0 sprint additions

| Surface | Permission gate | Notes |
|---|---|---|
| `POST /submissions` | `is_user_logged_in()` | Member-facing submit; `SubmissionService` enforces `DAILY_CAP=5` per user via `SubmissionRepository::count_today_for_user`. |
| `GET /submissions`, `POST /submissions/{id}/approve`, `POST /submissions/{id}/reject` | `current_user_can( 'manage_options' )` | Admin-only review queue. |
| `GET /settings/emails`, `POST /settings/emails` | `current_user_can( 'manage_options' )` | Per-event email toggles (`wb_gam_email_level_up`, `_badge_earned`, `_challenge_completed`); whitelist in `EmailSettingsController::EVENTS`. Default OFF. |
| `GET /point-types*`, `GET /point-type-conversions*` | Public read | Catalog reads consistent with other catalog endpoints. |
| Write `/point-types*`, `/point-type-conversions*` | `current_user_can( 'manage_options' )` | Admin-only currency CRUD. |
| `POST /point-types/{from}/convert` | `is_user_logged_in()` | Member-facing currency conversion; `PointTypeConversionService` validates against admin-defined rules + transaction-locked. |
| Public profile `/u/{user_login}` | Site option `wb_gam_profile_public_enabled` AND user_meta `wb_gam_profile_public` | Both required — site owner enables feature, member opts in. Returns 404 if either gate fails. |

All new admin pages (Point Types, Conversions, Submissions) use `manage_options` as their menu cap, consistent with the existing matrix.

---

## v1.6.2 additions — caps delegation UI + Manage members

**In-plugin delegation UI shipped.** Settings › Access now has a **Staff
permissions** matrix (roles × plugin caps). Site owners can grant/revoke any
plugin cap to a non-admin role via checkboxes — no role-editor plugin required.
Administrator is always on (locked). A "Reset to defaults" option strips every
plugin cap from non-admin roles. Saves through the SettingsPage `handle_save`
dispatcher (nonce + `manage_options`) → `WP_Role::add_cap/remove_cap`.

**New cap `wb_gam_manage_members`** (CAPS_VERSION → 1.5) covers the member
roster + moderation cluster, which previously hardcoded `manage_options`:

| Surface | Now gated by | Was |
|---|---|---|
| Members roster page + `GET /members`, `POST /members/{id}/exclude`, `/reset-points` | `wb_gam_manage_members` (menu cap + `Capabilities::user_can`) | `manage_options` |
| Streaks moderation page + `POST`/`DELETE /members/{id}/streak` | `wb_gam_manage_members` | `manage_options` (new in 1.6.2) |
| Kudos moderation page + `DELETE /kudos/{id}` | `wb_gam_manage_members` | `manage_options` (new in 1.6.2) |

Verified: an Editor granted `wb_gam_manage_members` + `wb_gam_manage_badges`
sees only Members / Streaks / Kudos Moderation / Badges in the Gamification
menu and is denied API Keys (WP capability error).

**Still intentionally `manage_options`-only** (sensitive config / not member
management — documented as a deliberate choice, delegation deferred): API Keys,
Tools (data reset), Point Types + Conversions, Events ingestion, Capabilities
controller, Abilities registration, Intelligence, Recap, Actions list, and the
main Settings page itself.

---

## Plugin custom capabilities

All caps are granted to `administrator` on plugin activation (and on `plugins_loaded` for existing installs via `Capabilities::sync()`). Removed from every role on uninstall.

| Cap | Unlocks | REST surfaces | Admin pages |
|---|---|---|---|
| `wb_gam_award_manual` | Manual point award + revoke | `PointsController` | Award Points |
| `wb_gam_manage_badges` | Badge library + rules + manual badge award | `BadgesController`, `RulesController` | Badges (library) |
| `wb_gam_manage_challenges` | Individual + community challenges + cohort leagues | `ChallengesController` | Challenges, Community Challenges, Cohort Leagues |
| `wb_gam_manage_rewards` | Redemption store catalog | `RedemptionController` | Redemption Store |
| `wb_gam_manage_webhooks` | Outbound webhook config + delivery log | `WebhooksController` | _none — REST-only_ |
| `wb_gam_view_analytics` | Analytics dashboard read | _none_ | Analytics |

**Admin pages that intentionally keep `manage_options`:**
- **Gamification** (top-level menu / `SettingsPage`) — broad settings surface, admin-only by design.
- **Setup Wizard** (`SetupWizard`) — one-time first-run flow.
- **API Keys** (`ApiKeysPage`) — API key issuance is security-sensitive; not delegated.

**Layered model.** Every gate accepts `manage_options` OR the granular cap via `\WBGam\Engine\Capabilities::user_can( $cap )`. Admins (who have `manage_options`) keep working without reconfiguration. Site owners grant a granular cap to a non-admin role via User Role Editor / Members / programmatic `$role->add_cap()` and that role gains access to exactly that surface — both REST endpoints AND admin menu visibility AND per-page authorization checks.

---

## Legend

- **C** — Create
- **R** — Read
- **U** — Update
- **D** — Delete
- **—** — No access

---

## Frontend (consumer surfaces)

| Feature | Admin | Editor | Author | Subscriber | Anonymous |
|---|---|---|---|---|---|
| View leaderboard | R | R | R | R | R |
| View own points / level / badges / streak | R | R | R | R | — |
| View **other user’s** points / level / badges | R | R (incl. self) | R (own only*) | R (own only*) | — |
| Earn points (auto from events) | ✓ | ✓ | ✓ | ✓ | — |
| Receive badges (auto from rules) | ✓ | ✓ | ✓ | ✓ | — |
| Give kudos | ✓ | ✓ | ✓ | ✓ | — |
| Complete a challenge | ✓ | ✓ | ✓ | ✓ | — |
| Redeem points (Redemption store) | ✓ | ✓ | ✓ | ✓ | — |
| View own toasts | ✓ | ✓ | ✓ | ✓ | — |
| View badge share page | R | R | R | R | R |
| Year recap (own) | R | R | R | R | — |

\* Other-user reads gated by `MembersController::get_item_permissions_check`. Default policy: own profile or admin. Site owners can override via `permission_callback` filter.

---

## Admin (management surfaces)

All gates evaluate `current_user_can('manage_options')`. Only `administrator` role passes by default.

| Admin operation | Admin | Editor | Author | Subscriber |
|---|---|---|---|---|
| Settings page (`wb-gamification`) | CRUD | — | — | — |
| Award Points (`wb-gamification-award`) | CRUD | — | — | — |
| Redemption Store (`wb-gam-redemption`) | CRUD | — | — | — |
| Setup Wizard (`wb-gamification-setup`) | CRUD | — | — | — |
| Challenges (`wb-gam-challenges`) | CRUD | — | — | — |
| Community Challenges (`wb-gam-community-challenges`) | CRUD | — | — | — |
| Cohort Leagues (`wb-gam-cohort`) | CRUD | — | — | — |
| Analytics (`wb-gamification-analytics`) | R | — | — | — |
| API Keys (`wb-gam-api-keys`) | CRUD | — | — | — |
| Badges (`wb-gamification-badges`) | CRUD | — | — | — |

---

## REST API permission map

| Route | Method | Permission callback | Effective default |
|---|---|---|---|
| `/abilities` | GET | `__return_true` | Public. |
| `/actions` | GET | `__return_true` | Public. |
| `/actions/{id}` | GET | `__return_true` | Public. |
| `/badges` | GET | `__return_true` | Public. |
| `/badges/{id}` | GET / PUT / DELETE | `get_item_permissions_check` / `admin_check` / `admin_check` | Read public, write admin. |
| `/badges/{id}/award` | POST | `award_permissions_check` | Admin (defaults to `manage_options`). |
| `/badges/{id}/share/{user_id}` | GET | `__return_true` | Public — OG share endpoint. |
| `/badges/{id}/credential/{user_id}` | GET | `__return_true` | Public — OpenBadges credential. |
| `/capabilities` | GET | `__return_true` | Public — discovery endpoint. |
| `/challenges` | GET / POST | `__return_true` / `admin_check` | Read public, create admin. |
| `/challenges/{id}` | GET / PUT / DELETE | `__return_true` / `admin_check` / `admin_check` | Read public, write admin. |
| `/challenges/{id}/complete` | POST | `require_logged_in` | Logged-in user. |
| `/events` | POST | `create_item_permissions_check` | Logged-in user (default). |
| `/kudos` | GET / POST | `__return_true` / `create_item_permissions_check` | Read public, give logged-in. |
| `/kudos/me` | GET | `require_logged_in` | Logged-in user. |
| `/leaderboard` | GET | `__return_true` | Public. |
| `/leaderboard/group/{group_id}` | GET | `__return_true` | Public. |
| `/leaderboard/me` | GET | `require_logged_in` | Logged-in user. |
| `/levels` | GET | `__return_true` | Public. |
| `/members/{id}` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/{id}/points` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/{id}/level` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/{id}/badges` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/{id}/events` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/{id}/streak` | GET | `get_item_permissions_check` | Self or admin. |
| `/members/me/toasts` | GET | `get_toasts_permissions_check` | Logged-in user (own toasts). |
| `/openapi.json` | GET | `__return_true` | Public — API spec. |
| `/points/award` | POST | `admin_permission_check` | `manage_options` (with `wb_gam_award_manual` fallback — **see § cap drift in FEATURE_AUDIT.md**). |
| `/points/{id}` | DELETE | `admin_permission_check` | `manage_options` (with same fallback). |
| `/members/{id}/recap` | GET | `permissions_check` | Self or admin. |
| `/redemptions/items` | GET / POST | `__return_true` / `admin_check` | Read public, create admin. |
| `/redemptions/items/{id}` | GET / PUT / DELETE | `__return_true` / `admin_check` / `admin_check` | Read public, write admin. |
| `/redemptions` | POST | `require_logged_in` | Logged-in user. |
| `/redemptions/me` | GET | `require_logged_in` | Logged-in user. |
| `/rules` | GET / POST | `admin_check` | Admin. |
| `/rules/{id}` | GET / PUT / DELETE | `admin_check` | Admin. |
| `/webhooks` | GET / POST | `admin_check` | Admin. |
| `/webhooks/{id}` | GET / PUT / DELETE | `admin_check` | Admin. |
| `/webhooks/{id}/log` | GET / DELETE | `admin_check` | Admin. |

All `admin_check` and `admin_permission_check` methods evaluate `current_user_can('manage_options')`.

---

## Authentication modes accepted

The plugin accepts three auth modes for REST endpoints:

| Mode | Where | Notes |
|---|---|---|
| Cookie + `wp_rest` nonce | Default for blocks/admin JS — passed via `wp_localize_script`. | `wb-gamification.php:272`. |
| Application Passwords (WP core) | Native — works with any REST route. | No plugin code needed. |
| Plugin-issued API key | `Authorization: Bearer <key>` (or similar — see `src/API/ApiKeyAuth.php`). | Issued from `Admin → API Keys`. Per-key scope and rate limits (RateLimiter). |

---

## WP-CLI access

All `wp wb-gamification` subcommands run as the OS user that invoked `wp`. There is no in-CLI capability check — the gate is shell access. The 6 commands:

| Command | Effective access |
|---|---|
| `wp wb-gamification points` | Award/revoke (write). |
| `wp wb-gamification member` | Inspect/repair (read+write). |
| `wp wb-gamification actions` | List/inspect (read). |
| `wp wb-gamification logs` | Inspect/prune (write). |
| `wp wb-gamification export` | Export user data (read). |
| `wp wb-gamification doctor` | Diagnostic check (read). |

Treat shell access as equivalent to admin.

`wp wb-gamification import <gamipress|mycred|badgeos> [--dry-run]` (v1.6.2) also
runs on shell access (no in-CLI cap), same as the rest.

---

## Competitor import (v1.6.2)

Migration from GamiPress / myCred / BadgeOS. All three surfaces are gated by the
`wb_gam_manage_members` capability (which includes the `manage_options` admin
fallback), so a delegated community manager can run imports without full admin.

| Surface | Gate |
|---|---|
| REST `GET /import/sources` | `wb_gam_manage_members` (`ImportController::permissions`) |
| REST `POST /import/{source}` (run / dry-run) | `wb_gam_manage_members` |
| REST `POST /events/import` (bulk ingestion) | `wb_gam_manage_members` (`EventsController::import_permissions_check`) |
| Admin **WB Gamification → Import** page | `wb_gam_manage_members` (submenu cap + `render_page` re-check) |
| `wp wb-gamification import <source>` | Shell access (WP-CLI) |

Imports READ the source plugin's own tables/meta and WRITE only through the
engine's import path (`ImportService` → `Engine::process` import mode). They are
idempotent (`wb_gam_events.source_key` UNIQUE) and reconcile against each
source's own getters. Source plugins may stay active during import (read-only);
deactivate afterward. See `src/Integrations/Importers/*` and the per-source
data maps in `plan/importers/`.

---

## Recommendations

1. **Register `wb_gam_award_manual` via `add_cap()`** so the secondary gate at `PointsController.php:273` is grantable. Currently dormant — see `audit/FEATURE_AUDIT.md` § 12.
2. **Introduce 3–4 granular plugin caps** to break the `manage_options` monoculture — for example: `wb_gam_manage_badges`, `wb_gam_manage_challenges`, `wb_gam_award_points`, `wb_gam_view_analytics`. Today, a community-manager role cannot operate the plugin without being a full admin.
3. **Document the override model** for `MembersController::get_item_permissions_check` — site owners should know they can open up cross-user reads without forking.

---

## Staff permissions — what an owner can delegate, and what they cannot (1.6.4)

An owner delegates capabilities in **Settings → Access → Staff permissions** (a role × capability
matrix), or over REST at `GET|PUT /wb-gamification/v1/settings/capabilities`. Both go through the one
write path, `Capabilities::set_role_caps()` — two copies of "work out the difference and
add_cap/remove_cap" is two chances to disagree about what the owner just asked for, and a permission
system that disagrees with itself is the one place you really cannot afford it.

`Capabilities::user_can()` is `manage_options` **OR** the granular cap, so an administrator keeps
working with nothing reconfigured, and the Administrator row can never be edited or stripped — a UI
that can take capabilities off the administrator is a UI that can lock the owner out of their site.

### Delegable (a community manager can be given these)

| Capability | Surface |
|---|---|
| `wb_gam_award_manual` | Award / revoke points by hand |
| `wb_gam_manage_badges` | Badge library |
| `wb_gam_manage_rules` | Rules, and **action point values** (`POST /actions/{id}/overrides`) |
| `wb_gam_manage_challenges` | Challenges + community challenges |
| `wb_gam_manage_rewards` | Redemption store |
| `wb_gam_manage_webhooks` | Outbound webhooks |
| `wb_gam_manage_levels` | Levels |
| `wb_gam_manage_submissions` | Submission review queue |
| `wb_gam_manage_members` | Members roster, kudos moderation, streaks, import |
| `wb_gam_manage_email_settings` | Transactional email toggles |
| `wb_gam_view_analytics` | Analytics, and **another member's year recap** (`GET /members/{id}/recap`) |

### Deliberately NOT delegable — administrator only

This is a **decision, not an oversight**. Each of these either hands out credentials, destroys data,
or grants the ability to grant capabilities — none of which is a thing you delegate to the person you
are delegating badge management to.

| Surface | Why it stays `manage_options` |
|---|---|
| `ApiKeysController` | An API key is a bearer credential for the points + badge write surface. Anyone who can mint one has escaped the capability system entirely. |
| `ToolsController` | Settings import/export and **reset member progress**. Destructive and irreversible. |
| `CapabilitiesController` (`/settings/capabilities`) | The surface that grants capabilities cannot itself be a capability you can grant, or a community manager promotes themselves to everything. |
| `EventsController` (ingestion) | Writes to the ledger with backdating and side-effect suppression. It is the importer's door; it is not a moderation tool. |
| `PointTypesController`, `PointTypeConversionsController` | Defining currencies and their exchange rates is economy design, not day-to-day moderation. |
| `IntelligenceController` | Per-member behavioural projections (churn risk). Owner-level data about people. |
| `AbilitiesRegistration` | Registers AI abilities against the site. |

Revisit an entry only by changing this table first — that is what makes it a choice.
