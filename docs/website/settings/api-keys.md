# API Keys

Go to **WB Gamification > API Keys** in your admin menu.

API keys let remote sites connect to this WordPress installation as a centralized gamification hub. A remote site sends requests to this site's REST API using the key as an authentication credential.

## Generating a New Key

Fill in the **Generate New Key** form.

| Field | Required | Description |
|-------|----------|-------------|
| **Label** | Yes | A human-readable name for the key. Use the name of the site or application it belongs to, e.g. "MediaVerse Production." |
| **Site ID** | No | A short machine-readable identifier for the remote site, e.g. `mediaverse-prod`. Used for per-site reporting and filtering in the points log. |

Click **Generate Key**.

### Copy the key immediately

The full API key is shown once in a notice at the top of the page immediately after generation. It will not be shown again. Copy it now and store it securely (a password manager or secrets vault).

After you navigate away from the page, the key table shows only the first 12 characters followed by `...` — enough to identify the key but not enough to use it.

## The Active Keys Table

| Column | Description |
|--------|-------------|
| **Label** | The name you gave the key |
| **Site ID** | The machine identifier you provided |
| **Key (prefix)** | First 12 characters of the key for identification |
| **Created** | When the key was generated |
| **Last Used** | The most recent time the key authenticated a request, or — if unused |
| **Status** | Active or Revoked |
| **Actions** | Revoke and Delete buttons |

## Revoking a key

Click **Revoke** next to an active key. The key remains in the table with a "Revoked" status badge. Requests sent with a revoked key are rejected with a 401 response. The remote site is immediately locked out without deleting the audit record.

Use revoke when you suspect a key has been compromised but want to keep the entry visible for review.

## Deleting a key

Click **Delete** next to any key (active or revoked) and confirm the prompt. The key is permanently removed from the database. Unlike revoking, this removes the record entirely.

Use delete to clean up keys from decommissioned sites.

## How authentication works

Remote sites include the key in the `X-WB-Gam-Key` HTTP request header:

```
X-WB-Gam-Key: your-api-key-here
```

Any request to the WB Gamification REST API (`/wp-json/wb-gamification/v1/`) that includes a valid active key is authenticated as an admin-level consumer. All endpoints are available to authenticated key requests.

## Typical use case: centralized gamification

You operate multiple WordPress sites in your network. One site acts as the gamification hub — it holds all the point rules, badge definitions, and leaderboards. Each other site has WB Gamification installed in remote mode and sends events to the hub via REST API using its own API key. Members accumulate points across all connected sites in one unified profile.

Generate one key per remote site. Label each key clearly so you can identify and revoke individual sites if needed.
