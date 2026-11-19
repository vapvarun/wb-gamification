# MemberPress Integration

The MemberPress integration rewards membership activity: activating a membership, renewing it, and joining for the first time. The manifest loads automatically when MemberPress is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `mp_membership_activated` | Activate a MemberPress membership | 50 |
| `mp_membership_renewed` | Renew a MemberPress membership | 30 |
| `mp_first_membership` | Join as a member for the first time | 100 |

## Requirements

- MemberPress active

## How it works

This integration is auto-detected. No configuration is needed. Points fire automatically as soon as MemberPress is active and a member activates or renews a membership.

`mp_membership_activated` pays once per membership, so leaving and rejoining the same membership earns nothing. Renewal payments do not count as activating. `mp_membership_renewed` pays on each renewal payment of a recurring membership. `mp_first_membership` pays once per member, the first time they join any membership, free or paid. A pending offline payment earns nothing until it is marked complete, and a member dropped to a group's fallback membership earns nothing.
