# Badge Sharing

Badge Sharing gives an earned badge a public URL with proper Open Graph meta tags and optional LinkedIn credential support. Members can share proof of their achievements on social networks or add them to their LinkedIn profile as verified certifications.

## Share URL Format

Every badge stays private until the member presses **Share** on it in their badge showcase. A shared badge gets a public page at:

```
/gamification/badge/{badge-id}/{user-id}/share/
```

Opening the page for a badge that is not shared sends the visitor to the member's profile instead. Developers can build the link with `wb_gam_get_badge_share_url( $badge_id, $user_id )`.

The `BadgeSharePage` engine generates this page dynamically. It outputs:

- Open Graph title, description, and image (the badge artwork)
- Twitter Card meta tags
- A canonical URL

## OpenBadges 3.0 Credentials

When a badge is marked as a **credential** (`is_credential: true` in the badge definition), a verifiable JSON-LD credential following the OpenBadges 3.0 specification is published for it, and the share page links to it. This makes the badge machine-readable and verifiable by third-party tools.

The credential JSON is available at:

```
GET /wp-json/wb-gamification/v1/badges/{badge_id}/credential/{user_id}
```

## LinkedIn "Add to Profile" Link

Credential badges include a pre-built LinkedIn deep-link on the share page. Clicking it takes the member directly to LinkedIn's "Add Certification" flow with the badge name, issuer, and credential URL pre-filled. No copying and pasting required.

## How to Enable Credential Badges

1. Go to **WB Gamification > Badges**.
2. Edit the badge you want to make shareable as a credential.
3. Tick **Mark as shareable credential (LinkedIn, OpenBadges)**.
4. Save the badge.

Non-credential badges still get share pages once the member shares them - they just have no credential and no LinkedIn link. Step by step: [Badge Credentials](../usage/180-badge-credentials.md).

## Toggling the feature

Badge Sharing is on by default. To turn it off site-wide, untick **Badge sharing** under **WB Gamification > Modules**.
