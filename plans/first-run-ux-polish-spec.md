# First-Run UX Polish — Design Spec

> Fixes the gaps identified by the deep user-journey audit on 2026-04-01.
> All items are small, focused UX improvements — no architecture changes.

**Goal:** Make the first 5 minutes of a new admin's experience clear, guided, and confidence-building. Eliminate dead ends and "what do I do now?" moments.

**Scope:** 6 targeted fixes. No new engines, no new pages, no new blocks.

---

## Fix 1: Setup Wizard — Skip Button Help Text [PENDING]

**Problem:** The "Skip & configure manually" button submits the form but applies no defaults. Admin lands on settings page without context.

**Fix:** Add description text below the skip button:
- Text: "You can configure point values, levels, and badges manually from the Settings page. Default values are already set — you can always change them later."
- This reassures admins that defaults exist even when skipping.

**File:** `src/Admin/SetupWizard.php` (render method, near skip button)

---

## Fix 2: Empty Dashboard — First-Run Hint [DONE — needs browser verification]

**Problem:** Dashboard KPI cards show all 0s on first visit. Admin may think something is broken.

**Status:** Code exists in `src/Admin/SettingsPage.php` (grep confirms `dismissed_welcome` / first-run logic). Needs Playwright browser test to verify rendering.

**Fix:** Detect first-run state (no points in DB) and show an info card above the KPIs:
- Heading: "Getting Started"
- Text: "Your gamification system is ready! Points, badges, and levels will appear here as members interact with your site. Here's what to do next:"
- Three action links:
  1. "Configure point values" → #points section
  2. "Create a challenge" → Challenges page
  3. "View your badge library" → Badges page
- Dismiss: Set a user meta `wb_gam_dismissed_welcome` so it only shows once per admin.

**File:** `src/Admin/SettingsPage.php` (render method, at top of dashboard section)

---

## Fix 3: readme.txt for WordPress.org [DONE]

**Status:** `readme.txt` exists in plugin root (created in latest pull).

**Original problem:** No readme.txt file — required for .org submission and standard plugin distribution.

**Fix:** Create `readme.txt` with:
- Plugin name, contributors, tags, stable tag, tested up to, requires PHP
- Short and long descriptions
- Installation steps
- FAQ (5-6 common questions)
- Changelog for v1.0.0
- Screenshots section (references)

**File:** New `readme.txt` in plugin root

---

## Fix 4: Earning Guide Block (Member-Facing) [DONE]

**Status:** `blocks/earning-guide/block.json` + `blocks/earning-guide/render.php` exist. Shortcode `[wb_gam_earning_guide]` registered in ShortcodeHandler.

**Original problem:** Members have no way to discover how to earn points. Blocks show status but not instructions.

**Fix:** Add a new block + shortcode `[wb_gam_earning_guide]` that renders the action registry as a user-friendly card grid:
- Shows each enabled action: icon, label, points value
- Grouped by category (WordPress, BuddyPress, Media, etc.)
- Only shows actions the current user can actually trigger
- Compact card layout, responsive grid
- Uses existing `Registry::get_actions()` — no new data source

**Files:**
- `blocks/earning-guide/block.json`
- `blocks/earning-guide/render.php`
- `src/Engine/ShortcodeHandler.php` (add `wb_gam_earning_guide` shortcode)

---

## Fix 5: Doctor Command — Fix False Positive for MVS Pro Manifest

**Problem:** Doctor warns "WPMediaVerse has no manifest" even when MVS Pro has it (gamification is a Pro feature).

**Fix:** Already applied — doctor now checks both `wpmediaverse-pro/wb-gamification.php` and `wpmediaverse/wb-gamification.php`.

**Status:** Done.

---

## Fix 6: Minified Assets Build

**Problem:** Missing `.min.css` and `.min.js` files.

**Fix:** Run `grunt build` before release. No code change needed — just a release-process step documented in the QA checklist.

**Status:** Existing Gruntfile handles this. `.min.css` and `.min.js` files now exist on disk. Add to pre-release checklist.

---

## Implementation Priority (Updated 2026-04-12)

| Fix | Effort | Impact | Priority | Status |
|-----|--------|--------|----------|--------|
| Fix 1: Skip button help text | 5 min | Low | P3 | **PENDING** |
| Fix 2: First-run welcome card | 30 min | High | P1 | **Code done, needs browser test** |
| Fix 3: readme.txt | 30 min | High (required for .org) | P1 | **DONE** |
| Fix 4: Earning guide block | 1 hour | Medium | P2 | **DONE** |
| Fix 5: Doctor MVS Pro check | Done | Low | Done | **DONE** |
| Fix 6: Minified assets | `grunt build` | High | P1 (release step) | **DONE** |

---

## What NOT to Do

- Do NOT add an onboarding tour/wizard beyond the existing setup wizard
- Do NOT add tooltips to every field (descriptions already added)
- Do NOT create a separate "getting started" admin page — use the existing dashboard section
- Do NOT auto-create pages with shortcodes on activation — let admin place blocks where they want
