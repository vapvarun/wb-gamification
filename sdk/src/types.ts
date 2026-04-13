export interface WBGamificationConfig {
  baseUrl: string;
  apiKey?: string;
  nonce?: string;
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
