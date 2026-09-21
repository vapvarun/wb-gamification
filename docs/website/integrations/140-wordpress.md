# WordPress Core Integration

The WordPress Core integration rewards everyday site activity such as registering, logging in, publishing posts, and leaving comments. It is built in and works with no other plugin installed.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `wp_user_register` | Register an account on the site | 15 |
| `wp_first_login` | Log in for the very first time | 10 |
| `wp_profile_complete` | Save a WordPress profile that includes a bio | 10 |
| `wp_post_receives_comment` | Post author earns when an approved comment lands on their content | 3 |
| `wp_publish_post` | Publish a new blog post | 25 |
| `wp_first_post` | Publish your first post ever | 20 |
| `wp_leave_comment` | Leave an approved comment | 5 |
| `wp_comment_approved` | Have a pending comment approved from moderation | 5 |

### Notes

- `wp_user_register`, `wp_first_login`, `wp_profile_complete`, and `wp_first_post` are awarded once and do not repeat.
- `wp_publish_post` and `wp_first_post` fire on the transition into the published state, so editing an already-published post does not re-award points.
- `wp_post_receives_comment` rewards the post author and is capped at 10 awards per day to stop comment-spam grinding. `wp_leave_comment` rewards the commenter and has a 60-second cooldown.
- Comment actions skip product reviews so they never overlap with the WooCommerce integration.
- The post and comment actions for individual authors (`wp_publish_post`, `wp_first_post`, `wp_leave_comment`, `wp_comment_approved`) are standalone-only: when BuddyPress is active, BuddyPress covers the same activity and these stay off to prevent double-awarding.

## How it works

These actions fire automatically on core WordPress hooks such as `user_register`, `wp_login`, `transition_post_status`, and `comment_post`. There is nothing to wire up. As soon as the plugin is active, a member registering, logging in, publishing, or commenting earns points.

Point values, daily caps, and cooldowns are configured in Settings. Adjust any default above or disable an action you do not want to reward.

## Requirements

- None. This integration works out of the box with WordPress alone.
