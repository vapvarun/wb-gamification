# WB Gamification — Technology Stack (2026–2031)

> Ultra-modern architecture designed to stay competitive for 5 years.
> Every decision documented with the reasoning so future contributors understand *why*, not just *what*.

---

## Design Principles

1. **API-first, rendering-agnostic** — the gamification engine works without WordPress templates. Any frontend consumes it.
2. **AI-native, not AI bolt-on** — behavioral events feed an intelligence layer from day one, not added later.
3. **Standards-compliant** — OpenBadges 3.0, ActivityPub, W3C Verifiable Credentials. Member data is portable.
4. **Privacy by architecture** — GDPR compliance is in the data model, not patched in after.
5. **Performance at scale** — designed for 100K+ member communities, not broken at 10K.
6. **Progressively enhanced** — every feature works on shared hosting. Advanced tiers (Redis, WebSockets) enhance but don't require.

---

## Layer Overview

```
┌─────────────────────────────────────────────────────────────┐
│  CLIENTS                                                     │
│  Public blocks (Interactivity API, server-rendered)          │
│  Admin UI (PHP templates + Settings API + Interactivity API) │
│  Block editor (React — Gutenberg only)                       │
│  React Native App │ Headless Frontend │ External Apps        │
│  AI Agents │ Fediverse (ActivityPub)                         │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│  API LAYER                                                   │
│  REST v1  │  GraphQL  │  Webhooks  │  ActivityPub           │
│  OpenBadges 3.0  │  WP-CLI  │  JS SDK  │  RN SDK           │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│  REAL-TIME LAYER                                             │
│  SSE (broadcasts)  │  WebSocket (optional, enterprise)      │
│  Event Bus  │  Redis Pub/Sub (multi-server)                 │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│  INTELLIGENCE LAYER (AI)                                     │
│  Behavioral Event Stream  │  Churn Prediction               │
│  Adaptive Challenges  │  Badge Recommendations              │
│  Anti-Gaming Detection  │  Natural Language Admin           │
│  Content Quality Scoring  │  Pluggable LLM Provider        │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│  CORE ENGINE (PHP 8.3+)                                      │
│  Points  │  Badges  │  Levels  │  Streaks  │  Challenges   │
│  Kudos  │  Leaderboards  │  Teams  │  Rewards                │
│  Registry (Extension API)  │  Action Scheduler (async)      │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│  DATA LAYER                                                  │
│  Custom MySQL Tables  │  Object Cache (Redis/Memcached)     │
│  Time-Series Optimization  │  Auto-Pruning  │  Archiving    │
└─────────────────────────────────────────────────────────────┘
```

---

## 1. PHP Engine Layer

### PHP Version Strategy

| Year | Minimum | Target | Why |
|---|---|---|---|
| 2026 | PHP 8.1 | PHP 8.3 | Enums, readonly classes, fibers (8.1). Typed class constants (8.3). |
| 2027 | PHP 8.2 | PHP 8.4 | Property hooks, asymmetric visibility (8.4). |
| 2028+ | PHP 8.3 | PHP 8.5+ | Track WordPress minimum. |

### PHP 8.x Features We Use

**Enums** — point action types, badge trigger types, challenge periods:
```php
enum GamActionCategory: string {
    case BuddyPress = 'buddypress';
    case Commerce   = 'commerce';
    case Learning   = 'learning';
    case Social     = 'social';
    case Manual     = 'manual';
}

enum ChallengePeriod: string {
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';
    case None    = 'none';
}
```

**Readonly classes** — immutable value objects for events:
```php
readonly class PointsAwardedEvent {
    public function __construct(
        public readonly int    $userId,
        public readonly string $actionId,
        public readonly int    $points,
        public readonly int    $objectId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
```

**Fibers** — async-like processing for badge evaluation without blocking:
```php
// Badge engine evaluates multiple conditions concurrently
$fiber = new \Fiber( function () use ( $userId, $action ): void {
    WB_Gam_Badge_Engine::evaluate_async( $userId, $action );
} );
$fiber->start();
```

**First-class callables** — cleaner hook registration:
```php
add_action( 'wb_gamification_points_awarded', WB_Gam_Streak_Engine::record_activity(...) );
add_filter( 'wb_gamification_should_award',   WB_Gam_Rate_Limiter::check(...) );
```

### Async Processing — Action Scheduler

All point awards in high-volume communities process asynchronously via Action Scheduler (the battle-tested queue library, battle-proven at WooCommerce scale):

```php
// Fast path — record event immediately, process async
as_enqueue_async_action(
    'wb_gamification_process_point_award',
    [ 'user_id' => $userId, 'action_id' => $actionId, 'object_id' => $objectId ],
    'wb-gamification'
);

// Slow path — actual award happens in background worker
add_action( 'wb_gamification_process_point_award', function ( $userId, $actionId, $objectId ) {
    WB_Gam_Points_Engine::award( $userId, $actionId, 0, $objectId );
} );
```

