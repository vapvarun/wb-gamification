---
journey: hub-accent-color-applies
plugin: wb-gamification
priority: high
roles: [administrator, subscriber]
covers: [BC-10060536808, appearance-accent, hub-block, design-tokens]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Hub page exists (wb_gam_hub_page_id set)"
estimated_runtime_minutes: 4
---

# Hub block adopts the site owner's custom accent color

The site owner picks an accent in Settings > Appearance (`WBGam\Engine\Appearance`,
option `wb_gam_accent_color`). That override is emitted on `--wb-gam-color-accent`.
The Hub stylesheet (`assets/css/hub.css`) uses its own legacy `--gam-*` token family;
if `--gam-accent` is a hardcoded hex instead of bridging to `--wb-gam-color-accent`,
the whole Hub silently ignores the owner's accent (BC 10060536808 / customer ticket
233992000086423048). This journey locks the bridge: change the accent, the Hub must
follow — in light, dark, and mobile — and revert cleanly when cleared.

## Setup

- Site: `$SITE_URL`
- Hub URL: `$SITE_URL/?page_id=$(wp option get wb_gam_hub_page_id)`
- Test user: admin (autologin via `?autologin=1`)
- Restore state after: `wp option delete wb_gam_accent_color`

## Steps

### 1. Baseline — default accent is the brand purple
- **Action**: `wp option delete wb_gam_accent_color` then `playwright_navigate <hub-url>`
- **Expect**: on `.gam-page`, `getComputedStyle().getPropertyValue('--gam-accent')` === `#5b4cdb`; `.gam-nudge` border-left-color === `rgb(91, 76, 219)`.
- **On fail**: `assets/css/hub.css` `:root --gam-accent` fallback changed, or the bridge var is undefined because `wb-gam-tokens` isn't enqueued on the hub (`src/Blocks/hub/render.php:82`).

### 2. Set a distinctive custom accent
- **Action**: `wp option update wb_gam_accent_color '#059669'` (emerald) then reload the hub.
- **Expect**: `--gam-accent` on `.gam-page` === `#059669`; `.gam-nudge` border-left-color === `rgb(5, 150, 105)`; `--gam-accent-light` === `color-mix(in srgb, #059669 14%, #ffffff)` (derived, follows automatically). Visually: nudge border, icon chips, level progress bar, and "View ->" links are all green, not purple.
- **On fail**: `--gam-accent` still `#5b4cdb` => hub.css un-bridged (hardcoded hex) — the exact BC 10060536808 regression. Check `assets/css/hub.css:25` and regenerate `hub-rtl.css` + `*.min.css`.

### 3. Dark mode keeps the custom accent (lightened, not purple)
- **Action**: `document.documentElement.setAttribute('data-bx-mode','dark')`, read `--gam-accent` on `.gam-page`.
- **Expect**: resolves to `color-mix(in srgb,#059669 60%,#fff)` (lightened emerald), NOT the purple default. `Appearance::inline_css()` emits the dark override for `--wb-gam-color-accent`; the hub dark block (`hub.css:876-883`) bridges to it.
- **On fail**: dark `--gam-accent` shows a purple mix => `hub.css` dark `.gam-page` block reverted to hardcoded `#5b4cdb`.

### 4. Mobile (390px) applies the accent with no horizontal overflow
- **Action**: resize to 390x844, reload hub, read `--gam-accent` + overflow check.
- **Expect**: `--gam-accent` === `#059669`; `document.documentElement.scrollWidth <= clientWidth` (no horizontal scroll).

### 5. Clearing the accent reverts cleanly to default
- **Action**: `wp option delete wb_gam_accent_color`, reload hub.
- **Expect**: `--gam-accent` === `#5b4cdb` again; nudge border `rgb(91, 76, 219)`. The "override if set, else theme default" contract holds both directions.

## Pass criteria

ALL hold:
1. Default: `--gam-accent` = `#5b4cdb` (unchanged from pre-fix default — zero regression).
2. Custom set: `--gam-accent` = the chosen hex on the Hub, visually applied to nudge/chips/progress/links.
3. Dark: `--gam-accent` = lightened custom accent, not purple.
4. 390px: accent applies, no horizontal overflow.
5. Cleared: reverts to `#5b4cdb`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Custom accent set, Hub stays purple | `--gam-accent` hardcoded, not bridged | `assets/css/hub.css:25` (light), `:876-883` (dark) — must be `var(--wb-gam-color-accent, ...)` |
| Accent works on other blocks but not Hub | hub.css forked `--gam-*` family un-bridged | `assets/css/hub.css` + regen `hub-rtl.css`, `hub.min.css`, `hub-rtl.min.css` |
| Accent applies in light, purple in dark | dark `.gam-page` block un-bridged | `assets/css/hub.css:876-883` |
| `--gam-accent` empty / bridge undefined | `wb-gam-tokens` not enqueued on hub | `src/Blocks/hub/render.php:82`, `src/Engine/Appearance.php:146-158` |
| Coding-rules Rule 13 fails in CI | someone hardcoded `--gam-accent` again | `bin/coding-rules-check.sh` Rule 13 — restore the bridge |
