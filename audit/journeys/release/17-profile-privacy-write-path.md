---
journey: profile-privacy-write-path
plugin: wb-gamification
priority: high
roles: [member, guest]
covers: [basecamp-9985172423, profile-privacy-write-path, wb_gam_profile_public]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Public profiles enabled site-wide (wb_gam_profile_public_enabled = 1, the default)"
  - "A member with a known user_login (e.g. qa_member)"
estimated_runtime_minutes: 4
---

# Member can make their own profile private — wb_gam_profile_public write path

Locks Basecamp 9985172423. Before 1.5.5 the `wb_gam_profile_public` user-meta was
read by the profile-visibility gates (`Privacy::can_view_public_profile`,
`ProfilePage::is_publicly_visible`) and registered in the GDPR export/erase model,
but nothing ever wrote it — so a member could not make their own profile private.
1.5.5 adds the member-facing surface: an owner-only toggle on `/u/{login}` and a
self-service REST endpoint `POST /members/me/profile-visibility`. If this journey
fails, the member's documented privacy choice is non-functional again.

## Setup

- Site: `$SITE_URL`
- Member: `qa_member` (autologin via `?autologin=qa_member`)
- Reset to known state (public) before running:
  ```sql
  UPDATE wp_usermeta SET meta_value = '1'
   WHERE user_id = (SELECT ID FROM wp_users WHERE user_login = 'qa_member')
     AND meta_key = 'wb_gam_profile_public';
  ```

## Steps

### 1. Owner sees the visibility control on their own profile
- **Action**: `playwright_navigate $SITE_URL/u/qa_member?autologin=qa_member`, snapshot `.wb-gam-profile-privacy`
- **Expect**: a "Profile visibility" section with copy "This profile is visible to anyone with the link." and a "Make profile private" button (`aria-pressed="false"`).
- **On fail**: `ProfilePage::render_owner_visibility_control()` not rendered — check the owner gate (`get_current_user_id() === user->ID`) in `render_profile()`.

### 2. A non-owner does NOT see the control
- **Action**: as a different logged-in member (or admin), navigate to `/u/qa_member` and snapshot.
- **Expect**: no `.wb-gam-profile-privacy` section (only the owner may flip their own choice).
- **On fail**: owner gate too loose in `render_profile()`.

### 3. Toggling to private writes the meta and updates the UI in place
- **Action**: click `.wb-gam-profile-privacy__toggle`; wait for the button text to change.
- **Expect**: button now reads "Make profile public" (`aria-pressed="true"`), copy reads "Only you and site admins can see this profile." No page reload, no console errors.
- **Capture**: confirm via `mysql_query "SELECT meta_value FROM wp_usermeta WHERE user_id = <QA_MEMBER_ID> AND meta_key='wb_gam_profile_public'"` → `0`.
- **On fail**: `POST /members/me/profile-visibility` (MembersController::set_profile_visibility) or `assets/js/profile-visibility.js` — check the X-WP-Nonce header and JSON body `{ public: false }`.

### 4. The private choice is enforced for guests
- **Action**: `curl -s -o /dev/null -w "%{http_code}" $SITE_URL/u/qa_member/` (logged out).
- **Expect**: `404` (profile hidden).
- **On fail**: `Privacy::can_view_public_profile()` not reading the freshly-written meta, or `ProfilePage::maybe_render()` not 404ing on private.

### 5. Toggling back to public restores visibility
- **Action**: as the owner, click the toggle again; then `curl` the profile logged out.
- **Expect**: meta = `1`, button reads "Make profile private", guest curl returns `200`.
- **On fail**: same surfaces as steps 3–4.

### 6. REST self-route is logged-in-gated
- **Action**: `curl -X POST $SITE_URL/wp-json/wb-gamification/v1/members/me/profile-visibility -H "Content-Type: application/json" -d '{"public":false}'` with NO auth cookie/nonce.
- **Expect**: `401` (rest_not_logged_in) — and the toggle never affects another user (the route always acts on `get_current_user_id()`).
- **On fail**: `logged_in_permissions_check()` on the route.

## Pass criteria

ALL of the following hold:
1. Owner sees the control (step 1); non-owner does not (step 2).
2. Toggling private writes meta `0` and updates the UI without reload (step 3).
3. Guests get 404 on a private profile (step 4) and 200 after re-publishing (step 5).
4. The REST route rejects anonymous writes with 401 (step 6).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Control not shown for owner | owner gate / render not wired | `src/Engine/ProfilePage.php` render_profile + render_owner_visibility_control |
| Click does nothing | nonce/header/body mismatch | `assets/js/profile-visibility.js`, `src/API/MembersController.php` set_profile_visibility |
| Meta written but guest still sees profile | read gate not consuming meta | `src/Engine/Privacy.php:98`, `ProfilePage::is_publicly_visible` |
| Anonymous POST succeeds | missing permission gate | `src/API/MembersController.php` logged_in_permissions_check |
