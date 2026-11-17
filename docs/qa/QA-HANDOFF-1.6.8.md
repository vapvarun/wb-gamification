# QA Handoff: WB Gamification 1.6.8

Temporary file for this test round. Delete it when testing is done. The Basecamp project is 47162271, and each card has its own detailed checklist.

## Round 2 (17 Nov 2026): start here

A full audit of the open cards, then a fix round, landed on `1.6.8` (commit `ee2b8ca` or later). Test **Part C** first: it lists every card fixed in this round with its exact check. Parts A and B below are the first round and still apply where Part C does not replace them.

- **Run the 1.6.8 upgrade step once** on an existing site. The header stays 1.6.7 until release, so the upgrade does not run by itself. It adds a ledger index and repairs old BuddyPress activity cards in the background:
  `wp eval '$m=new ReflectionMethod("WBGam\\Engine\\DbUpgrader","upgrade_to_1_6_8"); $m->setAccessible(true); $m->invoke(null);'` then `wp action-scheduler run`.
- **BuddyPress set up from WP-CLI:** end with `wp eval 'bp_update_option("_bp_db_version", bp_get_db_version());'`, or BuddyPress re-installs itself (components off, a second Activity page, /activity/ 404) on the first admin request. That is a lab setup trap, not a plugin bug.
- **QA pages for every block:** `wp wb-gamification qa seed_pages`.

## Before you start

- **Code to test:** branch `1.6.8`, commit `c17509c` or later. The plugin header says 1.6.7 until release, so check the commit, not the version number.
- **Tenure and site-first badge change:** included from the next commit on this branch. Section 3 can be tested, except the last check, which waits for the owner's decision.
- **Part A** is the fix round. **Part B** is the check of every plugin integration.

### Sites

| Needed for | Site |
|---|---|
| Most fixes in Part A | Any test site on this branch with the Reign theme. Use `?autologin=1` for admin, or `?autologin=<username>` for a member. |
| BuddyPress steps | BuddyPress active |
| Toast link hover | BuddyX theme active |
| Import stall | Throwaway site with myCred, GamiPress or BadgeOS data, WP-Cron off and loopback blocked |
| Part B | Throwaway sites. Group plugins so each site stays simple, for example one for learning plugins and one for shop and membership plugins |
| Scale or bulk data | Docker only, never the model site |

### How to report

- **Pass:** tick the box here and add a one-line comment on the card, if the step has one.
- **Fail:** comment on the card with steps, what you expected, what happened, and a screenshot. A Part B failure with no card: list it in your round summary so the developer can file it.
- Check member pages in light and dark mode. Check every changed screen at desktop and 390px. Admin screens do not need dark mode.

---

# Part A: fixes

## 1. Test these first

- [ ] Import stall: **Run it in this window** on a dead queue (10458810873). Throwaway site. Ignore any earlier result on this card, because the first fix did not work on real imports.
- [ ] Member choices saved at the same moment (10461754687). Found by the scale check, so it has had no QA.
- [ ] Leaderboards and emails after the clean-up (10461755109). This code changed after other cards went to testing, so repeat earlier passes in these areas.
- [ ] Weekly nudge reaches every eligible member (10461754852). See section 2.

## 2. Weekly nudge

The nudge used to stop at 5,000 members. It now queues 500 at a time, with the next page one minute later.

Already measured on Docker with 12,000 members, 50 opted out: 11,950 jobs, one per member, none for opted-out members.

Check on a small site with Action Scheduler open at **Tools > Scheduled Actions**:

- [ ] Run `wp cron event run wb_gam_weekly_nudge`, or wait for the weekly run. A member with points this week gets one `wb_gam_nudge_single_user` job.
- [ ] A member who opted out of the leaderboard or notifications gets no job.
- [ ] A member with no points this week gets no job.
- [ ] Running it again within the hour adds no jobs.
- [ ] With email on, the nudge email arrives and reads correctly.
- [ ] With more than 500 eligible members on Docker, a `wb_gam_nudge_page` job appears and the next page runs.

