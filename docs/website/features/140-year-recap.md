# Year Recap

The Year Recap is a personal end-of-year summary for each member: their points, top actions, badges, longest streak, challenges and kudos from a given calendar year. Think Spotify Wrapped, but for community engagement.

## What's In a Recap

Each member's recap shows:

- **Total points earned** in the year
- **Total events** (actions) fired in the year
- **Top 3 actions** by how often they were done
- **Badges earned** in the year (every one, not a top-N)
- **Peak activity week** (the ISO week they earned the most)
- **Challenges completed** in the year
- **Kudos given and received**
- **Percentile among all members** that year
- A **headline** sentence built from the above ("You were in the top 5% of contributors")

The recap is computed from the points ledger on request and cached for an hour; there is no admin action or nightly job that builds it.

## Where It Shows

The **Year Recap block** (`[wb_gam_year_recap]`) renders one member's recap wherever an admin places it: the member's own dashboard, a hub page, a profile template. Placed with no `user_id` attribute, it shows the viewer's own recap; a specific `user_id` shows that member's, subject to the privacy rule below. There is no separate recap page or URL — this is a block, not a route.

By default it shows the **previous calendar year** (`year = 0`); an admin can set a specific `year` on the block, or a caller can request one via the REST endpoint or WP-CLI.

## Privacy

A recap is a piece of achievement-shaped data, so it follows the same public-profile privacy system as the rest of a member's public stats (`Privacy::can_view_public_profile()` — see [Public Profile Pages](150-public-profile-pages.md)): visible to the member themselves and administrators always; visible to anyone else only when the site's public-profiles setting and that member's own visibility are both on. There is no recap-specific opt-out separate from that.

Over REST, viewing your **own** recap always works while logged in; viewing another member's recap needs the `wb_gam_view_analytics` capability (administrators have it by default; a site owner can delegate it — see [Staff permissions](../usage/90-member-access.md)).

## Sharing

The block's own "Share Your Year" button (shown only to the member viewing their own recap) uses the device's native share sheet when available, or copies the current page's URL to the clipboard otherwise. It shares the page the block is on, not a dedicated public recap link — there is no separate shareable URL, QR code, or auto-generated share image.

## Getting a Recap Outside the Block

```bash
wp wb-gamification export user --user=42 > export.json
```

`export user` includes the member's full data, not a recap specifically. To read just the recap, call the REST endpoint directly:

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42/recap?year=2025 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Customization

Two filters let you reshape the data before it renders or is returned:

| Filter | Where | Purpose |
|---|---|---|
| `wb_gam_recap_data` | `RecapEngine::get_recap()` | Add or change fields on the raw recap array, before it is cached. Runs for both the block and REST. |
| `wb_gam_block_year_recap_data` | Year Recap block | Reshape the recap array just for this block's render, after the cached data is fetched. |

See the [Filters reference](../developer-guide/130-filters-reference.md) for signatures.
