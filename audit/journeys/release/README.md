# Release verification journeys

These 9 journeys correspond 1:1 to the 9 tiers in `plans/V1-RELEASE-VERIFICATION-PLAN.md`. Run them in order before tagging a release. A failing journey blocks the tag.

| # | Journey | Priority | Runtime | Gate |
|---|---|---|---|---|
| 1 | [Tier 1 — Foundations](01-tier-1-foundations.md) | critical | ~8 min | static + unit gates green |
| 2 | [Tier 2 — Editor surface](02-editor-15-blocks.md) | critical | ~12 min | 15 blocks insertable + configurable |
| 3 | [Tier 3 — Frontend surface](03-frontend-15-blocks.md) | critical | ~15 min | 15 frontends serve clean at 1280 + 390 |
| 4 | [Tier 4 — Earning journey](04-earning-journey.md) | critical | ~5 min | award + debit + ledger contract |
| 5 | [Tier 5 — Admin REST](05-admin-9-pages-rest.md) | critical | ~18 min | 9 admin pages REST-driven, 0 form-posts |
| 6 | [Tier 6 — Integration](06-integration-graceful-degradation.md) | high | ~6 min | graceful degradation + live host probes |
| 7 | [Tier 7 — A11y + mobile](07-a11y-and-mobile.md) | high | ~12 min | WCAG 2.1 AA, modal a11y, reduced-motion |
| 8 | [Tier 8 — Theme matrix](08-theme-matrix.md) | high | ~10 min | 3 themes, no link-color leak |
| 9 | [Tier 9 — Release zip](09-release-zip-gate.md) | critical | ~8 min | zip excludes dev artefacts, lint clean |

**Total runtime:** ~95 min for the full suite. The 4 critical-priority journeys (1, 2, 3, 4, 5, 9) run first — anything red there is a hard block.

**Promotion:** when adding a new feature or fixing a bug that one of these journeys would have caught, update the journey's Steps + Fail diagnostics in the same PR. Journeys are living regression tests, not write-once docs.

## How a single tier-N run works

```
1. Read journey markdown
2. Open prerequisites — verify each is satisfied
3. Walk steps in order; capture VARS where the journey says to
4. After all steps, evaluate pass criteria
5. Land run artefacts under audit/release-runs/<run-date>/tier-N/
   (screenshots, console logs, network HARs, JSON outputs)
6. PASS or FAIL — if FAIL, the diagnostics table tells you the most likely files
```

A cheap LLM agent can drive this end-to-end via Playwright + curl + wp-cli + the wppqa MCP. Re-running 9 journeys per release is cheaper than maintaining 200+ fragile unit tests.
