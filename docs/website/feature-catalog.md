# Feature Catalog

The complete inventory of WB Gamification features. Every row is shipped in the single plugin — there is no add-on, no upsell, no paid extension. The "tier" column reflects which engine layer owns the feature.

---

## Engines

| Feature | Tier | Where it's configured | Where it's documented |
|---|---|---|---|
| Points engine (event-sourced) | Core | Settings → Points | [Points](features/01-points.md) |
| Multi-currency points | Core | Settings → Point Types | [Points](features/01-points.md) |
| Currency conversions | Core | Settings → Conversions | — |
| Materialised user-totals | Core | (automatic — performance layer) | — |
| Async award pipeline | Core | (automatic — Action Scheduler) | — |
| Levels (5-tier progression) | Core | Settings → Levels | [Levels](features/05-levels.md) |
| Badge engine | Core | Badges admin | [Badges](features/03-badges.md) |
| Challenge engine | Core | Challenges admin | [Challenges](features/06-challenges.md) |
| Streak engine | Core | Settings → Streaks | [Streaks](features/08-streaks.md) |
| Kudos engine | Core | Settings → Kudos | [Kudos](features/09-kudos.md) |
| Login bonus engine | Core | Settings → Daily Login | — |
| Tenure / anniversary badges | Core | (automatic — fires on registration anniversary) | — |
| Site-first-action badges | Core | (automatic — first user to perform action wins) | — |
| Submission queue | Core | Submissions admin | — |
| Year-recap engine | Core | (automatic, generates per-member summary on Dec 1) | — |

## Frontend surfaces

| Feature | Tier | Where it lives | Where it's documented |
|---|---|---|---|
| Hub page | Core | Auto-created at `/gamification` | [Quick Start](getting-started/02-quick-start.md) |
| Public profile pages | Core | `/u/{user_login}` | [Privacy](features/22-privacy.md) |
| Badge share URL (OG-ready) | Core | `/?wb-gam-share=<badge_slug>` | [Badge Sharing](features/04-badge-sharing.md) |
| OpenBadges 3.0 credentials | Core | `/credentials/{badge}/{user}` | [OpenBadges](features/04-badge-sharing.md) |
| Toast notifications | Core | (automatic — Interactivity API) | [Notifications](features/18-notifications.md) |

## Gutenberg blocks (17)

Every block has a matching shortcode and is fully responsive at 390px viewport with dark-mode support.

| Block | Shortcode | Documented |
|---|---|---|
| Leaderboard | `wb_gam_leaderboard` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Hub | `wb_gam_hub` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Member Points | `wb_gam_member_points` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Points History | `wb_gam_points_history` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Badge Showcase | `wb_gam_badge_showcase` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Earning Guide | `wb_gam_earning_guide` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Daily Bonus | (block-only) | — |
| Streak | `wb_gam_streak` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Challenges | `wb_gam_challenges` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Community Challenges | `wb_gam_community_challenges` | [Community Challenges](features/07-community-challenges.md) |
| Cohort Rank | `wb_gam_cohort_rank` | [Cohort Leagues](features/11-cohort-leagues.md) |
| Top Members | `wb_gam_top_members` | [Blocks & Shortcodes](blocks/01-blocks-overview.md) |
| Kudos Feed | `wb_gam_kudos_feed` | [Kudos](features/09-kudos.md) |
| Submit Achievement | (block-only) | — |
| Level Progress | `wb_gam_level_progress` | [Levels](features/05-levels.md) |
| Redemption Store | `wb_gam_redemption_store` | [Redemption Store](features/12-redemption-store.md) |
| Year Recap | `wb_gam_year_recap` | — |

## Admin pages (13)

| Admin page | Purpose | URL slug |
|---|---|---|
| Dashboard | KPI overview + Settings root | `wb-gamification` |
| Analytics | Daily/30/90-day metrics | `wb-gamification-analytics` |
| Badges | Badge library + visual editor | `wb-gamification-badges` |
| Challenges | Individual-challenge CRUD | `wb-gam-challenges` |
| Community Challenges | Aggregate-group challenges | `wb-gam-community-challenges` |
| Cohort Leagues | Cohort group settings | `wb-gam-cohort` |
| Award Points | Manual point grants | `wb-gamification-award` |
| API Keys | Mint, revoke, rotate keys | `wb-gam-api-keys` |
| Redemption Store | Reward catalog | `wb-gam-redemption` |
| Webhooks | Outbound webhook config | `wb-gam-webhooks` |
| Submissions | UGC moderation queue | `wb-gam-submissions` |
| Point Types | Currency definitions | `wb-gam-point-types` |
| Conversions | Currency-conversion rules | `wb-gam-conversions` |

## REST API

| Surface | Count | Reference |
|---|---|---|
| Endpoints | 65 | [REST API Reference](developer-guide/15-rest-overview.md) |
| Controllers | 24 | — |
| Public read endpoints | 10 | (see allowlist below) |
| Auth-required endpoints | 7 | — |
| Admin-required endpoints | 6 | — |
| Member-private endpoints | (rest) | — |
| OpenAPI 3.0 spec | `/openapi.json` | — |

