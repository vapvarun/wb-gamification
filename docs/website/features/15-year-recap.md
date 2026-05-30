# Year Recap

The Year Recap is a shareable end-of-year summary for each member — their top earnings, badges, streaks, and milestones from the past 12 months. Think Spotify Wrapped, but for community engagement.

## What's In a Recap

Each member's recap shows:

- **Total points earned** in the year
- **Top 3 actions** by points contributed
- **Top 5 badges** earned (or all badges, if fewer than 5)
- **Longest streak** (days)
- **Total challenges completed**
- **Total kudos given and received**
- **Rank within the community** (e.g., "Top 5% of members this year")
- **Cohort performance** (if cohort leagues are active)

The recap is generated automatically from the points ledger — no admin action required.

## Member Flow

The Year Recap block on a member's profile or hub page shows their personal recap. A "Share my recap" button generates a public, OG-tagged URL members can share to social media, with an auto-generated image showing their top stats.

The shared URL works without authentication — any visitor can view a member's public recap (controlled by the member's privacy settings).

## When the Recap Generates

Two windows by default:

1. **December 1** — recap for the current year locks in. Members can share through Dec 31 plus the new year.
2. **Anytime** — admins can trigger an out-of-cycle recap via WP-CLI:
   ```
   wp wb-gamification member recap --user=42 --year=2025
   ```

The recap is cached in the `wb_gam_recap_cache` table, so multiple shares of the same recap don't recompute every time.

## Display Surface

The **Year Recap block** can be placed on:

- The member's profile page (BuddyPress integration)
- A dedicated `/recap/` page (WP Page or auto-generated)
- The Hub page during December

The shareable URL is `/recap/{user_login}/{year}`. Members get a QR code in the block that points to their share URL — useful for printed materials or in-person events.

## Privacy

A member can opt out of the shareable URL — their personal recap still shows on their dashboard, but the public URL returns a 404 if they have set `wb_gam_recap_public = 0` in their preferences.

The recap data is:
- ✓ Included in GDPR export
- ✓ Erased on user deletion
- ✓ Excluded from the public URL when opt-out is set

## Configuration

Settings → Year Recap.

| Setting | Default |
|---|---|
| Enabled | On |
| Lock date | December 1 |
| Public share by default | On (members can opt out) |
| Show cohort comparison | Yes (if cohort leagues active) |
| Top-N actions | 3 |
| Top-N badges | 5 |

## Customization

The recap output can be filtered with `wb_gam_year_recap_data` to add custom stats or remove default ones. The recap template can be overridden via the standard theme template hierarchy: `theme/wb-gamification/recap.php`.

## See Also

- **[Privacy](22-privacy.md)** — how member opt-out controls the public URL
- **[Cohort Leagues](11-cohort-leagues.md)** — adds cohort-comparison to recap
- **[Notifications](18-notifications.md)** — December email reminders
