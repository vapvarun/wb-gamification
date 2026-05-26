# WB Gamification — Documentation

The complete gamification engine for WordPress and BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, and kudos — zero config, works out of the box.

> Install. Activate. Pick a starter template. Members start earning points within 60 seconds.

---

## Start here

Pick the path that matches what you're trying to do.

### I just installed the plugin

1. **[Installation & Activation](getting-started/installation.md)** — drop the zip, activate, you're done.
2. **[Setup Wizard & Templates](getting-started/setup-wizard.md)** — pick one of 5 starter templates (Blog, Community, Online Course, Coaching, Nonprofit). Pre-configures point values, leaderboard mode, and email defaults for your use case.
3. **[Quick Start (60-second walkthrough)](getting-started/quick-start.md)** — earn your first points, see your first badge, view the leaderboard.
4. **[How It Works](getting-started/how-it-works.md)** — the engine model: events in → rules evaluate → effects out (points, badges, levels, notifications).

### I want to add gamification to my site

1. **[Blocks & Shortcodes](features/blocks-shortcodes.md)** — 17 Gutenberg blocks (leaderboard, hub, badge showcase, member points, points history, …) plus matching shortcodes for classic editors.
2. **[Points](features/points.md)** — how points are awarded, what triggers them, daily caps and rate limits.
3. **[Levels](features/levels.md)** — tier progression, threshold tuning, level-up notifications.
4. **[Leaderboard](features/leaderboard.md)** — daily / weekly / monthly / all-time, group-scoped, member-rank lookups.
5. **[Badges](features/badges.md)** — 30 pre-built badges + visual editor for custom auto-awarded or manual ones.
6. **[Challenges](features/challenges.md)** — individual goals with bonus points and date ranges.
7. **[Streaks](features/streaks.md)** — daily/weekly streaks with grace period, milestones, bonus rewards.
8. **[Kudos](features/kudos.md)** — peer-to-peer recognition with daily cooldown.
9. **[Analytics](features/analytics.md)** — admin dashboard with KPI cards, top-action breakdown, daily sparkline.
10. **[Notifications](features/notifications.md)** — toast popups, BP notifications, transactional emails.
11. **[Privacy](features/privacy.md)** — public profile opt-in, GDPR export/erasure, per-user toggles.
12. **[Daily Login Bonus](features/daily-login-bonus.md)** — tier-based daily login rewards (10 / 20 / 50 / 100 / 250 across day 1 / 3 / 7 / 14 / 30+).
13. **[Submissions (UGC achievements)](features/submissions.md)** — member-submitted achievements with admin approval queue.
14. **[Year Recap](features/year-recap.md)** — shareable end-of-year summary with social-card OG image.
15. **[Public Profile Pages](features/public-profile-pages.md)** — sharable `/u/{user_login}` profiles with privacy controls.
16. **[Multi-Currency Points](features/multi-currency-points.md)** — multiple distinct point currencies running on one site.

### I want to configure something specific

1. **[Points & Actions](settings/points-actions.md)** — set point values for every tracked action, group by category, enable/disable.
2. **[Levels Config](settings/levels-config.md)** — tier thresholds and labels.
3. **[Badge Management](settings/badge-management.md)** — visual editor, auto-award rules, manual award.
4. **[Challenge Manager](settings/challenge-manager.md)** — create challenges with action, target, bonus, date range.
5. **[Manual Awards](settings/manual-awards.md)** — direct admin point grants with audit trail.
6. **[Kudos Settings](settings/kudos-settings.md)** — daily-give limits, point values, cooldown.
7. **[Rank Automation](settings/rank-automation.md)** — automatic role / capability assignment based on level.
8. **[API Keys](settings/api-keys.md)** — mint, revoke, and rotate keys for cross-site / mobile / headless clients.

### I'm running BuddyPress

1. **[Profile Display](buddypress/profile-display.md)** — gamification tab on member profiles with badges, points, streak.
2. **[Activity Feed](buddypress/activity-feed.md)** — auto-tracked activity events that earn points.
3. **[Members Directory](buddypress/members-directory.md)** — leaderboard-ranked members directory.

### I'm integrating with another plugin

The plugin auto-detects host plugins on activation and enables matching point actions. Each integration page lists the actions it tracks, the configurable point values, and what to expect.

- **[BuddyPress](integrations/buddypress.md)** — activity updates, comments, reactions, friends, groups.
- **[bbPress](integrations/bbpress.md)** — topics, replies, voice in forums.
- **[WooCommerce](integrations/woocommerce.md)** — purchases, refund-debit, customer-loyalty patterns.
- **[LearnDash](integrations/learndash.md)** — lessons, courses, quizzes.
- **[LifterLMS](integrations/lifterlms.md)** — courses, achievements, certificates.
- **[MemberPress](integrations/memberpress.md)** — membership levels, renewals.
- **[GiveWP](integrations/givewp.md)** — donations, fundraisers.
- **[The Events Calendar](integrations/the-events-calendar.md)** — RSVPs, attendance.

### I'm a developer building on top

1. **[Hooks & Filters](developer-guide/hooks-filters.md)** — every action and filter the plugin fires, with parameter types and example listeners.
2. **[REST API Reference](developer-guide/rest-api.md)** — 65 endpoints with auth, envelope, error codes, curl examples.
3. **[Adding Custom Actions](developer-guide/custom-actions.md)** — register a new tracked action via manifest or filter.
4. **[Adding Custom Badges](developer-guide/custom-badges.md)** — programmatic badge registration with `wb_gam_should_award_badge` filter.
5. **[OpenBadges 3.0](developer-guide/openbadges.md)** — credential issuance, verification, public credential URL.
6. **[Outbound Webhooks](developer-guide/webhooks.md)** — register, verify HMAC signatures, replay payloads.

### I'm an advanced operator

- **[Cohort Leagues](features/cohort-leagues.md)** — auto-ranking within signup-week / role / custom groups.
- **[Community Challenges](features/community-challenges.md)** — aggregate-group goals with per-contributor reward distribution.
- **[Redemption Store](features/redemption-store.md)** — point-cost rewards (custom or WooCommerce-backed).
- **[Webhooks](features/webhooks.md)** — outbound webhooks to Zapier / Make / n8n.
- **[Weekly Emails](features/weekly-emails.md)** — personalised digest cadence.
- **[Badge Sharing](features/badge-sharing.md)** — public OG share URL with social-image fallback.
- **[Cosmetics](features/cosmetics.md)** — profile frames + per-user visual customisation.

---

## Reference

- **[Feature Catalog](feature-catalog.md)** — every shipped feature on one page, with status and where to configure it.
- **[REST API](developer-guide/rest-api.md)** — full endpoint reference.
- **[CHANGELOG](../../CHANGELOG.md)** — release history.
- **[readme.txt](../../readme.txt)** — WordPress.org listing.

## Support

- **Plugin home & store**: [wbcomdesigns.com](https://wbcomdesigns.com/)
- **GitHub issues**: report bugs + request features
- **Support inbox**: support@wbcomdesigns.com
