# GiveWP Integration

The GiveWP integration rewards donor activity: completing a donation, making a first donation, contributing to recurring donations, and helping a campaign reach its goal. The manifest loads automatically when GiveWP is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `give_donation_completed` | Complete a donation | 30 |
| `give_first_donation` | Make first donation ever | 75 |
| `give_recurring_donation` | Make a recurring donation payment | 20 |
| `give_campaign_goal_reached` | Campaign reaches its goal | 15 |

## Requirements

- GiveWP active

## How it works

This integration is auto-detected. No configuration is needed. Points fire automatically as soon as GiveWP is active and a donation is successfully processed.

Points recognize the act of donating, not the amount. Values are not tied to donation size, which preserves donor privacy and avoids rewarding larger donors disproportionately. `give_first_donation` awards once per member, the first time they ever donate. `give_recurring_donation` requires the GiveWP Recurring Donations add-on.
