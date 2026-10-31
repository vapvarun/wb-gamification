# GiveWP Integration

The GiveWP integration rewards donor activity: completing a donation, making a first donation, and helping a campaign reach its goal. The manifest loads automatically when GiveWP is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `give_donation_completed` | Complete a donation | 30 |
| `give_first_donation` | Make first donation ever | 75 |
| `give_campaign_goal_reached` | Give the gift that takes a campaign to its goal | 15 |

## Requirements

- GiveWP active

## How it works

This integration is auto-detected. No configuration is needed. Points fire automatically as soon as GiveWP is active and a donation is successfully processed.

Points recognize the act of donating, not the amount. Values are not tied to donation size, which preserves donor privacy and avoids rewarding larger donors disproportionately. `give_first_donation` awards once per member, the first time they ever donate. The GiveWP Recurring Donations add-on has no action of its own. `give_campaign_goal_reached` pays the logged-in donor whose gift takes a campaign to its goal, once per campaign. Guest donors earn nothing, because there is no member to pay. A pending donation earns nothing until it completes.
