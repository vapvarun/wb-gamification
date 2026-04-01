# How WB Gamification Works

Understanding how the plugin processes activity helps you configure it well and troubleshoot when something unexpected happens.

## The Core Idea: Events In, Rules Evaluate, Effects Out

Every time a member does something on your site — publishes a post, comments, completes a course, joins a group — WB Gamification sees it as an **event**. The engine takes that event, checks its rules, and produces **effects** like points, badges, and notifications.

The source of the event does not matter to the engine. A WooCommerce purchase, a LearnDash lesson completion, and a BuddyPress activity post all arrive as the same type of object. This is why one plugin can handle so many different site types without add-ons.

## The Flow, Step by Step

Here is what happens each time a member takes an action:

**1. Action detected**
A member does something that has a registered gamification trigger — for example, they post an activity update on BuddyPress. WordPress fires the `bp_activity_posted_update` hook.

**2. Event created**
WB Gamification catches that hook and creates a standardized event record. The record stores: which user, which action, a timestamp, and any relevant metadata (like the word count of a post, which helps with quality-weighted scoring).

**3. Points awarded**
The engine looks up how many points this action is worth (from your settings). It adds a row to the immutable points ledger. This ledger is the permanent record — all other data is derived from it.

**4. Badge conditions checked**
After points are saved, the BadgeEngine checks every active badge condition to see if this member now qualifies. Badge conditions include things like "earned 100 cumulative points" or "published 10 posts." If a condition is met and the member does not already have the badge, it awards immediately.

**5. Level checked**
The LevelEngine compares the member's new cumulative points against your level thresholds. If they have crossed into a new level, it updates their level and fires a level-up notification.

**6. Streak updated**
The StreakEngine records that this member was active today. If today is consecutive with their last active day (within the grace period), their streak counter increases. If they hit a streak milestone (7, 14, 30, 60, 100, 180, or 365 days), they earn bonus points and see a milestone notification.

**7. Challenge progress updated**
If any active challenge involves this action, the ChallengeEngine increments the member's progress counter. If they reach the target, the challenge completes and bonus points are awarded.

**8. Notification sent**
The NotificationBridge collects all the events from this request — points, badge, level-up, streak milestone, challenge completion — and outputs them as toast notifications in the bottom-right corner of the page. BuddyPress notifications are also created if BuddyPress is active.

**9. Activity feed updated (if BuddyPress is active)**
Significant events like earning a badge, levelling up, or receiving kudos are posted to the BuddyPress activity stream so the community can see them.

## Rules Are Stored as Data, Not Code

One key design decision: all badge conditions, point values, and level thresholds are stored in your database. This means you can change any rule from the admin settings without writing PHP or editing files. It also means the rules can be read and updated via the REST API.

## Auto-Detection of Active Plugins

WB Gamification scans your active plugins on each page load and loads only the integration manifests that are relevant. If WooCommerce is not installed, WooCommerce triggers are never registered. If you later add WooCommerce, the triggers activate automatically — no reconfiguration needed.

## Processing Is Asynchronous

Some point awards — particularly high-traffic ones like activity updates — are processed asynchronously using Action Scheduler. This means the member's action completes immediately without waiting for the gamification pipeline to finish. Points and badges may appear a few seconds after the action rather than instantly.
