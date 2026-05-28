import type {
  WBGamificationConfig,
  Member,
  Badge,
  LeaderboardEntry,
  Challenge,
  CommunityChallenge,
  KudosEntry,
  PointsHistoryEntry,
  Action,
  Level,
  PointType,
  PointTypeConversion,
  RedemptionItem,
  Redemption,
  Submission,
  Webhook,
  ApiKey,
  Toast,
  Rule,
  Recap,
} from './types';

/**
 * The WB Gamification REST client.
 *
 * Covers the 90% case with typed convenience methods, plus a generic
 * {@link WBGamification.request} escape hatch for routes not wrapped here.
 * Every route in `audit/openapi.json` is reachable; whether it has a
 * dedicated helper or you call `request<T>(path)` directly, the
 * `paths` / `components` types from the package's `openapi` export
 * give you strict typing.
 *
 * Authentication: pass `apiKey` for cross-site setups OR `nonce` for
 * same-site requests. Both can coexist (server uses whichever wins
 * permission_callback for the route).
 */
export class WBGamification {
  private baseUrl: string;
  private headers: Record<string, string>;

  constructor(config: WBGamificationConfig) {
    this.baseUrl =
      config.baseUrl.replace(/\/$/, '') + '/wp-json/wb-gamification/v1';
    this.headers = { 'Content-Type': 'application/json' };

    if (config.apiKey) {
      this.headers['X-WB-Gam-Key'] = config.apiKey;
    }
    if (config.nonce) {
      this.headers['X-WP-Nonce'] = config.nonce;
    }
  }

