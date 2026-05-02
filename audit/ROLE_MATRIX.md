# WB Gamification — Role / Permission Matrix

**Generated**: 2026-05-02
**Source**: [`audit/manifest.json`](manifest.json)

> **TL;DR — granular caps available alongside `manage_options`.** Every REST controller's permission method accepts `manage_options` (default WP admin gate) OR the corresponding granular plugin cap below. Administrators keep working without reconfiguration. Site owners can delegate individual surfaces to non-admin roles by granting the granular cap via User Role Editor / Members / programmatic `$role->add_cap()`. The admin pages still use `manage_options` as their menu cap (granular menu caps are a future follow-up).

## Plugin custom capabilities

All caps are granted to `administrator` on plugin activation (and on `plugins_loaded` for existing installs via `Capabilities::sync()`). Removed from every role on uninstall.

| Cap | Unlocks | Registered | Enforced |
|---|---|---|---|
| `wb_gam_award_manual` | Manual point award + revoke | `src/Engine/Capabilities.php` | `src/API/PointsController.php` |
| `wb_gam_manage_badges` | Badge library + rules + manual badge award | `src/Engine/Capabilities.php` | `src/API/BadgesController.php`, `src/API/RulesController.php` |
| `wb_gam_manage_challenges` | Individual + community challenge CRUD | `src/Engine/Capabilities.php` | `src/API/ChallengesController.php` |
| `wb_gam_manage_rewards` | Redemption store catalog | `src/Engine/Capabilities.php` | `src/API/RedemptionController.php` |
| `wb_gam_manage_webhooks` | Outbound webhook config + delivery log | `src/Engine/Capabilities.php` | `src/API/WebhooksController.php` |
| `wb_gam_view_analytics` | Reserved (AnalyticsDashboard menu cap) | `src/Engine/Capabilities.php` | (pending — admin page cap update) |

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

---

## Recommendations

1. **Register `wb_gam_award_manual` via `add_cap()`** so the secondary gate at `PointsController.php:273` is grantable. Currently dormant — see `audit/FEATURE_AUDIT.md` § 12.
2. **Introduce 3–4 granular plugin caps** to break the `manage_options` monoculture — for example: `wb_gam_manage_badges`, `wb_gam_manage_challenges`, `wb_gam_award_points`, `wb_gam_view_analytics`. Today, a community-manager role cannot operate the plugin without being a full admin.
3. **Document the override model** for `MembersController::get_item_permissions_check` — site owners should know they can open up cross-user reads without forking.
