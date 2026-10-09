# Public Profile Pages

WB Gamification ships member profile pages at `/u/{user_login}` - sharable, OG-tagged, member-controllable, search-engine-friendly. Use them for social proof, recruiting, or just to give members something to be proud of.

## What's On a Profile

Each public profile shows:

- **Member name + avatar**
- **A stats line**: total points, badge count, current level (e.g. "1,240 Points · 6 badges · Contributor")
- **Their badge showcase** (the same [Badge Showcase](30-badge-sharing.md) grid you can place elsewhere)
- **Their last 10 points-history events**

That is the whole page - there is no separate streak, challenges, top-actions or kudos section, and no bio or social-links field. If you want more on a member's public page, place additional Gamification blocks on your own theme template; the plugin's own `/u/{user_login}` page renders only the sections above.

## Privacy Controls

As of 1.5.2 public profiles are **on by default (opt-out)**. Three layers:

1. **Site-wide setting** - admin can disable public profiles entirely (Settings → Privacy). This kill switch always wins.
2. **Member preference** - each member can opt out by setting the per-user `wb_gam_profile_public` flag to `0`. An unset/empty value means public.
3. **Per-section toggles** - a member can show their badges but hide their streak, etc.

By default, when public profiles are enabled site-wide, each member's profile is **visible** until they explicitly opt out. (Before 1.5.2 the per-user flag was opt-IN, but no member-facing UI ever wrote it, so every `/u/` profile returned 404 - that is fixed in 1.5.2.) The owner of a profile and administrators (`manage_options`) can always view it regardless of the flag.

The `wb_gam_profile_publicly_visible` filter (`bool $visible, int $user_id`) lets a site override visibility per member - see the [Filters reference](../developer-guide/130-filters-reference.md).

A profile that is opted out (per-user flag `0`) returns a 404 instead of a partial page - search engines and visitors see no information.

## URL Structure

There is one URL: `/u/{user_login}`. It always shows the full page above; there is no `/badges`-only or per-year variant. The slug uses `user_login` (the WordPress login name), which is stable - username changes are not allowed in WordPress core, so the URL never breaks. The `u` prefix is configurable under **WB Gamification > Settings > Habits & Profiles > Slug base**; re-save Permalinks after changing it.

## SEO + Social

Each profile page renders:

- **OG meta tags** - `og:title`, `og:description`, `og:image` (auto-generated card)
- **Twitter Card meta** - same as OG
- **Schema.org JSON-LD** - `Person` type with name, avatar, badges as `Achievement` items
- **Canonical URL** - points to the profile page so duplicate-content scoring stays clean

This means a member sharing their profile to LinkedIn, Twitter, Facebook gets a rich preview card with their avatar, name, and top achievement.

## Auto-Generated OG Image

The profile generates a dynamic 1200×630 OG image showing the member's avatar, name, point total, and top badge. The image is cached for 24 hours per member and regenerates when their stats change significantly.

## BuddyPress Coexistence

If BuddyPress is active, the profile URL `/members/{user}/` continues to work for the BP profile. The WB Gamification profile at `/u/{user}/` is a separate, complementary surface - admins choose which to feature in their navigation.

## Configuration

Settings → Privacy → Public Profiles.

| Setting | Default |
|---|---|
| Enabled site-wide | On |
| Default per-member visibility | On (members opt out explicitly; owner + admins always view) |
| Show badges | On (when profile is public) |
| Show streak | On |
| Show challenges | On |
| Show top actions | On |
| Show kudos | Off |
| Show bio | On (when member fills it) |
| OG image generator | On |

## See Also

- **[Privacy](200-privacy.md)** - full GDPR export and erasure behavior
- **[Year Recap](140-year-recap.md)** - shareable per-year recap that uses the same opt-in flag
- **[Badge Sharing](30-badge-sharing.md)** - sharable URL per badge with OG image
