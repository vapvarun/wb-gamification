# Other Plugin Integrations

WB Gamification includes drop-in manifests for four additional plugins. Each manifest lives in `integrations/contrib/` and loads automatically when its target plugin is active.

---

## LifterLMS

| Action ID | Label | Default Points |
|---|---|---|
| `llms_course_completed` | Complete a LifterLMS course | 100 |
| `llms_lesson_completed` | Complete a LifterLMS lesson | 10 |
| `llms_quiz_passed` | Pass a LifterLMS quiz | 25 |
| `llms_achievement_earned` | Earn a LifterLMS achievement | 30 |
| `llms_certificate_earned` | Earn a LifterLMS certificate | 50 |

`llms_quiz_passed` only awards points when the attempt returns `passed: true`. `llms_certificate_earned` rewards the final milestone in any course that issues certificates.

**Requires:** LifterLMS active (`LLMS_Student` class present)

---

## MemberPress

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `mp_membership_activated` | Activate a membership | 50 | Yes (1hr cooldown) |
| `mp_membership_renewed` | Renew a membership | 30 | Yes |
| `mp_first_membership` | Join as paid member (first time) | 100 | No (once only) |

`mp_first_membership` fires on the same hook as `mp_membership_activated` but checks whether the member has only one subscription. It awards once per lifetime.

**Requires:** MemberPress active (`MeprUser` class present)

---

## GiveWP

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `give_donation_completed` | Complete a donation | 30 | Yes |
| `give_first_donation` | Make first donation ever | 75 | No (once only) |
| `give_recurring_donation` | Make a recurring donation payment | 20 | Yes |
| `give_campaign_goal_reached` | Campaign reaches its goal | 15 | Yes |

GiveWP's design recognizes the **act** of donating, not the amount. Point values are not tied to donation size — this preserves donor privacy and avoids rewarding larger donors disproportionately. `give_recurring_donation` requires the GiveWP Recurring Donations add-on.

**Requires:** GiveWP active (`give()` function present)

---

## The Events Calendar

| Action ID | Label | Default Points |
|---|---|---|
| `tec_rsvp_registered` | RSVP to an event | 10 |
| `tec_ticket_purchased` | Purchase an event ticket | 20 |
| `tec_event_checked_in` | Check in to an event | 15 |

`tec_rsvp_registered` only awards on "going" RSVPs — declining an RSVP earns nothing. `tec_event_checked_in` reads the `_tribe_tickets_attendee_user_id` post meta to identify the member.

**Requires:** The Events Calendar active (`Tribe__Events__Main` class present)