Known and accepted: if nudges are switched off mid-run, the run stops and Action Scheduler logs the next page as failed with "no callbacks are registered". Do not report that.

## 3. Tenure and site-first badges

Cards 10459672711 and 10459672796.

**Fresh install on an established community:** the first daily check, about 1 hour after activation, awards every tenure badge members already qualify for, silently. Later badges are announced as normal.

- [ ] Fresh install: 37 badges, including the four tenure and three site-first badges, with rules and icons.
- [ ] A member registered 400 days ago gets 1-Year Member at the daily check.
- [ ] A site-first badge goes to the first qualifying member only.
- [ ] Update from 1.6.7: no duplicates, owner edits kept, deleted badges stay deleted, no new badges added.
- [ ] **Modules** no longer lists Tenure badges or Site-first badges.
- [ ] Fresh install with members registered over a year ago: at the first check they get their tenure badges with no feed posts, toasts, emails or webhooks.
- [ ] Next daily check: a member who has just reached one year gets the badge with the feed post and toast.
- [ ] Update from 1.6.7 is not silent: a member reaching a tenure date is announced as normal.

## 4. The rest of the round

- [ ] All-time rank is the same on the board, rank strip and API (10389379202).
- [ ] New personal record notice, including a brand-new member (10435809454).
- [ ] Switching an action off saves and stops the points (10407077371).
- [ ] BuddyPress profile rank block (10458809769). Needs BuddyPress. The developer has not checked this in a real browser.
- [ ] BuddyPress members directory. Needs BuddyPress. The developer used a stand-in for the BuddyPress function.
- [ ] Blocks hide from a logged-in member who may not see the data (10450930360).
- [ ] Kudos tab count and settings text (10393175262).
- [ ] Licence retry at most once a day, usage tracking not auto-enabled (10375331641).
- [ ] Setup wizard action names (10389462809).
- [ ] Dashboard Top actions labels (10450929564).
- [ ] Members table at 390px (10450930455).
- [ ] Kudos composer margin (10351295073).
- [ ] Toast link hover and focus (10422396072). Needs BuddyX.
- [ ] readme toast position (10407360678).
- [ ] Docs pages and corrected docs (10453477945).
- [ ] Final pass: fresh install, setup wizard, earn points, leaderboard, profile, no PHP or console errors.

## 5. Do not test in this round

- Credential issuer link, share link after Share, outdated badge docs and the scale benchmark are open and not fixed.
- Clean-up items 3 to 8 (10461755109) are optional and not done.
- Already measured at 10,000 members on Docker: leaderboard, rank, REST and page speed, and a 100,000-row import and undo.

---

# Part B: plugin integrations

WB Gamification has 126 actions: 8 for WordPress and 118 across 17 plugins and their 5 Pro add-ons.

**What has been checked so far:** every plugin's code was read for the actions listed below, and the fixed plugins were run on Docker. The other actions have had their arguments compared with the source but have not been run, so they still need a real run.

**Use the checklists.** Each integration has its own checklist in [`docs/qa/integrations/`](integrations/README.md) and its own Basecamp card in Ready for Testing with the same points as tickable steps. Each action also says what the developer did and did not run.

## 6. Where to look first

These were broken and are fixed in this round, so test them first:

