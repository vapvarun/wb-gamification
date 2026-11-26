---
journey: release-zip-deflated
plugin: wb-gamification
priority: critical
roles: [release]
covers: [release-zip-waf, store-upload]
prerequisites:
  - "python3 on PATH"
  - "A green smoke report (bin/build-release.sh gate)"
estimated_runtime_minutes: 5
---

# The release zip has no readable PHP (1.7.0)

Card 10549179297: the `zip` command stores tiny files (index.php "Silence is golden") uncompressed,
so plain `<?php` is readable in the upload and the store's Cloudflare upload rule rejects it; wp-admin
shows only "Unexpected response from the server". BuddyVendor 1.8.0 failed this way on 26 Nov 2026.

## Steps

### 1. Build
- **Action**: `bash bin/build-release.sh`
- **Expect**: exit 0 and `dist/wb-gamification-<version>.zip`. The build stops with
  "has plain-text PHP in it" if any PHP entry is stored.
- **On fail**: the Python packer and the `<?php` guard near the end of `bin/build-release.sh`.

### 2. Check the zip
- **Action**: `LC_ALL=C grep -a -c '<?php' dist/wb-gamification-<version>.zip` and
  `python3 -I ~/.claude/skills/release-distribution/bin/zip-waf-check.py dist/wb-gamification-<version>.zip`
- **Expect**: `0`, and exit 0.

### 3. The guard can fail
- **Action**: pack a folder holding a one-line `<?php` file with `zip -qr`, run step 2 on it.
- **Expect**: count above 0 and exit 1 (measured: 2 and exit 1).

## Pass criteria

1. The built zip contains no readable `<?php`.
2. `zip-waf-check.py` exits 0 on it.
3. `unzip -t` reports no errors.
