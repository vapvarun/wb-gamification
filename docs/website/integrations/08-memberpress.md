# MemberPress Integration

The MemberPress integration rewards membership activity: activating a membership, renewing it, and joining as a paid member for the first time. The manifest loads automatically when MemberPress is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `mp_membership_activated` | Activate a MemberPress membership | 50 |
| `mp_membership_renewed` | Renew a MemberPress membership | 30 |
| `mp_first_membership` | Join as a paid member for the first time | 100 |

## Requirements

- MemberPress active

## How it works

This integration is auto-detected. No configuration is needed. Points fire automatically as soon as MemberPress is active and a member activates or renews a membership.

`mp_membership_activated` has a one-hour cooldown so a single signup is not rewarded more than once. `mp_first_membership` fires on the same signup event but only awards once per member, the very first time they join as a paid member.