**Why Action Scheduler over custom queue:**
- Battle-tested at WooCommerce scale (billions of jobs)
- Admin UI for queue inspection
- Automatic retry on failure
- Horizontal scaling without shared-state problems

### Object Caching Strategy

```
Layer 1: WordPress object cache (in-memory per request)
Layer 2: Redis / Memcached (cross-request, persistent)
Layer 3: Database (source of truth)
```

Cache keys and TTLs:
```php
// User total points — busted on every award
wp_cache_set( "wb_gam_total_{$userId}", $total, 'wb_gamification', 300 );

// Leaderboard — expensive query, cache aggressively
wp_cache_set( "wb_gam_leaderboard_week", $rows, 'wb_gamification', 60 );

// User level — recalculate only on points change
wp_cache_set( "wb_gam_level_{$userId}", $level, 'wb_gamification', 600 );
```

### Event Sourcing Pattern

The points ledger is an **append-only event log** — never update a row, only insert. This gives:
- Full audit trail
- Point-in-time reconstruction of any member's state
- Safe concurrent writes
- Easy data export/deletion (GDPR)

```php
// Every action = new row. Never UPDATE.
INSERT INTO wb_gam_points (user_id, action_id, points, object_id, created_at)
VALUES (?, ?, ?, ?, NOW());

// "Balance" is always computed, never stored
SELECT COALESCE(SUM(points), 0) FROM wb_gam_points WHERE user_id = ?;
// → cached aggressively, recomputed on event
```

---

## 2. AI Intelligence Layer

The AI layer is **pluggable** — swap the provider without changing the engine interface. Default: lightweight rule-based signals. Enhanced: LLM + ML via provider adapter.

### Provider Interface

```php
interface WB_Gam_AI_Provider {
    public function predict_churn( int $userId ): float;             // 0.0–1.0 risk score
    public function suggest_challenge( int $userId ): array;         // Challenge config
    public function score_content_quality( string $content ): float; // 0.0–1.0
    public function detect_farming( int $userId, string $actionId ): bool;
    public function recommend_badges( int $userId ): array;          // Badge IDs to surface
    public function natural_language_query( string $query ): array;  // Admin query → data
}

// Default: rule-based, no external API needed
class WB_Gam_AI_RuleBased implements WB_Gam_AI_Provider { ... }

// Enhanced: OpenAI/Anthropic via REST
class WB_Gam_AI_OpenAI implements WB_Gam_AI_Provider { ... }

// Enhanced: Anthropic Claude
class WB_Gam_AI_Anthropic implements WB_Gam_AI_Provider { ... }

// Future: on-device via ONNX/local model
class WB_Gam_AI_Local implements WB_Gam_AI_Provider { ... }
```

### AI Feature 1: Churn Prediction

Three behavioral signals combined into a risk score:

```php
class WB_Gam_Churn_Predictor {

    public function score( int $userId ): float {
        // Signal 1: Posting frequency decay
        $frequency_decay = $this->get_frequency_decay( $userId );

        // Signal 2: Reciprocity ratio (replies given vs received)
        // Members who stop getting responses disengage quietly
        $reciprocity = $this->get_reciprocity_ratio( $userId );

        // Signal 3: Response latency (time until someone replies to their posts)
        // Increasing latency = community not responding to them
        $response_latency = $this->get_avg_response_latency( $userId );

        // Signal 4: Streak state
        $streak_broken_recently = $this->streak_broken_in_last_7_days( $userId );

        // Weighted composite — tunable per community type
        return $this->composite_score(
            $frequency_decay,
            $reciprocity,
            $response_latency,
            $streak_broken_recently
        );
    }
}
```

**Admin action when risk > threshold:**
- Automated: trigger a personal message from community manager
- Automated: award a surprise kudos from admin
- Dashboard alert: "14 members at churn risk this week"

### AI Feature 2: Adaptive Challenge Difficulty

Challenges auto-adjust based on member's recent activity baseline:

```php
class WB_Gam_Adaptive_Challenges {

    public function generate_for_user( int $userId ): array {
        $baseline = $this->get_activity_baseline( $userId, days: 14 );
        // If member averages 3 posts/week, challenge = 5 posts (achievable stretch)
        // If member averages 20 posts/week, challenge = 30 posts (still a stretch)
        return [
            'action_id'    => $this->select_best_action( $userId ),
            'target'       => (int) ceil( $baseline * 1.4 ), // 40% stretch
            'bonus_points' => $this->calculate_bonus( $baseline ),
            'period'       => 'weekly',
        ];
    }
}
```

