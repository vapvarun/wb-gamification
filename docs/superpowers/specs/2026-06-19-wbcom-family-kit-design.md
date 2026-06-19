# Wbcom Family Kit — Design

- **Date:** 2026-06-19
- **Status:** Approved (design) — pending spec review
- **v1 target:** wb-gamification (standalone plugin — exercises the "works alone" path hardest)
- **Rollout:** the same Kit then drops into the other family owners (BuddyNext, Jetonomy, Learnomy, WPMediaVerse, Listora, Career Board)

## Purpose

The Wbcom family plugins currently onboard users toward **3rd-party** plugins (wb-gamification's setup wizard offers "Community Engagement — requires BuddyPress", "Online Course — requires LearnDash"), even though the whole family is installed alongside. We want the family to **cross-reference each other** with BuddyNext as the Community Engine — and to do it as a **guide, not advertising**: show the user what they're trying to accomplish and the path to do it; the family plugins appear as the *means*, never as ad cards.

This is delivered as a portable, bundled **Wbcom Family Kit** that renders an outcome-first "Integrations" page inside each plugin, adapting to what's installed locally, and can install+activate the free family members in one click.

## Principles (non-negotiable)

- **Guide, not ads.** Organized by what the user wants to do, not a grid of plugin promos. One clear next action per item. No banners, no repeated CTAs, no marketing imagery/gradients. Calm and value-first (the LinkedIn-minimum-value bar).
- **Plugin level, no cloud.** Registry is bundled in the Kit; install-state is detected locally; zero network dependency. "Dynamic" = reflects the live local install/active state.
- **Works standalone.** Each plugin installs separately; when only one is present the page is pure guidance ("to do X, add Y"), never a hard dependency on BuddyNext or any sibling.
- **Family-first, 3rd-party demoted.** 3rd-party compatibility (BuddyPress/LearnDash/WooCommerce) appears only as a secondary "also works with…" line, never as the required path.
- **Follow ux-foundation.** Wbcom admin tokens, Lucide icons, desktop+iPad responsive (admin screens are occasional-use; the 390px rule does not apply).

## Ownership & portability

- The Kit is a **self-contained, portable PHP module** at `libs/wbcom-family/` with its own `Wbcom\Family` namespace and a tiny bootstrap (no reliance on the host plugin's autoloader/namespace), so the identical directory drops into any plugin.
- **Free+Pro pair** (BuddyNext, Jetonomy, Learnomy, WPMediaVerse, Listora, Career Board): the **free** plugin owns `libs/wbcom-family/`; Pro consumes the free copy (Pro already requires Free). No duplication within a pair.
- **Standalone** (wb-gamification): bundles `libs/wbcom-family/` itself.
- **Versioned.** A `VERSION` constant in the Kit + a guarded bootstrap so that if two plugins in one site load different Kit versions, the highest version wins (load-once guard keyed on version) — same defensive pattern as a shared SDK.

## Components

### 1. Family registry — `libs/wbcom-family/registry.php`
Returns an array of family members (bundled data, no network). Each member:
- `slug_free`, `slug_pro` (plugin folder/file slugs for install-state + install),
- `name`, `tagline` (one outcome-oriented line), `icon` (Lucide slug), `category` (Community Engine | Engagement | Learning | Media | Commerce | Careers),
- `wporg_slug` (for free install via `plugins_api`; null if not on wp.org),
- `learn_url`, `pro_url`,
- `is_engine` (true for BuddyNext — featured).

Plus an **outcomes map**: `outcome => { title, description, requires: [member slugs] }` — e.g. `reward_engagement => { requires: [wb-gamification] }`, `run_courses => { requires: [learnomy] }`, `messaging_media => { requires: [wpmediaverse] }`, `jobs_board => { requires: [wp-career-board] }`, `forums => { requires: [jetonomy] }`, and a `cross` matrix of "together you get…" lines per pair (the host plugin + a sibling).

### 2. Install-state detector — `libs/wbcom-family/class-state.php`
Pure local detection per member: `not_installed | installed_inactive | active` (free and pro independently). Uses `get_plugins()` / `is_plugin_active()`. No network.

### 3. Installer — `libs/wbcom-family/class-installer.php`
One-click **install + activate** for free family members via WordPress core `plugins_api` + `Plugin_Upgrader` (with `Automatic_Upgrader_Skin`), behind an admin-only AJAX/REST action with nonce + `install_plugins`/`activate_plugins` capability checks. Pro members are never auto-installed — they render a "Learn more / Get Pro" link only. No bundled binaries; free installs come from wp.org.

### 4. Renderer — `libs/wbcom-family/class-page.php`
Renders the outcome-first guide from the registry + live state. For each outcome the user might pursue:
- **Enabling piece active** → "You have this — here's how to set it up" + one configure/learn link.
- **Installed, inactive** → a single "Activate" action.
- **Not installed** → one calm factual line ("To {outcome}, {host} works with {member} — here's how it works") + a single "Install & activate" (free) or "Learn more" (pro) action.
The host plugin itself is shown as "active / configured"; BuddyNext is surfaced as the Community Engine when relevant. Built with ux-foundation tokens; no promo chrome.

### 5. Host adapter (per plugin)
A ~10-line glue file the host plugin provides: declares its own slug + which outcomes it owns/enables, registers the page (see below), and boots the Kit. Everything else is in the Kit.

## v1 — wb-gamification integration

- Place the Kit at `wb-gamification/libs/wbcom-family/`.
- Register an **"Integrations" tab** inside the existing gamification admin area (alongside `src/Admin/SetupWizard.php` / `SettingsPage.php`) — a tab, not a new top-level menu (a tab reads as "part of the product", a standalone menu reads as a promo surface). Label: **"Integrations"**.
- The host adapter declares gamification's outcome (`reward_engagement`) and the cross lines (Gamification + BuddyNext = points/badges on activity & profiles; + Learnomy = course-completion badges; + Career Board = hiring/milestone rewards).
- The page leads with "What do you want to reward?" guidance; family members that enable richer rewards are shown as guidance with one-click install for the free ones.

## Rollout (post-v1)
- Drop `libs/wbcom-family/` + the ~10-line host adapter into each other free owner; Pros inherit.
- **Phase 2 (separate spec):** reorient each plugin's onboarding wizard to family-first from the same registry (e.g. gamification's "Community Engagement — requires BuddyPress" → "— works with BuddyNext").

## Out of scope (v1)
- Cloud/central registry (explicitly rejected — plugin level only).
- Onboarding-wizard reorientation (Phase 2).
- Auto-installing Pro plugins (paid; link out only).
- Front-end surfaces (admin only).

## Testing
- Registry shape unit test (every member has required keys; outcomes reference real members).
- State detector: not_installed / inactive / active resolved correctly against a faked `get_plugins()`.
- Installer: capability + nonce enforced; refuses pro slugs; happy-path install mocked (no live network in tests).
- Renderer: given a state map, asserts each outcome renders the correct single action (configure | activate | install | learn-more) and emits no banner/ad markup.
- Browser-verify the gamification Integrations tab at desktop + iPad widths, light + dark.
