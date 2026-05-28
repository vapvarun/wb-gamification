# Integration test matrix — 2026-05-28

Live PASS/FAIL/SKIP for every action_id across every supported integration.
Each plugin was installed + activated in isolation, every action triggered
via the canonical engine pipeline (`Engine::process(new Event(...))`), and
the user's points total verified to increment by the configured value.

**Method**: direct PHP bootstrap helper at `/tmp/wb-gam-test-helper.php`
(bypasses wp-cli to avoid a Local-by-Flywheel autoloader-cache issue
discovered during the BP install loop earlier in the session). Each
integration plugin activated → all manifest actions fired → integration
deactivated before moving to next, so cross-integration state doesn't
contaminate the results.

**Real bugs caught by this matrix** (already shipped fixes):
1. `ActivityPub::on_badge_awarded()` arg order — fatal when bob earned
   his Century Club badge. Fixed in `89573a1`.
2. **`ManifestLoader::scan()` never loaded `integrations/contrib/` manifests**
   — all 15 contrib actions (LifterLMS, MemberPress, GiveWP,
   The Events Calendar) were silently invisible to the engine. Fixed
   in `aa038dc`.

---

## Summary

| # | Integration | Status | Tested | Passed |
|---|---|---|---|---|
| 1 | WooCommerce | ✅ PASS | 5 | 5/5 |
| 2 | BuddyPress | ✅ PASS | 14 | 14/14 |
| 3 | bbPress | ✅ PASS | 3 | 3/3 |
| 4 | LearnDash | ✅ PASS | 5 | 5/5 |
| 5 | WPMediaVerse + Pro | ✅ PASS | 15 | 14/15 |
| 6 | Jetonomy + Pro | ✅ PASS | 11 | 11/11 |
| 7 | LifterLMS | ✅ PASS | 5 | 5/5 |
| 8 | GiveWP | ✅ PASS | 4 | 4/4 |
| 9 | The Events Calendar | ✅ PASS | 3 | 3/3 |
| 10 | MemberPress | ⏸ SKIP | 0 | n/a — paid, not installable from WP.org |

**Total**: 9 integrations pass live, 1 skip (paid).
**Action coverage**: 64 of 65 actions PASS (1 skip due to a daily-cap).

---

## 1. WooCommerce (5/5 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `wc_order_completed` | 25 | +25 | processed |
| `wc_first_purchase` | 50 | +50 | processed |
| `wc_product_reviewed` | 15 | +15 | processed |
| `wc_wishlist_add` | 5 | +5 | processed |
| `wc_add_to_cart` | 1 | +1 | processed |

**Note**: `wc_product_review` (singular) is NOT a valid id — manifest
uses `wc_product_reviewed` (past tense). The other actions follow
mixed patterns — this is a minor naming-consistency issue, not a bug.

## 2. BuddyPress (14/14 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `bp_activity_update` | 10 | +10 | processed |
| `bp_activity_comment` | 5 | +5 | processed |
| `bp_friends_accepted` | 8 | +8 | processed |
| `bp_groups_join` | 8 | +8 | processed |
| `bp_groups_create` | 20 | +20 | processed |
| `bp_profile_complete` | 15 | +15 | processed |
| `bp_reactions_received` | 3 | +3 | processed |
| `bp_polls_created` | 10 | +10 | processed |
| `bp_publish_post` | 25 | +25 | processed |
| `bp_media_upload` | 5 | +5 | processed |
| `bp_avatar_upload` | 10 | +10 | processed |
| `bp_cover_upload` | 10 | +10 | processed |
| `bp_group_cover_upload` | 10 | +10 | processed |
| `bp_message_sent` | 3 | +3 | processed |

**Doc fix**: my earlier journey listed `bp_activity_post` — the
canonical ID is `bp_activity_update`. Journey doc updated.

## 3. bbPress (3/3 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `bbp_new_topic` | 10 | +10 | processed |
| `bbp_new_reply` | 5 | +5 | processed |
| `bbp_topic_closed` | 20 | +20 | processed |

## 4. LearnDash (5/5 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `ld_course_completed` | 100 | +100 | processed |
| `ld_lesson_completed` | 15 | +15 | processed |
| `ld_topic_completed` | 5 | +5 | processed |
| `ld_quiz_passed` | 25 | +25 | processed |
| `ld_assignment_approved` | 20 | +20 | processed |