| Plugin | What was wrong |
|---|---|
| MemberPress | All 3 used hooks that never fire, so nothing paid |
| GiveWP | Donation, first donation and goal reached never paid. **Recurring gifts action removed**: we do not have the paid add-on to test it |
| The Events Calendar | RSVP never paid. **Purchase a ticket paid whoever saved a ticket in admin**, and now pays the buyer. RSVP check-ins were missed |
| BuddyPress | Receive a reaction and Create a poll never paid. Group cover paid every upload. **Upload media is removed**: nothing in BuddyPress fires it |
| WooCommerce | A guest order matched by email could pay First purchase on the wrong order. A review held for moderation never paid |
| WordPress | Complete profile read the bio before it was saved. A comment held for moderation never paid the post author |
| Eventonomy | Promotion from the waitlist never paid. Importing events paid the importer. Offline orders marked as paid needed Pro |
| BuddyNext | Accept a connection pays the sender, so it is now labelled Have a connection request accepted |
| WooCommerce orders | Cash, bank and check orders never paid Pay for an order or Make your first purchase. They now pay when marked Completed. Free orders pay nothing |
| BuddyNext, WB Listora, Eventonomy, WP Career Board | Repeat pays closed: bookmark, listing submit and approval, event submit, event follow and hire now pay once per item. A saved listing or event draft pays nothing |
| Actions removed by the owner | BuddyNext connection request, direct message, update profile. Jetonomy Pro message sent, conversation started, message received. MediaVerse follow someone, bookmark. Learnomy apply to teach. Listora favourite. WP Sell Services review approved, merged into Review a service, which still pays moderated reviews |
| LearnDash, LifterLMS, Tutor LMS | See the LMS change below |

Still open:

- **BuddyNext:** a first follow through a private account's approval pays nothing, and joining a space with a linked forum pays twice. Both have cards on its board.
- **Learnomy Pro:** completing a learning path pays 150 with or without a certificate template (Learnomy Pro 2.3.0 fires the hook either way). The earlier note that it paid nothing is out of date.
- **WooCommerce Memberships in Jetonomy:** buying a plan for the first time pays nothing for Activate a membership. The fix belongs in Jetonomy Pro and has a card on its board. WooCommerce Subscriptions works.

**LMS change in this round:** LearnDash, LifterLMS and Tutor LMS now pay once per course, lesson, quiz or similar. Retaking a quiz must earn nothing. Run each LMS on its own site: Tutor LMS and LifterLMS both use `/courses/`, so lesson pages return 404 when both are active.

## 7. How to test one plugin

1. Activate the plugin with WB Gamification. Confirm its actions appear under **WB Gamification > Points**, grouped under the right plugin name.
2. **Options:** for each action, confirm the row has a clear label and description, and the on switch and points amount. Repeatable actions also need the cooldown, daily and weekly limits. Change one value, save, reload, and confirm it stayed.
3. **Earn:** log in as a member and do the action for real. Confirm the points appear in the member's history with the default amount, and the right member gets them.
4. **Off:** switch the action off, repeat it, and confirm no points. Switch it back on.
5. **Limits:** for a repeatable action with a limit, repeat it quickly and confirm the limit holds. For a "Repeats: no" action, confirm the second time pays nothing.
6. **Abuse:** where it applies, try earning from your own content, such as buying, reviewing or reacting to your own item.
7. **Refund or undo:** where it applies, refund, cancel or delete, and record what happens to the points.
8. **Plugin off:** deactivate the plugin. Confirm its actions leave the Points screen, past points stay, and no PHP errors appear.

## 8. Coverage to review

Filling these gaps is an owner decision, so report your view but do not file each one as a bug.

**Actions with no limit:** 56 repeatable actions ship with no cooldown or daily limit. Many are naturally one-time, such as completing a given course. Flag any that can be repeated for points, starting with the high values: tournament win (500), challenge winner (200), tournament round win (150), battle win (100), candidate hired (100).

**Member actions the plugin fires but no option covers.** These come from a search of hook names, so check each one makes sense as a reward:

| Plugin | Possible missing options |
|---|---|
| BuddyPress | Post in a group (`bp_groups_posted_update`), favorite an activity |
| bbPress | Favorite a topic |
| WooCommerce | Online and offline orders, free orders, see section 6 |
| LearnDash | Mark an essay as graded |
| LifterLMS | Buy a plan |
| Tutor LMS | Assignments and certificates, which are in Tutor LMS Pro and not tested |
| Learnomy | Comment on a lesson, publish a course, make a purchase |
| Learnomy Pro | Create a cohort, buy a learning path |
| WP Sell Services | Publish a service, deliver an order, accept a delivery, send or win a proposal, post a buyer request. There are 3 options today |
| WPMediaVerse | Share media, send a message |
| BuddyNext | Follow someone (the option today rewards gaining a follower), join a space by approval |
| WP Career Board | Finish the setup wizard, republish a job |
| Eventonomy | Create a ticket |

