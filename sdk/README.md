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