### AI Feature 3: Anti-Gaming Detection

Pattern recognition on suspicious behavior — without blocking legitimate power users:

```php
class WB_Gam_Anti_Gaming {

    private const SIGNALS = [
        'identical_content_bursts',   // Same post content repeatedly
        'coordinated_kudos_rings',    // User A kudos User B, B kudos A, all day
        'new_account_velocity',       // Account < 7 days old, maxing daily caps
        'off-hours_bulk_actions',     // 3am burst of 50 forum replies
        'single_action_mono-farming', // 90%+ of points from one action type
    ];

    public function is_suspicious( int $userId, string $actionId ): bool {
        $signals = array_filter(
            self::SIGNALS,
            fn( $signal ) => $this->check_signal( $userId, $actionId, $signal )
        );

        // Flag for review if 2+ signals fire — don't auto-block
        if ( count( $signals ) >= 2 ) {
            $this->flag_for_review( $userId, $signals );
            return true;
        }

        return false;
    }
}
```

### AI Feature 4: Content Quality Scoring

Used for quality-weighted point awards — reactions received amplified by content quality:

```php
// Rule-based quality signals (no LLM required for basic tier)
class WB_Gam_Content_Quality {

    public function score( string $content ): float {
        $signals = [
            'word_count'      => $this->word_count_score( $content ),    // 0–1
            'has_question'    => $this->has_question_mark( $content ),   // 0 or 0.2
            'specificity'     => $this->specificity_score( $content ),   // 0–1
            'no_spam_phrases' => $this->spam_phrase_check( $content ),   // 0 or 1
        ];
        return array_sum( $signals ) / count( $signals );
    }
}

// LLM-enhanced quality scoring (optional, higher accuracy)
// POST /wp-json/wb-gamification/v1/ai/score-content
// { "content": "...", "provider": "anthropic" }
```

### AI Feature 5: Natural Language Admin Interface

Admin can query their community data in plain English:

```
"How many members earned their first badge this month?"
→ SELECT COUNT(DISTINCT user_id) FROM wb_gam_user_badges
  WHERE MONTH(earned_at) = MONTH(NOW())
  AND user_id NOT IN (SELECT user_id FROM wb_gam_user_badges WHERE earned_at < DATE_FORMAT(NOW(), '%Y-%m-01'))

"Which actions are most correlated with 90-day retention?"
→ Complex cohort query → formatted as admin widget
```

**Implementation:** Admin text input → LLM generates SQL query → executed with strict read-only permissions → result formatted as widget. The LLM has access only to schema definitions, never raw data.

### AI Feature 6: Zero-Party Data Collection Loops

Gamification mechanics that collect preference data *intentionally shared by members*:

```
"What brings you to this community?" (quiz) → +20 points + profile enrichment
"Which topics interest you most?" (preferences) → Unlock personalized challenge feed
"How often do you plan to engage?" (goal setting) → Streak target customization
```

This generates training signal for personalization without passive tracking. GDPR-safe by design.

---

## 3. Real-Time Layer

### Protocol Decision

| Use Case | Protocol | Reason |
|---|---|---|
| Badge award notification | SSE | One-way push, no WS upgrade needed |
| Leaderboard live update | SSE | Broadcast to many, no client → server |
| Points toast notification | SSE | Instant, low-latency push |
| Level-up celebration | SSE | Server pushes one event |
| Live group challenge progress | SSE | Periodic broadcast |
| Live chat (future) | WebSocket | Bidirectional required |
| Collaborative real-time (future) | WebSocket | Bidirectional required |

**SSE wins for gamification because:**
- Works on shared hosting (no WS upgrade needed)
- HTTP/2 multiplexing means one connection handles all event types
- Automatic reconnection built into browser API
- No sticky session requirement for horizontal scaling

### SSE Architecture

```php
// PHP endpoint: /wp-json/wb-gamification/v1/events/stream
class WB_Gam_SSE_Controller extends WP_REST_Controller {

    public function stream( $request ): void {
        // Headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' ); // Disable nginx buffering

        $userId   = get_current_user_id();
        $lastId   = (int) $request->get_param( 'last_event_id' );
        $timeout  = 30; // seconds
        $interval = 2;  // poll DB every 2 seconds
        $elapsed  = 0;

        while ( $elapsed < $timeout ) {
            $events = $this->get_events_since( $userId, $lastId );

            foreach ( $events as $event ) {
                echo "id: {$event->id}\n";
                echo "event: {$event->type}\n";
                echo 'data: ' . wp_json_encode( $event->payload ) . "\n\n";
                $lastId = $event->id;
            }

            // Keepalive
            echo ": keepalive\n\n";
            ob_flush();
            flush();

            sleep( $interval );
            $elapsed += $interval;
        }

        // Client will auto-reconnect
        exit;
    }
}
```

