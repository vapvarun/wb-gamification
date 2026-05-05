# UX Token Migration

**Status:** active backlog (Tier-1 #58)
**Owner:** unassigned
**Driving principle:** every CSS value lives in a `var(--wb-gam-*)` token — no raw px, hex, or emoji in user-facing surfaces.

## Why

The wp-plugin-development standard requires CSS values to flow through tokens so themes and customers can rebrand without touching plugin source. The wppqa MCP currently flags **591 raw values**:

| Severity | Count | Pattern | Fix template |
|---|---|---|---|
| medium | 159 | Raw hex outside `var(--wb-gam-color-*)` | `color: #5b4cdb;` → `color: var(--wb-gam-color-accent);` |
| low | 427 | Raw px outside `var(--wb-gam-space-*)` / `--wb-gam-radius-*` | `padding: 16px;` → `padding: var(--wb-gam-space-md);` |
| medium | 5 | Emoji in user-facing strings | Replace with Lucide icon component |

These are warnings, not blockers. They do not affect 1.0.0 release readiness. They DO affect long-term maintainability and theme-customisation UX.

## What ships in 1.0.0

- The full token system is registered (`src/shared/design-tokens.css` registered as `wb-gam-tokens` style handle, mirrored in admin via `assets/css/admin.css :root`).
- All NEW code added in this 1.0.0 push (Tier 0 REST migration, Phase G block-card system, generic admin-rest-form driver) consumes tokens exclusively.
- The legacy CSS predating the standard contains the 591 raw values and is the migration target.

## Migration phases

### Phase 1 — Hex consolidation (high-impact)

Hex values control brand identity. Customers rebrand by overriding tokens; raw hex breaks rebrandability.

**Targets:**
- `assets/css/frontend.css` — toast/overlay tints, success/warning palette
- `assets/css/admin.css` — admin notice colors, icon tints
- `assets/css/admin-premium.css` — premium card palette
- `assets/css/hub.css` — already mostly tokenised (this is the canonical example)

**Recipe:**
1. Run `grep -nE "#[0-9a-fA-F]{3,6}" <file>` to find every raw hex.
2. For each match: identify which token it's morally equivalent to (compare against the `--wb-gam-color-*` family in `src/shared/design-tokens.css`).
3. Replace `#5b4cdb` with `var(--wb-gam-color-accent)`, etc.
4. If no token exists for the value, add one — don't introduce new raw hex.

### Phase 2 — Spacing consolidation (low-impact, high-volume)

The 427 raw `px` values are mostly padding/margin/font-size. The token scale already covers the common values:

| Raw px | Token |
|---|---|
| `4px` | `var(--wb-gam-space-xs)` |
| `8px` | `var(--wb-gam-space-sm)` |
| `16px` | `var(--wb-gam-space-md)` |
| `24px` | `var(--wb-gam-space-lg)` |
| `32px` | `var(--wb-gam-space-xl)` |

**Recipe:** mass `sed` replacement per file, then `git diff` review for visual regressions.

### Phase 3 — Emoji → Lucide

Five emoji strings in user-facing PHP. Locate via:

```bash
grep -rE "[\x{1F300}-\x{1FAFF}]" src/ blocks/ --include='*.php' --include='*.css'
```

Replace each with the equivalent Lucide icon via the existing `wb-gam-lucide` SVG sprite.

## Acceptance gate

Re-run `mcp__wp-plugin-qa__wppqa_check_ux_guidelines` — target metric: **0 medium warnings, ≤ 50 low warnings**. Eliminate the high-impact hex set first; the px tail is safe to slow-roll.

## Why this isn't blocking 1.0.0

- Token system is correctly architected and registered.
- All Tier 0/Phase G new code uses tokens correctly.
- The 591 raw values exist in pre-Phase-G legacy CSS, which renders correctly today and will not regress on release.
- Themes that override `var(--wb-gam-color-accent)` will still get correct branding on every NEW component (every block, the admin REST forms, the confirm modal). Only the legacy frontend.css/admin.css surfaces will keep their raw values until migrated.

Track progress here. Each Phase X sub-PR should reduce the warning count and update the table at the top of this file.
