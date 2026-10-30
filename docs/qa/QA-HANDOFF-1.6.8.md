# QA Handoff: WB Gamification 1.6.8

Temporary file for this test round. Delete it when testing is done. The Basecamp project is 47162271, and each card has its own detailed checklist.

## Before you start

- **Code to test:** branch `1.6.8`, commit `c17509c` or later. The plugin header says 1.6.7 until release, so check the commit, not the version number.
- **Not part of `c17509c`:** the tenure and site-first badge change. Section 3 is on hold until the owner decides how it should behave.
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

## 3. Tenure and site-first badges: ON HOLD

Do not test until the developer confirms the change is pushed (cards 10459672711 and 10459672796). The checks will be:

- [ ] Fresh install: 37 badges, including the four tenure and three site-first badges, with rules and icons.
- [ ] A member registered 400 days ago gets 1-Year Member at the daily check.
- [ ] A site-first badge goes to the first qualifying member only.
- [ ] Update from 1.6.7: no duplicates, owner edits kept, deleted badges stay deleted, no new badges added.
- [ ] **Modules** no longer lists Tenure badges or Site-first badges.
- [ ] Fresh install on an established community: behaviour matches the owner's decision on announcements.

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

WB Gamification has 128 actions: 8 for WordPress and 120 across 16 plugins and their 5 Pro add-ons.

**What has been checked so far:** only the code. For each action, the developer searched the current plugin source for its hook. Nothing here has been run on a real site, so a "Hook found" row is untested, and "Expected to fail" means the code says so and QA needs to confirm it.

The integration files did not change in this fix cycle, so these failures are older than 1.6.8.

## 6. Where to look first

14 actions are expected to fail:

| Plugin | Expected to fail | Effect |
|---|---|---|
| MemberPress | 3 of 3 | Nothing on this integration can award points |
| GiveWP | 3 of 4 | Donations, first donation and goal reached award nothing |
| LifterLMS | 3 of 5 | Course, lesson and quiz award nothing |
| The Events Calendar | 2 of 3 | RSVP awards nothing. **Purchase an event ticket pays whoever saves a ticket in admin**, and pays no buyer |
| BuddyPress | 3 of 14 | Receive a reaction, Create a poll and Upload media award nothing |

Two more need a real test:

- **WooCommerce offline orders:** the order actions use `woocommerce_payment_complete`. Check whether a cash on delivery or bank transfer order earns points when marked Completed by hand.
- **GiveWP recurring gifts:** needs the paid Recurring add-on, and the hook is only in legacy subscription code.

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
| WooCommerce | Offline orders, see section 6 |
| LearnDash | Enroll in a course, submit an assignment |
| LifterLMS | Enroll in a course, join a membership, buy a plan |
| Learnomy | Comment on a lesson, publish a course, make a purchase |
| Learnomy Pro | Create a cohort, buy a learning path |
| WP Sell Services | Publish a service, deliver an order, accept a delivery, send or win a proposal, post a buyer request. There are 3 options today |
| WPMediaVerse | Share media, send a message |
| BuddyNext | Follow someone (the option today rewards gaining a follower), join a space by approval |
| WP Career Board | Finish the setup wizard, republish a job |
| Eventonomy | Create a ticket |

**Docs:**

- [ ] BuddyNext, Eventonomy, Learnomy, WB Listora and WP Career Board have no page in `docs/website/integrations/`.
- [ ] `readme.txt` says "62 gamification actions across 10 integration manifests" and "5 more plugins". The real count is 128.

## 9. Test sheet

Generated from the integration files. "Limit" is the shipped default, and owners can change it on the Points screen.

#### WordPress core

