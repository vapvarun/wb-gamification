# Setup

Setup opens by itself the first time you visit your admin after activating WB Gamification. It reads the plugins on your site and asks four short questions. It takes about two minutes.

You can open it any time from **Gamification > Tools > Setup** (`admin.php?page=wb-gamification-setup`). Only administrators can run it.

Setup is one form in four steps. Use **Continue** and **Back**, or click a step number at the top. Nothing is saved until you click **Launch gamification** in step 4.

## Step 1: Your Community

Setup lists every plugin on your site that has gamification actions. Each card shows:

- The plugin name. A plugin with a Pro add-on shows one card, marked **+ Pro**.
- How many actions it has, and how many take points back.
- Three example actions with their point amounts.

Switch off any plugin you do not want to reward. Its actions stay off.

**WordPress basics are always included**: joining, logging in, completing a profile, publishing and commenting.

Plugins that WB Gamification supports but that are not installed appear in one **Also works with** line. Install one later and its actions appear under **Earning**.

## Step 2: How Members Earn

Choose a pace. It sets the daily and weekly limits for the actions you kept.

| Pace | What it does |
|---|---|
| **Relaxed** | Half each plugin's own daily and weekly limits. For a quiet community or one just starting. |
| **Balanced** (recommended) | Each plugin's own limits. The default on a first run. |
| **Competitive** | Double the limits. For active, game-minded communities. |
| **Keep my limits** | Shown only when you run Setup again, and selected by default. Leaves the limits you set under **Earning** as they are. |

Below the pace, open any plugin to see its actions. For each action you can:

- Switch it on or off.
- Change its point amount.
- Read its limit in words, for example "5 a day" or "No limit".

Actions that take points away carry a **Takes points** label.

## Step 3: What Members Get

This step shows what you already have. Change any of it later under **Rewards**.

- **Levels**: your site's level names and the points each one needs. **Edit level names and points** opens the Levels screen in a new tab.
- **Badges**: how many badges are in your library. **Open the badge library** opens it in a new tab.
- **Leaderboard**: when Jetonomy is active, choose **Gamification leaderboard** or **Let Jetonomy run it**. Without Jetonomy, the Gamification leaderboard is used.
- **Public profile pages**: show members' points, badges and level to others. Each member can still hide their own. This switch is hidden when your community plugin already controls profile privacy.

## Step 4: Review and Launch

A summary lists the plugins rewarded, the number of actions on and the pace, and your rewards. Each line has a **Change** link back to its step.

Choose your emails:

| Email | First-run default |
|---|---|
| **Level-up email** | Off. Members already see a toast. |
| **Badge email** | Off. Members already see a toast. |
| **Weekly recap** | On. One email a week with their points and rank. |

**Send me a test toast** gives your own account 10 points, so you can see the toast on the front of your site.

A blank amount, or one outside 0 to 9,999, stops **Continue** and **Launch** and shows a message beside the field. Use 0 for no points. Click **Launch gamification** to save. Members start earning from their next action. Nothing is awarded for past activity.

You land on **Overview > Dashboard**, with a **Finish setting up** checklist. See [Admin Dashboard](../usage/150-admin-dashboard.md).

## Skipping Setup

Click **Skip setup, I'll configure it myself** at the top. Nothing is saved except that setup is marked done. Members keep earning with each plugin's own default values. Change them any time under **Earning**.

## Running Setup Again

Open **Gamification > Tools > Setup**. Every step opens with your site's current values, including your emails. The pace defaults to **Keep my limits**.

To close Setup without saving, click **Leave without changes**.
