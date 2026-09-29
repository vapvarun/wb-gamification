# QA prompt: WB Gamification 1.6.5, Ready for Testing column

Give the block below to the QA team (or a fresh QA session) as is. State on 2026-09-28: branch `1.6.5`,
head `8d4408a`, local CI green (PHPUnit 335, PHPStan 9, WPCS, all gates, Plugin Check 0 unbaselined
errors). Nothing is merged or released; the release waits for QA to clear every card below.

---

## PROMPT

You are QA for WB Gamification 1.6.5 (plugin repo `wb-gamification`, branch `1.6.5`). Your job is to verify
every card in the "Ready for Testing" column of the WP Gamification Basecamp board and give each a verdict
with evidence. You verify and report. You do not patch plugin code.

### Where
- Basecamp project 47162271, card table 9860004450. Ready for Testing column 9860021091, Testing 9860028737,
  Done 9860004460, Bugs 9860020654.
- Site: http://buddynext.local (BuddyNext 1.2.2 active beside this plugin). Hub page:
  http://buddynext.local/gamification/. Before you start, run `wp option get siteurl` and confirm it says
  buddynext.local: WP-CLI can hit another Local site's database.
- Log in by URL, never by typing: `?autologin=1` (administrator varundubey), `?autologin=alice` (member),
  `?autologin=bn_demo_priya_nair` (member).
- Read first: `audit/journeys/release/23-popup-keyboard-and-icons.md` and
  `audit/journeys/release/24-popup-shell-family.md` in the plugin. They are the step-by-step test scripts and
  each names the file to blame when a step fails.

### Rules
1. A card is a lead, not a verdict. Reproduce or verify in the browser at the exact viewport it names. If you
   cannot reproduce a reported problem, say what you tried.
2. For every card test 1440px and 390px, light and dark, and keyboard only. Where a card mentions motion, test
   with "prefers-reduced-motion: reduce" emulated. Where it mentions layout direction, test an RTL site.
3. Look at every screenshot for presentation problems (cramped, wrapping, misaligned, overflow, unreadable
   contrast), not only the thing under test. Report those as separate cards.
4. Measure instead of judging by eye where a number answers it (`getComputedStyle`, `getBoundingClientRect`,
   contrast ratio). Contrast must be at least 4.5:1 for body text.
5. Use only your own test data. Note the highest row id in a table before you seed and delete only rows above it
   afterwards. Other sessions use this site (the queue currently holds rows for user 16 that are not yours).
   Never run anything that resets or reseeds the site.
6. Save screenshots outside the WordPress root: `~/Local Sites/buddynext/app/qa-artifacts/`. Attach the ones
   that prove a verdict to the card.
7. Verdicts go on the card as a Basecamp comment (HTML, use `<strong>` and `<br>`), then move it: pass to
   Done, fail to Bugs with the reproduction. A comment states what you ran, what you saw, and the file:line
   or selector that explains it. "Fixed" and "cannot reproduce" alone are not comments. New defects you find
   go to Bugs as their own card, never Ready for Development.
8. Basecamp CLI: `create --column` is ignored, and comment and move commands can hang waiting on stdin, so
   always add `< /dev/null`. After every move confirm the column with `basecamp cards show <id> --json`.
   `cards list` can be wrong.
9. Out of scope, do not file: bell links opening the member profile instead of a hub panel (owner decision
   pending), 57 baselined raw hover transitions, per-event toast icons not showing on BuddyNext pages
   (BuddyNext draws its own four status icons on purpose), and the flush spacing between the give-kudos form
   and the first kudos-feed card in the hub Kudos panel (already noted, separate card).

### Test data (create, use, delete)
- Fire an event for a member (queues a toast or Moment card for that member on their next page load):
  `wp eval 'do_action("wb_gam_streak_milestone", 3, 30);'`
  Others: `wb_gam_cohort_outcome` (3, 0, 1, "promoted", 0), `wb_gam_community_goal_reached` (3, <community
  challenge id>, 25), `wb_gam_submission_approved` (<fake id>, 3, "wp_publish_post", 1),
  `wb_gam_submission_rejected` (<fake id>, 3, "wp_publish_post", 1, "note"), `wb_gam_level_changed`,
  `wb_gam_badge_awarded` (3, ["name"=>"QA Badge","description"=>"x","image_url"=>""], "qa-badge").
  User 3 is `bn_demo_priya_nair`.