Code checked against: always on.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Join the site | `wp_user_register` | `user_register` | 15 | no | once | Hook found |
| [ ] | Log in for the first time | `wp_first_login` | `wp_login` | 10 | no | once | Hook found |
| [ ] | Complete WordPress profile | `wp_profile_complete` | `personal_options_update` | 10 | no | once | Hook found |
| [ ] | Receive a comment on a post | `wp_post_receives_comment` | `comment_post` | 3 | yes | 10/day | Hook found |
| [ ] | Publish a blog post | `wp_publish_post` | `transition_post_status` | 25 | yes | none | Hook found |
| [ ] | Publish your first post | `wp_first_post` | `transition_post_status` | 20 | no | once | Hook found |
| [ ] | Leave a comment | `wp_leave_comment` | `comment_post` | 5 | yes | 60s wait | Hook found |
| [ ] | Get a comment approved from moderation | `wp_comment_approved` | `transition_comment_status` | 5 | yes | none | Hook found |

#### BuddyPress

Code checked against: 14.5.4.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Post an activity update | `bp_activity_update` | `bp_activity_posted_update` | 10 | yes | 30s wait | Hook found |
| [ ] | Comment on an activity | `bp_activity_comment` | `bp_activity_comment_posted` | 5 | yes | 30s wait | Hook found |
| [ ] | Accept a friendship | `bp_friends_accepted` | `friends_friendship_accepted` | 8 | yes | none | Hook found |
| [ ] | Join a group | `bp_groups_join` | `groups_join_group` | 8 | yes | none | Hook found |
| [ ] | Create a group | `bp_groups_create` | `groups_group_create_complete` | 20 | yes | none | Hook found |
| [ ] | Complete extended profile | `bp_profile_complete` | `xprofile_updated_profile` | 15 | no | once | Hook found |
| [ ] | Receive a reaction | `bp_reactions_received` | `bp_reactions_add` | 3 | yes | none | **Expected to fail.** Not fired by our BuddyPress Reactions, which fires `bp_reactions_after_react`. |
| [ ] | Create a poll | `bp_polls_created` | `bp_polls_created` | 10 | yes | none | **Expected to fail.** Not fired by our BuddyPress Polls. |
| [ ] | Publish a member blog post | `bp_publish_post` | `publish_post` | 25 | yes | 600s wait + 5/day | Hook found |
| [ ] | Upload media | `bp_media_upload` | `bp_media_add` | 5 | yes | 60s wait | **Expected to fail.** Not found in BuddyPress or any plugin checked. Which plugin should fire this? |
| [ ] | Upload profile photo | `bp_avatar_upload` | `bp_members_avatar_uploaded` | 10 | no | once | Hook found |
| [ ] | Upload cover photo | `bp_cover_upload` | `members_cover_image_uploaded` | 10 | no | once | Hook found |
| [ ] | Upload group cover photo | `bp_group_cover_upload` | `groups_cover_image_uploaded` | 10 | yes | none | Hook found |
| [ ] | Send a private message | `bp_message_sent` | `messages_message_sent` | 3 | yes | 60s wait | Hook found |

#### bbPress

Code checked against: 2.6.21.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Create a forum topic | `bbp_new_topic` | `bbp_new_topic` | 10 | yes | 300s wait | Hook found |
| [ ] | Post a forum reply | `bbp_new_reply` | `bbp_new_reply` | 5 | yes | 60s wait | Hook found |
| [ ] | Have a forum topic closed | `bbp_topic_closed` | `bbp_closed_topic` | 20 | yes | none | Hook found |

#### WooCommerce

Code checked against: 11.2.1, YITH Wishlist latest.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Pay for an order | `wc_order_completed` | `woocommerce_payment_complete` | 25 | yes | none | Check an offline order (cash on delivery, bank transfer) marked Completed by hand. |
| [ ] | Make your first purchase | `wc_first_purchase` | `woocommerce_payment_complete` | 50 | no | once | Check an offline order (cash on delivery, bank transfer) marked Completed by hand. |
| [ ] | Leave a product review | `wc_product_reviewed` | `comment_post` | 15 | yes | 86400s wait | Hook found |
| [ ] | Add a product to wishlist (YITH) | `wc_wishlist_add` | `yith_wcwl_added_to_wishlist` | 5 | yes | 300s wait | Hook found |
| [ ] | Add a product to cart | `wc_add_to_cart` | `woocommerce_add_to_cart` | 1 | yes | 300s wait | Hook found |

#### LearnDash

