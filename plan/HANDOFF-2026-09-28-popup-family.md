# WB Gamification handoff: 2026-09-28

Read this first in the new session. The role is **developer** for WB Gamification on buddynext.local.
QA works the Ready for Testing column; the owner decides product calls.

## Progress update (2026-09-28, later same day)

- **PR #89 DONE.** Kudos hide dropped (302bfc0), merged into `1.6.5` (239d31c), card 10345164548 in Ready for Testing.
- **Pop-up audit fixes DONE (owner said go).** Commits 1e1de15 + follow-up on `1.6.5`, all four cards in Ready for Testing with QA checklists; journey audit/journeys/release/23-popup-keyboard-and-icons.md. Cards were:
  10345571341 toast icons blank (lucide-icons never enqueued by the toast surface), 10345571460 hub tiles
  not keyboard-operable + panel focus, 10345571565 admin confirmAction (danger focuses Confirm, Tab leak, duplicate
  of dialog.js), 10345571686 small polish (link toast 4s no pause, badge Share drops focus).
- **Verified fine, do not re-test:** toast backlog (server cap 5, client cap 3), toast clears BN mobile tab bar,
  reduced-motion, redeem confirm dialog, Give Kudos combobox.
- **Not yet audited:** dark mode + 1440 pass on toasts, hub-convert dialog, badge share LinkedIn link, submit-achievement
  success text (its evidence field is a full wp_editor with 16 quicktag buttons and no label on the textarea: worth a look),
  cooldown/skip toast wording, toast+bell together decision.
- Then: release 1.6.5 once RFT clears and the four cards are decided.

- **Popup Atlas (owner asked for uniformity + confetti planning).** Board: https://claude.ai/artifact/B4CUgX4FMJnsnFAZh6iKVi (11 surfaces measured, findings, one-family proposal, live confetti prototype on 3 palettes). Captures in ~/Local Sites/buddynext/app/qa-artifacts/popup-*.png. New Bugs filed from it: 10345734787 (admin toasts unstyled: JS builds .wb-gam-toast, admin CSS styles .wbgam-toast) and 10345745269 (redeem confirm unfinished, banners fail AA 3.22/2.96, Arial buttons). Owner decision pending: confetti on badge+challenge (small) as well as level-up+streak (full)? My pick: both. Then order: defects (1.6.5), one popup family, then celebrate.js (~50 lines, WAAPI, filter wb_gam_celebration_style).
- **Notification links are OURS, not BN's.** BN filter_url uses the payload url as-is; most of our 8 types send the profile front page. Plan: point each type at the hub panel (?panel=badges|kudos|challenges). Corrected on card 10345164548. RFD card 10343975676 bullet "nudges and personal records reach BuddyPress only" is stale (personal records shipped in PR #89; leaderboard/retention nudges deliberately excluded).

