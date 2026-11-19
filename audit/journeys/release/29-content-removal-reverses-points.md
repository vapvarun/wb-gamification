---
journey: content-removal-reverses-points
plugin: wb-gamification
priority: high
roles: [administrator, author, subscriber]
covers: [reverse-on-trash, reverse-on-delete, restore-on-untrash, trash-then-delete-once, negative-balance, pre-1.6.8-untouched, bulk-delete-cost, upload-daily-cap]
prerequisites:
  - "Site reachable at $SITE_URL, WP-CLI available"
  - "Comment moderation off for the comment steps (Settings > Discussion), or approve by hand"
estimated_runtime_minutes: 15
---

# Removing content takes its points back (1.6.8)

A member who publishes and deletes the same post keeps farming points if this regresses (card
10515592964). Points follow the content: trashed, spammed or deleted takes them back once; restored gives
them back once; badges and action counts stay.

## Setup

- Users `qa_rev_author` (author) and `qa_rev_reader` (subscriber). Delete both afterwards.
- Read a balance with `wp eval 'echo WBGam\Engine\PointsEngine::get_total( <id> );'`.

## Steps

### 1. Publish, trash, delete
- **Action**: as the author publish a post; trash it; delete it permanently.
- **Expect**: +25 on publish, -25 on trash, nothing on delete. Points history shows
  "Publish a blog post (content removed)". Rows: `wp db query "SELECT action_id, points, object_id FROM wp_wb_gam_points WHERE object_id = <post id>"` = one award, one `_removed`.
- **On fail**: `integrations/wordpress.php` `wp_publish_post` `reverse_on`; `PointsEngine::reverse_object_awards()`.

### 2. Restore and publish again
- **Action**: publish a post, trash it, restore it (goes to draft), publish it.
- **Expect**: back to the published balance once; editing the live post adds nothing.
- **On fail**: `PointsEngine::has_object_award()` (net of reversals).

### 3. Comments
- **Action**: as the reader comment on the author's post (approved). Trash it, restore it, mark it spam,
  mark it not spam, then delete the post.
- **Expect**: reader +5 / author +3 on comment; each removal takes both back; each return gives both back
  as `_restored` (no extra "Get a comment approved" award); deleting the post takes the comment's points
  back with it.
- **On fail**: `Registry::comment_object_hooks()`, the `wb_gam_comment_approved` bridge in `integrations/wordpress.php`.

### 4. Short balance
- **Action**: publish a post, redeem or deduct the whole balance, delete the post.
- **Expect**: balance shows a negative number (e.g. -25) everywhere it is printed; level and rank update.
- **On fail**: `PointsEngine::debit()` `$allow_negative`.

### 5. Older content and deleted members
- **Action**: delete a post published before 1.6.8 (its ledger row has `object_id` NULL); delete a member
  with content (no reassign).
- **Expect**: the old post's points stay; no `_removed` rows are written for the deleted member.
- **On fail**: `PointsEngine::skip_reversals_for()`, `MemberData::init()`.

### 6. BuddyPress, bbPress, BuddyNext, MediaVerse
- **Action**: delete an activity update with a comment; trash, restore and delete a forum topic with a
  reply; delete a BuddyNext post and comment; trash, restore and delete a MediaVerse upload.
- **Expect**: each removal takes the award back once; each restore gives it back once.

### 7. Bulk delete stays cheap
- **Action**: create 2,000 posts, then `wp post delete $(wp post list --post_type=post --format=ids) --force`.
- **Expect**: one indexed ledger read per post that earned nothing; EXPLAIN on the read uses
  `idx_action_object`. Measured 2026-11-17 (Docker, MariaDB 11, 200k-row ledger): plugin off 6.01 s,
  no awards 6.17 s (+1 query per post), with awards 7.39 s (+8 queries per post).

### 8. Upload cap
- **Action**: upload 21 photos in MediaVerse in one day as one member.
- **Expect**: the first 20 earn points, the 21st does not; an owner's own Daily cap for "Upload a photo" wins.
