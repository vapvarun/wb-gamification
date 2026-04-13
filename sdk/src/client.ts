import type {
  WBGamificationConfig,
  Member,
  Badge,
  LeaderboardEntry,
  Challenge,
  KudosEntry,
  PointsHistoryEntry,
  Action,
} from './types';

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

  private async request<T>(
    path: string,
    options: RequestInit = {}
  ): Promise<T> {
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

    return response.json();
  }

  // Members
  async getMember(userId: number): Promise<Member> {
    return this.request<Member>(`/members/${userId}`);
  }

  // Leaderboard
  async getLeaderboard(
    period: 'all' | 'week' | 'month' | 'day' = 'all',
    limit = 10
  ): Promise<LeaderboardEntry[]> {
    return this.request<LeaderboardEntry[]>(
      `/leaderboard?period=${period}&limit=${limit}`
    );
  }

  // Badges
  async getBadges(): Promise<Badge[]> {
    return this.request<Badge[]>('/badges');
  }

  async getMemberBadges(userId: number): Promise<Badge[]> {
    return this.request<Badge[]>(`/members/${userId}/badges`);
  }

  // Challenges
  async getChallenges(): Promise<Challenge[]> {
    return this.request<Challenge[]>('/challenges');
  }

  // Points
  async awardPoints(
    userId: number,
    points: number,
    actionId = 'manual',
    message = ''
  ): Promise<{ success: boolean }> {
    return this.request('/events', {
      method: 'POST',
      body: JSON.stringify({
        user_id: userId,
        action_id: actionId,
        points,
        message,
      }),
    });
  }

  // Events
  async submitEvent(
    userId: number,
    actionId: string,
    meta: Record<string, unknown> = {}
  ): Promise<{ success: boolean }> {
    return this.request('/events', {
      method: 'POST',
      body: JSON.stringify({
        user_id: userId,
        action_id: actionId,
        metadata: meta,
      }),
    });
  }

  // Kudos
  async giveKudos(
    receiverId: number,
    message: string
  ): Promise<{ success: boolean }> {
    return this.request('/kudos', {
      method: 'POST',
      body: JSON.stringify({ receiver_id: receiverId, message }),
    });
  }

  // Actions
  async getActions(): Promise<Action[]> {
    return this.request<Action[]>('/actions');
  }

  // OpenAPI Spec
  async getOpenApiSpec(): Promise<Record<string, unknown>> {
    return this.request('/openapi.json');
  }
}
