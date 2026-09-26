# The Events Calendar Integration

The Events Calendar integration rewards event participation: RSVPing to an event, purchasing a ticket, and checking in on the day. The manifest loads automatically when The Events Calendar is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `tec_rsvp_registered` | RSVP to an event | 10 |
| `tec_ticket_purchased` | Purchase an event ticket | 20 |
| `tec_event_checked_in` | Check in to an event | 15 |

## Requirements

- The Events Calendar active

## How it works

This integration is auto-detected. No configuration is needed. Points fire automatically as soon as The Events Calendar is active and a member RSVPs, buys a ticket, or checks in.

`tec_rsvp_registered` only awards on a "going" RSVP. Declining an RSVP earns nothing. `tec_event_checked_in` awards the attendee when they are checked in at the event.