Code checked against: 5.2.2.1.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a LearnDash course | `ld_course_completed` | `learndash_course_completed` | 100 | yes | none | Hook found |
| [ ] | Complete a LearnDash lesson | `ld_lesson_completed` | `learndash_lesson_completed` | 15 | yes | none | Hook found |
| [ ] | Complete a LearnDash topic | `ld_topic_completed` | `learndash_topic_completed` | 5 | yes | none | Hook found |
| [ ] | Pass a LearnDash quiz | `ld_quiz_passed` | `learndash_quiz_completed` | 25 | yes | none | Hook found |
| [ ] | Get an assignment approved | `ld_assignment_approved` | `learndash_assignment_approved` | 20 | yes | none | Hook found |

#### LifterLMS

Code checked against: 10.3.2.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a LifterLMS course | `llms_course_completed` | `llms_user_course_complete` | 100 | yes | none | **Expected to fail.** Never fired by LifterLMS 10.3.2. Likely `lifterlms_course_completed`. |
| [ ] | Complete a LifterLMS lesson | `llms_lesson_completed` | `llms_user_lesson_complete` | 10 | yes | none | **Expected to fail.** Never fired. Likely `lifterlms_lesson_completed`. |
| [ ] | Pass a LifterLMS quiz | `llms_quiz_passed` | `llms_user_quiz_complete` | 25 | yes | none | **Expected to fail.** Never fired. Likely `lifterlms_quiz_passed` or `lifterlms_quiz_completed`. |
| [ ] | Earn a LifterLMS achievement | `llms_achievement_earned` | `llms_user_earned_achievement` | 30 | yes | none | Hook found |
| [ ] | Earn a LifterLMS certificate | `llms_certificate_earned` | `llms_user_earned_certificate` | 50 | yes | none | Hook found |

#### MemberPress

Code checked against: 1.14.1.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Activate a MemberPress membership | `mp_membership_activated` | `mepr-event-signup-completed` | 50 | yes | 3600s wait | **Expected to fail.** Never fired by MemberPress 1.14.1. Likely `mepr-event-member-signup-completed`. |
| [ ] | Renew a MemberPress membership | `mp_membership_renewed` | `mepr-event-renewals` | 30 | yes | none | **Expected to fail.** Never fired. Likely `mepr-event-renewal-transaction-completed`. |
| [ ] | Join as a paid member for the first time | `mp_first_membership` | `mepr-event-signup-completed` | 100 | no | once | **Expected to fail.** Never fired by MemberPress 1.14.1. Likely `mepr-event-member-signup-completed`. |

#### GiveWP

Code checked against: 4.19.1.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a donation | `give_donation_completed` | `give_complete_purchase` | 30 | yes | none | **Expected to fail.** Never fired by GiveWP 4.19.1. Likely `give_complete_donation`. |
| [ ] | Make first donation ever | `give_first_donation` | `give_complete_purchase` | 75 | no | once | **Expected to fail.** Never fired by GiveWP 4.19.1. Likely `give_complete_donation`. |
| [ ] | Make a recurring donation payment | `give_recurring_donation` | `give_recurring_record_payment` | 20 | yes | none | Needs the paid Recurring add-on. Fires from legacy subscription code only. |
| [ ] | Campaign reaches its goal | `give_campaign_goal_reached` | `give_goal_complete` | 15 | yes | none | **Expected to fail.** Never fired by GiveWP 4.19.1. No direct replacement found. |

#### The Events Calendar + Event Tickets

Code checked against: 6.18.2 + 5.30.3.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | RSVP to an event | `tec_rsvp_registered` | `tribe_tickets_rsvp_attendee_created` | 10 | yes | none | **Expected to fail.** Never fired by Event Tickets 5.30.3. Likely `event_tickets_rsvp_attendee_created`. |
| [ ] | Purchase an event ticket | `tec_ticket_purchased` | `event_tickets_after_save_ticket` | 20 | yes | none | **Expected to fail.** Fires when a ticket is SAVED, not bought. Wrong event: may pay whoever edits a ticket. |
| [ ] | Check in to an event | `tec_event_checked_in` | `event_tickets_checkin` | 15 | yes | none | Hook found |