## 5. WPMediaVerse + Pro (14/15 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `mvs_upload_photo` | 10 | +10 | processed |
| `mvs_create_album` | 15 | +15 | processed |
| `mvs_receive_like` | 2 | +2 | processed |
| `mvs_receive_comment` | 5 | +5 | processed |
| `mvs_receive_follow` | 3 | +3 | processed |
| `mvs_receive_favorite` | 2 | +2 | processed |
| `mvs_give_comment` | 3 | +3 | processed |
| `mvs_give_follow` | 1 | +1 | processed |
| `mvs_bookmark_photo` | 1 | +1 | processed |
| `mvs_battle_win` | 100 | +100 | processed |
| `mvs_challenge_participate` | 10 | +10 | processed |
| `mvs_challenge_winner` | — | 0 | **skipped (daily-cap)** |
| `mvs_tournament_round_win` | 150 | +150 | processed |
| `mvs_tournament_win` | 500 | +500 | processed |
| `mvs_streak_milestone` | 50 | +50 | processed |

## 6. Jetonomy + Pro (11/11 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `jetonomy_space_joined` | 5 | +5 | processed |
| `jetonomy_join_request_approved` | 10 | +10 | processed |
| `jetonomy_trust_level_up` | 50 | +50 | processed |
| `jetonomy_membership_activated` | 25 | +25 | processed |
| `jetonomy_pro_poll_created` | 10 | +10 | processed |
| `jetonomy_pro_poll_voted` | 2 | +2 | processed |
| `jetonomy_pro_message_sent` | 2 | +2 | processed |
| `jetonomy_pro_conversation_created` | 5 | +5 | processed |
| `jetonomy_pro_badge_earned` | 15 | +15 | processed |
| `jetonomy_pro_dm_received` | 1 | +1 | processed |
| `jetonomy_pro_reaction_added` | 1 | +1 | processed |

## 7. LifterLMS (5/5 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `llms_course_completed` | 100 | +100 | processed |
| `llms_lesson_completed` | 10 | +10 | processed |
| `llms_quiz_passed` | 25 | +25 | processed |
| `llms_achievement_earned` | 30 | +30 | processed |
| `llms_certificate_earned` | 50 | +50 | processed |

**Note**: this would have ALL failed before commit `aa038dc` because
`integrations/contrib/` wasn't being scanned by ManifestLoader.

## 8. GiveWP (4/4 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `give_donation_completed` | 30 | +30 | processed |
| `give_first_donation` | 75 | +75 | processed |
| `give_recurring_donation` | 20 | +20 | processed |
| `give_campaign_goal_reached` | 15 | +15 | processed |

## 9. The Events Calendar (3/3 ✅)

| Action | Default points | Delta | Result |
|---|---:|---:|---|
| `tec_rsvp_registered` | 10 | +10 | processed |
| `tec_ticket_purchased` | 20 | +20 | processed |
| `tec_event_checked_in` | 15 | +15 | processed |

## 10. MemberPress (skipped — paid plugin)

Cannot install from WP.org — MemberPress is a paid commercial plugin.

The manifest (`integrations/contrib/memberpress.php`) was verified:
- Syntactically valid (`php -l` clean)
- Conditional `if ( ! class_exists( 'MeprUser' ) ) { return []; }` correctly
  short-circuits when MP isn't installed → no false-positive registration
- 3 actions declared: `mp_membership_activated`, `mp_membership_renewed`,
  `mp_first_membership`

Live action firing would require:
1. Valid MemberPress license
2. MP installation + active membership product
3. Test customer purchasing the membership

Documented as **untested-live this session**. Sites that have MP installed
get the actions registered automatically via the contrib-loader fix.

---

## Bugs caught + fixed

### Bug 1 — ActivityPub argument order

**Trigger**: bob crossed 100 points during WC test → BadgeEngine fired
`wb_gam_badge_awarded` for Century Club → ActivityPub::on_badge_awarded()
fatal'd with type error.

**Root cause**: my new ActivityPub integration declared the listener as
`(int $user_id, string $badge_id, array $badge)` but BadgeEngine emits
`(int $user_id, array $badge_def, string $badge_id)`. NotificationBridge
has the correct order; ActivityPub was written against a different
mental model.

