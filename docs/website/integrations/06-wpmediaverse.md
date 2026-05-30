# WPMediaVerse Pro Integration

WPMediaVerse Pro ships its own gamification manifest (`wb-gamification.php` in the plugin root). When both WPMediaVerse Pro and WB Gamification are active, `ManifestLoader` discovers the file automatically and registers all 17 actions. No configuration is needed.

This is a **drop-in manifest** — it lives inside WPMediaVerse Pro, not inside WB Gamification. That means manifest updates ship with WPMediaVerse Pro releases.

## Actions by Category

### Media (content creation and engagement received)

| Action ID | Label | Default Points |
|---|---|---|
| `mvs_upload_photo` | Upload a photo | 10 |
| `mvs_create_album` | Create an album | 15 |
| `mvs_receive_like` | Receive a like on photo | 2 |
| `mvs_receive_comment` | Receive a comment on photo | 5 |
| `mvs_receive_favorite` | Photo bookmarked by someone | 2 |

`mvs_receive_like` and `mvs_receive_favorite` award the **media owner**, not the person who reacted. Self-likes (liking your own content) are excluded — the engine compares the reactor ID against the media author and returns 0 if they match.

### Social (engagement given — awards the actor)

| Action ID | Label | Default Points | Daily Cap |
|---|---|---|---|
| `mvs_receive_follow` | Gain a new follower | 3 | — |
| `mvs_give_comment` | Write a meaningful comment | 3 | 20 |
| `mvs_give_follow` | Follow another member | 1 | 50 |
| `mvs_bookmark_photo` | Bookmark a photo | 1 | 30 |

`mvs_give_comment` requires the comment to be 20 or more characters. Single-word or empty comments earn nothing. Daily caps prevent point farming.

Note: `mvs_receive_follow` awards the member being followed (social category), not the follower — it appears here because it measures social growth.

### Competition

| Action ID | Label | Default Points |
|---|---|---|
| `mvs_battle_win` | Win a photo battle | 100 |
| `mvs_challenge_participate` | Enter a photo challenge | 10 |
| `mvs_challenge_win_1st` | Win 1st place in a challenge | 200 |
| `mvs_challenge_win_2nd` | Win 2nd place in a challenge | 100 |
| `mvs_challenge_win_3rd` | Win 3rd place in a challenge | 50 |
| `mvs_tournament_round_win` | Win a tournament round | 150 |
| `mvs_tournament_win` | Win a tournament | 500 |

Competition actions have no cooldowns or daily caps — they are high-stakes events that fire infrequently by design.

### Engagement (streaks)

| Action ID | Label | Default Points |
|---|---|---|
| `mvs_streak_milestone` | Hit an upload streak milestone | 50 (base) |

`mvs_streak_milestone` fires when a member hits 7, 30, 100, or 365 consecutive upload days. The manifest uses a `points_callback` that reads the `$xp` bonus passed by WPMediaVerse Pro's streak engine — the actual points awarded may exceed the 50-point default depending on streak length.

## Requirements

- WPMediaVerse Pro active
- WB Gamification (free) active
