/**
 * Hand-written interfaces for the most-common return shapes.
 *
 * These are NOT exhaustive — the openapi-typescript-generated types in
 * `./openapi` cover every route. The shapes here exist to give the
 * client's typed methods a narrow, ergonomic return type for the 90%
 * case (member reads, leaderboard, badges, etc.) without forcing every
 * consumer through the verbose `paths[...]` shape.
 *
 * For full strict typing on the long tail (admin routes, point-type
 * conversions, webhooks, etc.) import the generated types:
 *
 *   import type { paths, components } from '@wbcom/wb-gamification';
 *   type Resp = paths['/levels']['get']['responses']['200']['content']['application/json'];
 */

export interface WBGamificationConfig {
  baseUrl: string;
  apiKey?: string;
  nonce?: string;
  /** Per-request timeout in ms (default 15000). A hung host aborts instead of hanging. */
  timeoutMs?: number;
}

export interface Member {
  id: number;
  name: string;
  points: number;
  level: { id: number; name: string; min_points: number } | null;
  badges: Badge[];
  streak: {
    current_streak: number;
    longest_streak: number;
    last_active: string;
  };
}

export interface Badge {
  id: string;
  name: string;
  description: string;
  image_url: string;
  earned_at: string | null;
  expires_at: string | null;
}

export interface LeaderboardEntry {
  rank: number;
  user_id: number;
  display_name: string;
  points: number;
  avatar_url: string;
}

export interface Challenge {
  id: number;
  title: string;
  description: string;
  action_id: string;
  target: number;
  progress: number;
  bonus_points: number;
  completed: boolean;
  starts_at: string;
  ends_at: string;
}

export interface CommunityChallenge {
  id: number;
  title: string;
  description: string;
  action_id: string;
  target_count: number;
  global_progress: number;
  bonus_points: number;
  status: 'active' | 'completed' | 'expired';
  starts_at: string;
  ends_at: string;
}

export interface KudosEntry {
  id: number;
  giver_id: number;
  receiver_id: number;
  message: string;
  created_at: string;
}

export interface PointsHistoryEntry {
  id: number;
  action_id: string;
  points: number;
  object_id: number | null;
  created_at: string;
}

export interface Action {
  id: string;
  label: string;
  description: string;
  default_points: number;
  category: string;
  repeatable: boolean;
}

export interface Level {
  id: number;
  name: string;
  min_points: number;
  badge_image_url?: string;
}

export interface PointType {
  slug: string;
  label: string;
  symbol?: string;
  is_default: boolean;
}

export interface PointTypeConversion {
  id: number;
  from_type: string;
  to_type: string;
  ratio: number;
  is_active: boolean;
}

export interface RedemptionItem {
  id: number;
  title: string;
  description: string;
  cost: number;
  point_type: string;
  stock: number; // 0 = Unlimited
  is_active: boolean;
}

export interface Redemption {
  id: number;
  user_id: number;
  item_id: number;
  cost: number;
  point_type: string;
  status: string;
  coupon_code?: string;
  created_at: string;
}

export interface Submission {
  id: number;
  user_id: number;
  action_id: string;
  evidence: string;
  evidence_url: string;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
}

export interface Webhook {
  id: number;
  url: string;
  events: string[];
  is_active: boolean;
  created_at: string;
}

export interface ApiKey {
  id: number;
  label: string;
  key_prefix: string;
  is_active: boolean;
  created_at: string;
  last_used_at: string | null;
}

export interface Toast {
  _id: number;
  type: string;
  message: string;
  meta?: Record<string, unknown>;
}

export interface Rule {
  id: number;
  badge_id: string;
  condition_type: string;
  condition_data: Record<string, unknown>;
}

export interface Recap {
  user_id: number;
  total_points: number;
  badges_earned: number;
  top_actions: Array<{ action_id: string; count: number; points: number }>;
  longest_streak: number;
  level_changes: number;
}