#### BuddyNext

Code checked against: 1.2.7.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Create a post | `bn_post_created` | `buddynext_post_created` | 5 | yes | 30s wait + 20/day | Hook found |
| [ ] | Share a post | `bn_post_shared` | `buddynext_post_shared` | 5 | yes | 10/day | Hook found |
| [ ] | Write a comment | `bn_comment_created` | `buddynext_comment_created` | 3 | yes | 30s wait | Hook found |
| [ ] | Receive a reaction on your content | `bn_reaction_received` | `buddynext_post_reaction_received` | 2 | yes | 20/day | Hook found |
| [ ] | Vote on a poll | `bn_poll_voted` | `buddynext_poll_voted` | 1 | yes | 5/day | Hook found |
| [ ] | Bookmark a post | `bn_post_bookmarked` | `buddynext_post_bookmarked` | 1 | yes | 5/day | Hook found |
| [ ] | Gain a follower | `bn_followed` | `buddynext_follower_gained` | 5 | yes | 10/day | Hook found |
| [ ] | Follow a member for the first time | `bn_first_follow` | `buddynext_user_followed_first_time` | 5 | no | once | Hook found |
| [ ] | Accept a connection | `bn_connected` | `buddynext_connection_accepted` | 10 | yes | none | Hook found |
| [ ] | Send a connection request | `bn_connection_requested` | `buddynext_connection_requested` | 1 | yes | 5/day | Hook found |
| [ ] | Send a direct message | `bn_dm_sent` | `buddynext_dm_sent` | 1 | yes | 10/day | Hook found |
| [ ] | Join a space | `bn_space_joined` | `buddynext_space_member_joined` | 5 | yes | 5/day | Hook found |
| [ ] | Create a space | `bn_space_created` | `buddynext_space_created` | 10 | yes | none | Hook found |
| [ ] | Update your profile | `bn_profile_updated` | `buddynext_profile_completion_changed` | 2 | yes | 300s wait | Hook found |
| [ ] | Complete your profile | `bn_profile_completed` | `buddynext_profile_strength_changed` | 25 | no | once | Hook found |
| [ ] | Complete onboarding | `bn_onboarding_completed` | `buddynext_onboarding_completed` | 20 | no | once | Hook found |

#### Jetonomy

Code checked against: 2.0.7.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Join a space | `jetonomy_space_joined` | `jetonomy_user_joined_space` | 5 | yes | 60s wait + 5/day | Hook found |
| [ ] | Get approved into a gated space | `jetonomy_join_request_approved` | `jetonomy_join_request_approved` | 10 | yes | 300s wait | Hook found |
| [ ] | Get promoted to a higher trust level | `jetonomy_trust_level_up` | `jetonomy_trust_level_changed` | 50 | yes | none | Hook found |
| [ ] | Activate a membership | `jetonomy_membership_activated` | `jetonomy_membership_activated` | 25 | yes | none | Hook found |

#### Jetonomy Pro

Code checked against: 2.0.7.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Create a poll | `jetonomy_pro_poll_created` | `jetonomy_pro_poll_created` | 10 | yes | 60s wait | Hook found |
| [ ] | Vote on a poll | `jetonomy_pro_poll_voted` | `jetonomy_pro_poll_voted` | 2 | yes | 30s wait | Hook found |
| [ ] | Send a Jetonomy private message | `jetonomy_pro_message_sent` | `jetonomy_pro_message_sent` | 2 | yes | 60s wait + 20/day | Hook found |
| [ ] | Start a Jetonomy conversation | `jetonomy_pro_conversation_created` | `jetonomy_pro_conversation_created` | 5 | yes | 120s wait | Hook found |
| [ ] | Receive a Jetonomy private message | `jetonomy_pro_dm_received` | `jetonomy_pro_dm_received` | 1 | yes | 120s wait + 10/day | Hook found |
| [ ] | Send a reaction | `jetonomy_pro_reaction_added` | `jetonomy_pro_reaction_toggled` | 1 | yes | 30s wait + 50/day | Hook found |

