---
journey: tier-2-editor-15-blocks
plugin: wb-gamification
priority: critical
roles: [editor]
covers: [block-editor-registration, phase-g4]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged-in admin (autologin via ?autologin=1)"
estimated_runtime_minutes: 12
---

# Tier 2 — Editor Surface (15 blocks × inserter + sidebar)

Every block must (a) register an editor script, (b) register a render callback, (c) appear in the inserter, (d) insert without showing "Your site doesn't include support" message, (e) configure correctly via the standard Inspector panels (Layout / Style / Visibility / Hover), (f) persist settings on reload.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test user: admin (`?autologin=1`)
- Fixtures: 15 QA pages already seeded (see `wp wb-gamification qa list_pages`)

## Steps

### 1. Verify editor + render handles for all 15 blocks
- **Action**: `wp eval` with the snippet:
  ```php
  $slugs = ['leaderboard','member-points','badge-showcase','level-progress','challenges','streak','top-members','kudos-feed','year-recap','points-history','earning-guide','hub','redemption-store','community-challenges','cohort-rank'];
  foreach ($slugs as $s) {
    $bt = WP_Block_Type_Registry::get_instance()->get_registered("wb-gamification/$s");
    echo $s . ': editor=' . count($bt->editor_script_handles) . ' render=' . ($bt->render_callback ? 'set' : 'null') . PHP_EOL;
  }
  ```
- **Expect**: every line shows `editor=1 render=set`. **Zero blocks** with editor=0.

### 2. Editor canvas opens for each block (sample 3)
For `hub`, `community-challenges`, `cohort-rank` (the 3 blocks Phase G.4 fixed):
- **Action**: `playwright_navigate http://wb-gamification.local/wp-admin/post.php?post=<id>&action=edit&autologin=1` (post id from QA seeder)
- **Wait**: ~3s for editor canvas iframe
- **Action**: `playwright_evaluate` looking inside `iframe[name="editor-canvas"]`:
  ```js
  const doc = document.querySelector('iframe[name="editor-canvas"]').contentDocument;
  return {
    has_no_support_msg: /doesn't include support/i.test(doc.body.innerText),
    has_block: !!doc.querySelector('[data-type="wb-gamification/<slug>"]')
  };
  ```
- **Expect**: `has_no_support_msg: false`, `has_block: true`

### 3. Inserter discovery
- **Action**: in a new post, click `+` inserter, type "wb-gam" or block name
- **Expect**: each of 15 blocks appears in the result list

### 4. Inspector panels
For one representative block (`leaderboard`):
- **Action**: insert → click block to focus → open Block sidebar
- **Expect**: panels exist for "Layout", "Style", "Visibility", "Hover"
- **Action**: change padding-mobile, accent color, hover color, hide-on-mobile toggle
- **Action**: save draft → reload page
- **Expect**: every changed setting reads back the same value

## Pass criteria

ALL of the following hold:
1. 15/15 blocks return `editor=1 render=set` from the registry probe
2. None of the 3 previously-broken blocks show "doesn't include support"
3. Every standard Inspector panel renders for the sampled block
4. Settings persist across save+reload

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `editor=0` for a specific block | Legacy `register_block_type` shadowing the Registrar | `wb-gamification.php` `register_blocks()` body — must stay empty |
| "doesn't include support" message | The block's `block.json` declares `editorScript` but legacy register fired first without it | `blocks/<slug>/block.json` — should have NO `register_block_type` callers anymore |
| Panel missing | Standard schema attribute missing from `block.json` | `src/Blocks/<slug>/block.json` — compare against leaderboard's full schema |
| Settings don't persist | Attribute serialization mismatch | `src/Blocks/<slug>/edit.js` + `block.json` attributes |