**Docs:**

- [ ] BuddyNext, Eventonomy, Learnomy, WB Listora and WP Career Board have no page in `docs/website/integrations/`.
- [ ] `readme.txt` says "62 gamification actions across 10 integration manifests" and "5 more plugins". The real count is 137.

## 9. Test sheet

Moved to one checklist per integration: [`docs/qa/integrations/`](integrations/README.md).

---

# Part C: Round 2 fixes (17 Nov 2026)

Every check: as a **member** unless it says admin; member pages at **1440 and 390, light and dark**; on **Reign, BuddyX and BuddyX Pro** where it is a front-end screen. Tick here and add a one-line comment on the card.

## 10. Points follow the content (card 10515592964, P1)

- [ ] Publish a post (+25). Trash it: history shows "Publish a blog post (content removed)" and the total drops by 25. Restore and publish: +25 back. Delete for good: -25 once, never twice.
- [ ] A comment that pays (after approval): spam it -5, unspam +5, trash -5, untrash +5, delete -5. Each step exactly once.
- [ ] BuddyPress: delete an activity update or comment: its points come back off once.
- [ ] Spend points first, then delete the content: the balance may go below zero (owner decision). Badges already earned stay (owner decision).
- [ ] A post trashed and re-published three times counts once toward "publish N posts" badges.
- [ ] Points awarded before 1.6.8 are left alone (owner decision).

## 11. Moderated content (found in integration QA)

- [ ] WP Sell Services, review moderation on: pending 0, approve +10, reject 0, approve again +10, approve a second time no change.
- [ ] WB Listora review (held for approval): pending 0, approve +8, reject 0, approve +8, delete 0.
- [ ] WB Listora listing: submit +10, reject 0, approve 20 (submission restored + approval), trash 0, restore and publish 20.
- [ ] WB Listora Pro need: rejected, trashed or deleted takes its points back.

## 12. Payouts that were wrong

- [ ] Reactions: set "Receive a reaction" to 1 on Points; a reaction on an activity update pays the owner 1 (it used to force 5).
- [ ] WooCommerce: a member whose first order was free (or an unpaid cash-on-delivery order) still gets "First purchase" (+50) on the first paid order.
- [ ] Copy guards (10462743872, 10462658390): members copied into a BuddyNext space from a linked forum are not paid "Join a space" again; one MediaVerse comment pays once.
- [ ] MediaVerse photo uploads: the 21st upload in a day pays nothing (new default cap 20, applies to every site without its own cap).

## 13. Redemption Store (10483129430, 10483238276)

- [ ] Admin: add a reward with Status Inactive: the list says Inactive and the member store does not show it.
- [ ] Edit a limited reward to Unlimited: stays Unlimited after reload.
- [ ] Edit only the title of a reward: status and description are unchanged.

## 14. Activity stream (10516025691, owner decisions Q1 and Q2)

- [ ] /activity/ on BuddyPress (and BuddyBoss if available): badge, level and kudos posts are one compact card (icon on a light tile, small label, name, one-line reason), inside the post with the same inset as text, on Reign, BuddyX, BuddyX Pro and a Twenty theme. No gap between icon and text, no empty paragraph.
- [ ] Old posts look the same after the upgrade step has run.
- [ ] Three ordinary badges on one day: one post, "X earned 3 badges". A credential badge: its own post. Next day: a new post. No database error in debug.log.
- [ ] Dark mode: badge icons are clearly visible.

## 15. Overlays and toasts (10479164212, 10516939110)

