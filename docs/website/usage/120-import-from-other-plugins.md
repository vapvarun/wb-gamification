# Import from Other Plugins

Go to **WB Gamification > Import** in your admin menu.

Moving from GamiPress, myCred or BadgeOS? The importer brings your members' points history, earned badges and ranks across, so nobody starts again from zero. Keep the old plugin active until the import finishes: the importer reads its data in place.

## Before You Start

- Back up your database.
- Keep the source plugin (GamiPress, myCred or BadgeOS) active. A source shows **Data found** when there is something to import, and **No data** when there is not.
- Run the import on a staging copy first if your community is large.

## Step 1: Preview

Click **Preview (dry run)** on the source card. Nothing is written. You see:

- How many point rows, badge awards and rank tiers a run would import.
- About how many background jobs it will take.
- The first rows it would import, so you can check they look right.
- Anything an earlier run already imported. Those rows are skipped, so running again is safe.

## Step 2: Run the Import

Click **Run import**, then click again to confirm. The import runs in the background in small pages and shows a progress bar:

1. Creating levels
2. Importing points
3. Importing badges
4. Working out badges and levels for each member
5. Checking every member against the source

You can leave the page; the import keeps going. When it finishes, you see a summary and any members whose totals do not match the source plugin.

Members are not notified about imported history: no toasts, emails or "level up" messages fire for it.

## If the Import Stops Moving

The import uses your site's background queue (Action Scheduler, or WP-Cron). On some hosts that queue does not run, for example when WP-Cron is disabled or the site cannot call itself (loopback requests blocked).

When the queue has not picked up the next page for two minutes, the screen says the background queue is not running and offers two buttons:

- **Run it in this window** processes the remaining pages from your browser, one at a time. Keep the tab open until it finishes.
- **Resume import** hands the run back to the background queue. Use it once the queue works again.

Either way the import continues from its last checkpoint. Nothing is imported twice.

## Undo an Import

Click **Undo import** on the source card. You see what would be removed first, then confirm. Undo removes the imported events, badge awards and the levels the import created, and lowers each member's total by exactly what the import added.

Points and badges members earned *because of* the imported history (for example a level badge and its bonus) are ordinary awards and stay.

## From the Command Line

```bash
wp wb-gamification import mycred --dry-run   # preview, writes nothing
wp wb-gamification import mycred             # queue the import in the background
wp wb-gamification import mycred --sync      # run every page now, no queue needed
wp wb-gamification import mycred --resume    # continue an unfinished or stalled run
```

Use `gamipress`, `mycred` or `badgeos` as the source. `--sync` is the fastest way on a site where the background queue does not run.

## Who Can Import

Administrators, and staff given the **Manage members** permission under **WB Gamification > Settings > Access > Staff permissions**.