**Public read allowlist** (no auth required): `abilities`, `actions`, `badges`, `capabilities`, `challenges`, `leaderboard`, `levels`, `openapi.json`, `redemptions/items`, `kudos`.

**Authentication**: cookie + nonce for browser admin; `X-WB-Gam-Key: <key>` for cross-site / mobile / headless / AI-agent clients.

## Integrations (10 host plugins)

Auto-detected on activation. Each integration ships a manifest declaring its actions; admin sees them in Settings → Points only when the host plugin is active.

| Host plugin | Tracked actions | Documented |
|---|---|---|
| WordPress core | publish post, leave comment, post receives comment, first post | [WordPress core](integrations/11-wordpress.md) |
| BuddyPress | activity update / comment, reactions, friends accepted, groups join, kudos | [BuddyPress](integrations/02-buddypress.md) |
| bbPress | topic create, reply | [bbPress](integrations/05-bbpress.md) |
| WooCommerce | order completed, refund debit | [WooCommerce](integrations/03-woocommerce.md) |
| LearnDash | lesson complete, course complete, quiz pass | [LearnDash](integrations/04-learndash.md) |
| LifterLMS | course complete, achievement earned | [LifterLMS](integrations/07-lifterlms.md) |
| MemberPress | level join, level renew | [MemberPress](integrations/08-memberpress.md) |
| GiveWP | donation made | [GiveWP](integrations/09-givewp.md) |
| The Events Calendar | RSVP, attendance | [The Events Calendar](integrations/10-the-events-calendar.md) |
| Elementor | block widget rendering | (widget integration only) |
| ACF | rule-editor field-context support | (rule-editor integration only) |

## WP-CLI commands

```
wp wb-gamification points <subcommand>     # award, list, debit per user/type
wp wb-gamification member <subcommand>     # status, history, recap
wp wb-gamification actions <subcommand>    # list, register, point-value
wp wb-gamification logs <subcommand>       # list, prune, export
wp wb-gamification export                   # GDPR-friendly user export
wp wb-gamification doctor                   # health check (schema, cron, cache)
wp wb-gamification replay                   # idempotent re-evaluation of historical events
wp wb-gamification email-test               # send a test transactional email
wp wb-gamification qa <seed-pages|remove>   # QA test pages per block
wp wb-gamification scale <seed|bench>       # scale benchmark with synthetic data
```

## Database tables (23)

Performance + auditability layer.

| Table | Purpose |
|---|---|
| `wb_gam_events` | Append-only event log (UUID PK, source of truth) |
| `wb_gam_points` | Points ledger (linked to events via `event_id` FK) |
| `wb_gam_user_totals` | Materialised aggregate (PK lookup, no SUM aggregation) |
| `wb_gam_user_badges` | Earned badges with `expires_at` for time-bounded credentials |
| `wb_gam_badge_defs` | Badge definitions (name, description, image, criteria) |
| `wb_gam_rules` | Rule conditions (point milestones, action counts, custom) |
| `wb_gam_levels` | Level definitions (name, threshold, icon) |
| `wb_gam_challenges` | Individual challenge definitions |
| `wb_gam_community_challenges` | Group / community challenges |
| `wb_gam_community_challenge_contributions` | Per-member contribution log |
| `wb_gam_challenge_log` | Per-member challenge state |
| `wb_gam_kudos` | Peer kudos given / received |
| `wb_gam_streaks` | Per-user streak state |
| `wb_gam_member_prefs` | Notification + privacy toggles |
| `wb_gam_leaderboard_cache` | Snapshot cache (recomputed by cron) |
| `wb_gam_cohort_members` | Cohort group memberships |
| `wb_gam_webhooks` | Registered outbound webhook endpoints |
| `wb_gam_redemption_items` | Reward catalog |
| `wb_gam_redemptions` | Redemption history |
| `wb_gam_submissions` | UGC moderation queue |
| `wb_gam_point_types` | Currency definitions |
| `wb_gam_point_type_conversions` | Currency-conversion rules |
| `wb_gam_api_keys` | API-key authentication store |

All tables are `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. Schema migrations are version-gated and idempotent.

## Hosting requirements

For 10K+ active users:

- **Persistent object cache** — Redis or Memcached (drop-in detected automatically by WP).
- **Action Scheduler** — bundled with the plugin; tune workers for the install's throughput.
- **MySQL 8.0+ or MariaDB 10.5+** — leaderboard snapshot uses `RANK() OVER (...)` window functions. MySQL 5.7 / MariaDB <10.2 are not supported.

## Quality bar

- ✓ PHPStan level 5 (zero baseline entries)
- ✓ WordPress Coding Standards (zero ignores outside legacy `phpstan-baseline.neon`)
- ✓ 11 plugin-specific coding rules enforced via `bin/coding-rules-check.sh`
- ✓ 108 unit / integration tests (236 assertions)
- ✓ 15 deterministic journey files (customer / admin / qa / security / release)
- ✓ Pre-release agent smoke walk via the generic Claude-level `wp-plugin-smoke` skill
- ✓ Build-release gate refuses to package without a green smoke report

---

For the rolling release history, see [CHANGELOG.md](../../CHANGELOG.md).
