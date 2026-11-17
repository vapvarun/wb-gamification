---
journey: revoked-kudos-and-progress-reset
plugin: wb-gamification
priority: medium
roles: [administrator, subscriber]
covers: [recap-live-kudos, analytics-live-kudos, recap-cache-invalidation, recap-top-actions-no-reversals, reset-all-personal-records]
prerequisites:
  - "Site reachable at $SITE_URL, WP-CLI available, two members"
estimated_runtime_minutes: 5
---

# Revoked kudos leave every count; a reset clears every personal record (cards 10393175262, 10516939156, 10516939203)

## Steps

### 1. Year Recap and analytics
- **Action**: member A gives B a kudos; open B's Year Recap for this year (warms the 1-hour cache);
  revoke the kudos in Kudos Moderation; reopen the recap; open Gamification > Analytics.
- **Expect**: recap "kudos received" drops 1 -> 0 at once (no wait for the cache); analytics "kudos
  given" excludes it. The daily send limit still counts it (abuse guard).
- **On fail**: `RecapEngine::get_recap()`, `RecapEngine::forget_kudos()` (bound in `Engine::init()` to
  `wb_gam_kudos_revoked`), `AnalyticsDashboard` kudos count.

### 2. Recap top actions
- **Action**: publish and delete a post, then open the recap.
- **Expect**: "(content removed)" never appears among the top actions.

### 3. Reset clears every personal record
- **Action**: give a member points over several days so `wb_gam_pr_best_day/week/month` and
  `wb_gam_pr_run_*` exist; run the progress reset; award once.
- **Expect**: `wp user meta list <id> | grep wb_gam_pr_` is empty after the reset, and the next award
  does not announce a "new personal record" against pre-reset totals.
- **On fail**: `ProgressReset::PROGRESS_META_PREFIXES`.
