# WB Gamification — Documentation

The complete gamification engine for WordPress and BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, and kudos — zero config, works out of the box.

> Install. Activate. Pick a starter template. Members start earning points within 60 seconds.

---

## Start here

Pick the path that matches what you're trying to do.

### I just installed the plugin

1. **[Installation & Activation](getting-started/01-installation.md)** — drop the zip, activate, you're done.
2. **[Setup Wizard & Templates](getting-started/03-setup-wizard.md)** — pick one of 5 starter templates (Blog, Community, Online Course, Coaching, Nonprofit). Pre-configures point values, leaderboard mode, and email defaults for your use case.
3. **[Quick Start (60-second walkthrough)](getting-started/02-quick-start.md)** — earn your first points, see your first badge, view the leaderboard.
4. **[How It Works](getting-started/04-how-it-works.md)** — the engine model: events in → rules evaluate → effects out (points, badges, levels, notifications).

### I want to add gamification to my site

1. **[Blocks & Shortcodes](blocks/01-blocks-overview.md)** — 17 Gutenberg blocks (leaderboard, hub, badge showcase, member points, points history, …) plus matching shortcodes for classic editors.
2. **[Points](features/01-points.md)** — how points are awarded, what triggers them, daily caps and rate limits.
3. **[Levels](features/05-levels.md)** — tier progression, threshold tuning, level-up notifications.
4. **[Leaderboard](features/10-leaderboard.md)** — daily / weekly / monthly / all-time, group-scoped, member-rank lookups.
5. **[Badges](features/03-badges.md)** — 30 pre-built badges + visual editor for custom auto-awarded or manual ones.
6. **[Challenges](features/06-challenges.md)** — individual goals with bonus points and date ranges.
7. **[Streaks](features/08-streaks.md)** — daily/weekly streaks with grace period, milestones, bonus rewards.
8. **[Kudos](features/09-kudos.md)** — peer-to-peer recognition with daily cooldown.
9. **[Analytics](features/21-analytics.md)** — admin dashboard with KPI cards, top-action breakdown, daily sparkline.
10. **[Notifications](features/18-notifications.md)** — toast popups, BP notifications, transactional emails.
11. **[Privacy](features/22-privacy.md)** — public profile opt-in, GDPR export/erasure, per-user toggles.
12. **[Daily Login Bonus](features/14-daily-login-bonus.md)** — tier-based daily login rewards (10 / 20 / 50 / 100 / 250 across day 1 / 3 / 7 / 14 / 30+).
13. **[Submissions (UGC achievements)](features/13-submissions.md)** — member-submitted achievements with admin approval queue.
14. **[Year Recap](features/15-year-recap.md)** — shareable end-of-year summary with social-card OG image.
15. **[Public Profile Pages](features/16-public-profile-pages.md)** — sharable `/u/{user_login}` profiles with privacy controls.
16. **[Multi-Currency Points](features/02-multi-currency-points.md)** — multiple distinct point currencies running on one site.

### I want to configure something specific

1. **[Points & Actions](settings/01-points-actions.md)** — set point values for every tracked action, group by category, enable/disable.
2. **[Levels Config](settings/02-levels-config.md)** — tier thresholds and labels.
3. **[Badge Management](settings/03-badge-management.md)** — visual editor, auto-award rules, manual award.
4. **[Challenge Manager](settings/04-challenge-manager.md)** — create challenges with action, target, bonus, date range.
5. **[Manual Awards](settings/07-manual-awards.md)** — direct admin point grants with audit trail.
6. **[Kudos Settings](settings/05-kudos-settings.md)** — daily-give limits, point values, cooldown.
7. **[Rank Automation](settings/06-rank-automation.md)** — automatic role / capability assignment based on level.
8. **[API Keys](settings/08-api-keys.md)** — mint, revoke, and rotate keys for cross-site / mobile / headless clients.

### I'm running BuddyPress

1. **[Profile Display](buddypress/03-profile-display.md)** — gamification tab on member profiles with badges, points, streak.
2. **[Activity Feed](buddypress/01-activity-feed.md)** — auto-tracked activity events that earn points.
3. **[Members Directory](buddypress/members-directory.md)** — leaderboard-ranked members directory.

### I'm integrating with another plugin

The plugin auto-detects host plugins on activation and enables matching point actions. Each integration page lists the actions it tracks, the configurable point values, and what to expect.

- **[BuddyPress](integrations/02-buddypress.md)** — activity updates, comments, reactions, friends, groups.
- **[bbPress](integrations/05-bbpress.md)** — topics, replies, voice in forums.
- **[WooCommerce](integrations/03-woocommerce.md)** — purchases, refund-debit, customer-loyalty patterns.
- **[LearnDash](integrations/04-learndash.md)** — lessons, courses, quizzes.
- **[LifterLMS](integrations/lifterlms.md)** — courses, achievements, certificates.
- **[MemberPress](integrations/memberpress.md)** — membership levels, renewals.
- **[GiveWP](integrations/givewp.md)** — donations, fundraisers.
- **[The Events Calendar](integrations/the-events-calendar.md)** — RSVPs, attendance.

### I'm a developer building on top

1. **[Hooks & Filters](developer-guide/12-hooks-overview.md)** — every action and filter the plugin fires, with parameter types and example listeners.
2. **[REST API Reference](developer-guide/15-rest-overview.md)** — 65 endpoints with auth, envelope, error codes, curl examples.
3. **[Adding Custom Actions](developer-guide/custom-actions.md)** — register a new tracked action via manifest or filter.
4. **[Adding Custom Badges](developer-guide/custom-badges.md)** — programmatic badge registration with `wb_gam_should_award_badge` filter.
5. **[OpenBadges 3.0](developer-guide/openbadges.md)** — credential issuance, verification, public credential URL.
6. **[Outbound Webhooks](developer-guide/20-webhooks-overview.md)** — register, verify HMAC signatures, replay payloads.

### I'm an advanced operator

- **[Cohort Leagues](features/11-cohort-leagues.md)** — auto-ranking within signup-week / role / custom groups.
- **[Community Challenges](features/07-community-challenges.md)** — aggregate-group goals with per-contributor reward distribution.
- **[Redemption Store](features/12-redemption-store.md)** — point-cost rewards (custom or WooCommerce-backed).
- **[Webhooks](features/20-webhooks.md)** — outbound webhooks to Zapier / Make / n8n.
- **[Weekly Emails](features/19-weekly-emails.md)** — personalised digest cadence.
- **[Badge Sharing](features/04-badge-sharing.md)** — public OG share URL with social-image fallback.
- **[Cosmetics](features/17-cosmetics.md)** — profile frames + per-user visual customisation.

---

## Reference

- **[Feature Catalog](feature-catalog.md)** — every shipped feature on one page, with status and where to configure it.
- **[REST API](developer-guide/15-rest-overview.md)** — full endpoint reference.
- **[CHANGELOG](../../CHANGELOG.md)** — release history.
- **[readme.txt](../../readme.txt)** — WordPress.org listing.

## Support

- **Plugin home & store**: [wbcomdesigns.com](https://wbcomdesigns.com/)
- **GitHub issues**: report bugs + request features
- **Support inbox**: support@wbcomdesigns.com
