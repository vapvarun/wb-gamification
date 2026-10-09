# WPMediaVerse Integration

WB Gamification ships a first-party WPMediaVerse manifest at `integrations/wpmediaverse.php`. When WPMediaVerse is active, `ManifestLoader` discovers the file automatically and registers its actions - no configuration is needed.

The manifest has two layers:

- **Core actions** load whenever WPMediaVerse is active.
- **Competition and streak actions** load only when WPMediaVerse Pro is also active (the manifest checks for the `MVS_PRO_VERSION` constant).

All point values below are defaults you can override from **WB Gamification → Actions → Edit**. The full trigger list can be filtered by developers via `wb_gam_wpmediaverse_triggers`.

## Core Actions (WPMediaVerse active)

| Action ID | Label | Default Points | Daily Cap |
|---|---|---|---|
| `mvs_upload_photo` | Upload a photo | 10 | - |
| `mvs_create_album` | Add items to an album | 15 | - |
| `mvs_receive_like` | Receive a like on photo | 2 | - |
| `mvs_receive_comment` | Receive a comment on photo | 5 | - |
| `mvs_receive_follow` | Gain a new follower | 3 | - |
| `mvs_receive_favorite` | Photo bookmarked by someone | 2 | - |
| `mvs_give_comment` | Write a meaningful comment | 3 | 20 |
| `mvs_give_follow` | Follow another member | 1 | 50 |
| `mvs_bookmark_photo` | Bookmark a photo | 1 | 30 |

`mvs_receive_like` and `mvs_receive_favorite` award the **media owner**, not the person who reacted. Self-reactions (liking or bookmarking your own content) are excluded - the engine compares the reactor ID against the media author and returns 0 if they match.

`mvs_give_comment` requires the comment to be 20 or more characters. Single-word or empty comments earn nothing. The daily caps on the "give" actions prevent point farming.

## Competition and Streaks (WPMediaVerse Pro active)

| Action ID | Label | Default Points |
|---|---|---|
| `mvs_battle_win` | Win a photo battle | 100 |
| `mvs_challenge_participate` | Enter a photo challenge | 10 |
| `mvs_challenge_winner` | Place in a photo challenge | 200 |
| `mvs_tournament_round_win` | Win a tournament round | 150 |
| `mvs_tournament_win` | Win a tournament | 500 |
| `mvs_streak_milestone` | Hit an upload streak milestone | 50 (base) |

`mvs_challenge_winner` awards the top-three finishers of a photo challenge. The default 200 points is the 1st-place value; a points callback resolves 200 / 100 / 50 for 1st / 2nd / 3rd place when WPMediaVerse Pro supplies the rank (200 is the fallback if Pro is inactive).

`mvs_streak_milestone` fires when a member hits 7, 30, 100, or 365 consecutive upload days. The manifest uses a points callback that reads the bonus passed by WPMediaVerse Pro's streak engine, so the points awarded may exceed the 50-point base depending on streak length.

Competition actions have no cooldowns or daily caps - they are high-stakes events that fire infrequently by design.

## Requirements

- WPMediaVerse active (for the core actions).
- WPMediaVerse Pro also active (for the competition and streak actions).
- WB Gamification active.
