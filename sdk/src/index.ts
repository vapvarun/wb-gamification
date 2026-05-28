export { WBGamification } from './client';
export type {
  WBGamificationConfig,
  Member,
  Badge,
  LeaderboardEntry,
  Challenge,
  KudosEntry,
  PointsHistoryEntry,
  Action,
} from './types';

// Re-export the openapi-typescript-generated types so power users who want
// the full surface (every path, request body, response shape from
// audit/openapi.json) can `import type { paths, components }` directly.
// The hand-written types above remain the canonical SDK shape for the
// methods on the client; the openapi types are the contract.
export type { paths, components, webhooks, operations } from './openapi';
