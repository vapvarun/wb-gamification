# Badge Management

Badges are awarded automatically when members meet defined criteria. Go to **Gamification → Badges** to view and manage them.

## Badge List

The admin page shows all registered badges with:

- Badge name and icon
- Award criteria summary
- Number of members who have earned it
- Enabled/disabled toggle

## Creating a Badge

Click **Add New Badge** and fill in:

| Field | Description |
|---|---|
| Name | Display name (e.g. "Top Contributor") |
| Description | Short explanation of how to earn it |
| Icon | Upload an image or choose from the default set |
| Trigger | The action or condition that awards the badge |
| Criteria | Threshold required (e.g. 10 comments published, 500 points reached) |
| Category | Groups badges in the Badge Showcase block (social, content, achievement, etc.) |
| Validity (days) | Optional — set an expiry period for credential-style badges |

## Automatic Awarding

The badge engine runs checks automatically:

- **Action-based badges** — checked when the relevant action fires (real-time)
- **Points milestone badges** — checked when a point award pushes the member over the threshold
- **Tenure badges** — checked daily at midnight UTC
- **Credential expiry** — weekly cron revokes expired credentials and notifies the member

## Badge Credentials and LinkedIn Sharing

Badges with a validity period generate a public shareable URL:

```
/gamification/badge/{badge_id}/{user_id}/share/
```

This page includes:

- Open Graph meta tags (title, description, image) so link previews work
- An **Add to LinkedIn** button that pre-fills LinkedIn's certification form with the badge name, issuer, and credential URL

The credential endpoint at `/wp-json/wb-gamification/v1/credentials/{badge_id}/{user_id}` returns an OpenBadges 3.0 JSON-LD document. It returns HTTP 410 Gone after the badge expires.

## Revoking a Badge

Go to **Gamification → Manual Award**, find the member, select the badge, and click **Revoke**. The badge is removed from their profile immediately and a notification is sent.

## Default Badge Library

The plugin ships 30+ pre-configured badges across six categories:

| Category | Examples |
|---|---|
| Social | First Kudos Sent, Kudos Champion, Community Connector |
| Content | First Post, Prolific Writer, Comment Contributor |
| Commerce | First Purchase, Top Spender |
| Achievement | Points milestones (100, 500, 1000, 5000, 10000) |
| Tenure | 30-day, 90-day, 1-year, 5-year anniversary badges |
| Site | Site First badges (first member to reach a milestone site-wide) |

All default badges can be renamed, reconfigured, or disabled without affecting the plugin's operation.
