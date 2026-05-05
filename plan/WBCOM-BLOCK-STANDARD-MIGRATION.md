# Wbcom Block Quality Standard — Migration Plan

**Generated:** 2026-05-03
**Status:** Draft, awaiting approval
**Owner:** wp-gamification core
**Companion:** [`audit/UX-ADMIN-AUDIT-2026-05-03.md`](../audit/UX-ADMIN-AUDIT-2026-05-03.md), `wp-block-development` skill

## TL;DR

All **15 blocks** in `wb-gamification` violate the canonical Wbcom Block Quality Standard documented in `~/.claude/skills/wp-block-development/references/block-quality-standard.md`. The plugin predates the standard, has no `src/shared/` directory, no design tokens, no responsive editor controls, and at least one block (`redemption-store`) violates even the plugin's own internal conventions. This plan describes the architectural migration to bring the plugin into compliance — not a piecemeal patch on `redemption-store` alone.

The user's stated rule: *"all planning must be based on architecture plan so we do not add or fix things in random way"* and *"patch of patch type approach is not acceptable"*. This plan honours that.

---

## Why this plan exists

After the redemption-store frontend block (PR #20) shipped, an audit against `wp-block-development` revealed two gap tiers:

| Tier | Scope | Severity | Why |
|---|---|---|---|
| **Tier 1** | redemption-store violates this plugin's own existing conventions | High — outlier block | Inline `<style>`/`<script>`, raw `fetch()`, `window.confirm()`, hardcoded hex. The other 14 blocks don't have these. |
| **Tier 2** | All 15 blocks violate the Wbcom Block Quality Standard | Medium — architectural debt | No `src/shared/` infra, no design tokens, no responsive controls, no editor InspectorControls, no per-side spacing, no hover states, no unique-ID-scoped CSS. |

A "small fix" that brought redemption-store into Tier 1 compliance would be a **patch on top of a Tier 2 violation** — exactly the patch-on-patch the user has banned. The right move is to plan the Tier 2 migration first; redemption-store becomes the pilot block under the new architecture, and the small Tier 1 fix-up is replaced with the proper migration.

---

## Current state — by the numbers

| Concern | Standard says | wb-gamification has |
|---|---|---|
| `block.json` apiVersion | 3 | 3 ✓ |
| Per-side spacing (`{top, right, bottom, left}` objects) | All blocks | None |
| 3-breakpoint responsive (Desktop/Tablet/Mobile suffixes) | All blocks | None |
| Device visibility toggles (`hideOnDesktop` etc.) | All blocks | None |
| Hover-color controls on interactive elements | Mandatory | None |
| Box-shadow + per-corner border-radius controls | All blocks | None |
| Unique ID + scoped CSS (`.wb-gam-block-{uniqueId}`) | All blocks | None |
| Design tokens (`--wb-gam-*` CSS variables) | All blocks | None — `assets/css/frontend.css` uses hardcoded hex |
| BEM class naming | All blocks | Partial — community-challenges uses BEM, others mix |
| `src/shared/` directory with React components | Mandatory | Does not exist — plugin has no `src/` directory |
| Editor InspectorControls panels | All blocks | Only `leaderboard` has an `edit.js`; the other 14 are server-render-only |
| Build pipeline (`@wordpress/scripts`) | Required for `src/shared/` + editor JS | None — plugin uses no build step |
| `viewScriptModule` for view-side JS | Mandatory | redemption-store has inline `<script>`; hub uses `data-wp-*` + module enqueue (correct pattern) |
| Accessibility (ARIA, keyboard, `prefers-reduced-motion`) | Mandatory | Partial — varies block to block |
| Theme isolation (no bare element selectors) | Mandatory | Mostly OK — some blocks have bare-element selectors |

The hub block partially follows the Wbcom standard (uses `data-wp-*` directives, has its own enqueued module). It's the closest reference inside the plugin and a useful template for the rest.

---

## Target architecture (after migration)

```
wp-content/plugins/wb-gamification/
├── src/
│   ├── shared/                          ← NEW — copy from wbcom-essential v4.5.0
│   │   ├── design-tokens.css            ← --wb-gam-* CSS variables
│   │   ├── base.css                     ← Block reset + responsive visibility utilities
│   │   ├── components/
│   │   │   ├── ResponsiveControl.js     ← Desktop/Tablet/Mobile switcher
│   │   │   ├── SpacingControl.js        ← Per-side padding/margin with linked toggle
│   │   │   ├── TypographyControl.js
│   │   │   ├── BoxShadowControl.js
│   │   │   ├── BorderRadiusControl.js
│   │   │   ├── ColorHoverControl.js
│   │   │   ├── DeviceVisibility.js
│   │   │   └── index.js
│   │   ├── hooks/
│   │   │   ├── useUniqueId.js
│   │   │   └── useResponsiveValue.js
│   │   └── utils/
│   │       ├── attributes.js            ← Standard attribute schemas (spacing, typography, shadow, border, visibility, uniqueId)
│   │       └── css.js                   ← Generate scoped CSS from attributes (responsive media queries)
│   │
│   ├── blocks/                          ← NEW — block source under @wordpress/scripts
│   │   ├── redemption-store/
│   │   │   ├── block.json
│   │   │   ├── edit.js
│   │   │   ├── view.js                  ← viewScriptModule entry — IA actions
│   │   │   ├── style.scss
│   │   │   └── editor.scss
│   │   └── … (14 more blocks migrated phase-by-phase)
│   │
│   └── (existing PSR-4 PHP namespaces — Engine/, API/, Admin/, etc., unchanged)
│
├── includes/
│   ├── class-wb-gam-css.php             ← NEW — PHP per-instance CSS generator
│   └── class-wb-gam-block-registrar.php ← NEW — auto-register from build/blocks/*/block.json
│
├── build/                               ← NEW — @wordpress/scripts output
├── package.json                         ← NEW — devDeps: @wordpress/scripts, @wordpress/create-block
└── (existing files unchanged)
```

PHP render callbacks stay where they are (in `blocks/{slug}/render.php`) but consume per-instance CSS from `WB_Gam_CSS::generate( $attributes, $unique_id )` instead of hardcoding it inline.

---

## Migration phases

Each phase ships independently, gates on the previous, and is browser-verified before mark-done. **No phase merges before its acceptance tests pass.**

### Phase A — Build infrastructure (Pre-req for everything else)

**Goal:** plugin can compile React components + run `npm run build`.

| Step | What | Effort |
|------|------|--------|
| A.1 | Add `package.json` with `@wordpress/scripts` devDep | 0.5 h |
| A.2 | Add `webpack.config.js` (extend default `@wordpress/scripts` config to multi-block source dirs) | 1 h |
| A.3 | Add `.gitignore` rules for `node_modules/` and `build/` | 0.1 h |
| A.4 | Add CI step for `npm install && npm run build` | 0.5 h |
| A.5 | Decision point: SCSS or vanilla CSS? Recommendation: vanilla CSS (one fewer build step, design-tokens.css is just `:root { --vars }`) | 0 h |

**Deliverable:** `npm run build` produces empty `build/` (no blocks ported yet). CI green.

**Effort:** ~2 hours.

---

### Phase B — Shared infrastructure (`src/shared/`)

**Goal:** copy the canonical `src/shared/` directory from wbcom-essential v4.5.0 + swap prefix `wbe` → `wb-gam`. Standard attribute schemas, React components, design tokens, PHP CSS generator.

| Step | What | Effort |
|------|------|--------|
| B.1 | Copy `wbcom-essential/plugins/gutenberg/src/shared/` → `wb-gamification/src/shared/` | 0.5 h |
| B.2 | Find-replace `wbe` → `wb-gam` in design-tokens.css | 0.2 h |
| B.3 | Find-replace `wbe` → `wb-gam` in BEM class hardcoded strings | 0.5 h |
| B.4 | Verify all 7 components (ResponsiveControl, SpacingControl, TypographyControl, BoxShadowControl, BorderRadiusControl, ColorHoverControl, DeviceVisibility) compile | 0.5 h |
| B.5 | Port `class-{P}-css.php` from wbcom-essential, rename to `class-wb-gam-css.php` — **PHP that generates per-instance scoped CSS from a standard attribute object** | 2 h |
| B.6 | Port `BlockRegistrar.php` (auto-register from `build/blocks/*/block.json`) | 1 h |
| B.7 | Document the standard attribute schemas in `docs/website/developer-guide/block-attributes.md` | 1 h |

**Deliverable:** `src/shared/` fully ported. PHP `WB_Gam_CSS::generate()` callable. BlockRegistrar registered but no blocks yet.

**Effort:** ~6 hours.

---

### Phase C — Pilot block: `redemption-store` rebuilt to standard

**Goal:** redemption-store becomes the canonical reference implementation in this plugin. The other 14 blocks copy this pattern.

| Step | What | Effort |
|------|------|--------|
| C.1 | Move `blocks/redemption-store/` → `src/blocks/redemption-store/` | 0.2 h |
| C.2 | Add `block.json` Wbcom-standard attribute schema (uniqueId, spacing, typography, color, hover, shadow, border, visibility) — import via `block_type_metadata` filter | 1 h |
| C.3 | Build `edit.js` with InspectorControls panels in standard order: Content / Layout / Style / Advanced | 3 h |
| C.4 | Build `view.js` (Interactivity API store: `wb-gamification/redemption` namespace, `actions.redeem` calls `wp.apiFetch`) | 2 h |
| C.5 | Build `style.css` using design tokens — no hardcoded hex | 2 h |
| C.6 | Refactor `render.php` — emit `data-wp-*` directives, drop inline `<style>`/`<script>`, call `WB_Gam_CSS::generate( $attributes, $unique_id )` for per-instance CSS | 1.5 h |
| C.7 | Replace `window.confirm` with a styled confirm component (standard panel/modal pattern) | 1 h |
| C.8 | Browser-verify all 4 states + responsive controls (Desktop/Tablet/Mobile) at 1440px and 390px | 1 h |
| C.9 | Acceptance tests in `tests/functional/RedemptionStoreBlockTest.php` per Part 13 of wp-plugin-development | 2 h |

**Deliverable:** redemption-store is fully Wbcom-standard. PR includes a screencap showing responsive controls in editor. PR description references this plan.

**Effort:** ~14 hours.

---

### Phase D — Bulk migration of remaining 14 blocks

Each block follows the redemption-store template: copy the block.json shape, add edit.js with the standard control panels, refactor render.php to consume `WB_Gam_CSS::generate()`. Estimated **2–4 hours per block** depending on complexity (interactive blocks with IA stores take longer; static display blocks are quick).

Suggested order (lowest risk → highest, ship in 5 groups so reviewers don't drown):

| Group | Blocks | Why this order | Effort |
|---|---|---|---|
| D.1 — Static display | `member-points`, `streak`, `level-progress`, `earning-guide` | Lowest interactive surface — pure data display, easy to standardise | 8 h |
| D.2 — List blocks | `leaderboard`, `top-members`, `points-history`, `badge-showcase`, `kudos-feed` | Tabular data, share patterns | 12 h |
| D.3 — Challenge family | `challenges`, `community-challenges`, `cohort-rank` | Closely related; refactor together | 8 h |
| D.4 — Recap | `year-recap` | Heavier custom layout, year-end UI | 4 h |
| D.5 — Hub (last) | `hub` | Most complex IA + child block rendering. Migrate after the 13 it consumes | 6 h |

**Deliverable:** all 15 blocks meet the Wbcom standard, all use `src/shared/` components, all consume design tokens. Verified live at 1440px and 390px per block.

**Effort:** ~38 hours (D.1–D.5 combined).

---

### Phase E — Cleanup

| Step | What | Effort |
|------|------|--------|
| E.1 | Drop legacy `assets/css/frontend.css` block-specific selectors (replaced by per-block style.css emitted from build pipeline) | 1 h |
| E.2 | Drop legacy `assets/interactivity/index.js` (interactivity moved to per-block view.js) — verify no other code depends on it | 1 h |
| E.3 | Drop block-render-side `wp_enqueue_style( 'wb-gamification' )` calls — handled by block.json now | 1 h |
| E.4 | Update `audit/manifest.json` block schema fields | 0.5 h |
| E.5 | Update `CLAUDE.md` blocks pointer to `src/blocks/` | 0.2 h |
| E.6 | Add a "Wbcom Block Standard" entry to the project's CI gate — fail if a `block.json` lacks the standard attributes | 1.5 h |

**Deliverable:** plugin has zero blocks-related legacy assets. CI prevents regression.

**Effort:** ~5 hours.

---

## Total effort

| Phase | Effort |
|---|---|
| A — Build infra | 2 h |
| B — Shared infra (`src/shared/`) | 6 h |
| C — Pilot (redemption-store) | 14 h |
| D — Bulk migration of 14 blocks | 38 h |
| E — Cleanup | 5 h |
| **Total** | **~65 hours / ~8 working days** |

---

## Decision points before kicking off

These choices change the plan; surface them before Phase A.

1. **Pull `src/shared/` from wbcom-essential, or copy-paste?**
   - Pull (git submodule or composer pkg): single source of truth across all Wbcom plugins; updates propagate. Higher initial setup, lower long-term cost.
   - Copy-paste: fast to start, drifts over time, becomes its own maintenance burden.
   - **Recommendation:** copy-paste for now (Phase B is faster); plan to converge to a shared package once 2 plugins use it.

2. **Build pipeline target — `@wordpress/scripts` or hand-rolled webpack?**
   - `@wordpress/scripts` is the documented standard. Recommended.

3. **Pilot block — `redemption-store` or `hub`?**
   - redemption-store: simpler, has a clear before/after demo (current block has 3 violations the user has already seen).
   - hub: more complex, biggest payoff if standardised, but riskier.
   - **Recommendation:** redemption-store as pilot.

4. **Free tier vs Pro tier scope?**
   - Wbcom standard separates Free (mandatory baseline) and Pro (premium features via filter hooks).
   - This plugin is single-tier today.
   - **Recommendation:** ship Free tier across all 15 blocks; revisit Pro tier post-migration.

5. **Backwards compatibility?**
   - `block.json` attribute changes break existing `<!-- wp:wb-gamification/X -->` markup in posts.
   - Wbcom standard requires `deprecated` versions in block.json so saved blocks don't show "Invalid block."
   - **Add deprecated migrations to every block.json during Phase D — this is non-optional.**

---

## What this plan deliberately does NOT include

- **Dependency injection refactor** — out of scope; the plugin's PHP architecture is fine.
- **REST contract changes** — the existing REST API stays as-is; only frontend block consumption changes.
- **Admin UI standardisation** — that's the audit's C2/C3 from `UX-ADMIN-AUDIT-2026-05-03.md`, separate effort.
- **Pro tier** — single-tier plugin today.
- **Bringing every legacy hardcoded value into a token** — only the standard tokens `--wb-gam-spacing-*`, `--wb-gam-font-*`, `--wb-gam-accent`, etc. The plugin can keep its other CSS values for now.

---

## Acceptance criteria

The migration is "done" when:

1. ✅ `npm run build` produces `build/blocks/*/` for all 15 blocks
2. ✅ Every `block.json` declares the standard attribute schema (spacing, typography, color, shadow, border, visibility, uniqueId)
3. ✅ Every block has `edit.js` with the 4 standard InspectorControls panels
4. ✅ `assets/css/frontend.css` no longer contains block-specific styles (only shared frontend utilities)
5. ✅ `assets/interactivity/index.js` is deleted (functionality moved into per-block `view.js`)
6. ✅ Every block renders correctly at 1440px and 390px
7. ✅ Editor responsive preview (Desktop/Tablet/Mobile tabs) shows the right values per device
8. ✅ Existing posts with old block markup still render via `deprecated` versions
9. ✅ CI passes (lint + WPCS + PHPStan + new "wbcom-block-standard" gate)
10. ✅ `audit/manifest.json` regenerated and reflects new structure

---

## Approval gates

| Gate | Approver | What's reviewed |
|---|---|---|
| **Pre-Phase A** | User | This plan. Confirm decision points 1–5. |
| **Post-Phase B** | User | Pilot infrastructure works (`WB_Gam_CSS::generate()` returns expected CSS, design tokens load on test page) |
| **Post-Phase C** | User | redemption-store as pilot — does the editor UX match expectations? Is the responsive control workflow good enough? |
| **Post each Phase D group** | User | One PR per group (D.1, D.2, D.3, D.4, D.5) — five PRs total in Phase D |
| **Post-Phase E** | User | Final state — plugin matches the Wbcom standard 100% |

---

## Why we're not just patching redemption-store

The user's pre-existing rule from 2026-05-02:

> "all planning must be based on architecture plan so we do not add or fix things in random way, all organized for long term plan first and then implement based on what we have, we do not want duplicate code or dead codes, patch of patch type apporach is not acceptable"

A small Tier 1 fix-up of redemption-store (move CSS to `assets/css/frontend.css`, IA store, `wp.apiFetch`) would:

- Bring redemption-store in line with this plugin's existing convention
- Leave the broader Wbcom standard violation untouched
- Force a second refactor when the plugin migrates to the standard — patch on patch

Skipping the small fix-up and going straight to this architectural plan respects that rule. **The cost of patching now is the cost of patching twice.**

---

## Status

- **Plan:** drafted 2026-05-03
- **Approval:** pending
- **Phase A start:** TBD upon approval
- **Estimated completion:** Phase A starts day-1 → Phase E completes day-9 of focused work
