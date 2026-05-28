---
journey: self-healing-boot
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [install-resilience, cli-activation-gap, db-restore-gap, container-clone-gap]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli on PATH"
  - "Direct mysql access via Local Site Shell socket"
estimated_runtime_minutes: 2
---

# Plugin self-heals when activation-hook effects are missing

`register_activation_hook` only fires on the canonical WP activation
path. Several real-world scenarios skip it or lose its effects:

1. `wp plugin activate` from CLI with `--skip-plugins` set
2. Backup restore that kept `active_plugins` but lost custom tables
3. InstaWP / migration clones snapshotted between activation and the
   first `dbDelta` run
4. Dev resets via WP Reset that wipe schema but leave the plugin file
   on disk and active

Without self-heal the plugin boots into a half-installed state — admin
menu doesn't render, wizard doesn't auto-open, `DbUpgrader` tries to
ALTER non-existent tables, the user sees 403s or a 500.

`Installer::maybe_install()` runs at `plugins_loaded@0` (just ahead of
`DbUpgrader::init` at priority 1), detects the missing-tables state
via a single `SHOW TABLES` probe, and re-runs the activation payload
(tables + caps + wizard redirect option). This journey asserts both
the healthy-site no-op path AND the fresh-state heal path.

See `src/Engine/Installer.php::maybe_install()` and
the git-history snapshot of `audit/PERF-DIAG-2026-05-27.yaml`.

## Setup

- Site: `$SITE_URL` = `http://wb-gamification.local`
- Plugin: must already be in `active_plugins`
- Tools: `wp eval`, direct `mysql` via the Local socket

## Steps

### 1. Healthy-site path — maybe_install is a no-op

```bash
wp eval '
global $wpdb;
echo "Before: ";
echo ($wpdb->get_var("SHOW TABLES LIKE \"wp_wb_gam_events\"") ?: "missing") . "\n";

$did_install = \WBGam\Engine\Installer::maybe_install();
echo "Did install: " . ($did_install ? "YES (wrong — should be no-op)" : "no (correct)") . "\n";
'
```

Pass: `Before: wp_wb_gam_events` + `Did install: no (correct)`.

### 2. Fresh-state path — maybe_install heals

Drop the source-of-truth events table outside the wp process so the
next boot finds the missing-state.

```bash
# Outside of wp-cli so the DROP persists.
MYSQL_PWD=root mysql --socket="$(ls -t ~/Library/Application\ Support/Local/run/ | head -1)/mysql/mysqld.sock" \
   -uroot local -e "DROP TABLE wp_wb_gam_events; DELETE FROM wp_options WHERE option_name='wb_gam_db_version';"
```

Then a fresh wp eval should self-heal:

```bash
wp eval '
global $wpdb;
echo "events: " . ($wpdb->get_var("SHOW TABLES LIKE \"wp_wb_gam_events\"") ?: "MISSING") . "\n";
echo "db_version: " . get_option("wb_gam_db_version", "(missing)") . "\n";
'
```

Pass: `events: wp_wb_gam_events` + `db_version: 1.4.0`. The fresh wp
eval bootstrapped WordPress, fired `plugins_loaded@0`, and our
self-heal restored the table.

### 3. Fresh-state path — pending wizard option toggles correctly

When the heal happens on a site that has NEVER completed the wizard,
`wb_gam_pending_setup_redirect` MUST be set so the wizard auto-opens.

When the heal happens on a site that HAS completed the wizard
(`wb_gam_wizard_complete = 1`), the redirect option MUST NOT be set
— we don't pull post-onboarding admins back into the wizard.

```bash
# Set wizard-complete then drop the events table.
wp option update wb_gam_wizard_complete 1
MYSQL_PWD=root mysql ... -e "DROP TABLE wp_wb_gam_events;"

# Trigger heal.
wp eval 'echo "pending: " . get_option("wb_gam_pending_setup_redirect", "(unset)") . "\n";'
```

Pass: `pending: (unset)` — heal ran, table restored, but wizard stays
where it is.

### 4. Idempotent on rapid re-call

Two `maybe_install()` calls in the same request — second must return
false.

```bash
wp eval '
$a = \WBGam\Engine\Installer::maybe_install();
$b = \WBGam\Engine\Installer::maybe_install();
echo "first=" . ($a ? "1" : "0") . " second=" . ($b ? "1" : "0") . "\n";
'
```

Pass: `first=0 second=0` on a healthy site. (First call would be
`first=1` only if you cleared the table between this and step 2.)

## Pass criteria

All four steps pass without modification. The heal path leaves the
site in the same logical state the activation hook would have.

## Failure-mode coverage

This journey would have caught:
- The exact "reset → activate via CLI → no admin menu" failure mode
  reported during the 2026-05-27 reset session.
- Any future regression that drops `Installer::maybe_install()` from
  the `plugins_loaded@0` boot closure.
- A migration script that creates tables without bumping
  `wb_gam_db_version`, so subsequent `DbUpgrader::init` invocations
  re-run version migrations against an already-current schema.

## Cleanup

None — the journey is idempotent and leaves a healthy install behind.