### Multi-Server SSE (Redis Pub/Sub)

For sites on multiple app servers, SSE uses Redis as the event bus:

```php
// Publisher: fires when any gamification event happens
class WB_Gam_Event_Bus {
    public static function publish( string $channel, array $payload ): void {
        if ( self::redis_available() ) {
            $redis->publish( "wb_gam:{$channel}", wp_json_encode( $payload ) );
        } else {
            // Fallback: write to DB events table, SSE polls it
            self::write_to_db( $channel, $payload );
        }
    }
}

// Subscriber: each SSE stream connection subscribes
$redis->subscribe( "wb_gam:user:{$userId}", function ( $message ) {
    echo "data: {$message}\n\n";
    flush();
} );
```

### Client-Side (Interactivity API + SSE)

```javascript
// assets/interactivity/index.js
import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store( 'wb-gamification', {
    state: {
        points: 0,
        level: 1,
        toastQueue: [],
    },
    actions: {
        connectSSE() {
            const stream = new EventSource(
                '/wp-json/wb-gamification/v1/events/stream',
                { withCredentials: true }
            );

            stream.addEventListener( 'points_awarded', ( e ) => {
                const data = JSON.parse( e.data );
                state.points += data.points;
                actions.showToast( `+${data.points} points` );
            } );

            stream.addEventListener( 'level_up', ( e ) => {
                actions.triggerLevelUpCelebration( JSON.parse( e.data ) );
            } );

            stream.addEventListener( 'badge_earned', ( e ) => {
                actions.showBadgeUnlock( JSON.parse( e.data ) );
            } );
        },

        showToast( message ) {
            // Smart batching — don't show 10 toasts in 2 seconds
            state.toastQueue.push( message );
            if ( state.toastQueue.length === 1 ) {
                setTimeout( actions.flushToasts, 500 );
            }
        },
    },
} );
```

---

## 4. API Layer

### REST API (Stable, v1)

Versioned from day one. Breaking changes get a v2, v1 stays supported.

```
Namespace: /wp-json/wb-gamification/v1/

Members:
GET    /members/{id}                    Full gamification profile
GET    /members/{id}/points             Points + history (paginated)
GET    /members/{id}/badges             Earned badges
GET    /members/{id}/level              Level + progress to next
GET    /members/{id}/streak             Streak data
GET    /members/{id}/kudos              Kudos given/received
GET    /members/{id}/challenges         Active challenges + progress
GET    /members/{id}/leaderboard-rank   Current rank (each period)

Leaderboards:
GET    /leaderboard                     ?period=day|week|month|all&limit=10&scope_type=bp_group|challenge|custom&scope_id=X
GET    /leaderboard/team                Scoped aggregate (scope_type=challenge|bp_group|custom)

Community:
GET    /badges                          All available badges (with locked state for user)
GET    /actions                         All registered actions (for app discovery)
GET    /challenges                      Active challenges
GET    /levels                          All level definitions

AI:
GET    /ai/churn-risks                  Members at churn risk (admin only)
GET    /ai/challenge/suggest/{user_id}  Personalized challenge for member
POST   /ai/query                        Natural language admin query → data

Real-time:
GET    /events/stream                   SSE event stream (authenticated)

Write (authenticated):
POST   /points/award                    Manual award with message
POST   /kudos                           Give peer kudos
DELETE /points/{id}                     Revoke points
POST   /badges/award/{user_id}/{badge}  Admin-award a badge

OpenBadges 3.0:
GET    /openbadges/assertion/{id}       OB3 assertion (public, verifiable)
GET    /openbadges/badge-class/{id}     OB3 badge class definition
GET    /openbadges/issuer               OB3 issuer profile
POST   /openbadges/verify               Verify an external OB3 assertion
```

### GraphQL (Phase 2)

For mobile apps and headless frontends that need flexible queries:

```graphql
type Member {
    id: ID!
    points: Int!
    level: Level!
    badges: [Badge!]!
    streak: Streak
    leaderboardRank(period: Period!): Int
    recentActivity(limit: Int): [PointEvent!]!
}

type Query {
    member(id: ID!): Member
    leaderboard(period: Period!, limit: Int, groupId: ID): [LeaderboardEntry!]!
    challenges(active: Boolean): [Challenge!]!
    badges: [Badge!]!
}

type Mutation {
    awardKudos(receiverId: ID!, message: String): KudosResult!
    joinChallenge(challengeId: ID!): ChallengeProgress!
}

type Subscription {
    memberUpdated(id: ID!): Member!       # Real-time via SSE bridge
    leaderboardChanged(period: Period!): [LeaderboardEntry!]!
}
```

### Webhooks (Outbound Events)

