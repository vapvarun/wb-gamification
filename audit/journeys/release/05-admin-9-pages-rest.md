---
journey: tier-5-admin-9-pages-rest
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [admin-rest-migration, tier-0-c, no-form-posts]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged-in admin (autologin via ?autologin=1)"
estimated_runtime_minutes: 18
---

# Tier 5 — Admin Surface (9 pages × REST + 1280/390)

The 9 migrated admin pages must serve cleanly and exercise their REST endpoints (POST/PATCH/DELETE) without ever calling `admin-post.php`. The audit gate is **`grep -rE "add_action.*'admin_post_wb_gam_" src/ wb-gamification.php` returns 0 lines**.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Admin user: `admin` (autologin)
- Fixtures: none — JS exercises live REST

## Steps

### 1. admin-post hook count gate (the structural rule)
- **Action**: `grep -rE "add_action\\s*\\(\\s*['\"]admin_post_wb_gam_" src/ wb-gamification.php | wc -l`
- **Expect**: `0`. (Standard allows ≤2 documented exceptions; we use 0.)
- **Action**: `grep -rE "add_action\\s*\\(\\s*['\"]wp_ajax_wb_gam_" src/ wb-gamification.php | wc -l`
- **Expect**: `0`

### 2. HTTP + fatal sweep across all 9 admin pages
For each slug in `[wb-gamification, wb-gam-cohort, wb-gam-api-keys, wb-gam-webhooks, wb-gamification-award, wb-gamification-badges, wb-gam-challenges, wb-gam-community-challenges, wb-gam-redemption]`:
- **Action**: `curl -sk -L -o /dev/null -w "%{http_code}" $SITE_URL/wp-admin/admin.php?page=<slug>&autologin=1`
- **Expect**: HTTP 200
- **Action**: body grep for `Fatal error|Parse error|Uncaught`
- **Expect**: 0 hits per page

### 3. Levels round-trip (representative bulk-PATCH page)
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wb-gamification&tab=levels&autologin=1`
- **Action**: `playwright_evaluate` to read `window.wbGamLevelsSettings.restUrl` + `.nonce`
- **Expect**: both present
- **Action**: change a level name → click Save Levels → wait 1.5s
- **Expect**: toast `.wb-gam-toast.is-visible` appears, table row reflects new value (no full page reload)
- **Cleanup**: PATCH the level back to its original name via the same JS settings

### 4. ApiKeys "show secret once" pattern
- **Action**: navigate to `wb-gam-api-keys`, fill the create form (label="qa", site_id="qa"), submit
- **Expect**: `.wb-gam-toast--success` appears AND `[data-wb-gam-api-keys-fresh]` is now visible with a `wbgam_…` secret
- **Cleanup**: DELETE the just-created key via REST so the test is idempotent

### 5. Webhooks event enum agreement (regression gate)
- **Action**: POST `/webhooks` with `events: ['points_awarded', 'badge_earned']`
- **Expect**: 201 (NOT 400). Adding `'badge_awarded'` would 400 — this is the contract-bug gate.

### 6. Confirm modal (a11y)
- **Action**: trigger any DELETE button (e.g. webhook delete)
- **Expect**: a `.wb-gam-confirm-overlay` with `role="dialog"` and `aria-modal="true"` appears. Esc closes it. Backdrop click closes it. Cancel button closes it. Confirm button proceeds.

## Pass criteria

ALL of the following hold:
1. `admin_post_wb_gam_*` count = 0
2. `wp_ajax_wb_gam_*` count = 0
3. 9/9 admin pages return 200 with 0 fatals
4. Levels in-place save shows toast + persists without page reload
5. ApiKeys creation shows the secret in `data-wb-gam-api-keys-fresh` panel
6. Webhooks accepts `badge_earned` (not `badge_awarded`)
7. Confirm modal is keyboard-accessible

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `admin_post_*` count > 0 | A new admin page added a form-post hook — Tier 0.C standard violated | The flagged file's `init()` / `register_*_hooks()` |
| Toast doesn't appear after save | Generic driver not loaded; check `wbGamAdminRest` global | `src/Admin/<Page>::enqueue_assets()` — must enqueue `wb-gam-admin-rest-utils` + `wb-gam-admin-rest-form` |
| API Keys secret panel never shows | `data-wb-gam-api-keys-fresh` not toggled visible | `assets/js/admin-api-keys.js` `showFreshSecret()` |
| Webhooks 400 with `badge_awarded` | Admin and REST event enums drifted again | `src/Admin/WebhooksAdminPage::available_events()` MUST match `src/API/WebhooksController::ALLOWED_EVENTS` |
| Confirm modal native | `confirmAction` reverted to `window.confirm` | `assets/js/admin-rest-utils.js` — must be promise-based DOM modal |
