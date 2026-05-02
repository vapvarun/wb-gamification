# WordPress.org marketing assets

Files in this directory are **mirrored to the SVN `assets/` folder** when the plugin is published to wordpress.org/plugins. They live here in the GitHub repo so design changes are version-controlled alongside code.

> **Status as of 2026-05-02**: this directory is empty (specs only). The wppqa audit at `audit/wppqa-full-2026-05-02/REPORT.md` flags 3 missing-asset errors:
>
> ```
> [marketing] No WordPress.org banner image
> [marketing] No WordPress.org icon
> [marketing] No screenshot images
> ```
>
> All three become errors only at .org submission time — not blockers for distribution via direct download or paid channel. If you're not submitting to .org, this directory can stay empty.

## Required files (when submitting to wordpress.org/plugins)

| File | Size | Format | Purpose |
|---|---|---|---|
| `banner-1544x500.png` | 1544×500 | PNG (≤1 MB) | High-DPI plugin directory banner |
| `banner-772x250.png` | 772×250 | PNG (≤1 MB) | Standard plugin directory banner |
| `icon-256x256.png` | 256×256 | PNG (≤200 KB) | High-DPI menu/grid icon |
| `icon-128x128.png` | 128×128 | PNG (≤200 KB) | Standard menu/grid icon |
| `screenshot-1.png` ... `screenshot-N.png` | ≥1280×800 typical | PNG | Per-screenshot referenced in `readme.txt` |

The `readme.txt` already lists 10 screenshots with content descriptions (lines 153-164). When the screenshot files are added, they should match those descriptions in numbered order.

Optional but recommended:
- `icon.svg` — vector source for the icons. WordPress.org will use the PNG, but the SVG lets future renders stay crisp.
- An animated `banner-772x250.gif` — wordpress.org also accepts animated banners up to ~1 MB; not required.

## Brand parameters

| Element | Value | Source |
|---|---|---|
| Primary colour | `#2563eb` (admin-premium primary), historic `#6c63ff` (admin secondary) | `assets/css/admin.css`, `assets/css/admin-premium.css` |
| Plugin name | "WB Gamification" | `wb-gamification.php` plugin header |
| Tagline | "Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack." | Plugin header description |
| Dashicon | `dashicons-awards` (the trophy icon) | `src/Admin/SettingsPage.php:54` (`add_menu_page` icon) |
| Author | Wbcom Designs (https://wbcomdesigns.com/) | Plugin header |

## Producing the assets

Several paths — pick whichever fits the team's workflow:

1. **Designer brief** — share this README + the existing screenshots referenced in `readme.txt`. Brief: "produce 4 PNGs to wordpress.org spec, on-brand with the colour/dashicon above, plus 10 product screenshots from the running plugin matching the readme.txt descriptions."

2. **In-house, Figma/Sketch** — start from the dashicon trophy + primary colour. WordPress.org's plugin handbook has banner templates: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/

3. **Browser-screenshot for screenshots** — run the plugin on a Local install, navigate each admin/frontend page from `readme.txt:155-164`, capture at 1280×800 viewport, save numbered. Playwright MCP can drive this once the journey runner is wired.

4. **Quick placeholder** — if you need to ship something to .org soon and don't have design ready, a banner with just `WB Gamification` text on a solid `#2563eb` background plus the trophy dashicon SVG passes review. Minimal but acceptable.

## Where the SVN sync expects them

When the plugin is published, the build step copies `.wordpress-org/*.png` into the wordpress.org SVN `assets/` directory (NOT `trunk/assets/`). The repo path stays here; the publishing automation handles the SVN side.

If you use `Gruntfile.js`'s `copy:dist` task, this directory is **deliberately excluded** from the distributable zip (it's not a plugin runtime dependency). That's correct — these files only live in the SVN `assets/` folder, not inside the zip.