Every significant event fires a webhook to registered endpoints. Site owners integrate with n8n, Zapier, Make, custom CRMs:

```php
class WB_Gam_Webhooks {
    const EVENTS = [
        'member.points_awarded',
        'member.badge_earned',
        'member.level_up',
        'member.streak_milestone',
        'member.streak_broken',
        'member.challenge_completed',
        'member.kudos_received',
        'member.churn_risk_flagged',    // AI signal
        'leaderboard.weekly_winner',
        'challenge.ended',
    ];

    public static function fire( string $event, array $payload ): void {
        $endpoints = self::get_registered_endpoints( $event );
        foreach ( $endpoints as $endpoint ) {
            as_enqueue_async_action(           // Non-blocking, via Action Scheduler
                'wb_gam_deliver_webhook',
                [ 'url' => $endpoint, 'event' => $event, 'payload' => $payload ],
                'wb-gamification-webhooks'
            );
        }
    }
}

// Payload structure (consistent, versioned)
{
    "event":   "member.badge_earned",
    "version": "1",
    "ts":      "2026-03-12T11:00:00Z",
    "site":    "https://example.com",
    "data": {
        "user_id":    42,
        "badge_id":   "top_contributor",
        "badge_name": "Top Contributor",
        "earned_at":  "2026-03-12T11:00:00Z"
    }
}
```

### ActivityPub Integration (Phase 3)

Gamification events federate to the open social web. Members' achievements visible on Mastodon, Threads, Ghost:

```php
// Badge earned → ActivityPub Award activity
class WB_Gam_ActivityPub {

    public static function emit_badge_award( int $userId, string $badgeId ): void {
        if ( ! function_exists( 'activitypub_send_activity' ) ) {
            return; // ActivityPub plugin not active
        }

        $activity = [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'type'      => 'Award',
            'actor'     => activitypub_get_actor_url( $userId ),
            'object'    => [
                'type'    => 'Link',
                'href'    => home_url( "/gamification/badges/{$badgeId}" ),
                'name'    => WB_Gam_Badge_Engine::get_badge_name( $badgeId ),
                'summary' => WB_Gam_Badge_Engine::get_badge_description( $badgeId ),
            ],
            'published' => current_time( 'c' ),
        ];

        activitypub_send_activity_to_followers( $activity, $userId );
    }
}
```

### OpenBadges 3.0 (Phase 1)

Every badge can be issued as an OB3-compliant verifiable credential. Portable to LinkedIn, HR systems, digital wallets:

```php
class WB_Gam_OpenBadges3 {

    /**
     * Issue an OpenBadges 3.0 assertion (W3C Verifiable Credential format).
     */
    public static function issue_assertion( int $userId, string $badgeId ): array {
        return [
            '@context'          => [
                'https://www.w3.org/ns/credentials/v2',
                'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
            ],
            'id'                => home_url( "/wp-json/wb-gamification/v1/openbadges/assertion/" . self::generate_id( $userId, $badgeId ) ),
            'type'              => [ 'VerifiableCredential', 'OpenBadgeCredential' ],
            'issuer'            => self::get_issuer(),
            'validFrom'         => current_time( 'c' ),
            'credentialSubject' => [
                'id'            => self::get_member_did( $userId ),     // DID or email
                'type'          => [ 'AchievementSubject' ],
                'achievement'   => self::get_badge_class( $badgeId ),
            ],
            'proof'             => self::sign_assertion( $userId, $badgeId ), // Ed25519 signature
        ];
    }

    private static function get_issuer(): array {
        return [
            'id'   => home_url( '/wp-json/wb-gamification/v1/openbadges/issuer' ),
            'type' => 'Profile',
            'name' => get_bloginfo( 'name' ),
            'url'  => home_url(),
        ];
    }
}
```

---

## 5. Frontend Architecture

### Guiding Rule: Right Tool Per Context

No single JS framework rules everything. The rendering context determines the tool.

| Context | Tool | Why |
|---|---|---|
| Public block frontend (leaderboard, member-points, etc.) | **WordPress Interactivity API** | PHP server-renders full HTML. Store adds reactivity. Best LCP, SEO-safe, ~10KB. |
| Block editor `edit.js` inspector panels | **React + TypeScript** | Unavoidable — Gutenberg IS React |
| Admin settings pages (points, levels, toggles) | **PHP + Settings API** | WordPress-native, no JS needed |
| Admin dynamic UI (rule builder live preview, analytics period switcher) | **Interactivity API** | Same paradigm as frontend — one JS approach site-wide |
| Admin data tables (badge library, rule list) | **`WP_List_Table`** | WordPress-native, built for this |

**React appears in exactly one place: the Gutenberg block `edit.js`. Nowhere else.**

---

### Public Blocks — WordPress Interactivity API

