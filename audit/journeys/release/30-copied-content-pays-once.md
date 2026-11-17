---
journey: copied-content-pays-once
plugin: wb-gamification
priority: medium
roles: [subscriber]
covers: [buddynext-space-copy, mediaverse-comment-to-activity, activity-comment-to-mediaverse, genuine-activity-comment]
prerequisites:
  - "BuddyNext with a space linked to a Jetonomy forum; MediaVerse with BuddyPress activity sync on"
estimated_runtime_minutes: 10
---

# Content another plugin copies pays once (cards 10462743872, 10462658390)

## Steps

### 1. Forum join copied into a BuddyNext space
- **Action**: join the Jetonomy forum linked to a BuddyNext space.
- **Expect**: one "Join a space" award (jetonomy_space_joined); no bn_space_joined row for the copy.
- **On fail**: `integrations/buddynext.php` bn_space_joined `HostActivity::is_copy()`.

### 2. Photo comment copied onto the photo's activity
- **Action**: comment 20+ characters on a MediaVerse photo in the lightbox.
- **Expect**: mvs_give_comment (and mvs_receive_comment for the owner) pay; no bp_activity_comment row.
- **On fail**: `integrations/buddypress.php` bp_activity_comment `doing_action( 'mvs_comment_created' )`.

### 3. A genuine activity comment
- **Action**: comment on a plain activity update.
- **Expect**: bp_activity_comment pays once. A comment MediaVerse syncs from it (`$source = 'bp_activity'`)
  pays no mvs_* award.
