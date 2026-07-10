---
journey: activation-rewrite-profile
plugin: wb-gamification
priority: critical
roles: [anonymous]
covers: [activation-rewrite, public-profile-default-on]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one member exists with a public profile (default on)"
estimated_runtime_minutes: 3
---

# Public profile /u/{username} survives a clean plugin reactivate

Public member profiles at `/u/{username}` are default-on. The rewrite rule for
that route is registered on `init` (`ProfilePage::register_rewrite`), which does
NOT fire during CLI activation (`wp plugin activate`). If the activation hook
does not register that rule before its rewrite flush, every member's public
profile 404s after any deactivate/reactivate cycle until an admin manually
flushes permalinks — a common troubleshooting step on live sites, so the
regression is silent and customer-visible (broken profile links, dead OG/share
cards). Regression of the same symptom `17-profile-privacy-write-path` and the
`D.public-profile-default-on` (v1.5.2) guard were written to prevent, via a new
root cause (activation-time flush, not the rule itself).

## Setup

- Site: `$SITE_URL`
- Member: pick any existing user login as `$MEMBER` (e.g. `wp user list --field=user_login --number=1`)
- No manual `wp rewrite flush` anywhere in this journey — the whole point is that
  activation must wire the rule itself.

## Steps

### 1. Reactivate the plugin cleanly (no manual flush)
- **Action**: `wp plugin deactivate wb-gamification && wp plugin activate wb-gamification`
- **Expect**: both commands succeed, no fatal.
- **On fail**: `wb-gamification.php` activation/deactivation hooks (~line 782).

### 2. Anonymous GET of a member's public profile
- **Action**: `curl -s -o /dev/null -w '%{http_code}' $SITE_URL/u/$MEMBER/`
- **Expect**: `200` (NOT 404). This must hold immediately after step 1 with no
  intervening permalink flush.
- **On fail**: the activation hook did not register + flush ProfilePage's rewrite
  rule. Fix: `\WBGam\Engine\ProfilePage::register_rewrite();` must run in the
  `register_activation_hook` callback before the `BadgeSharePage::activate()`
  flush (`wb-gamification.php`), mirroring `BadgeSharePage::activate()`
  (`src/Engine/BadgeSharePage.php:37-40`). Rule itself lives at
  `src/Engine/ProfilePage.php:53`.

### 3. Confirm REST is unaffected (control)
- **Action**: `curl -s -o /dev/null -w '%{http_code}' $SITE_URL/wp-json/wb-gamification/v1/openapi.json`
- **Expect**: `200`. REST uses WP core's independent rewrite tag, so it stays up
  even when the profile rule is missing — this is why a REST-only activation
  check does not catch the profile 404.