PHP renders the full HTML on the server. The Interactivity API store adds reactive behavior (period switching, live updates, toast notifications) without a client-side bootstrap:

```js
// store.js — same file works for both public frontend and admin widgets
import { store, getContext } from '@wordpress/interactivity';

store( 'wb-gamification/leaderboard', {
    state: { period: 'week', loading: false, entries: [] },
    actions: {
        async switchPeriod() {
            const ctx = getContext();
            state.loading = true;
            const res = await fetch(
                `/wp-json/wb-gamification/v1/leaderboard?period=${ctx.period}&scope_type=${ctx.scopeType}&scope_id=${ctx.scopeId}`
            );
            state.entries = await res.json();
            state.loading = false;
        },
    },
} );
```

```html
<!-- PHP renders this. Interactivity API hydrates it. No React bootstrap. -->
<div data-wp-interactive="wb-gamification/leaderboard"
     data-wp-context='{"period":"week","scopeType":"global","scopeId":0}'>

    <button data-wp-on--click="actions.switchPeriod"
            data-wp-context='{"period":"day"}'>Today</button>

    <button data-wp-on--click="actions.switchPeriod"
            data-wp-context='{"period":"week"}'>This Week</button>

    <div data-wp-class--is-loading="state.loading">
        <!-- Server-rendered rows; replaced by Interactivity API on period switch -->
    </div>
</div>
```

---

### Block Editor — React (Unavoidable)

React is the Gutenberg editor. Keep it strictly contained to `edit.js`:

```
block.json     ← metadata, view/editor script separation
edit.tsx       ← React component (block editor only — never ships to public frontend)
save.tsx       ← null (dynamic block) or static HTML
view.js        ← Interactivity API store (the public frontend render)
```

`"viewScript"` in `block.json` always points to the Interactivity API store, never React.

---

### Admin UI — PHP + Settings API + Interactivity API

```php
// Standard settings form — no JS required
add_settings_section( 'wb_gam_points', 'Points Settings', null, 'wb-gamification' );
add_settings_field( 'wb_gam_points_wp_publish_post', 'Publish Post', ... );

// Badge library + rule list
class WB_Gam_Rules_List_Table extends WP_List_Table { ... }

// Dynamic admin behavior (rule condition live preview, analytics period switch)
// → same Interactivity API store as the public leaderboard block
// → no React in wp-admin, ever
```

---

### Build Tooling

| Tool | Scope |
|---|---|
| `@wordpress/scripts` | Compiles all JS/TS — handles dependency extraction, WP externals |
| TypeScript 5.x | All JS: Interactivity API stores + block editor `edit.tsx` |
| ESLint + `@wordpress/eslint-plugin` | WP-aware lint rules across all JS |
| Prettier | Formatting |

One build pipeline. Two output targets: `view.js` (Interactivity API, public + admin) and `index.js` (React, block editor only).

---

## 6. Mobile Layer  <!-- was §5 -->

### 2026: PWA (Progressive Web App)

Service worker + Web App Manifest makes the community gamification experience installable on mobile home screen without an app store:

```javascript
// service-worker.js
const CACHE = 'wb-gam-v1';
const STATIC = [ '/wp-content/plugins/wb-gamification/assets/css/frontend.css' ];

// Cache badge images, leaderboard for offline viewing
self.addEventListener( 'install', ( e ) => {
    e.waitUntil( caches.open( CACHE ).then( ( c ) => c.addAll( STATIC ) ) );
} );

// Push notifications for badge awards
self.addEventListener( 'push', ( e ) => {
    const data = e.data.json();
    e.waitUntil(
        self.registration.showNotification( data.title, {
            body: data.body,
            icon: data.badge_image,
            badge: '/wb-gam-badge-icon.png',
            data: { url: data.url },
        } )
    );
} );
```

### 2027: React Native SDK

A white-label mobile SDK that site owners embed in their React Native app:

```typescript
// npm install @wbcom/wb-gamification-rn-sdk
import { WBGamificationProvider, usePoints, useLeaderboard, BadgeShowcase } from '@wbcom/wb-gamification-rn-sdk';

// Provider wraps your app
<WBGamificationProvider
    siteUrl="https://example.com"
    userId={currentUser.id}
    authToken={authToken}
>
    <YourApp />
</WBGamificationProvider>

// Hooks consume the API
const { points, level, streak } = usePoints();
const { entries, period, setPeriod } = useLeaderboard();

// Pre-built components
<BadgeShowcase userId={userId} />
<LeaderboardWidget period="week" limit={10} />
<StreakCounter userId={userId} />
<ChallengeCard challenge={activeChallenge} />
```

