# WB Gamification — Privacy Model

**Status:** canonical reference (long-term).
**Last updated:** 2026-05-06.
**Owns this doc:** plugin maintainers. Every privacy/permission decision in code or roadmap MUST cite a rule here. If reality drifts from this model, this doc gets updated FIRST, code follows.

---

## Why this doc exists

The plugin's whole purpose is **recognition**: notice what members do, reward them, and broadcast it. That broadcast is what makes gamification work. Aggressive default privacy turns a recognition system into a journal nobody reads — the loop dies.

But broadcasting is not the same as exposing. Members earn the right to *show off* their level, badges, and total points. Members do **not** consent to a play-by-play of when they were online and what they clicked. The plugin holds that line.

Every privacy question reduces to: **does this surface push the recognition loop forward, or does it leak behavioral patterns?** This doc answers that question for every category of data the plugin stores.

---

## The mental model: who's looking, and why

Three audiences. Each has legitimate needs the plugin must serve:

| Audience | What they want | Plugin's promise |
|---|---|---|
| **The member themselves** | "Show off what I've earned, on my terms. Let me go private with one click if I need to." | Public-facing achievements ON by default. ONE toggle to make the profile private. Behavioral history is mine alone. |
| **Other members & the public** | "Is this person legit? Worth following / engaging with?" | See achievements (level, badges, total, rank) when the member chose to share. Never see when/how the member spends time. |
| **Site owner & admins** | "I need to moderate, support, debug, recognize manually." | See everything. Friction-free. With audit trail for compliance. |

The marketing pitch — *"public achievement profiles at /u/{username} with rich social previews"* — only works if T1 is **on by default**. The trust pitch — *"your activity timeline is yours"* — only works if T2 is **never optional**.

---

## Data classification (the canonical answer)

Every piece of data the plugin stores falls into exactly one tier. New endpoints, blocks, exports, webhooks — all must declare their tier.

### T1 — Public showcase data

**Definition:** What the member earned. Status signals. The recognition payload.

**Examples:**
- `display_name`, `avatar_url` (already WP-public via author archives anyway)
- `level` — name, icon, progress percent, next-level name
- `badges` — list of earned badges with name, description, icon, earned_at
- `points_total` (single number, primary currency)
- `badges_count`, `challenges_completed_count`
- Public leaderboard rank (when not opted out)

**Default visibility:** Public, conditional on **two switches both on** (see below).

**Why this is on by default:** This is what the member earned and chose to display. Hiding it by default would defeat every reason they're engaging with the plugin. Equivalent to a Steam achievement count or a LinkedIn endorsement count.

### T2 — Member-private behavioral data

**Definition:** What the member *did* and *when*. Patterns of behavior. The activity diary.

**Examples:**
- Full **points history** (paginated rows: action_id, object_id, point_type, timestamp)
- Full **event log** with metadata JSON (post IDs, badge IDs, custom context)
- **Streak heatmap** — daily activity grid
- `last_active` timestamp
- `wb_gam_login_streak`, `wb_gam_login_last_award` (login pattern)
- `wb_gam_seen_first_earn_toast` flag
- `points_by_type` breakdown (multi-currency reveal)
- **Preferences object** — `show_rank`, `leaderboard_opt_out`, `notification_mode`, email opt-in flags. **The user's privacy choices are themselves private** — leaking them defeats the choice.

**Default visibility:** Private to **self + admin only**, **always**, no toggle. This is the trust line.

**Why no toggle:** Behavioral history is qualitatively different from achievements. A member who shares "I have 12 badges" is not consenting to share "I logged in at 3am every Tuesday for the last six months." The plugin doesn't ask the member to make this distinction every time — it draws the line for them.

### T3 — Sensitive identifiers (PII)

**Definition:** Identifiers that are sensitive even to the data subject's view in the wrong context.

**Examples:**
- `user_email`
- IP addresses (if recorded in events; currently the plugin does not record these)
- Session tokens, auth cookies
- Raw `wb_gam_member_prefs` row (contains all T2 prefs)
- API keys, webhook secrets