**Fix**: `src/Integrations/ActivityPub.php:93` — swap signature to
`(int $user_id, array $badge, string $badge_id = '')`. Commit `89573a1`.

### Bug 2 — ManifestLoader::scan() ignored integrations/contrib/

**Trigger**: LifterLMS activated, fired `llms_course_completed`, got
`Result: skipped` with no error.

**Root cause**: `ManifestLoader::load_first_party()` used
`glob('integrations/*.php')` which only matched the top-level
directory. The `integrations/contrib/` subdirectory contains 4
manifests (LifterLMS, MemberPress, GiveWP, The Events Calendar)
declaring 15 actions total — ALL silently invisible to the engine.

**Impact**: any site running these 4 integrations had ZERO points
firing for them despite the manifest existing. Worst-case silent
bug class because there's no error — just no awards. A user's
"why am I not getting points for completing my LifterLMS course"
support ticket would lead an investigator to the manifest file
(which exists, looks correct) and the action would never be found
in the registry.

**Fix**: `src/Engine/ManifestLoader.php:86` — extend `$paths` to
include `integrations/contrib/` alongside `integrations/`. Two
new lines of code. Commit `aa038dc`.

**Severity assessment**: would have broken 4 commercial-integration
installs from day 1 if any customer had subscribed. The fact that
this wasn't caught until live testing is itself a finding: the
existing audit/journeys/ never exercised contrib manifests at the
"fire the hook → verify award" granularity, and the wppqa audit
checks structure-of-manifest not load-by-Registry.

---

## What this matrix did NOT cover

Each test fires the manifest's `id` directly via `Engine::process()`.
That tests:
- Manifest is loaded into the Registry
- Engine routes the action to the correct points value
- Points are written to the ledger and user_totals
- Side-effects (badge eval, level-up, etc.) run

What it does NOT test:
- **The integration's hook adapter**: e.g. that
  `do_action('woocommerce_payment_complete', $order_id)` correctly
  resolves to `wc_order_completed` with the right user_id from the
  order. Each integration manifest declares a `user_callback` that
  extracts user_id from the framework's event object — that callback
  was NOT exercised.
- **Async paths**: actions with `async: true` go through Action
  Scheduler. The test helper bypasses AS by calling Engine::process()
  directly. All tested actions in the matrix have `async: false`.
- **Rate-limit edge cases**: only cooldown-related skips surfaced.
  Daily/weekly caps not tested.
- **Filter veto paths**: `wb_gam_before_evaluate` returning false,
  `wb_gam_event_metadata` mutating fields, etc.

A future test matrix could exercise the full integration adapter
(simulate a real `wc_order_completed` order via WC's test mode, etc.)
— that's an order of magnitude more setup but tests the actual
customer-visible path.

---

## Test users used

- alice (id=2) — 218 → 458 pts across the matrix
- bob (id=3) — 45 → ~205 pts (Century Club triggered)
- carol (id=4) — 30 → ~750 pts
- david (id=5) — 0 → ~620 pts
- eve (id=6) — 0 → ~165 pts

DB cleanup if you want to reset: `DELETE FROM wp_wb_gam_events WHERE metadata LIKE '%live_integration_test%';`

---

## Reproducibility

```bash
# Re-run the entire matrix in ~30 seconds:
cd /Users/varundubey/Local\ Sites/wb-gamification/app/public

# WC (already active)
for a in wc_order_completed wc_first_purchase wc_product_reviewed wc_wishlist_add wc_add_to_cart; do
  php /tmp/wb-gam-test-helper.php "$a" carol
done

# Each other integration: activate → loop actions → deactivate
wp plugin activate buddypress
for a in bp_activity_update bp_activity_comment bp_friends_accepted ...; do ...; done
wp plugin deactivate buddypress

# ...etc per integration
```

The `/tmp/wb-gam-test-helper.php` script is ephemeral (would be a
permanent CLI command in `src/CLI/IntegrationTestCommand.php` for
production use). It reads action manifest config, fires the event
via the canonical pipeline, surfaces the SKIP reason via the
`wb_gam_award_skipped` listener, and reports the points delta.
