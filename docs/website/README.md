# Customer documentation source

This directory holds the markdown source for the **customer-facing** documentation that publishes to [docs.wbcomdesigns.com](https://docs.wbcomdesigns.com/) under the `wb-gamification` product.

If you arrived here looking for *how to use the plugin*, start with **[index.md](index.md)** — that's the journey-oriented landing page.

If you're a developer adding to these docs, the rest of this README is for you.

## Folder layout

```
docs/website/
├── index.md                       Customer-docs landing (start here)
├── feature-catalog.md             Every shipped feature on one page
├── docs_config.json               Publish pipeline categorisation
├── image_map.json                 Image-asset upload tracking (auto-managed)
│
├── getting-started/               4 docs — installation, wizard, quick-start, how-it-works
├── features/                      23 docs — every shipped gamification feature in one folder
├── settings/                      8 docs — admin-screen-by-screen configuration walkthroughs
├── integrations/                  7 docs — per-host-plugin integration notes
├── buddypress/                    3 docs — BP-specific surfaces
└── developer-guide/               13 docs — REST, hooks, custom actions, OpenBadges, webhooks, …
```

**Every feature is in the free plugin.** There is no paid tier and no add-on. The legacy `pro-features/` folder was retired on 2026-05-26 — its contents merged into `features/`.

## Publishing

**Source of truth is this directory + GitHub.** Edit the `.md` files here, commit, push. Whatever pipeline reads the repo handles publishing downstream — there is no remote upload step initiated from your laptop.

- Edit `.md` files under `docs/website/` and commit them like any other source file.
- Keep `docs_config.json` and `image_map.json` in the repo — they travel with the docs.
- Do **not** call `mcp__wbcom-docs__publish_product_docs` or any of the `mcp__wbcom-docs__*` tools. They push to a remote service that is not part of this team's publishing pipeline.

For the rules around folder structure, filename conventions, image handling, and markdown style, see `~/.claude/workflows/wbcom-docs.md`.

## Editing rules

- Customer docs are **journey-oriented**, not reference-oriented. Lead with what the reader is trying to *do*, then how to do it.
- Every doc has a frontmatter-free Markdown title (`# Title`) — the publish pipeline lifts the title from the H1.
- Internal cross-links use relative paths (`[Points](features/01-points.md)`). The pipeline rewrites to canonical doc URLs at publish.
- Screenshots go in the WP media library via the publish pipeline; reference them by the URL the pipeline returns. Don't embed local paths.
- Don't duplicate content from `readme.txt`, the changelog, or the developer-side docs in `docs/` (root). Cross-link.
- Keep `feature-catalog.md` in lockstep with what actually ships — it's the docs equivalent of `audit/manifest.summary.json`.

## What does NOT belong here

- API reference internals — those live in [`docs/REST-API.md`](../REST-API.md) (developer-side, not customer-side). Customer-side REST docs at `developer-guide/rest-api.md` should be journey-oriented (e.g. "how to consume the leaderboard from your mobile app") not exhaustive.
- Architecture internals — see [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md).
- QA infrastructure — see [`docs/qa/`](../qa/).
- Developer / contributor process — `docs/CONTRIBUTING.md`.

## Maintenance rule

Every customer-visible feature change ships with:
1. A new or updated doc here under the right category.
2. A row added or updated in [`feature-catalog.md`](feature-catalog.md).
3. An entry in [`../../CHANGELOG.md`](../../CHANGELOG.md) under the unreleased section.
4. (If applicable) an updated screenshot in the media library + image_map entry.

PR review must call out missing doc updates the same way it calls out missing tests.
