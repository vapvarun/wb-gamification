# WB Gamification: first-hour walk

> The site owner's, content author's and member's first hour on a FRESH install.
> QA walks this independently at every cycle start and before every release gate,
> single plugin (no Pro), at 1440 and 390, on the theme matrix in `docs/qa/qa-config.json`
> for front-end steps. Standard: `~/.claude/skills/wp-card-qa/references/first-hour-walk.md`.
> Each step passes only when the browser check AND the code-flow or DB check pass.
> Every escaped defect adds a step or a check line here, tagged `card:<id>`.
> Where the fresh install needs a host community, walk it twice: plain WordPress, then
> WordPress + BuddyPress (the two most common first installs).

Last confirmed: not yet (written 2026-11-18 for 1.6.8; first walk pending)

## Site owner (minutes 0-60)

| # | Step | Where (route / screen) | Must be true | Emails |
|---|---|---|---|---|
| A1 | Activate | Plugins | No notice or redirect loop; the first admin page load (not AJAX, cron, REST or WP-CLI) opens the setup wizard; the Gamification menu is open on it | none |
| A2 | Setup wizard, each starter template: Blog / Publisher, Community Engagement, BuddyPress Community, Online Course, Coaching Platform, Nonprofit / Mission | `admin.php?page=wb-gamification-setup` | A template whose host plugin is missing says which plugin to activate and cannot be applied; an applied template's point values are the ones the Points screen then shows; the Hub page exists and opens | none |
| A3 | First dashboard, empty | `admin.php?page=wb-gamification` | Checklist shows what is left; Top members and Top actions show calm empty states, no zero-filled charts or warnings | none |
| A4 | First settings: points per action, per-action enable, Realtime toast position, Modules on/off, Engagement (BuddyPress stream toggles only when BuddyPress is active) | Settings tabs `points`, `realtime`, `modules`, `engagement` | Each save survives a reload; a disabled module's blocks render nothing and its admin page is hidden; nothing is deleted | none |
| A5 | First badge and first challenge created | Badges, Challenges | Saved with the values entered; listed; visible to members on the Hub | none |
| A6 | Visitor view | Hub page logged out, a page with the Leaderboard block | Hub shows the guest join card with a login link; leaderboard renders public data only | none |
| A7 | First member arrives and earns | Hub as the member; Members screen as owner | The member's first observed action (comment on someone else's post) pays the configured points; the owner sees them in Members and on the dashboard | transactional award email only if enabled and the member's choice allows it |
| A8 | Owner acts on the member: manual award, then reverse it | Award Points; Members | Award confirmed after save; balance and history agree on every surface | per settings |
| A9 | Deactivate and reactivate | Plugins | Ledger, badges and settings intact; `/u/<login>/` still 200 with no permalink flush; no duplicate scheduled jobs | none |

## Content author (minutes 0-60)

| # | Step | Where | Must be true | Emails |
|---|---|---|---|---|
| C1 | Publishes a post | Posts | Pays once; trash and restore does not pay again | none |
| C2 | Receives a comment from another member | the post | Author gets the receive award once the comment is approved, not while held | none |
| C3 | Comments on their own post | the post | Earns nothing | none |
| C4 | Post deleted | Posts | The points it earned are taken back; ledger nets to zero | none |
| C5 | Sees only their own history | Hub, `/u/<login>/`, REST `/members/{id}/points` | Another member's private history is refused (UI and REST replay) | none |

## Member (minutes 0-60)

| # | Step | Where | Must be true | Emails |
|---|---|---|---|---|
| M1 | Arrives logged out | Hub, leaderboard, a member's `/u/` profile | Public profile 200; a private or unknown profile answers the same 403 page | none |
| M2 | Registers via the real form and logs in | site registration | No PHP notice; Hub opens for the new member | WordPress welcome only |
| M3 | First earning action | comment on another member's post | Toast names what the points were for, in the configured corner (BuddyNext pages: BuddyNext's stack) | none |
| M4 | First badge unlocked | Hub, badge board | Badge shows on the board; sharing makes its link work, before sharing it does not | badge email if enabled |
| M5 | Every member surface, empty and after activity: Hub panels, points history, streak, kudos, Year Recap | Hub and blocks | Empty states read as "nothing yet", never broken; Year Recap names a year with no points | none |
| M6 | Sets their choices: show rank, leaderboard opt-out, reward emails | own profile (`/u/<login>/`, BuddyPress Achievements tab) | Saves and reloads; another member cannot see or change them (UI and REST `POST /members/{id}/preferences` 403) | none |
| M7 | Gives kudos | Give Kudos block | Recorded; a repeat to the same member earns nothing; the daily ceiling answers clearly | none |

## Break it (every step above that sends a request)

Run each request under: connection dropped, a 502 HTML page, a slow answer, a
double submit, and a real server refusal. The bar: a calm message, never browser
or parser words, and nothing half-done.

| Step | Request (route) | Dropped | 502 page | Slow | Double | Refusal |
|---|---|---|---|---|---|---|
| A4 | Settings save (admin-post) | | | | | |
| A8 | `POST /wb-gamification/v1/points/award` | | | | | |
| M6 | `POST /wb-gamification/v1/members/{id}/preferences` | | | | | |
| M7 | `POST /wb-gamification/v1/kudos` | | | | | |
| A5 | `POST /wb-gamification/v1/webhooks` (owner adds a webhook) | | | | | private URL answers 400 |

## Decided (not bugs)

| Behaviour | Decision | Who / when |
|---|---|---|
| Hub shows logged-out visitors only a join card | Personal dashboard; other public blocks on the page are page content | runbook C.anon.hub-renders |
| Daily caps, weekly caps and cooldowns skip silently | Better than nagging active members; skips visible in REST and the event log | readme FAQ |
| A member cannot fire `POST /events` for themselves | Points come only from observed activity since 1.6.8 | f8a7dba |

## Escapes (every miss adds a line)

| Card | Step | Check that would have caught it |
|---|---|---|
| 10469870080 | A4 (admin in German), block editor | `static:i18n-json-keyed-to-scripts` |
| 10519574152 | M7 | `states:give-kudos-design-settings-apply` |
| 10519648017 | M5 | `states:year-recap-names-empty-year` |
| 10519647881 | M5 | `presentation:daily-bonus-default` contrast rule |