#### Learnomy

Code checked against: 2.1.4.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a lesson | `learnomy_lesson_completed` | `learnomy_lesson_completed` | 10 | yes | 30s wait | Hook found |
| [ ] | Pass a quiz | `learnomy_quiz_passed` | `learnomy_quiz_passed` | 25 | yes | 30s wait | Hook found |
| [ ] | Complete a course | `learnomy_course_completed` | `learnomy_course_completed` | 100 | yes | none | Hook found |
| [ ] | Enrol in a course | `learnomy_student_enrolled` | `learnomy_student_enrolled` | 5 | yes | 5/day | Hook found |
| [ ] | Earn a certificate | `learnomy_certificate_issued` | `learnomy_certificate_issued` | 50 | yes | none | Hook found |
| [ ] | Review a course | `learnomy_review_submitted` | `learnomy_review_submitted` | 8 | yes | 300s wait | Hook found |
| [ ] | Apply to teach | `learnomy_instructor_application` | `learnomy_instructor_application_submitted` | 5 | no | once | Hook found |

#### Learnomy Pro

Code checked against: 2.1.4.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a learning path | `learnomy_pro_path_completed` | `learnomy_pro_learning_path_completed` | 150 | yes | none | Hook found |
| [ ] | Enrol in a learning path | `learnomy_pro_path_enrolled` | `learnomy_pro_learning_path_enrolled` | 5 | yes | 5/day | Hook found |
| [ ] | Submit an assignment | `learnomy_pro_assignment_submitted` | `learnomy_pro_assignment_submitted` | 15 | yes | 60s wait | Hook found |
| [ ] | Join a cohort | `learnomy_pro_cohort_joined` | `learnomy_pro_cohort_member_added` | 5 | yes | none | Hook found |
| [ ] | Redeem a course gift | `learnomy_pro_gift_redeemed` | `learnomy_pro_gift_redeemed` | 5 | yes | none | Hook found |

#### Eventonomy

Code checked against: 1.9.0.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Reserve a place at an event | `evnm_rsvp_going` | `evnm_after_create_rsvp` | 10 | yes | 300s wait + 10/day | Hook found |
| [ ] | Submit an event | `evnm_event_created` | `evnm_after_create_event` | 25 | yes | 3/day | Hook found |
| [ ] | Complete a ticket order | `evnm_ticket_purchased` | `evnm_after_create_order` | 20 | yes | none | Hook found |

#### Eventonomy Pro

Code checked against: 1.9.0.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Attend an event | `evnm_event_attended` | `evnm_after_checkin` | 30 | yes | none | Hook found |
| [ ] | Complete a ticket order | `evnm_ticket_purchased_gateway` | `evnm_after_update_order` | 20 | yes | none | Hook found |
| [ ] | Follow an event or organizer | `evnm_follow` | `evnm_after_follow` | 2 | yes | 30s wait + 10/day | Hook found |

#### WB Listora

Code checked against: 2.1.0.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Submit a listing | `listora_listing_submitted` | `wb_listora_listing_submitted` | 10 | yes | 60s wait | Hook found |
| [ ] | Get a listing approved | `listora_listing_published` | `wb_listora_after_approve_listing` | 10 | yes | none | Hook found |
| [ ] | Write a review | `listora_review_written` | `wb_listora_review_submitted` | 8 | yes | 300s wait | Hook found |
| [ ] | Favourite a listing | `listora_favorite_added` | `wb_listora_favorite_added` | 2 | yes | 10/day | Hook found |
| [ ] | Claim a listing | `listora_claim_approved` | `wb_listora_claim_approved` | 15 | yes | none | Hook found |
| [ ] | Renew a listing | `listora_listing_renewed` | `wb_listora_listing_renewed` | 3 | yes | none | Hook found |

#### WB Listora Pro

Code checked against: 2.0.0.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Post a need | `listora_need_submitted` | `wb_listora_pro_need_submitted` | 8 | yes | 60s wait | Hook found |
| [ ] | Get a need approved | `listora_need_published` | `wb_listora_pro_need_published` | 5 | yes | none | Hook found |
| [ ] | Respond to a need | `listora_need_response` | `wb_listora_pro_need_response_created` | 5 | yes | 10/day | Hook found |