**On-device AI** (iOS 2027+, Android 2027+):
```typescript
// Personalized challenge suggestions run locally — no server roundtrip
import { OnDeviceGamificationAI } from '@wbcom/wb-gamification-rn-sdk';

const { suggestedChallenge } = await OnDeviceGamificationAI.suggest( {
    recentActivity: userActivityHistory,
    preferences: userPreferences,
    // Uses Apple Foundation Models / Android ML Kit
    // Behavioral data never leaves device
} );
```

---

## 6. Privacy & Compliance

### Data Model — GDPR by Design

```php
class WB_Gam_Privacy {

    /**
     * GDPR Right to Erasure — delete all gamification data for a user.
     * Registered with WordPress Privacy API.
     */
    public static function erase( int $userId ): array {
        global $wpdb;

        $tables = [
            'wb_gam_points',
            'wb_gam_user_badges',
            'wb_gam_streaks',
            'wb_gam_challenge_log',
            'wb_gam_kudos',
            'wb_gam_partners',
            'wb_gam_member_prefs',
        ];

        foreach ( $tables as $table ) {
            $wpdb->delete( $wpdb->prefix . $table, [ 'user_id' => $userId ] );
        }

        // Also delete as giver in kudos
        $wpdb->delete( $wpdb->prefix . 'wb_gam_kudos', [ 'giver_id' => $userId ] );

        wp_cache_flush_group( 'wb_gamification' );

        return [ 'items_removed' => true, 'items_retained' => false ];
    }

    /**
     * GDPR Right to Portability — export all gamification data.
     */
    public static function export( int $userId ): array {
        return [
            'points_history' => WB_Gam_Points_Engine::get_history( $userId ),
            'badges_earned'  => WB_Gam_Badge_Engine::get_earned( $userId ),
            'level'          => WB_Gam_Level_Engine::get_level_for_user( $userId ),
            'streak'         => WB_Gam_Streak_Engine::get( $userId ),
            'kudos_given'    => WB_Gam_Kudos_Engine::get_given( $userId ),
            'kudos_received' => WB_Gam_Kudos_Engine::get_received( $userId ),
        ];
    }
}

// Register with WordPress Privacy API
add_filter( 'wp_privacy_personal_data_erasers',  [ WB_Gam_Privacy::class, 'register_eraser' ] );
add_filter( 'wp_privacy_personal_data_exporters', [ WB_Gam_Privacy::class, 'register_exporter' ] );
```

### Consent-Aware Event Tracking

```php
class WB_Gam_Consent {

    /**
     * Check if a specific gamification data use is consented.
     * Integrates with popular consent plugins (CookieYes, Complianz, etc.)
     */
    public static function has_consent( int $userId, string $purpose ): bool {
        // Purpose: 'gamification_tracking' | 'ai_personalization' | 'leaderboard_display'
        return (bool) apply_filters( 'wb_gamification_has_consent', true, $userId, $purpose );
    }
}

// In PointsEngine::process_action()
if ( ! WB_Gam_Consent::has_consent( $userId, 'gamification_tracking' ) ) {
    return false; // Silently skip — no points without consent
}

// In AI layer
if ( ! WB_Gam_Consent::has_consent( $userId, 'ai_personalization' ) ) {
    return $this->rule_based_fallback( $userId ); // Degrade gracefully
}
```

### Opt-In Defaults

```php
// Member preferences — privacy-respecting defaults
$defaults = [
    'leaderboard_opt_out' => false,   // In leaderboard by default (can opt out)
    'show_rank_publicly'  => true,    // Rank visible (can hide)
    'ai_personalization'  => false,   // AI personalization OFF by default (opt IN)
    'federated_activity'  => false,   // ActivityPub sharing OFF by default (opt IN)
    'notification_mode'   => 'smart', // Smart batching (not every point)
];
```

---

## 7. Developer Experience

### WP-CLI Commands

```bash
# Award points
wp wb-gamification points award --user=42 --action=manual --points=100 --message="Great contribution this month"

# Check member status
wp wb-gamification member status --user=42

# Run leaderboard recalculation
wp wb-gamification leaderboard recalculate --period=week

# Prune old event logs
wp wb-gamification logs prune --before=6months --dry-run
wp wb-gamification logs prune --before=6months

# Export member data (GDPR)
wp wb-gamification export user --user=42 --format=json

# Test AI provider connection
wp wb-gamification ai test --provider=anthropic

# List all registered actions
wp wb-gamification actions list

# Seed test data
wp wb-gamification dev seed --members=100 --events=1000
```

### WordPress Playground Blueprint

One-click demo — no install required:

```json
{
    "$schema": "https://playground.wordpress.net/blueprint-schema.json",
    "plugins": [ "buddypress", "bbpress", "wb-gamification" ],
    "steps": [
        { "step": "activatePlugin", "pluginPath": "wb-gamification/wb-gamification.php" },
        { "step": "runPHP", "code": "<?php wb_gam_seed_demo_data(); ?>" }
    ]
}
```

