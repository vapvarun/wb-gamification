# Public Profile Pages

WB Gamification ships member profile pages at `/u/{user_login}` — sharable, OG-tagged, member-controllable, search-engine-friendly. Use them for social proof, recruiting, or just to give members something to be proud of.

## What's On a Profile

Each public profile shows:

- **Member name + avatar**
- **Total points** + current level + level progress bar
- **All earned badges** (with hover descriptions)
- **Longest streak**
- **Challenges completed**
- **Top actions** (last 30 days)
- **Optional**: bio text, social links (if member fills them in)

Hidden by default:
- The member's individual point events (history)
- Their kudos feed (givens / receives)
- Personal contact info

## Privacy Controls

Public profiles are **opt-in per member**. Three layers:

1. **Site-wide setting** — admin can disable public profiles entirely (Settings → Privacy)
2. **Member preference** — each member can flip `wb_gam_profile_public` in their user preferences
3. **Per-section toggles** — a member can show their badges but hide their streak, etc.

By default after the setup wizard, public profiles are **enabled site-wide** but **off per-member** until each member opts in. The setup wizard's "Enable public profile pages" checkbox sets the site-wide default.

A profile that is opted out returns a 404 instead of a partial page — search engines and visitors see no information.

## URL Structure

| URL | What it shows |
|---|---|
| `/u/{user_login}` | The member's full profile |
| `/u/{user_login}/badges` | Just their badges (lightweight) |
| `/u/{user_login}/recap/{year}` | Their year recap (separate privacy toggle) |

The URL slug uses `user_login` (the WordPress login name). This is stable — username changes are not allowed in WordPress core, so the URL never breaks.

## SEO + Social

Each profile page renders:

- **OG meta tags** — `og:title`, `og:description`, `og:image` (auto-generated card)
- **Twitter Card meta** — same as OG
- **Schema.org JSON-LD** — `Person` type with name, avatar, badges as `Achievement` items
- **Canonical URL** — points to the profile page so duplicate-content scoring stays clean

This means a member sharing their profile to LinkedIn, Twitter, Facebook gets a rich preview card with their avatar, name, and top achievement.

## Auto-Generated OG Image

The profile generates a dynamic 1200×630 OG image showing the member's avatar, name, point total, and top badge. The image is cached for 24 hours per member and regenerates when their stats change significantly.

## BuddyPress Coexistence

If BuddyPress is active, the profile URL `/members/{user}/` continues to work for the BP profile. The WB Gamification profile at `/u/{user}/` is a separate, complementary surface — admins choose which to feature in their navigation.

## Configuration

Settings → Privacy → Public Profiles.

| Setting | Default |
|---|---|
| Enabled site-wide | On |
| Default per-member opt-in | Off (members must enable explicitly) |
| Show badges | On (when profile is public) |
| Show streak | On |
| Show challenges | On |
| Show top actions | On |
| Show kudos | Off |
| Show bio | On (when member fills it) |
| OG image generator | On |

## See Also

- **[Privacy](privacy.md)** — full GDPR export and erasure behavior
- **[Year Recap](year-recap.md)** — shareable per-year recap that uses the same opt-in flag
- **[Badge Sharing](badge-sharing.md)** — sharable URL per badge with OG image
