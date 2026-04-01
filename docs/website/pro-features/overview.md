# WB Gamification Pro — Overview

WB Gamification Pro is an add-on for the free WB Gamification plugin. It requires the free plugin to be installed and active. You also need a valid EDD license key, which you activate under **WB Gamification → License**.

## What Pro Adds

The free plugin covers points, badges, levels, leaderboard, individual challenges, streaks, kudos, and all integration manifests. Pro adds nine optional engines on top of that foundation.

| Feature Flag | What It Does |
|---|---|
| `cohort_leagues` | Duolingo-style weekly competitions with promotion/demotion |
| `weekly_emails` | Automated weekly recap emails to members |
| `leaderboard_nudge` | Motivational emails when a member is close to climbing |
| `status_retention` | Prevents level drops for engaged members |
| `cosmetics` | Profile frames and cosmetic items members can equip |
| `community_challenges` | Team/global challenges with shared progress |
| `site_first_badges` | Badges for the first member to complete a specific action |
| `tenure_badges` | Automatic anniversary and milestone badges |
| `badge_share` | Public share pages with OG tags, LinkedIn, OpenBadges 3.0 |

Pro also includes two additional engines with no feature flag — the **Redemption Store** (spend points on rewards) and **Outbound Webhooks** (Zapier/Make/n8n). Both activate as soon as the pro add-on is active.

## How Feature Flags Work

All nine flags default to **off** after installation. You enable each one individually under **WB Gamification → Settings → Pro Features**. This lets you roll out features incrementally without activating everything at once.

The free plugin's `FeatureFlags` class checks for the constant `WB_GAM_PRO_VERSION` at boot. If the pro add-on is not active, no pro engines load — even if flags are saved as enabled in the database.

## Requirements

- WB Gamification (free) 1.0.0 or higher — active
- WordPress 6.0 or higher
- PHP 8.1 or higher
- Valid EDD license key

## Activation Steps

1. Install and activate the free **WB Gamification** plugin first.
2. Upload and activate **wb-gamification-pro**.
3. Go to **WB Gamification → License**.
4. Enter your license key and click **Activate License**.
5. Go to **WB Gamification → Settings → Pro Features**.
6. Toggle on the features you want to use.
