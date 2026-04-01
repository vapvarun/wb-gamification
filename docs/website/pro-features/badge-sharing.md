# Badge Sharing

Badge Sharing gives each earned badge a public URL with proper Open Graph meta tags and optional LinkedIn credential support. Members can share proof of their achievements on social networks or add them to their LinkedIn profile as verified certifications.

## Share URL Format

Every earned badge gets a public page at:

```
/wb-gamification/badge/{user-login}/{badge-slug}/
```

The `BadgeSharePage` engine generates this page dynamically. It outputs:

- Open Graph title, description, and image (the badge artwork)
- Twitter Card meta tags
- A canonical URL

## OpenBadges 3.0 Credentials

When a badge is marked as a **credential** (`is_credential: true` in the badge definition), the share page also outputs a verifiable JSON-LD credential following the OpenBadges 3.0 specification. This makes the badge machine-readable and verifiable by third-party tools.

The credential JSON is available at:

```
GET /wp-json/wb-gamification/v1/credential/{user_id}/{badge_id}
```

## LinkedIn "Add to Profile" Link

Credential badges include a pre-built LinkedIn deep-link on the share page. Clicking it takes the member directly to LinkedIn's "Add Certification" flow with the badge name, issuer, and credential URL pre-filled. No copying and pasting required.

## How to Enable Credential Badges

1. Go to **WB Gamification → Badges**.
2. Edit the badge you want to make shareable as a credential.
3. Check the **Is Credential** option.
4. Save the badge.

Non-credential badges still get OG share pages — they just do not output the JSON-LD block or the LinkedIn link.

## Feature Flag

Enable badge sharing under **WB Gamification → Settings → Pro Features → Badge Share**.

## Requirements

- Pro add-on active
- `badge_share` feature flag enabled