#### WP Career Board

Code checked against: 1.8.2.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Post a job | `wcb_job_posted` | `wcb_job_created` | 10 | yes | 60s wait | Hook found |
| [ ] | Get a job approved | `wcb_job_approved` | `wcb_job_approved` | 5 | yes | none | Hook found |
| [ ] | Apply to a job | `wcb_application_submitted` | `wcb_application_submitted` | 8 | yes | 10/day | Hook found |
| [ ] | Get hired | `wcb_candidate_hired` | `wcb_application_status_changed` | 100 | yes | none | Hook found |
| [ ] | Join as a candidate | `wcb_candidate_registered` | `wcb_candidate_registered` | 15 | no | once | Hook found |
| [ ] | Join as an employer | `wcb_employer_registered` | `wcb_employer_registered` | 15 | no | once | Hook found |

#### WP Career Board Pro

Code checked against: 1.8.1.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Publish your resume | `wcbp_resume_published` | `wcbp_resume_published` | 20 | no | once | Hook found |

#### WP Sell Services

Code checked against: 1.6.0 in repo; the file says it was verified on 1.7.2.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Complete a service order | `wpss_order_completed` | `wpss_order_completed` | 20 | yes | none | Hook found |
| [ ] | Review a service | `wpss_review_created` | `wpss_review_created` | 10 | yes | 60s wait | Hook found |
| [ ] | Review approved by a moderator | `wpss_review_approved` | `wpss_review_moderated` | 10 | yes | 60s wait | Hook found |

#### WPMediaVerse and Pro

Code checked against: 2.6.2, Pro 2.6.3.

| Pass | Action | Option ID | Hook | Points | Repeats | Limit | Check |
|---|---|---|---|---|---|---|---|
| [ ] | Upload a photo | `mvs_upload_photo` | `mvs_media_uploaded` | 10 | yes | 5s wait | Hook found |
| [ ] | Add items to an album | `mvs_create_album` | `mvs_album_items_added` | 15 | yes | 10s wait | Hook found |
| [ ] | Receive a like on a photo | `mvs_receive_like` | `mvs_reaction_added` | 2 | yes | none | Hook found |
| [ ] | Receive a comment on a photo | `mvs_receive_comment` | `mvs_comment_created` | 5 | yes | none | Hook found |
| [ ] | Gain a new follower | `mvs_receive_follow` | `mvs_user_followed` | 3 | yes | none | Hook found |
| [ ] | Have a photo bookmarked | `mvs_receive_favorite` | `mvs_favorite_toggled` | 2 | yes | none | Hook found |
| [ ] | Write a meaningful comment | `mvs_give_comment` | `mvs_comment_created` | 3 | yes | 30s wait + 20/day | Hook found |
| [ ] | Follow another member | `mvs_give_follow` | `mvs_user_followed` | 1 | yes | 50/day | Hook found |
| [ ] | Bookmark a photo | `mvs_bookmark_photo` | `mvs_favorite_toggled` | 1 | yes | 30/day | Hook found |
| [ ] | Win a photo battle | `mvs_battle_win` | `mvs_battle_resolved` | 100 | yes | none | Hook found |
| [ ] | Enter a photo challenge | `mvs_challenge_participate` | `mvs_challenge_entry_submitted` | 10 | yes | none | Hook found |
| [ ] | Place in a photo challenge | `mvs_challenge_winner` | `mvs_challenge_winner_named` | 200 | yes | none | Hook found |
| [ ] | Win a tournament round | `mvs_tournament_round_win` | `mvs_tournament_match_resolved` | 150 | yes | none | Hook found |
| [ ] | Win a tournament | `mvs_tournament_win` | `mvs_tournament_finalized` | 500 | yes | none | Hook found |
| [ ] | Hit an upload streak milestone | `mvs_streak_milestone` | `mvs_streak_milestone` | 50 | yes | none | Hook found |
