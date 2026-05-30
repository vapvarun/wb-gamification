# BuddyPress Integration

The BuddyPress integration is the most comprehensive integration in WB Gamification. It covers the core social loop — posting, commenting, friending, and group activity — as well as BuddyPress add-ons for reactions, polls, member blogs, and media.

The manifest loads automatically when BuddyPress is active. No configuration is required.

## Actions

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `bp_activity_update` | Post an activity update | 10 | Yes (30s cooldown) |
| `bp_activity_comment` | Comment on an activity | 5 | Yes (30s cooldown) |
| `bp_friends_accepted` | Accept a friendship | 8 | Yes |
| `bp_groups_join` | Join a group | 8 | Yes |
| `bp_groups_create` | Create a group | 20 | Yes |
| `bp_profile_complete` | Complete extended profile | 15 | No (once only) |
| `bp_reactions_received` | Receive a reaction | 3 | Yes |
| `bp_polls_created` | Create a poll | 10 | Yes |
| `bp_publish_post` | Publish a member blog post | 25 | Yes |
| `bp_media_upload` | Upload media | 5 | Yes (60s cooldown) |

### Notes

- `bp_friends_accepted` awards the member who **accepts** the request, not the one who initiated it.
- `bp_profile_complete` fires on `xprofile_updated_profile`. It is non-repeatable — a member earns it once.
- `bp_reactions_received` requires the BuddyPress Reactions add-on.
- `bp_polls_created` requires the BuddyPress Polls add-on.
- `bp_publish_post` requires the BP Member Blog add-on and awards on `publish_post` for `post` post type only. Pages and custom post types are excluded.
- `bp_media_upload` requires the BuddyPress Media add-on.

## Profile Display

When BuddyPress is active, WB Gamification adds a gamification panel to member profiles showing points, level, badges, and streak. This is handled by `ProfileIntegration` and `DirectoryIntegration`.

## Activity Feed

Points and badge events post to the BuddyPress activity feed via `BPActivity`. Members see their own achievements and (optionally) achievements from members they follow.

## Member Directory

The member directory gains a sort option for **Most Points** when BuddyPress Directory Integration is active.
