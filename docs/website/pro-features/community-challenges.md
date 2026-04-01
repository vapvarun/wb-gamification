# Community Challenges

Community Challenges let your entire membership work toward a shared goal — similar to Pokémon GO's community events. Every member's qualifying actions contribute to a single global progress bar. When the community hits the target, everyone earns bonus points.

## How It Differs from Individual Challenges

Individual challenges (free feature) are per-member: each person has their own progress bar and wins or loses independently. Community challenges have one progress bar shared across all participants. No member can complete it alone.

## Creating a Community Challenge

1. Go to **WB Gamification → Community Challenges → Add New**.
2. Set a **title** and **description** visible to members.
3. Choose the **action** that contributes to progress (any registered gamification action, e.g., `bp_activity_update`).
4. Set the **target count** — total number of qualifying actions needed.
5. Set a **deadline** (date and time).
6. Set the **bonus points** awarded to every member on completion.
7. Click **Publish**.

The `CommunityChallengeEngine` hooks into the event bus. Each time the chosen action fires, it increments the global counter by one.

## What Members See

Members see the challenge title, description, current global progress, target, and time remaining. You can display this with the `[wb_gam_community_challenge]` shortcode or the **Challenges** Gutenberg block.

## On Completion

When the global counter reaches the target before the deadline, every member who made at least one contributing action receives the bonus points. The challenge status changes to **Completed**.

If the deadline passes before the target is reached, the challenge expires without awarding bonus points.

## Requirements

- Pro add-on active
- `community_challenges` feature flag enabled