### Testing Stack

```
Unit tests:        PHPUnit 11 + Brain Monkey (WP function mocks)
Integration tests: WordPress test suite + real DB
E2E tests:         Playwright (via MCP tools)
Static analysis:   PHPStan Level 8 + Rector for upgrades
JS tests:          Jest (Interactivity API stores) + @testing-library/react (block editor edit.tsx only)
CI/CD:             GitHub Actions
```

```yaml
# .github/workflows/ci.yml
jobs:
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install
      - run: vendor/bin/phpstan analyse --level=8

  phpunit:
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3', '8.4']
        wp:  ['6.4', '6.5', '6.6', 'latest']
    steps:
      - run: phpunit --coverage-clover coverage.xml

  e2e:
    steps:
      - run: npx playwright test
```

---

## 8. Five-Year Roadmap

### 2026 — Foundation
**Ship:** Core engine, SSE real-time, OpenBadges 3.0, REST API v1, PWA, AI churn signals (rule-based), zero-party data consent model, GDPR tools

**Technology:** PHP 8.1–8.3, Interactivity API, Action Scheduler, SSE, OB3 issuance

**Wins over competition:**
- Native BuddyPress integration (no add-on)
- OpenBadges 3.0 (no SaaS platform has this)
- Zero-config with setup wizard
- Performance-safe from day one

### 2027 — Intelligence + Mobile
**Ship:** GraphQL API, Webhook event bus, Adaptive AI challenges, AI badge recommendations, React Native SDK, on-device AI personalization, ActivityPub draft

**Technology:** PHP 8.4 (property hooks), GraphQL, React Native SDK, Apple Foundation Models / Android ML Kit

**Wins over competition:**
- Mobile SDK (Bettermode still has no native app)
- AI-personalized challenges (no SaaS platform has adaptive difficulty)
- Headless-ready (growing market segment)

### 2028 — Federation + Portable Identity
**Ship:** ActivityPub federation (gamification events in fediverse), DID-based member identity, OB3 digital wallet export, cross-platform leaderboards

**Technology:** ActivityPub, W3C DIDs, OB3 with Ed25519 signatures

**Wins over competition:**
- Portable reputation (member's badges follow them across platforms)
- Federated achievements discoverable on Mastodon/Threads/Ghost
- First WordPress gamification plugin with true portable identity

### 2029–2031 — Platform
**Ship:** Cross-community reputation aggregation, AI community health companion, decentralized gamification governance, certification marketplace

**Technology:** W3C Verifiable Credentials ecosystem, agent-ready APIs (for AI-to-AI interactions), WASM for client-side gamification logic

**Position:** Not just a WordPress plugin. The open-standard gamification infrastructure layer for community platforms worldwide.

---

## Technology Decision Log

| Decision | Chosen | Rejected | Reason |
|---|---|---|---|
| Async queue | Action Scheduler | Custom queue | Battle-tested at WooCommerce scale |
| Real-time | SSE primary | WebSockets primary | SSE works on shared hosting; WS breaks at load balancers |
| AI interface | Pluggable provider | Hardcoded OpenAI | Swap providers; local models for privacy |
| Event log | Append-only insert | UPDATE rows | Audit trail, safe concurrency, GDPR erasure |
| Public block frontend | Interactivity API | React | Server-rendered HTML + ~10KB store vs React's 130KB bootstrap; better LCP, SEO-safe |
| Admin UI | PHP templates + Settings API + Interactivity API | React in wp-admin | React is overkill for forms and list tables; Interactivity API handles all dynamic admin behavior |
| Block editor | React + TypeScript | — | Unavoidable — Gutenberg IS React; keep strictly in `edit.tsx`, never `view.js` |
| Leaderboard scope | `scope_type` + `scope_id` params | `group_id` only | Zero-dependency core — BP group is one scope type, not the only one |
| Mobile | React Native SDK | Flutter / native | Largest developer ecosystem; web-to-mobile code reuse |
| Credentials standard | OpenBadges 3.0 | Custom badge system | W3C standard, portable, HR-system compatible |
| Federation | ActivityPub | Custom federation | Open standard, WordPress ecosystem momentum |
| Consent | Opt-in AI, opt-out basics | All opt-in or all opt-out | GDPR risk management + user trust |
| Points decay | ❌ Never implemented | — | Research: universally resented, no retention benefit |
| GraphQL | Phase 2 (not day 1) | Day 1 | REST covers 90% of cases; GraphQL complexity not justified at launch |
| on-device AI | Phase 2 (mobile SDK) | Server-only | Privacy win + latency win; technology ready in 2027 |
