---
journey: no-text-truncation
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [leaderboard-block, top-members-block, cohort-rank-block, earning-guide-block, badge-showcase-block, kudos-feed-block, ux-text-truncation]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as administrator (autologin via ?autologin=1)"
  - "QA pages seeded: wp wb-gamification qa seed_pages"
  - "At least 5 users with display names of varied lengths (including one ≥18 chars) and nonzero points"
estimated_runtime_minutes: 6
---

# No text truncation on any customer-facing surface

Every member-facing block must render full strings — display names, action labels, badge names, hint copy. The 1.4.0 UX sprint surfaced "Ah..." truncation on leaderboard, "Christ..." on top-members, and a `-webkit-line-clamp:2` on earning-guide labels. This journey is the regression lock: if any of those selectors gets `text-overflow:ellipsis`, `-webkit-line-clamp`, or `white-space:nowrap` reintroduced and a name doesn't fit, the journey fails.

The check is browser-level (computed style + DOM measurement) because pure-CSS lint won't catch a `white-space:nowrap` that wraps in a different rule down the cascade.

## Setup

- Site: `$SITE_URL`
- Admin login: `?autologin=1` (admin user_id=1)
- Seed once: `wp wb-gamification qa seed_pages`
- Verify there's a user with a long display name. If not, create one via DB:
  `mysql_query "UPDATE wp_users SET display_name = 'Christopher Alexander Williamson' WHERE ID = 2 LIMIT 1"`

## Steps

### 1. Walk every QA page, scan for truncation
For each block slug in
`[leaderboard, top-members, cohort-rank, earning-guide, badge-showcase, kudos-feed, points-history, challenges, community-challenges, member-points, level-progress, streak, redemption-store, year-recap, submit-achievement]`:

- **Action**: `playwright_navigate $SITE_URL/wb-gamification-qa-{slug}/?autologin=1` at viewport 390×844.
- **Expect** — for every visible element matching ANY of these selectors:
  ```
  [class*="__name"]
  [class*="__label"]
  [class*="__title"]
  [class*="__hint"]
  [class*="__display-name"]
  ```
  - `getComputedStyle(el).textOverflow` is NOT `"ellipsis"`, OR `getComputedStyle(el).whiteSpace` is NOT `nowrap`, OR `getComputedStyle(el).webkitLineClamp` is `"none"`.
  - `el.scrollWidth <= el.offsetWidth + 1` (sub-pixel tolerance) — i.e. the text fits inside its container in both directions, so no horizontal clipping.
- **Capture**: nothing — fail-fast.
- **On fail**:
  - `textOverflow: ellipsis` set → grep `src/Blocks/{slug}/style.css` for `text-overflow`; remove and replace with `overflow-wrap: anywhere; word-break: break-word;`.
  - `scrollWidth > offsetWidth` on a `__name` → parent `flex-wrap` or container query is wrong; the row should wrap vertically, not horizontally clip.
  - `webkitLineClamp` not `none` → grep `-webkit-line-clamp` in the slug's style.css; remove.

### 2. Repeat the same sweep at viewport 1024×800
- **Action**: resize viewport, re-navigate every URL from step 1.
- **Expect**: same as step 1 — no element clips horizontally; long names wrap vertically and remain fully visible.
- **On fail**: the container-query / `@container` rule for that block isn't firing — check the inline-size container declaration on the block wrapper.

### 3. Spot-check the hub flyout (the original "Ah..." location)
- **Action**: navigate to `$SITE_URL/?autologin=1` then open the hub via the toggle (selector resolved at runtime — likely `.wbgam-hub-toggle` or `data-wb-gam-hub-toggle`). The hub renders leaderboard + badge-showcase + earning-guide inside a ~465px panel.
- **Expect**: every `__name` / `__label` / `__hint` inside `.gam-panel__body` (or equivalent flyout root) satisfies the same truncation contract from step 1.
- **On fail**: the flyout-scoped CSS in `assets/css/hub.css` is overriding the per-block CSS with an `auto-fit minmax(...)` or `text-overflow: ellipsis`. Grep `assets/css/hub.css` for `.wb-gam-` selectors that set `text-overflow` or `white-space: nowrap` — those are the legacy overrides.

## Pass criteria

ALL of the following hold:
1. Across all 15 QA pages at 390px: zero elements with `text-overflow: ellipsis` or `white-space: nowrap` causing visible horizontal clip.
2. Same at 1024px.
3. The hub flyout — every member-facing string in its container reads in full.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `__name` clips at 390px on one block | Per-block style.css has truncation rules | `src/Blocks/{slug}/style.css` |
| Clips only inside hub flyout | Legacy override in hub.css | `assets/css/hub.css` |
| Label truncates with line-clamp | `-webkit-line-clamp:N` re-introduced | `src/Blocks/{slug}/style.css` |
| Earning-guide cards single-line at 460-540px container | `@container` rule not firing → check `container-type: inline-size` on the wrapper | `src/Blocks/earning-guide/style.css` |