  /**
   * Generic typed request. Public so consumers can reach routes that
   * don't have a wrapper method here without dropping out of TypeScript
   * (combine with the `paths` types from `@wbcom/wb-gamification`).
   *
   *   const lvls = await client.request<Level[]>('/levels');
   *
   * @param path     Path under /wp-json/wb-gamification/v1 (leading slash).
   * @param options  Standard fetch options (method, body, headers).
   */
  async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.baseUrl}${path}`;
    const response = await fetch(url, {
      ...options,
      headers: { ...this.headers, ...options.headers },
    });

    if (!response.ok) {
      const error = await response
        .json()
        .catch(() => ({ message: response.statusText }));
      throw new Error(
        `WB Gamification API error ${response.status}: ${error.message || response.statusText}`
      );
    }

    // Some endpoints (DELETE 204) return empty bodies. Tolerate that.
    const text = await response.text();
    if (!text) {
      return undefined as T;
    }
    return JSON.parse(text) as T;
  }

  /** Internal helper — JSON POST. */
  private post<T>(path: string, body: unknown): Promise<T> {
    return this.request<T>(path, {
      method: 'POST',
      body: JSON.stringify(body),
    });
  }

  /** Internal helper — JSON PUT. */
  private put<T>(path: string, body: unknown): Promise<T> {
    return this.request<T>(path, {
      method: 'PUT',
      body: JSON.stringify(body),
    });
  }

  /** Internal helper — DELETE. */
  private del<T>(path: string): Promise<T> {
    return this.request<T>(path, { method: 'DELETE' });
  }

  /** Internal helper — append a query string from a record. */
  private qs(params: Record<string, unknown>): string {
    const entries = Object.entries(params).filter(
      ([, v]) => v !== undefined && v !== null && v !== ''
    );
    if (entries.length === 0) {
      return '';
    }
    return (
      '?' +
      entries
        .map(
          ([k, v]) =>
            `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`
        )
        .join('&')
    );
  }

  // ── Members ──────────────────────────────────────────────────────────────

  async getMember(userId: number): Promise<Member> {
    return this.request<Member>(`/members/${userId}`);
  }

  async getMemberPoints(userId: number, pointType?: string): Promise<{
    user_id: number;
    point_type: string;
    total: number;
  }> {
    return this.request(`/members/${userId}/points${this.qs({ type: pointType })}`);
  }

  async getMemberLevel(userId: number): Promise<{ level: Level | null; progress: number }> {
    return this.request(`/members/${userId}/level`);
  }

  async getMemberStreak(userId: number): Promise<{
    current_streak: number;
    longest_streak: number;
    last_active: string;
  }> {
    return this.request(`/members/${userId}/streak`);
  }

  async getMemberRecap(userId: number, year?: number): Promise<Recap> {
    return this.request<Recap>(`/members/${userId}/recap${this.qs({ year })}`);
  }

  async getMemberEvents(
    userId: number,
    opts: { limit?: number; offset?: number } = {}
  ): Promise<PointsHistoryEntry[]> {
    return this.request<PointsHistoryEntry[]>(
      `/members/${userId}/events${this.qs(opts as Record<string, unknown>)}`
    );
  }

  async getMemberBadges(userId: number): Promise<Badge[]> {
    return this.request<Badge[]>(`/members/${userId}/badges`);
  }

  async getMyToasts(): Promise<Toast[]> {
    return this.request<Toast[]>('/members/me/toasts');
  }

  // ── Leaderboard ──────────────────────────────────────────────────────────

  async getLeaderboard(
    period: 'all' | 'week' | 'month' | 'day' = 'all',
    limit = 10,
    pointType?: string
  ): Promise<LeaderboardEntry[]> {
    return this.request<LeaderboardEntry[]>(
      `/leaderboard${this.qs({ period, limit, type: pointType })}`
    );
  }

  async getGroupLeaderboard(
    groupId: number,
    period: 'all' | 'week' | 'month' | 'day' = 'all',
    limit = 10
  ): Promise<LeaderboardEntry[]> {
    return this.request<LeaderboardEntry[]>(
      `/leaderboard/group/${groupId}${this.qs({ period, limit })}`
    );
  }

  async getMyRank(period: 'all' | 'week' | 'month' | 'day' = 'all'): Promise<{
    rank: number;
    user_id: number;
    points: number;
  }> {
    return this.request(`/leaderboard/me${this.qs({ period })}`);
  }

  // ── Badges ───────────────────────────────────────────────────────────────

  async getBadges(): Promise<Badge[]> {
    return this.request<Badge[]>('/badges');
  }

  async getBadge(id: string): Promise<Badge> {
    return this.request<Badge>(`/badges/${encodeURIComponent(id)}`);
  }

  async createBadge(badge: Partial<Badge> & { name: string }): Promise<Badge> {
    return this.post<Badge>('/badges', badge);
  }

  async updateBadge(id: string, patch: Partial<Badge>): Promise<Badge> {
    return this.post<Badge>(`/badges/${encodeURIComponent(id)}`, patch);
  }

  async deleteBadge(id: string): Promise<void> {
    return this.del<void>(`/badges/${encodeURIComponent(id)}`);
  }

  /** Manually award a badge to a user. */
  async awardBadge(
    badgeId: string,
    userId: number,
    note = ''
  ): Promise<{ success: boolean }> {
    return this.post(`/badges/${encodeURIComponent(badgeId)}/award`, {
      user_id: userId,
      note,
    });
  }

  /** OpenBadges 3.0 verifiable credential for an earned badge. */
  async getBadgeCredential(
    badgeId: string,
    userId: number
  ): Promise<Record<string, unknown>> {
    return this.request(
      `/badges/${encodeURIComponent(badgeId)}/credential/${userId}`
    );
  }

  /** Public share-card metadata for an earned badge (OG meta etc.). */
  async getBadgeShare(
    badgeId: string,
    userId: number
  ): Promise<Record<string, unknown>> {
    return this.request(
      `/badges/${encodeURIComponent(badgeId)}/share/${userId}`
    );
  }

  // ── Challenges ───────────────────────────────────────────────────────────

  async getChallenges(): Promise<Challenge[]> {
    return this.request<Challenge[]>('/challenges');
  }

  async getChallenge(id: number): Promise<Challenge> {
    return this.request<Challenge>(`/challenges/${id}`);
  }

  async createChallenge(
    challenge: Partial<Challenge> & { title: string; action_id: string }
  ): Promise<Challenge> {
    return this.post<Challenge>('/challenges', challenge);
  }

  async updateChallenge(id: number, patch: Partial<Challenge>): Promise<Challenge> {
    return this.post<Challenge>(`/challenges/${id}`, patch);
  }

  async deleteChallenge(id: number): Promise<void> {
    return this.del<void>(`/challenges/${id}`);
  }

  /** Force a challenge to completed status (admin). */
  async completeChallenge(id: number): Promise<Challenge> {
    return this.post<Challenge>(`/challenges/${id}/complete`, {});
  }

  // ── Community Challenges ─────────────────────────────────────────────────

  async getCommunityChallenges(): Promise<CommunityChallenge[]> {
    return this.request<CommunityChallenge[]>('/community-challenges');
  }

  async getCommunityChallenge(id: number): Promise<CommunityChallenge> {
    return this.request<CommunityChallenge>(`/community-challenges/${id}`);
  }

  async createCommunityChallenge(
    challenge: Partial<CommunityChallenge> & { title: string; action_id: string }
  ): Promise<CommunityChallenge> {
    return this.post<CommunityChallenge>('/community-challenges', challenge);
  }

  async updateCommunityChallenge(
    id: number,
    patch: Partial<CommunityChallenge>
  ): Promise<CommunityChallenge> {
    return this.post<CommunityChallenge>(`/community-challenges/${id}`, patch);
  }

  async deleteCommunityChallenge(id: number): Promise<void> {
    return this.del<void>(`/community-challenges/${id}`);
  }

  // ── Kudos ────────────────────────────────────────────────────────────────

  async giveKudos(
    receiverId: number,
    message: string
  ): Promise<{ success: boolean }> {
    return this.post('/kudos', { receiver_id: receiverId, message });
  }

  /** Give kudos to a user by login name or email (server-side resolution). */
  async giveKudosByLogin(
    recipientLogin: string,
    message: string
  ): Promise<{ success: boolean }> {
    return this.post('/kudos', { recipient_login: recipientLogin, message });
  }

  async getKudos(opts: { limit?: number; offset?: number } = {}): Promise<KudosEntry[]> {
    return this.request<KudosEntry[]>(`/kudos${this.qs(opts as Record<string, unknown>)}`);
  }

  async getMyKudos(): Promise<{ sent: KudosEntry[]; received: KudosEntry[] }> {
    return this.request('/kudos/me');
  }

  // ── Points / Events ──────────────────────────────────────────────────────

  /** Manual point award (admin). */
  async awardPoints(
    userId: number,
    points: number,
    reason = 'manual',
    note = ''
  ): Promise<{ success: boolean }> {
    return this.post('/points/award', {
      user_id: userId,
      points,
      reason,
      note,
    });
  }

  /** Submit a gamification event — same shape the integrations use. */
  async submitEvent(
    userId: number,
    actionId: string,
    meta: Record<string, unknown> = {}
  ): Promise<{ success: boolean }> {
    return this.post('/events', {
      user_id: userId,
      action_id: actionId,
      metadata: meta,
    });
  }

  /** Delete an individual points ledger entry (admin). */
  async deletePointsEntry(id: number): Promise<void> {
    return this.del<void>(`/points/${id}`);
  }

  // ── Actions ──────────────────────────────────────────────────────────────

  async getActions(category?: string): Promise<Action[]> {
    return this.request<Action[]>(`/actions${this.qs({ category })}`);
  }

  async getAction(id: string): Promise<Action> {
    return this.request<Action>(`/actions/${encodeURIComponent(id)}`);
  }

  /** Per-action cooldown + daily-cap overrides (admin). */
  async setActionOverrides(
    id: string,
    overrides: { cooldown?: number; daily_cap?: number }
  ): Promise<Action> {
    return this.post<Action>(
      `/actions/${encodeURIComponent(id)}/overrides`,
      overrides
    );
  }

  async clearActionOverrides(id: string): Promise<Action> {
    return this.del<Action>(`/actions/${encodeURIComponent(id)}/overrides`);
  }

  // ── Levels ───────────────────────────────────────────────────────────────

  async getLevels(): Promise<Level[]> {
    return this.request<Level[]>('/levels');
  }

  async createLevel(level: Omit<Level, 'id'>): Promise<Level> {
    return this.post<Level>('/levels', level);
  }

  async updateLevel(id: number, patch: Partial<Level>): Promise<Level> {
    return this.post<Level>(`/levels/${id}`, patch);
  }

  async deleteLevel(id: number): Promise<void> {
    return this.del<void>(`/levels/${id}`);
  }

  // ── Point Types ──────────────────────────────────────────────────────────

  async getPointTypes(): Promise<PointType[]> {
    return this.request<PointType[]>('/point-types');
  }

  async getPointType(slug: string): Promise<PointType> {
    return this.request<PointType>(`/point-types/${encodeURIComponent(slug)}`);
  }

  async createPointType(type: PointType): Promise<PointType> {
    return this.post<PointType>('/point-types', type);
  }

  async updatePointType(slug: string, patch: Partial<PointType>): Promise<PointType> {
    return this.post<PointType>(`/point-types/${encodeURIComponent(slug)}`, patch);
  }

  async deletePointType(slug: string): Promise<void> {
    return this.del<void>(`/point-types/${encodeURIComponent(slug)}`);
  }

  /** Convert one point currency into another (atomic, FOR UPDATE locked). */
  async convertPoints(
    fromType: string,
    toType: string,
    amount: number
  ): Promise<{ debited: number; credited: number; event_id: number }> {
    return this.post(`/point-types/${encodeURIComponent(fromType)}/convert`, {
      to_type: toType,
      amount,
    });
  }

  async getPointTypeConversions(): Promise<PointTypeConversion[]> {
    return this.request<PointTypeConversion[]>('/point-type-conversions');
  }

  async createPointTypeConversion(
    conversion: Omit<PointTypeConversion, 'id'>
  ): Promise<PointTypeConversion> {
    return this.post<PointTypeConversion>('/point-type-conversions', conversion);
  }

  async updatePointTypeConversion(
    id: number,
    patch: Partial<PointTypeConversion>
  ): Promise<PointTypeConversion> {
    return this.post<PointTypeConversion>(`/point-type-conversions/${id}`, patch);
  }

  async deletePointTypeConversion(id: number): Promise<void> {
    return this.del<void>(`/point-type-conversions/${id}`);
  }

  // ── Redemptions ──────────────────────────────────────────────────────────

  async getRedemptionItems(): Promise<RedemptionItem[]> {
    return this.request<RedemptionItem[]>('/redemptions/items');
  }

  async getRedemptionItem(id: number): Promise<RedemptionItem> {
    return this.request<RedemptionItem>(`/redemptions/items/${id}`);
  }

  async createRedemptionItem(
    item: Omit<RedemptionItem, 'id'>
  ): Promise<RedemptionItem> {
    return this.post<RedemptionItem>('/redemptions/items', item);
  }

  async updateRedemptionItem(
    id: number,
    patch: Partial<RedemptionItem>
  ): Promise<RedemptionItem> {
    return this.post<RedemptionItem>(`/redemptions/items/${id}`, patch);
  }

  async deleteRedemptionItem(id: number): Promise<void> {
    return this.del<void>(`/redemptions/items/${id}`);
  }

  /** Redeem one item — server debits points and may return a coupon code. */
  async redeem(itemId: number): Promise<Redemption> {
    return this.post<Redemption>('/redemptions', { item_id: itemId });
  }

  /** Caller's redemption history. */
  async getMyRedemptions(): Promise<Redemption[]> {
    return this.request<Redemption[]>('/redemptions/me');
  }

  // ── Submissions (UGC) ────────────────────────────────────────────────────

  async getSubmissions(
    status?: 'pending' | 'approved' | 'rejected'
  ): Promise<Submission[]> {
    return this.request<Submission[]>(`/submissions${this.qs({ status })}`);
  }

  async submitAchievement(
    actionId: string,
    evidence: string,
    evidenceUrl = ''
  ): Promise<Submission> {
    return this.post<Submission>('/submissions', {
      action_id: actionId,
      evidence,
      evidence_url: evidenceUrl,
    });
  }

  async approveSubmission(id: number): Promise<Submission> {
    return this.post<Submission>(`/submissions/${id}/approve`, {});
  }

  async rejectSubmission(id: number, reason = ''): Promise<Submission> {
    return this.post<Submission>(`/submissions/${id}/reject`, { reason });
  }

  // ── Webhooks ─────────────────────────────────────────────────────────────

  async getWebhooks(): Promise<Webhook[]> {
    return this.request<Webhook[]>('/webhooks');
  }

  async getWebhook(id: number): Promise<Webhook> {
    return this.request<Webhook>(`/webhooks/${id}`);
  }

  async createWebhook(
    webhook: Omit<Webhook, 'id' | 'created_at'>
  ): Promise<Webhook> {
    return this.post<Webhook>('/webhooks', webhook);
  }

  async updateWebhook(id: number, patch: Partial<Webhook>): Promise<Webhook> {
    return this.post<Webhook>(`/webhooks/${id}`, patch);
  }

  async deleteWebhook(id: number): Promise<void> {
    return this.del<void>(`/webhooks/${id}`);
  }

  async getWebhookLog(id: number): Promise<Record<string, unknown>[]> {
    return this.request(`/webhooks/${id}/log`);
  }

  async clearWebhookLog(id: number): Promise<void> {
    return this.del<void>(`/webhooks/${id}/log`);
  }

  // ── Rules (badge auto-award conditions) ──────────────────────────────────

  async getRules(): Promise<Rule[]> {
    return this.request<Rule[]>('/rules');
  }

  async getRule(id: number): Promise<Rule> {
    return this.request<Rule>(`/rules/${id}`);
  }

  async createRule(rule: Omit<Rule, 'id'>): Promise<Rule> {
    return this.post<Rule>('/rules', rule);
  }

  async updateRule(id: number, patch: Partial<Rule>): Promise<Rule> {
    return this.post<Rule>(`/rules/${id}`, patch);
  }

  async deleteRule(id: number): Promise<void> {
    return this.del<void>(`/rules/${id}`);
  }

  // ── API Keys (admin, cross-site) ─────────────────────────────────────────

  async getApiKeys(): Promise<ApiKey[]> {
    return this.request<ApiKey[]>('/api-keys');
  }

  /** Create — response includes the full key ONCE; store it client-side. */
  async createApiKey(
    label: string
  ): Promise<ApiKey & { key: string }> {
    return this.post('/api-keys', { label });
  }

  async deleteApiKey(id: number): Promise<void> {
    return this.del<void>(`/api-keys/${id}`);
  }

  async revokeApiKey(id: number): Promise<ApiKey> {
    return this.post<ApiKey>(`/api-keys/${id}/revoke`, {});
  }

  // ── Settings ─────────────────────────────────────────────────────────────

  async getCohortSettings(): Promise<Record<string, unknown>> {
    return this.request('/cohort-settings');
  }

  async updateCohortSettings(
    settings: Record<string, unknown>
  ): Promise<Record<string, unknown>> {
    return this.post('/cohort-settings', settings);
  }

  async getEmailSettings(): Promise<Record<string, unknown>> {
    return this.request('/settings/emails');
  }

  async updateEmailSettings(
    settings: Record<string, unknown>
  ): Promise<Record<string, unknown>> {
    return this.post('/settings/emails', settings);
  }

  // ── Discovery (capabilities, abilities, openapi) ─────────────────────────

  async getCapabilities(): Promise<Record<string, unknown>> {
    return this.request('/capabilities');
  }

  /** WP Abilities API (WP 6.9+) fallback enumeration. */
  async getAbilities(): Promise<Record<string, unknown>> {
    return this.request('/abilities');
  }

  /** Full OpenAPI 3.0 spec — same content as `audit/openapi.json`. */
  async getOpenApiSpec(): Promise<Record<string, unknown>> {
    return this.request('/openapi.json');
  }
}
