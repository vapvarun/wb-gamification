# Installation & Setup

WB Gamification is a complete gamification plugin for WordPress and BuddyPress communities. It requires WordPress 6.4+ and PHP 8.1+.

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.4 or later |
| PHP | 8.1 or later |
| BuddyPress | Optional — enables social features |

## Installation

1. Download the plugin ZIP file from your account.
2. Go to **Plugins → Add New → Upload Plugin** in your WordPress admin.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

On activation, the plugin automatically:

- Creates the required database tables
- Loads the default action manifest (30+ pre-configured actions)
- Schedules background maintenance tasks (log pruning, weekly digest emails, cohort league updates)

## First Visit

After activation, WordPress redirects you to the **Gamification** admin menu. The Setup Wizard walks you through:

1. Choosing your operating mode (Standalone, Community, or Full Reign)
2. Reviewing point values for the actions your site supports
3. Confirming your levels structure

If you skip the wizard, sensible defaults are already active. Everything is configurable later from **Gamification → Settings**.

## BuddyPress Integration

BuddyPress integration activates automatically. When BuddyPress is present, WB Gamification hooks into:

- **Member profiles** — rank badge and points display in the profile header
- **Member directory** — rank next to each member's name
- **Activity streams** — point awards and badge unlocks create activity entries
- **Groups** — group-scoped leaderboards via the Leaderboard block's `scope_type` attribute

No extra configuration is needed.

## Compatibility Notes

- **BuddyBoss Platform** — fully compatible; uses the same BuddyPress hooks
- **WooCommerce** — purchase and order actions are pre-configured in the manifest
- **Action Scheduler** — all point processing uses Action Scheduler for async, queue-safe execution
- **WPML / Polylang** — translatable strings live in `/languages` and follow the standard WordPress l10n pattern