- [ ] A member with a pending level-up opens the Redemption Store: the first tap on Redeem opens the confirm dialog (the celebration card no longer eats it).
- [ ] The celebration card closes itself after about 6 seconds; hovering it holds it; Esc closes it.
- [ ] A member who levelled up twice while away sees one card, the highest level.
- [ ] 390: with a toast showing, open any popup with a bottom button (e.g. Product Roadmap's Suggest Feature): no toast covers it; the toast appears after the popup closes.

## 16. Profiles and privacy (10450930360, 10516939317, 10458809769)

- [ ] Site without BuddyNext/BuddyPress: guest on /u/<public member>/ sees level, points and badges, no history, no login prompt; the tab shows the member's name.
- [ ] A private profile and /u/no-such-user/ both show "This profile is private" with the same status (403) and the same page.
- [ ] BuddyPress members directory as a guest: no rank for a private member.
- [ ] BuddyPress profile rank bar: `:root { --wb-gam-bar-color: red }` in Customizer CSS turns the bar red.

## 17. Look and contrast

- [ ] BuddyX Pro: the header "Log in" link uses the theme font with no stray icon (10512905406); admin icons still show.
- [ ] Dark mode on Reign and BuddyX: Redeem buttons, challenge bonus pills and status labels are readable (10479230907, 10479163870).
- [ ] Cohort Rank rows show avatars; row hover is visible (10479164010, 10479164082).
- [ ] Kudos feed at 390: the arrow stays with the receiver (10479164141). Give Kudos: one frame, no gap under Send Kudos.
- [ ] Leaderboard "Your rank" is one row: "Your rank #17 · 94 Points", next-rank distance below at 390.
- [ ] Top Members: Lucide crown (no emoji); podium steps #1, #2, #3; tied members share a rank like the Leaderboard.
- [ ] User Status Bar: one frame; never over the page title; bottom-right on desktop, in the page on phones.
- [ ] Hub suggestion: "SUGGESTED FOR YOU" on its own line. Badge dates stay on one line at 390.
- [ ] Badge Share: the share link and Copy button appear after Share (10459672934).

## 18. Admin and the rest

- [ ] Points: untick an action, save, reload: still off; it pays nothing (10407077371).
- [ ] Points: "Members can submit" tick per action; with none ticked, Submit Achievement shows "There is nothing to submit…" and `POST /submissions` returns 403; tick one and it can be submitted (10459347341).
- [ ] `[wb_gam_daily_bonus]` renders like the block (10479163783).
- [ ] Credential issuer link: `GET /wp-json/wb-gamification/v1/issuer` as a guest returns 200 (10459672858).
- [ ] Year Recap: a revoked kudos is not counted (10393175262, 10516939156). Progress reset clears personal records (10516939203).
- [ ] Redemption Store admin: gap between the two cards (10483141467). Import: a second "Run it in this window" tab waits instead of looping (10516939365).
- [ ] Badge docs match the Badges screen (10459673036); readme action count (10516939413).

## 19. Integration results this round

Three throwaway sites ran every action on real plugins (WP Sell Services 1.9.3, BuddyPress 14.5.4, LearnDash 5.2, Tutor 4.2, LifterLMS 11.0, WooCommerce 11.3, GiveWP 4.19, The Events Calendar 6.18, every Wbcom plugin). Every action passed after this round's fixes, except: MemberPress (paid, not tested), Tutor paid enrolment and real payment-gateway checkouts (not run). Each integration card has a comment with its results. `bp_publish_post` was removed: it never registered (WordPress's `wp_publish_post` covers it), so BuddyPress shows 12 actions.

## 20. Owner decisions still open (do not report as bugs)

- Approving a gated Jetonomy join request pays 15 (join 5 + approval 10); the doc says 10.
- Closing a bbPress topic pays every time (close, reopen, close).
- An event attendee with an RSVP and a ticket for the same event is paid twice for check-in.

## 21. Before release (not this round)

- Bump the version to 1.6.8 so the upgrade runs by itself.
- Refresh the wp-plugin-qa baseline (`audit/wppqa-baseline-*`, older than 30 days).
- Translations: Cursor translates from `languages/wb-gamification.pot` (prompt prepared separately).
