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

| Method | Description |
|--------|-------------|
| `getMember(userId)` | Get member profile with points, level, badges, streak |
| `getLeaderboard(period, limit)` | Get ranked leaderboard |
| `getBadges()` | List all badge definitions |
| `getMemberBadges(userId)` | Get badges earned by a user |
| `getChallenges()` | List active challenges |
| `awardPoints(userId, points, actionId, message)` | Award points manually |
| `submitEvent(userId, actionId, meta)` | Submit a gamification event |
| `giveKudos(receiverId, message)` | Send peer recognition |
| `getActions()` | List all registered actions |
| `getOpenApiSpec()` | Get the full OpenAPI 3.0 spec |

The hand-written methods above cover 9 of 56 REST endpoints — the
most-common surface for community apps. For the full 56-route type
contract, import the generated OpenAPI types:

```typescript
import type { paths, components } from '@wbcom/wb-gamification';

// paths describes every URL the API exposes:
type LeaderboardResponse = paths['/leaderboard']['get']['responses']['200']['content']['application/json'];

// components carries the shared schema definitions
type Badge = components['schemas']['wb-gamification-badge'];
```

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
