# Shortcodes Reference

Every WB Gamification block has a matching shortcode, except the three block-only blocks (Daily Login Bonus, Submit Achievement, and User Status Bar). All shortcodes begin with `[wb_gam_` and work anywhere WordPress processes shortcodes: pages, posts, text widgets, and theme templates. The table below lists each shortcode, what it renders, its main attributes, and the equivalent block.

| Shortcode | What it renders | Key attributes | Block equivalent |
|---|---|---|---|
| `[wb_gam_leaderboard]` | Ranked list of members by points for a time period | `period`, `limit`, `scope_type`, `scope_id`, `show_avatars` | Leaderboard |
| `[wb_gam_member_points]` | A member's total points, level, and progress | `user_id`, `show_level`, `show_progress_bar` | Member Points |
| `[wb_gam_badge_showcase]` | Grid of earned (and optionally locked) badges | `user_id`, `show_locked`, `category`, `limit` | Badge Showcase |
| `[wb_gam_level_progress]` | A member's level, icon, and progress to the next level | `user_id`, `show_progress_bar`, `show_next_level`, `show_icon` | Level Progress |
| `[wb_gam_challenges]` | Active challenges with progress and time remaining | `user_id`, `limit`, `show_completed`, `show_progress_bar` | Challenges |
| `[wb_gam_streak]` | A member's current streak and optional heatmap | `user_id`, `show_longest`, `show_heatmap`, `heatmap_days` | Streak |
| `[wb_gam_top_members]` | Compact podium or list of top members | `limit`, `period`, `layout`, `show_badges`, `show_level` | Top Members |
| `[wb_gam_kudos_feed]` | Stream of recent kudos activity | `limit`, `show_messages` | Kudos Feed |
| `[wb_gam_give_kudos]` | Form for members to give kudos to a peer | (none) | Give Kudos |
| `[wb_gam_year_recap]` | Shareable year-in-review recap card | `user_id`, `year`, `show_share`, `show_badges`, `show_kudos`, `accent_color` | Year Recap |
| `[wb_gam_points_history]` | Table of a member's point transactions | `user_id`, `limit`, `show_action_label` | Points History |
| `[wb_gam_earning_guide]` | Grid of all earning actions and their point values | `columns`, `show_category_headers` | Earning Guide |
| `[wb_gam_hub]` | Full member dashboard in one block | (none) | Gamification Hub |
| `[wb_gam_community_challenges]` | Group and community challenges with progress | `limit`, `show_progress_bar` | Community Challenges |
| `[wb_gam_cohort_rank]` | A member's cohort league standing | `user_id`, `limit`, `type` | Cohort Rank |
| `[wb_gam_redemption_store]` | Member-facing rewards catalog with Redeem buttons | `limit`, `columns`, `show_balance`, `type` | Redemption Store |
| `[wb_gam_my_rewards]` | A member's own redemption history and coupon codes | `limit`, `show_status` | (shortcode only) |

For per-block details and design options, see each block's own page and the [blocks and shortcodes overview](01-blocks-overview.md).
