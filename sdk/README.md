# @wbcom/wb-gamification

TypeScript SDK for the WB Gamification REST API.

## Install

```bash
npm install @wbcom/wb-gamification
```

## Quick Start

```typescript
import { WBGamification } from '@wbcom/wb-gamification';

const client = new WBGamification({
  baseUrl: 'https://your-site.com',
  apiKey: 'your-api-key',
});

// Get leaderboard
const leaders = await client.getLeaderboard('week', 10);

// Get member profile
const member = await client.getMember(42);

// Award points
await client.awardPoints(42, 100, 'manual', 'Great contribution!');

// Submit event
await client.submitEvent(42, 'publish_post', { post_id: 123 });

// Give kudos
await client.giveKudos(42, 'Thanks for the help!');
```

## Authentication

### API Key (cross-site)
```typescript
const client = new WBGamification({
  baseUrl: 'https://your-site.com',
  apiKey: 'your-api-key',
});
```

### WordPress Nonce (same-site)
```typescript
const client = new WBGamification({
  baseUrl: window.location.origin,
  nonce: wpApiSettings.nonce,
});
```

## API Reference

64 typed methods cover all 56 REST routes. Grouped by domain:

### Members
- `getMember(userId)` — profile bundle (points, level, badges, streak)
- `getMemberPoints(userId, pointType?)` — single-currency total
- `getMemberLevel(userId)` — level + progress toward next
- `getMemberStreak(userId)` — current + longest active streak
- `getMemberRecap(userId, year?)` — annual recap
- `getMemberEvents(userId, { limit, offset })` — paginated points history
- `getMemberBadges(userId)` — earned badges
- `getMyToasts()` — caller's pending toast queue

### Leaderboard
- `getLeaderboard(period, limit, pointType?)` — all/week/month/day
- `getGroupLeaderboard(groupId, period, limit)` — BuddyPress group scope
- `getMyRank(period)` — caller's rank only

### Badges
- `getBadges()` / `getBadge(id)`
- `createBadge(...)` / `updateBadge(id, patch)` / `deleteBadge(id)`
- `awardBadge(badgeId, userId, note)` — manual award (admin)
- `getBadgeCredential(badgeId, userId)` — OpenBadges 3.0 credential
- `getBadgeShare(badgeId, userId)` — public OG share metadata

### Challenges (individual + community)
- `getChallenges()` / `getChallenge(id)` / `createChallenge(...)` / `updateChallenge(id, ...)` / `deleteChallenge(id)`
- `completeChallenge(id)` — force-complete (admin)
- `getCommunityChallenges()` / `getCommunityChallenge(id)` / `createCommunityChallenge(...)` / `updateCommunityChallenge(id, ...)` / `deleteCommunityChallenge(id)`

### Kudos
- `giveKudos(receiverId, message)` — by user ID
- `giveKudosByLogin(login, message)` — by username or email
- `getKudos({ limit, offset })` — global feed
- `getMyKudos()` — sent + received

### Points / Events / Actions
- `awardPoints(userId, points, reason, note)` — manual award (admin)
- `submitEvent(userId, actionId, meta)` — drive the engine directly
- `deletePointsEntry(id)` — admin ledger correction
- `getActions(category?)` / `getAction(id)`
- `setActionOverrides(id, { cooldown, daily_cap })` / `clearActionOverrides(id)`

### Levels
- `getLevels()` / `createLevel(...)` / `updateLevel(id, ...)` / `deleteLevel(id)`

### Point Types + Conversions
- `getPointTypes()` / `getPointType(slug)` / `createPointType(...)` / `updatePointType(slug, ...)` / `deletePointType(slug)`
- `convertPoints(fromType, toType, amount)` — atomic FOR UPDATE locked
- `getPointTypeConversions()` / `createPointTypeConversion(...)` / `updatePointTypeConversion(id, ...)` / `deletePointTypeConversion(id)`

### Redemptions
- `getRedemptionItems()` / `getRedemptionItem(id)` / `createRedemptionItem(...)` / `updateRedemptionItem(id, ...)` / `deleteRedemptionItem(id)`
- `redeem(itemId)` — caller-side
- `getMyRedemptions()` — caller's history

### Submissions (UGC)
- `getSubmissions(status?)`
- `submitAchievement(actionId, evidence, evidenceUrl?)`
- `approveSubmission(id)` / `rejectSubmission(id, reason?)`

### Webhooks
- `getWebhooks()` / `getWebhook(id)` / `createWebhook(...)` / `updateWebhook(id, ...)` / `deleteWebhook(id)`
- `getWebhookLog(id)` / `clearWebhookLog(id)`

### Rules (badge auto-award conditions)
- `getRules()` / `getRule(id)` / `createRule(...)` / `updateRule(id, ...)` / `deleteRule(id)`

### API Keys (admin)
- `getApiKeys()` / `createApiKey(label)` (response includes the secret ONCE) / `deleteApiKey(id)` / `revokeApiKey(id)`

### Settings
- `getCohortSettings()` / `updateCohortSettings(...)`
- `getEmailSettings()` / `updateEmailSettings(...)`

### Discovery
- `getCapabilities()` — caller's role + permitted caps
- `getAbilities()` — WP Abilities API enumeration (WP 6.9+)
- `getOpenApiSpec()` — same content as `audit/openapi.json`

### Escape hatch
- `request<T>(path, options?)` — typed generic for routes you'd rather call directly

```typescript
import type { paths } from '@wbcom/wb-gamification';
type Lvl = paths['/levels']['get']['responses']['200']['content']['application/json'];
const levels = await client.request<Lvl>('/levels');
```

The generated `paths` / `components` types track `audit/openapi.json`
byte-for-byte (enforced by `bin/cut-release.sh --check`), so power
users always have the canonical contract surface.

## Development

```bash
cd sdk
npm install
npm run gen-types     # regenerate src/openapi.d.ts from ../audit/openapi.json
npm run build         # type-gen + tsc -> dist/
npm run typecheck     # type-gen + tsc --noEmit (no dist write)
```

The SDK version tracks the plugin version. `bin/cut-release.sh` bumps
`sdk/package.json` automatically and runs `npm run gen-types` so the
committed `src/openapi.d.ts` matches `audit/openapi.json` byte-for-byte.

The `gen-types` step is also wired into `bin/cut-release.sh --check`
as a drift gate — running `--check` against a clean release state must
exit 0, which proves `sdk/src/openapi.d.ts` was regenerated after any
`audit/openapi.json` change.
