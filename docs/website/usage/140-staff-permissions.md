# Staff Permissions

Go to **WB Gamification > Settings > Access** and scroll to **Staff permissions**.

Let a moderator, editor or community manager run part of gamification without making them a site administrator. Each permission covers one area. Administrators always have every permission.

## Grant a Permission

1. Find the permission in the **Capability** column.
2. Tick it under the role that should have it, for example **Editor**.
3. Click **Save** at the bottom of the form.

Everyone with that role gets the permission straight away. Untick and save to take it back. Out of the box only **Administrator** has these permissions.

## The Permissions

| Permission | What it allows |
|---|---|
| Award & revoke points | Manually give or remove members' points (Award Points screen). |
| Manage members | Open the member roster, exclude or include members, moderate streaks and kudos, run imports. |
| Manage badges | Create, edit and award badges. |
| Manage levels | Open the Levels page and edit the level ladder. |
| Manage rules | Edit point rules and multipliers. |
| Manage challenges | Create and edit individual and community challenges. |
| Manage redemption store | Edit the reward catalog. |
| Moderate submissions | Approve or reject member-submitted achievements. |
| Manage webhooks | Configure outbound webhooks. |
| Manage emails | Change email settings through the REST API. The Emails screen in Settings stays administrator-only. |
| View analytics | See the analytics dashboard. |

The same permissions apply in the REST API, so a mobile app or integration signed in as that staff member can do exactly what the screens allow, and no more.

## Tips

- Give the smallest set that covers the job. A community manager usually needs **Award & revoke points**, **Manage members** and **Moderate submissions**.
- Settings themselves (this screen) stay administrator-only.

## See Also

- [Member Access](90-member-access.md) decides who can *earn*. Staff permissions decide who can *manage*.