- **Popup plan v2 (Atlas republished, version 2).** Fill the gaps: cohort promotion + community goal = Moment cards, submission approved/rejected = toast, badge/challenge get a small burst; 3 new bell types via the existing contract (no BN work). Look: bridge --wb-gam-* to BN tokens FIRST (--bn-accent 50-900, --bn-surface, --bn-text-*, --bn-border, --bn-overlay, --bn-r-md/lg/xl = 10/14/20, --bn-shadow-md/lg, --bn-z-toast 100200 / --bn-z-modal 100100, --bn-success/danger/warn/info), then theme --bx-color-*, then fallback. BN brand is #176bc7 (ours resolved #1d76da). BN dark flips its own tokens, so dark is automatic. Confetti colours = CSS vars --bn-accent-500/400/600/800 + white, no colour maths in JS. Order: defects, token bridge, silent moments, confetti. Owner decisions pending: small burst on badge/challenge? notification-link deep-linking OK?
- **Popup plan v3 (Atlas version 3).** Adds the cross-stack view from the Popup Inventory (https://claude.ai/artifact/EzHhs4yfxsbmdeveWeBkhi). Verified in code: Learnomy has 2 native window.confirm (single-course-leave.js:48, admin/student-detail.js:98) and vendors canvas-confetti 1.9.3 (100 particles, rainbow, no reduced-motion, dashboard only). Inventory corrections: redeem confirm text is "Redeem this reward? %s will be deducted."; toast script already measures the mobile bar itself (16px clear), so BN's bn-base.css override is redundant and BN should DELETE it (not set a variable); link toasts now persist. Plan step 5 = Learnomy routes course completion through Gamification's Moment card + shared celebrate utility (Gamification owns it). BN/Learnomy items go on their own boards, none block Gamification.
- **MOTION LOCK PROPOSED (Atlas version 4, section "Motion, locked"; awaiting owner sign-off, build nothing until approved).** Reuse BuddyNext's own keyframes/timings: BN tokens --bn-dur-fast 120 / --bn-dur 200 / --bn-dur-slow 400, --bn-ease (.2,.8,.2,1), --bn-ease-out (.16,1,.3,1); BN keyframes bn-toast-in/out (10px, .25s), bn-modal-in (.18s, 8px+scale .98), bn-dropdown-in (4px, .15s), bn-bounce-in (.5s spring), bn-count-pop (.5s). Per type: Moment card = scrim 200 + bounce-in 500 + numeral count-pop +300 + confetti +250; Achievement toast = toast-in 250 + icon count-pop +150 + small burst +200; Notice = toast-in/out 250; Banner = dropdown-in 150; Dialog = scrim 200 + modal-in 180; Drawer = slide 400 expo-out, exit 200; Menu = dropdown-in 150. Reduced motion: 120ms opacity only, ring instead of confetti. Today: 83 transition/animation rules, only 6 tokenised, 14+ distinct durations, plugin has a THIRD scale (--wb-gam-transition 150/300/500). Lock mechanism: single assets/css/motion.css + CI gate rejecting raw durations outside it (baseline 83, burn down) + journey asserts animation-name in the 6 keyframes; hover/focus 46x .15s -> 120ms.
- **LOCKED VIEW PROPOSED (Atlas version 5: sections Master map, Locked view, Component lock; awaiting owner sign-off of 6 points, build nothing before).** 20 surfaces mapped (4 Moment cards incl. NEW cohort promotion + community goal; toasts: badge, challenge [Achievement, small burst], points, kudos, welcome, NEW submission approved / not approved, cap+cooldown, admin toast; banner; dialogs: redeem, admin confirm, convert, survey; drawer; kudos menu). Key BN findings measured live: BN's OWN toast is an inverted pill (background var(--text-1), color var(--bg), radius full, 14.9px/500), bottom-CENTRE (.bn-toast-container fixed bottom 24px, z 100200), semantic fills for success/danger; ours is a white card with 4-hue stripe bottom-RIGHT => lock: our toasts adopt BN's shell (inverted, bottom centre, pill for one line, accent icon disc). BN modal panel: radius --bn-r-lg 14, 1px border, shadow-lg, title 18/600, backdrop slate .55 (NOT --bn-overlay .45). BN button: 40px, radius 5px (--bn-r-sm), weight 550-600, blue #176bc7 (dark accent #97c0ee). BN success toast white-on-green = 4.45:1, warn fill 3.23:1 (both under AA; optional note to BN). Our status banners use ink text on tinted ground with icon disc (13:1+). The six approvals: BN toast shell; one close x; one button ladder; one scrim (BN modal backdrop); cap/cooldown quiet by default everywhere; tiers+motion+map as drawn.
- **WHOLE FAMILY (Atlas version 7, section "The whole family").** Scope answer: atlas designed only WB Gamification; BN/Pro/Learnomy are now under one rule: BuddyNext is the reference. Counts (Popup Inventory): BN 66 surfaces + 229 toast msgs, Pro 4, Gamification 20 mapped. LEARNOMY is far bigger than the inventory's 4: shared shells assets/js/shared/{toast,confirm-modal,cancel-modal,prompt-modal,animations}.js; window.lrnToast defined ONCE (toast.js:488) with 176 call sites, window.lrnConfirm ONCE (confirm-modal.js:359) with 63 call sites, plus bespoke course-builder modal + builder toast; 2 native confirms (single-course-leave.js:48, admin/student-detail.js:98); confetti vendored canvas-confetti (class-template-loader.php:2714). Learnomy tokens: accent = var(--brand) = theme blue #1d76da (NOT --bn-accent #176bc7); --lrn-bg/--lrn-text read BN short names but --lrn-bg-elevated #fff and --lrn-text-secondary #6b6b6b hard-coded (breaks dark); own --lrn-dur/--lrn-ease; confirm modal radius 8, scrim ~50% of text colour, 140ms fade, z 100001 (BELOW BN modal 100100). Careful: my first grep wrongly showed 6 lrnToast definitions (it matched typeof checks). Plan: Step A now (all layers read BN-first tokens + locked motion + dimensions; Learnomy cheap because shells are central), Step B later on BN's board (BN public API bnToast/bnConfirm/bnCelebrate with fallbacks). Seventh sign-off item = family rule. Other plugins (Jetonomy, MediaVerse, Listora, Career Board) NOT audited yet.
- **DESIGN-TYPE CENSUS (Atlas version 8, section "Design types and the guideline").** Owner's concern is NOT toast call counts but how many popup DESIGNS exist and whether they follow ONE guideline. Result: 30 distinct designs across the family (toast 6, banner 2, dialog 10, drawer/panel 4, menu 5, moment 3) vs 7 in the guideline; of 26 measured designs only 3 meet every applicable check (all BuddyNext: toast, modal, lightbox menu); BN 3 of 6, Learnomy 0 of 10, Gamification 0 of 10. BN itself drifts in popovers (notif dropdown r-md/shadow-lg, hover card r-lg raw 0 8 32 .12 z 9000, search palette r-xl raw 0 16 64 .2, dropdown-in at 150 vs 200ms). Learnomy has TWO toast looks in one plugin (learnomy.css radius 8/120ms vs shared/toast.css radius 6/0.3s slide) and confirm z 100001, toast stack z 99999. Not yet measured: BN 20 panels + mobile sheet, 15 shared-popover menus, 17 template modals (assumed modal shell). CROSS-SESSION: I am public-44 (this WB Gamification session, ref 96f0c8). public-41 = BuddyNext session, writing buddynext-pro/free-internal/docs/standards/popup-guideline.md; I sent it my Component lock + Motion values + asks (z-scale, one scrim token, toast contrast 4.45/3.23, popover drift). Plan: diff the two tables once, agree one toast + one celebration card + one dialog, each plugin adopts from its own side; BN publishes --bn-* shell tokens, partners read with fallback chain.
- **AGREED WITH BN SESSION (public-41), Atlas version 9; OWNER SIGN-OFF STILL PENDING, HOLD ALL CODE.** BN draft: buddynext-pro/free-internal/docs/standards/popup-guideline.md. Corrections I accepted: scrim = --bn-overlay (BN folds modal backdrop into it, ink 55%; blur 2px on modal + Moment only); layers = --bn-z-toast 100200 / --bn-z-modal 100100 / --bn-z-lightbox 100000 + NEW --bn-z-menu, no literals; buttons = BN shared button (40px; BN measures 10px in dialogs, my .bn-btn read was a compact 5px variant, asked BN to name the class and publish --bn-btn-h/--bn-btn-r); dialog width 420 confirm/prompt, 480 forms; close = Lucide x 40px (BN's is a 28px text glyph today); toast = BN inverted shell, bottom-centre, status by 30px icon disc only (success/error/info/achievement; I use info instead of warn); Moment card = I build the utility now, no emoji, no top bar, --bn-z-modal. BN will extend bnToast (title, body, icon, link); Gamification calls it when BN active, own shell only as fallback. ORDER: BN publishes tokens + toast + menu recipe first and messages me when on the 1.2.2 branch; I adopt after. My open points sent to BN: name the button class + tokens; add Panel + Banner roles; welcome = Notice toast w/ link not Moment; bnToast needs persist-on-link, pause on hover/focus, update-by-key (merged points), element handle (confetti), icon presets; TOP-LAYER CAVEAT (native dialog beats any z-index, hides a toast fired while a dialog is open -> toast host should be a manual popover); drawer motion 400 ease-out / exit 200; search palette explicit in menu recipe.
## Where things stand

- **Branch:** `1.6.5` in `wp-content/plugins/wb-gamification` (repo `vapvarun/wb-gamification`). HEAD `066ae58`.
  Not merged to main; no release yet (merge only after QA clears Ready for Testing).
- **Board** (Basecamp project 47162271): Bugs 0, Ready for Testing 0. Every 1.6.5 card is in Done.
  Ready for Development holds next-release work only (10343975676 admin organisation + 3 polish items,
  10304072907 leaderboard paging, then 10062823272 importer batching). 2.0.0 cards live in **Scope**
  (owner rule: future-version cards never sit in Ready for Development).
- **Open card:** 10345164548, review + merge of **PR #89** (community notification contract, base `1.6.5`,
  branch `feature/community-notification-contract`). Reviewed and tested 2026-09-28 (see the card):
  8 types, exactly one bell row each in BuddyNext 1.2.2, no email from BuddyNext, removal works.

## Next steps, in order

1. **Finish PR #89** (owner approved the change 2026-09-28):
   - In `src/Engine/CommunityNotifications.php`, drop the `kudos_received` clause in `filter_visible()`.
     Nothing else is hidden, so remove `filter_visible()` and its `add_filter` too; update
     `tests/Unit/Engine/CommunityNotificationsTest.php`.
     Why: the recipient is who the kudos was sent to, and the toast already names the giver.
   - Push to the PR branch, run tests/phpcs/PHPStan, merge into `1.6.5`, then post on the card and move it
     to Ready for Testing with the card's own "How to test" list.
   - Not ours: notification links land on the profile front page. BuddyNext deep-links by type through
     its own `buddynext_notification_url` filter (noted on the card).
2. **Pop-up UX audit** (owner request 2026-09-28: "check all popup ux"). See the checklist below.
   Reproduce each finding in the browser before filing; file grouped Bug cards (Where / Why wrong /
   Who it costs / Fix pointer); fix only after discussing with the owner.
3. **Release 1.6.5** when the board is clear: readme changelog (WooCommerce action-prefix style, no
   em-dashes) + Stable tag, the seeded 100k scale benchmark (`composer scale:seed && composer scale:bench
   && composer scale:teardown`, the release build exits 32 without it), `bin/refresh-manifest-live.php`,
   merge, smoke, zip, docs. Confirm with the owner before the merge.
4. **Testbed reseed** after the release (memory: buddynext-testbed-realistic-data).

## Pop-up UX audit checklist

Test as a member, at 1440 and 390, light and dark, plus `prefers-reduced-motion` and keyboard only.

| Surface | Code | What to check |
|---|---|---|
| Points/badge/level/challenge/kudos/streak toasts | `src/Engine/NotificationBridge.php`, `assets/js/toast.js` | Wording (uses `wb_gam_format_points`: "+1 Point"), icon, detail line; same-action merge ("+2 Points", "Leave a comment x2"); position (`wb_gam_toast_position`); covers the BuddyNext bottom tab bar or composer at 390?; dismiss button label; `aria-live` politeness; auto-dismiss timing |
| Toast backlog | `toast.js`, `NotificationBridge::fetch_unseen()` | **Known, unverified:** no cap on how many distinct toasts stack at once, and a backlog may replay on page load. Reproduce: queue 8+ events for a member who is away, then load a page |
| Toast + bell together | PR #89 | After the merge, one event shows a toast AND a BuddyNext bell row. Decide with the owner whether that is right (likely yes: toast is "now", bell is "later") |
| Cooldown/skip toasts | `NotificationBridge::on_award_skipped`, BuddyNext `GamificationBridge::suppress_cooldown_toast` | Never scold the member (memory: member-first, no dead notices) |
| Give kudos modal | `assets/js/give-kudos.js`, `assets/css/give-kudos.css` | Recipient suggestions keyboard use, focus trap, Esc, error text, 390 |
| Rewards redeem dialog | `src/Blocks/redemption-store/view.js` (native `<dialog>`) | Focus moves in and back, cost text ("50 Points"), success + coupon, insufficient balance |
| Badge share / showcase | `src/Blocks/badge-showcase/view.js`, `assets/js/dialog.js` | Share confirm, LinkedIn link, unshare |
| Submit achievement | `src/Blocks/submit-achievement/view.js` | Validation and success messages |
| Currency convert | `assets/js/hub-convert.js` | Confirm wording, result |
| Admin confirms | `assets/js/admin-rest-utils.js` (confirmAction), admin pages | Danger actions focus Cancel; Enter submits prompts |

## Environment gotchas

- WP-CLI: use the socket-aware wrapper in the old session scratchpad or pass the MySQL socket
  `~/Library/Application Support/Local/run/8c5ZD9hVi/mysql/mysqld.sock`; check `wp option get siteurl` first.
- Use `env -u PHPRC` for composer/phpunit/phpcs/PHPStan (CLI PHP is 8.5).
- **Blocks render from the gitignored `build/`.** Run `npm run build` after changing any
  `src/Blocks/*` file or the site shows the old markup.
- Plugin Check for local CI: `wp plugin install plugin-check --activate`, run
  `WBGAM_WP_PATH="$PWD" bash wp-content/plugins/wb-gamification/bin/local-ci.sh --no-journeys`, then delete it.
- Playwright MCP may only write inside the site root: save screenshots there, then move them to
  `~/Local Sites/buddynext/app/qa-artifacts/` and delete `.playwright-mcp/`.
- Other sessions act on this site (an event RSVP gave user 2 +10 on 2026-09-27). Clean up only your own
  test rows (capture MAX(id) before, delete after).
- Basecamp: `cards list` can be wrong; verify with `cards show` and read `parent.title` back after a move.
  Comments: `basecamp comments create <id> "<html>" --project 47162271` (content is positional).
- Commits: no Co-Authored-By or "Generated with" lines, ever.

## Decisions made on 2026-09-27/28 (do not re-litigate)

- Levels **and** leaderboards follow points **earned**: spends (rewards, currency exchange, anything
  through `wb_gam_spend_points()`) never lower them. The ledger `is_spend` flag and
  `wb_gam_user_totals.earned` are the source.
- Only a level climb is announced; drops are silent.
- Partner plugins send their own emails; BuddyNext only collects notifications (bell), never emails them.
- BuddyNext inbox: kudos, challenge, reward ready, credential expired, personal record, streak milestone.
  No leaderboard/retention nudges, no automatic feed posts.
- Point types have an optional singular name; every amount goes through `wb_gam_format_points()`.
- Kudos always shows in the recipient's bell (2026-09-28).

## Popup family shipped to 1.6.5 (2026-09-28, later)

State: commits be6b458, 00fcf40, 5389660 on `1.6.5`, pushed. QA card 10345933760 in Ready for Testing with the
checklist. Journey 24 (and updated 23), CI stage 2.16 `bin/check-motion-tokens.sh`, manifest delta, docs done.
Local CI green except Plugin Check (not installed on buddynext.local; install per the note above to run it).

Landed: six shells in `assets/css/popups.css` on `--bn-*` tokens, `wbGam.toast` (toast-core.js), `wbGam.celebrate`
(confetti), one Moment card (streak, level, league, community goal), hub drawer + convert dialog, redemption
confirm, deactivation survey, kudos menu, bell types `cohort_promotion` / `community_goal` / `submission_result`,
toast default bottom-center. Atlas corrected (cap and cooldown notes were already quiet since 1.6.3).

Open, owner or other teams:
- Bell links stay on the member profile. Deep links to hub panels (`?panel=`) need hub.js to read the param
  and an owner decision. Not built.
- `bnToast` delegation waits for BuddyNext to ship it (public-41). Fallback values match what was agreed.
- 57 raw transition lines baselined in `audit/motion-token-baseline.txt`; migrate file by file.
- Presentation nit: hub Kudos panel, first feed card sits flush against the give-kudos form card.
- Then release 1.6.5: readme changelog (action-prefix, no em-dashes), 100k scale benchmark, manifest refresh
  (`bin/refresh-manifest-live.php`, not write-manifest.mjs), merge only after QA clears the RFT column.
- Testbed reseed (clear "QA Pager Reward" fixtures) after QA clears.