**Default visibility:** Admin only. Self-fetch returns scrubbed view (e.g. `/members/me` does not echo email — WP core's pattern for `/wp/v2/users/me`).

**Why:** Standard PII discipline. Aligns with WP core, GDPR, CCPA.

---

## The two switches

Only T1 visibility is ever toggled. T2 is always private; T3 is always admin-only.

### Site-level: `wb_gam_profile_public_enabled` (option, default `true`)

**Owner:** site administrator.

**Purpose:** kill switch for closed communities (B2B, internal teams, schools, paid coaching). When OFF, no member's T1 data is exposed publicly anywhere — `/u/{slug}` 404s, public REST endpoints 403, leaderboard still works for logged-in members but is gated to logged-in.

**Default:** ON. Most sites are open communities and benefit from the recognition loop.

### Member-level: `wb_gam_profile_public` (user_meta, default `true`)

**Owner:** the member themselves.

**Purpose:** the member's per-account privacy toggle. When OFF, the member's T1 data is hidden from non-self/non-admin even if the site switch is ON.

**Default:** ON. New members benefit from the recognition loop unless they actively opt out.

### The truth table

```
Site switch (admin)   →  ON         ON         OFF        OFF
Member toggle         →  ON         OFF        ON         OFF
                         ────       ────       ────       ────
T1 visible publicly?     YES        NO         NO         NO
T1 visible to self/admin YES        YES        YES        YES
T2 visible to anyone?    self+admin only — always
T3 visible to anyone?    admin only — always
```

**Rule:** T1 is visible publicly **only if both switches are ON**. Either switch off ⇒ T1 hides for non-self/non-admin.

---

## Per-surface policy (the rule that replaces ad-hoc permission checks)

### REST API

| Endpoint | Anonymous | Other logged-in member | Self | Admin |
|---|---|---|---|---|
| `GET /members/{id}` (summary) | T1 if both switches ON; else 403 | Same as anon | T1 + T2 (no T3) | T1 + T2 (T3 via admin tools elsewhere) |
| `GET /members/{id}/level` | T1 if both switches ON; else 403 | Same | Full | Full |
| `GET /members/{id}/badges` | T1 if both switches ON; else 403 | Same | Full | Full |
| `GET /members/{id}/points` (history) | **403** | **403** | Full | Full |
| `GET /members/{id}/events` (event log) | **403** | **403** | Full | Full |
| `GET /members/{id}/streak` | `current_streak`, `longest_streak` only if both switches ON; else 403. **Never `last_active` or heatmap to non-owner.** | Same | Full incl. heatmap | Full |
| `GET /members/me/toasts` | 401 | Self only | Self | Admin sees own |
| `GET /leaderboard` | Public; respects `leaderboard_opt_out` ✓ already | Same | Same | Same |
| `GET /leaderboard/me` | 401 | Self | Self | Self |
| `GET /badge-share/{id}`, `GET /credentials/{id}` | Public **by design** (sharable OG; verifiable OpenBadges credential) | Same | Same | Same |
| `POST /events`, `POST /submissions`, `POST /point-types/{from}/convert`, `POST /kudos`, `POST /redemption/redeem` | 401 | Self | Self | Self |
| Everything admin (`POST /points/award`, settings writes, webhooks CRUD, etc.) | 403 | 403 | 403 | OK |

**The current bug** (filed 2026-05-06 as Basecamp `9863460807`): `MembersController::get_item_permissions_check()` returns `true` for everyone on T2 endpoints. Fix per the table above.

### Public profile page `/u/{user_login}`

Already correct. Renders T1 only when both switches ON. 404 otherwise. OG meta + Schema.org JSON-LD inject only when public.

**Rule going forward:** any new shortcode / block / template that takes a `user_id` argument must consult the same gates. The reusable helper should be `WBGam\Engine\Privacy::can_view_public_profile( int $target_id, ?int $viewer_id = null ): bool`.

### Blocks

Blocks render server-side. They take attributes and resolve a target user (often the current user, sometimes from `?wbgam_user=` query param).

| Block | T1 / T2 boundary |
|---|---|
| `member-points`, `level-progress`, `badge-showcase`, `points-history`, `streak`, `top-members`, `kudos-feed`, `daily-bonus` | Render T1 freely. T2 (history rows, heatmap, streak details) only if `viewer === target` OR admin. Otherwise show only T1 summary or empty state. |
| `leaderboard`, `top-members` | Public. Already respects `leaderboard_opt_out`. |
| `submit-achievement` | Logged-in only (rendered server-side checks). |
| `redemption-store` | Catalog public; redemption history private. |
| `hub` | Renders for the *current* user; never accepts a foreign user_id. |

### Shortcodes

Shortcodes pair 1:1 with blocks; same rules.

### WP-CLI

CLI runs as the site administrator. All commands have full access to all tiers — by definition. This is a feature, not a leak (the admin already has DB access).

### Webhooks

Webhook payloads ship to admin-configured endpoints. Treat the destination as a trusted recipient. T1 + T2 are OK to include. **Never include T3** (no email, no IP, no session tokens) — if an integration needs to email a member, the recipient site can fetch via authenticated REST.

### Emails

Transactional emails go to the member themselves (T1+T2 OK in body). Weekly recap emails (`WeeklyEmailEngine`) — per-member render — same rule.

Email *configuration* (per-event toggles) is admin-managed.

---

## GDPR / CCPA alignment

WordPress provides a privacy framework (`Tools → Export Personal Data`, `Erase Personal Data`). The plugin's `Privacy` class is the integration point.

### Export must include

Both T1 and T2 (the member's own T2 belongs to them under data portability rights). T3 export per WP core conventions (email comes from WP core, not us).

**Currently exports:**
- ✅ Points summary per currency
- ✅ Full points history with currency
- ✅ Earned badges
- ✅ Streak stats (current, longest, last_active)
- ✅ Member preferences (leaderboard_opt_out, show_rank, notification_mode)

**Currently MISSING from export (must add):**
- `wb_gam_login_streak`, `wb_gam_login_streak_max`, `wb_gam_login_last_award` user_meta (login bonus history)
- `wb_gam_seen_first_earn_toast` user_meta
- `wb_gam_profile_public` user_meta (the member's own privacy choice)
- `wb_gam_submissions` table (UGC submissions and their status/notes)
- `wb_gam_dismissed_welcome` user_meta (admin-side preference; still personal)
- Event log full export (`wb_gam_events` rows with metadata) — currently only points history is exported, not the immutable event log

### Erase must remove

All T1, T2, and T3 data tied to the member. After erase, the member is invisible to gamification — leaderboards, profile pages, badge counts.

**Currently erases:**
- ✅ `wb_gam_events`, `wb_gam_points`, `wb_gam_user_totals`, `wb_gam_leaderboard_cache`, `wb_gam_user_badges`, `wb_gam_streaks`, `wb_gam_challenge_log`, `wb_gam_kudos` (both directions), `wb_gam_member_prefs`
- ✅ `wb_gam_pr_best_week` user_meta
- ✅ Wrapped in transaction; busts object cache after commit
- ✅ Fires `wb_gam_user_data_erased` action for downstream consumers

**Currently MISSING from erase (must add):**
- `wb_gam_login_streak`, `wb_gam_login_streak_max`, `wb_gam_login_last_award` user_meta
- `wb_gam_seen_first_earn_toast` user_meta
- `wb_gam_dismissed_welcome` user_meta
- `wb_gam_profile_public` user_meta
- `wb_gam_submissions` rows where `user_id = $erased`
- `wb_gam_submissions` rows where `reviewer_id = $erased` (anonymize: set reviewer_id to 0, retain row for audit) — admin role
- `wb_gam_redemptions` rows for the erased user (verify)

These gaps are **debt from the v1.0 sprint** — every new table or meta key shipped in 2026-05 needs to be wired into Privacy::erase_user_data. **No new T2/T3 surface ships without a Privacy.php update in the same commit.** This is a coding rule going forward.

---

## Cross-cutting principles (apply to every new feature)

1. **Tier declaration is mandatory.** Every new REST endpoint, block, or export must declare which tier its data lives in. Add `@privacy-tier T1|T2|T3` to the docblock. PR review checks this.

2. **No callback-deferred enforcement.** `permission_callback` must return the actual access decision, not `true` with a comment "enforced in callback." If the callback shapes the response based on viewer, that's *response shaping*, not permission. Both are needed: permission gates entry, response shaping limits fields.

3. **Privacy preferences are themselves T2.** Never echo `show_rank`, `leaderboard_opt_out`, `notification_mode`, `wb_gam_profile_public` to a non-owner. The `/preferences` endpoint is owner-or-admin only.

4. **Tier escalation is conscious, not accidental.** If a new feature wants to show T2 data publicly (e.g. "this user's most-earned action this month"), the team must decide: either it's actually T1 (achievement-shaped — OK), or it stays T2. Don't sneak T2 into T1 by aggregating it.

5. **Default-on for T1, default-off for T2/T3 leaks.** New T1 surfaces ship with both switches respected and gated correctly. New T2/T3 leaks ship with `403`/`null`.

6. **Erase + export updated in the same commit.** Any PR that adds a new user-scoped table or user_meta key MUST update `Privacy::export_user_data` and `Privacy::erase_user_data`. CI gate (TODO: add to `bin/coding-rules-check.sh`).

7. **Member's privacy choice is honored everywhere, not just where it was originally added.** When a member flips `wb_gam_profile_public` off, every surface — REST, profile page, blocks, leaderboard scope (where applicable), webhook payloads of public events — must respect it. Test via per-role security journey.

8. **No PII in logs.** `error_log`, `WP_DEBUG_LOG`, journey artifacts, and audit reports must not contain user_email or IP. user_id is fine.

9. **Audit logging for admin reads of T2.** When an admin views another member's points history or event log, log it (admin user_id + target user_id + endpoint + timestamp). GDPR Art. 5(1)(f) accountability.

10. **Public profile discovery is explicit.** When `wb_gam_profile_public_enabled` is OFF site-wide, no member profile pages should appear in WordPress search results, sitemap, or `<link rel="canonical">` from author pages. Robots-noindex on `/u/{slug}` while gated. (Open question — confirm current behavior.)

---

## Member-facing UX implications

The model implies a specific UX. Calling it out so future settings/wizard work doesn't drift:

### What the member sees in their account settings

**Privacy** (one panel)

- ☑ **Show my profile publicly at `/u/{username}`** *(default ON)*
  Explainer: "Other members and visitors can see your level, badges, total points, and earned-badges list. Your activity history (points by date, event log) stays private."

- ☑ **Show me on the public leaderboard** *(default ON)*
  Explainer: "Hide your rank from public leaderboards. You'll still see your own rank when logged in." (this is the existing `leaderboard_opt_out`, inverted for sane phrasing)

That's it. Two member-facing toggles. No "show points publicly" / "show streak publicly" / etc — those are all governed by toggle #1.

### What the admin sees in site settings

- ☑ **Enable public profile pages** *(default ON)*
  Explainer: "Allow members to share their `/u/{username}` page publicly. Disable for closed communities (intranet, paid coaching, etc). When off, members keep their data but no profile is exposed externally."

### What the wizard's "Default notifications & privacy" fieldset shows

Already shipped in 2026-05-06 v1.0 onboarding sprint. Currently has 4 toggles. Aligned with this model:

- Send level-up emails (default OFF, opt-in)
- Send badge-earned emails (default OFF, opt-in)
- Send challenge-completed emails (default OFF, opt-in)
- ☑ **Enable public profile pages** (default ON) ✓ already aligned

---

## Reference implementation patterns

When fixing the T2 leak (Basecamp `9863460807`), follow these patterns. They are the recipe other endpoints will copy.

### Permission helper (single source of truth)

```php
namespace WBGam\Engine;

final class Privacy {

    /** Can $viewer (or anonymous) read $target's T1 data? */
    public static function can_view_public_profile( int $target_id, ?int $viewer_id = null ): bool {
        $viewer_id ??= get_current_user_id();
        if ( $viewer_id && ( $viewer_id === $target_id || user_can( $viewer_id, 'manage_options' ) ) ) {
            return true; // self or admin
        }
        if ( ! get_option( 'wb_gam_profile_public_enabled', true ) ) {
            return false; // site kill-switch
        }
        return (bool) get_user_meta( $target_id, 'wb_gam_profile_public', true );
    }

    /** Can $viewer read $target's T2 data? Self or admin only. */
    public static function can_view_private_history( int $target_id, ?int $viewer_id = null ): bool {
        $viewer_id ??= get_current_user_id();
        if ( ! $viewer_id ) return false;
        return $viewer_id === $target_id || user_can( $viewer_id, 'manage_options' );
    }
}
```

Every REST permission_callback, block render, shortcode, profile page calls one of these two methods. Nothing else.

### REST permission_callback shape

```php
'permission_callback' => function ( WP_REST_Request $req ) {
    return WBGam\Engine\Privacy::can_view_private_history( (int) $req['id'] )
        ?: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view this user\'s history.', 'wb-gamification' ), array( 'status' => 403 ) );
},
```

### Block render guard

```php
$target_id = $attributes['user_id'] ?? get_current_user_id();
if ( ! WBGam\Engine\Privacy::can_view_public_profile( $target_id ) ) {
    return ''; // empty render — block disappears for unauthorized viewers
}
```

---

## What's next

1. **Fix the T2 leak** (Basecamp `9863460807`) — apply this model to `MembersController`. M effort.
2. **Wire missing tables/meta into `Privacy::export_user_data` + `erase_user_data`** — the v1.0 sprint debt listed above. S effort.
3. **Add `audit/journeys/security/02-rest-member-private-allowlist.json`** — per-role assertions on every member endpoint. Regression sentinel.
4. **Update `bin/coding-rules-check.sh`** — fail PR if a new `user_id`-scoped table or user_meta key is shipped without a corresponding Privacy.php update. Long-term hygiene.
5. **Add `WBGam\Engine\Privacy::can_view_public_profile()` + `can_view_private_history()` helpers** — single source of truth used by every surface.
6. **Audit every block render for foreign user_id handling** — current blocks may not all consult the helper.
7. **Robots/sitemap discipline for `/u/{slug}`** — confirm noindex when gated; sitemap only includes public profiles.
8. **Settings UX consolidation** — collapse member privacy controls into the two toggles described above. Currently scattered across `wb_gam_member_prefs`.

---

## Glossary

- **T1 / T2 / T3** — the three data tiers defined above. Use these labels in code comments, PR descriptions, and bug cards.
- **The two switches** — `wb_gam_profile_public_enabled` (site option) and `wb_gam_profile_public` (user_meta).
- **The trust line** — the boundary between T1 (toggleable, default-on) and T2 (always-private, no toggle). Crossing it without thought is the failure mode this doc exists to prevent.
