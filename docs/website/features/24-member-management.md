# Member Management

WB Gamification gives site owners a dedicated place to see and manage every member's progress, plus a bulk grant for rewarding a whole role or the entire community at once. Added in 1.5.3.

## Members Roster

Go to **WB Gamification > Members** in your admin sidebar.

The Members page is a searchable, paginated table of every member, showing each one's points, level, and badges. It is admin-only (`manage_options`) and is built to stay fast on large communities - the per-page points and badge data are primed in a fixed number of queries, so the row loop never runs N+1.

### Searching and paging

Type a name, username, or email into the search box. The roster searches across username, display name, email, and nicename, and pages through the full member list. Each fetch returns the page rows plus the total count and page count.

### Per-member actions

Each row has three actions:

- **Award** - jumps to the [Award Points](../settings/07-manual-awards.md) page to grant (or deduct) points for that member.
- **Exclude / Include** - toggles whether the member can earn. Excluding sets the per-user `wb_gam_sandboxed` veto: the member keeps their points but stops earning and drops off leaderboards. Including reverses it. This is the per-member counterpart to the role and account lists in [Member Access](../settings/10-member-access.md).
- **Reset points** - zeroes the member's balance. The reset is recorded as a balancing debit, so the points ledger keeps a full audit trail rather than silently dropping rows.

### REST endpoints

All admin-only (`manage_options`), under `wb-gamification/v1`:

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/members` | Paginated, searchable roster (`page`, `per_page`, `search`) |
| `POST` | `/members/{id}/exclude` | Toggle the per-user earning veto (`excluded`) |
| `POST` | `/members/{id}/reset-points` | Zero the member's balance via a balancing debit |

## Bulk Award

Go to **WB Gamification > Award Points** and find the **Bulk Award** card.

Bulk Award grants the same number of points to every member of a chosen role, or to all members at once. Use it for community-wide rewards - a launch bonus, a milestone celebration, a thank-you to everyone in a role.

- **Award to** - pick `all members`, or a specific role.
- **Points each** - the amount to grant to every targeted member. Bulk awards are **positive only**.

Accounts excluded in [Member Access](../settings/10-member-access.md) are **skipped automatically**, even in a bulk grant - the bulk award routes through the same exclusion-aware batch path as every other award. For very large communities, prefer WP-CLI.

### REST endpoint

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/points/bulk \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "target": "all", "points": 50 }'
```

| Field | Type | Description |
|---|---|---|
| `target` | string | `all` for every member, or a role slug |
| `points` | integer | Points to grant each member (positive, 1 to 100000) |

Admin-only (`manage_options`). The response reports how many members were actually awarded after exclusions are applied.

## See Also

- **[Member Access](../settings/10-member-access.md)** - exclude roles or accounts from earning.
- **[Award Points](../settings/07-manual-awards.md)** - manual single-member awards.
- **[Point Expiry](25-point-expiry.md)** - opt-in inactivity decay.
- **[Admin Tools](../settings/12-tools.md)** - rebuild leaderboard, reset member progress.
