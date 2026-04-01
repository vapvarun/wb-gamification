# Cohort Leagues

Cohort Leagues add Duolingo-style weekly competitions to your community. Instead of every member competing against everyone else, members compete in small groups of similar ability. This makes competition feel winnable for average members — not just your top performers.

## How It Works

At the start of each week, the `CohortEngine` automatically sorts members into cohorts based on their tier. Members in the same tier compete only against each other for that week.

At the end of the week, the engine evaluates each cohort:

- **Top performers** in a cohort get promoted to a higher tier the following week.
- **Bottom performers** get demoted to a lower tier.
- **Middle performers** stay in place.

Point totals reset each week. Historical points (used for levels and badges) are unaffected — cohort leagues track weekly points separately.

## Tiers

The default tier structure runs from bottom to top. New members start in the lowest tier. Exact tier names and thresholds are configurable in the league settings.

## Why It Drives Engagement

Weekly resets create recurring motivation. Members who missed last week's top spot have a fresh chance every Monday. The promotion/demotion mechanic gives both top and bottom performers a reason to act — one to protect a hard-earned tier, the other to escape demotion.

The cohort model also prevents discouragement. A member with 200 points never appears on the same leaderboard as a member with 20,000 points.

## Setup

1. Go to **WB Gamification → Settings → Pro Features**.
2. Enable the **Cohort Leagues** toggle.
3. Go to **WB Gamification → Leagues** to configure tier names, cohort size, and promotion/demotion thresholds.
4. The engine runs its weekly sort automatically via WordPress cron.

## Requirements

- Pro add-on active
- `cohort_leagues` feature flag enabled