- Currency convert dialog needs a conversion rule: create a second point type and a rule with
  `WBGam\Services\PointTypeService::create(["slug"=>"qa-gems","label"=>"QA Gems"])` and
  `PointTypeConversionService::create_rule(["from_type"=>"points","to_type"=>"qa-gems","from_amount"=>100,"to_amount"=>1,"min_convert"=>100])`.
  Delete the rule then the type when done.
- Redemption store: `[wb_gam_redemption_store]` on a temporary page (delete it after).
- Clean up: rows in `wb_gam_notifications_queue` above your noted id, bell rows in `bn_notifications` whose
  type starts with `wb_gamification.` that you created, temporary page, point type, rule.

### Cards to verify (all in Ready for Testing)

**10345933760 Pop-ups: one shell family, celebrations and new bell types (main card).**
Run journey 24 in full plus the eight-point checklist on the card. Key checks:
level-up, streak, league promotion and community goal each show ONE full-screen card with confetti, a second
waits its turn, Awesome and Escape dismiss; reduced motion gives no confetti and a brief ring; repeated points
from one action merge into ONE toast ("+N Points"), never more than three toasts; Settings > Realtime lists
Bottom center as the recommended default and every position works; hub drawer and convert dialog fit 390px
and stay readable in dark (title and focused input included); the Plugins-screen deactivation survey uses the
same look and its x closes WITHOUT deactivating; the kudos recipient list is the shared menu and sits above the
drawer; bell rows for league promotion, community goal and submission result appear under Achievements, a
demotion creates none, and the reviewer's private note is not in the row text; RTL drawer slides in from the left.

**10347304232 Toasts: use BuddyNext's bnToast when present, one stack.**
On /activity/ with two queued gamification toasts, fire `window.bnToast('Profile saved','success')` while they
are up. Expect one `.bn-toast-container`, no `.wb-gam-toasts` element, no overlap at 1440 and 390, light and
dark; same-key updates repaint in place; a badge toast shows confetti. In wp-admin (no bnToast) the plugin's
own toast still appears.

**10345745269 Redeem confirm, banner contrast, buttons in Arial.**
Confirm has a title naming the reward, shadow, 40px buttons in Inter; success and error banners are at least
4.5:1 in light and dark (measured 14 to 16:1); the coupon code inside a success banner is readable; the
level-up "Awesome!" button is Inter. Check 390 and dark, and redeem once.

**10345734787 Admin toasts unstyled.**
On Badge Library, Settings, Award Points and any page that saves through the REST helpers: the toast is a fixed
pill at the bottom, inside the viewport, message vertically centred on the icon, success green and error red;
check dark, 390 and an RTL admin.

**10345571341 Toast icons blank without a gamification block.**
Load /activity/ (no gamification block on it) at 390 with a queued points, badge and kudos toast: every toast
shows its icon.

**10345571460 Hub tiles not keyboard reachable, panel focus.**
Tab to a hub tile, Enter and Space open the panel, focus lands on the close x, Tab stays inside, Escape and
backdrop close it and focus returns to the tile; a visible focus ring on every tile.

**10345571565 Admin confirmAction dialog.**
A danger confirm starts on Cancel, Tab never leaves the dialog, Escape cancels and returns focus, it fits a
390 viewport with a 16px gutter.

**10345571686 Link toast timeout, badge Share drops focus.**
A toast with a link stays until dismissed; a plain toast leaves after about 4s and pauses while hovered or
focused; Share on a badge keeps keyboard focus across two presses.

**10345164548 One BuddyNext inbox (community notification contract).**
Bell rows exist for badge earned, level up, kudos received, challenge completed, reward ready, credential
expired, personal record and streak milestone; rows group; each type has a switch in BuddyNext notification
preferences and turning it off stops new rows; kudos always shows in the recipient's bell; a demotion or a
level drop is silent; nothing is emailed by BuddyNext for these.

### Deliverable
For each card: verdict (Pass or Fail), evidence (what you ran, measurements, screenshot names), viewport and
theme matrix covered, and what you could not verify with the reason. Finish with one summary comment on card
10345933760 listing every card's verdict and any new Bugs cards you filed.
