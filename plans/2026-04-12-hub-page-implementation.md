# Gamification Hub Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Gamification Hub — a single auto-created WordPress page that connects all 11 blocks into a card-based dashboard with slide-in detail panels and a smart nudge bar.

**Architecture:** New `hub` Gutenberg block with server-side render. NudgeEngine returns contextual "what to do next" message. Card grid shows 6 summary cards. Each card click opens a slide-in panel that renders the existing block via `render_block()` inside a `<template>` tag (no AJAX, no duplication). Hub shortcode `[wb_gam_hub]` delegates to the block. Page auto-created on activation. WordPress Interactivity API handles panel open/close.

**Tech Stack:** PHP 8.1, WordPress 6.5+ Block API, Interactivity API, Lucide icons (CSS font), CSS custom properties

**Design Spec:** `plans/frontend-hub-flow-spec.md`
**Working Prototype:** `.superpowers/brainstorm/98241-1776001804/content/flow-architecture-v2.html`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Engine/NudgeEngine.php` | **Create** | 7-priority nudge logic, returns message + panel + icon |
| `blocks/hub/block.json` | **Create** | Block metadata — no attributes, no editor script |
| `blocks/hub/render.php` | **Create** | Full hub render: nudge bar, stats row, card grid, panel templates |
| `assets/css/hub.css` | **Create** | Hub-specific CSS with `--gam-*` design tokens, responsive breakpoints |
| `assets/interactivity/hub.js` | **Create** | Panel open/close, ESC key, focus trap, URL param panel pre-open |
| `src/Engine/ShortcodeHandler.php` | **Modify** | Add `[wb_gam_hub]` shortcode registration + render method |
| `src/Engine/Installer.php` | **Modify** | Auto-create "Gamification" page on activation |
| `wb-gamification.php` | **Modify** | Register `hub` block, register + enqueue `hub.css`, register hub interactivity module |
| `tests/Unit/Engine/NudgeEngineTest.php` | **Create** | Unit tests for all 7 nudge priorities |

---

## Task 1: NudgeEngine — Smart "What To Do Next" Logic

**Files:**
- Create: `src/Engine/NudgeEngine.php`
- Create: `tests/Unit/Engine/NudgeEngineTest.php`

### Overview

Returns a single nudge for a user based on 7 priority rules (first match wins). Result cached in user transient for 5 minutes to avoid recalculating on every page load.

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Engine/NudgeEngineTest.php`:

```php
<?php

namespace WBGam\Tests\Unit\Engine;

use WBGam\Engine\NudgeEngine;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class NudgeEngineTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_nudge_returns_array_with_required_keys(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        // Mock all engine calls to return empty/zero — triggers fallback nudge.
        Functions\when( 'WBGam\\Engine\\ChallengeEngine::get_active_challenges' )->justReturn( array() );
        Functions\when( 'WBGam\\Engine\\PointsEngine::get_total' )->justReturn( 0 );
        Functions\when( 'WBGam\\Engine\\LevelEngine::get_level_for_user' )->justReturn( null );
        Functions\when( 'WBGam\\Engine\\LevelEngine::get_next_level' )->justReturn( null );
        Functions\when( 'WBGam\\Engine\\StreakEngine::get_streak' )->justReturn( array( 'current_streak' => 0, 'longest_streak' => 0, 'last_active' => '' ) );
        Functions\when( 'WBGam\\Engine\\BadgeEngine::get_user_badges' )->justReturn( array() );
        Functions\when( 'WBGam\\Engine\\PointsEngine::get_period_total' )->justReturn( 0 );

        $nudge = NudgeEngine::get_nudge( 1 );

        $this->assertIsArray( $nudge );
        $this->assertArrayHasKey( 'message', $nudge );
        $this->assertArrayHasKey( 'panel', $nudge );
        $this->assertArrayHasKey( 'icon', $nudge );
        $this->assertNotEmpty( $nudge['message'] );
    }

    public function test_fallback_nudge_when_no_conditions_match(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );

        Functions\when( 'WBGam\\Engine\\ChallengeEngine::get_active_challenges' )->justReturn( array() );
        Functions\when( 'WBGam\\Engine\\PointsEngine::get_total' )->justReturn( 50 );
        Functions\when( 'WBGam\\Engine\\LevelEngine::get_level_for_user' )->justReturn( null );
        Functions\when( 'WBGam\\Engine\\LevelEngine::get_next_level' )->justReturn( null );
        Functions\when( 'WBGam\\Engine\\StreakEngine::get_streak' )->justReturn( array( 'current_streak' => 0, 'longest_streak' => 0, 'last_active' => '' ) );
        Functions\when( 'WBGam\\Engine\\BadgeEngine::get_user_badges' )->justReturn( array() );
        Functions\when( 'WBGam\\Engine\\PointsEngine::get_period_total' )->justReturn( 50 );

        $nudge = NudgeEngine::get_nudge( 1 );

        $this->assertSame( 'earning', $nudge['panel'] );
        $this->assertStringContainsString( '50', $nudge['message'] );
    }

    public function test_cached_nudge_is_returned_without_recalculation(): void {
        $cached = array(
            'message' => 'Cached nudge',
            'panel'   => 'badges',
            'icon'    => 'award',
        );

        Functions\when( 'get_transient' )->justReturn( $cached );

        $nudge = NudgeEngine::get_nudge( 1 );

        $this->assertSame( 'Cached nudge', $nudge['message'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification && php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit tests/Unit/Engine/NudgeEngineTest.php`

Expected: FAIL — `Class 'WBGam\Engine\NudgeEngine' not found`

- [ ] **Step 3: Implement NudgeEngine**

Create `src/Engine/NudgeEngine.php`:

```php
<?php
/**
 * Smart Nudge Engine — contextual "what to do next" logic.
 *
 * Returns a single nudge for the given user based on 7 priority rules.
 * First matching rule wins. Result cached for 5 minutes.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class NudgeEngine {

    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Get the highest-priority nudge for a user.
     *
     * @param int $user_id WordPress user ID.
     * @return array{message: string, panel: string, icon: string}
     */
    public static function get_nudge( int $user_id ): array {
        $cache_key = 'wb_gam_nudge_' . $user_id;
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) && isset( $cached['message'] ) ) {
            return $cached;
        }

        $nudge = self::calculate( $user_id );

        set_transient( $cache_key, $nudge, self::CACHE_TTL );

        return $nudge;
    }

    /**
     * Run the 7-priority nudge rules. First match wins.
     *
     * @param int $user_id WordPress user ID.
     * @return array{message: string, panel: string, icon: string}
     */
    private static function calculate( int $user_id ): array {
        // Priority 1: Unclaimed challenge reward.
        $challenges = ChallengeEngine::get_active_challenges( $user_id );
        foreach ( $challenges as $ch ) {
            if ( ! empty( $ch['completed'] ) && empty( $ch['claimed'] ) ) {
                return array(
                    'message' => sprintf(
                        /* translators: 1: challenge title, 2: bonus points */
                        __( 'You completed %1$s! Claim your +%2$s bonus points', 'wb-gamification' ),
                        $ch['title'] ?? __( 'a challenge', 'wb-gamification' ),
                        number_format_i18n( $ch['bonus_points'] ?? 0 )
                    ),
                    'panel' => 'challenges',
                    'icon'  => 'trophy',
                );
            }
        }

        // Priority 2: Close to level-up (within 20% of next threshold).
        $total_points = PointsEngine::get_total( $user_id );
        $next_level   = LevelEngine::get_next_level( $user_id );
        if ( $next_level ) {
            $needed    = $next_level['min_points'] - $total_points;
            $threshold = (int) ( $next_level['min_points'] * 0.20 );
            if ( $needed > 0 && $needed <= $threshold ) {
                return array(
                    'message' => sprintf(
                        /* translators: 1: points needed, 2: level name */
                        __( "You're %1\$s points from %2\$s — keep going!", 'wb-gamification' ),
                        number_format_i18n( $needed ),
                        $next_level['name'] ?? __( 'the next level', 'wb-gamification' )
                    ),
                    'panel' => 'earning',
                    'icon'  => 'trending-up',
                );
            }
        }

        // Priority 3: Streak at risk (active streak > 3, no activity today).
        $streak = StreakEngine::get_streak( $user_id );
        if (
            ( $streak['current_streak'] ?? 0 ) > 3
            && ! empty( $streak['last_active'] )
            && gmdate( 'Y-m-d' ) !== substr( $streak['last_active'], 0, 10 )
        ) {
            return array(
                'message' => sprintf(
                    /* translators: %s: streak day count */
                    __( "Don't break your %s-day streak! Do any activity to keep it", 'wb-gamification' ),
                    number_format_i18n( $streak['current_streak'] )
                ),
                'panel' => 'earning',
                'icon'  => 'flame',
            );
        }

        // Priority 4: New badges earned (unseen — earned in last 7 days as proxy).
        $badges = BadgeEngine::get_user_badges( $user_id );
        $recent = 0;
        $week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        foreach ( $badges as $b ) {
            if ( ( $b['earned_at'] ?? '' ) >= $week_ago ) {
                ++$recent;
            }
        }
        if ( $recent > 0 ) {
            return array(
                'message' => sprintf(
                    /* translators: %s: number of new badges */
                    _n(
                        'You earned %s new badge! Check it out',
                        'You earned %s new badges! Check them out',
                        $recent,
                        'wb-gamification'
                    ),
                    number_format_i18n( $recent )
                ),
                'panel' => 'badges',
                'icon'  => 'award',
            );
        }

        // Priority 5: Active challenge with progress > 50%.
        foreach ( $challenges as $ch ) {
            if ( empty( $ch['completed'] ) && ! empty( $ch['target'] ) ) {
                $progress = ( $ch['progress'] ?? 0 ) / $ch['target'];
                if ( $progress > 0.5 ) {
                    $remaining = $ch['target'] - ( $ch['progress'] ?? 0 );
                    return array(
                        'message' => sprintf(
                            /* translators: 1: challenge title, 2: percent, 3: remaining count */
                            __( '%1$s is %2$s%% done — %3$s more to complete it', 'wb-gamification' ),
                            $ch['title'] ?? __( 'Your challenge', 'wb-gamification' ),
                            number_format_i18n( (int) ( $progress * 100 ) ),
                            number_format_i18n( $remaining )
                        ),
                        'panel' => 'challenges',
                        'icon'  => 'target',
                    );
                }
            }
        }

        // Priority 6: No challenges joined.
        if ( empty( $challenges ) ) {
            return array(
                'message' => __( 'Try a challenge to earn bonus points', 'wb-gamification' ),
                'panel'   => 'challenges',
                'icon'    => 'target',
            );
        }

        // Priority 7: Fallback.
        $week_points = PointsEngine::get_period_total( $user_id, 'week' );
        return array(
            'message' => sprintf(
                /* translators: %s: points earned this week */
                __( "Keep going! You've earned %s points this week", 'wb-gamification' ),
                number_format_i18n( $week_points )
            ),
            'panel' => 'earning',
            'icon'  => 'zap',
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification && php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit tests/Unit/Engine/NudgeEngineTest.php`

Expected: 3 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Engine/NudgeEngine.php tests/Unit/Engine/NudgeEngineTest.php
git commit -m "feat: add NudgeEngine — 7-priority smart nudge logic with caching"
```

---

## Task 2: Hub Block — block.json + render.php

**Files:**
- Create: `blocks/hub/block.json`
- Create: `blocks/hub/render.php`
- Modify: `wb-gamification.php` (add `'hub'` to `register_blocks()` array and register hub assets)

### Overview

The hub block renders: nudge bar, stats row (4 cards), card grid (6 cards), and 6 `<template>` tags containing pre-rendered block output for the slide-in panel.

---

- [ ] **Step 1: Create block.json**

Create `blocks/hub/block.json`:

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "wb-gamification/hub",
    "version": "1.0.0",
    "title": "Gamification Hub",
    "category": "widgets",
    "description": "Member gamification dashboard — stats, badges, challenges, leaderboard, and more in a connected card layout with slide-in detail panels.",
    "keywords": [ "gamification", "hub", "dashboard", "points", "badges" ],
    "supports": {
        "html": false,
        "align": [ "wide", "full" ],
        "multiple": false
    },
    "render": "file:./render.php",
    "textdomain": "wb-gamification"
}
```

- [ ] **Step 2: Create render.php**

Create `blocks/hub/render.php`:

```php
<?php
/**
 * Gamification Hub block — render callback.
 *
 * Renders: nudge bar, stats row, card grid, slide-in panel with
 * pre-rendered block templates.
 *
 * @package WB_Gamification
 * @since   1.0.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

use WBGam\Engine\NudgeEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\StreakEngine;
use WBGam\Engine\ChallengeEngine;
use WBGam\Engine\KudosEngine;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

// Enqueue hub-specific assets.
wp_enqueue_style( 'wb-gamification-hub' );
wp_enqueue_script_module( 'wb-gamification-hub' );

$user_id = get_current_user_id();

// Guest state.
if ( ! $user_id ) {
    $wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'gam-page gam-page--guest' ) );
    printf(
        '<div %s><div class="gam-nudge"><div class="gam-nudge__body"><div class="gam-nudge__text">%s</div></div><a href="%s" class="gam-nudge__action">%s <i class="lucide-log-in"></i></a></div></div>',
        $wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        esc_html__( 'Log in to see your gamification progress, badges, and rank.', 'wb-gamification' ),
        esc_url( wp_login_url( get_permalink() ) ),
        esc_html__( 'Log in', 'wb-gamification' )
    );
    return;
}

// Pre-open panel from URL param (e.g. ?panel=badges from toast link).
$pre_open = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Gather data.
$nudge       = NudgeEngine::get_nudge( $user_id );
$total_pts   = PointsEngine::get_total( $user_id );
$level       = LevelEngine::get_level_for_user( $user_id );
$next_level  = LevelEngine::get_next_level( $user_id );
$progress    = LevelEngine::get_progress_percent( $user_id );
$badges      = BadgeEngine::get_user_badges( $user_id );
$badge_count = count( $badges );
$streak      = StreakEngine::get_streak( $user_id );
$challenges  = ChallengeEngine::get_active_challenges( $user_id );
$active_ch   = count( array_filter( $challenges, fn( $c ) => empty( $c['completed'] ) ) );
$user_rank   = LeaderboardEngine::get_user_rank( $user_id, 'week' );
$kudos_recv  = KudosEngine::get_received_count( $user_id );
$kudos_sent  = KudosEngine::get_daily_sent_count( $user_id );
$actions_ct  = count( Registry::get_actions() );

// Badge locked count (total defs minus earned).
global $wpdb;
$total_badges = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$locked_count = max( 0, $total_badges - $badge_count );

// Level display.
$level_name = $level['name'] ?? __( 'Starter', 'wb-gamification' );
$next_pts   = $next_level ? $next_level['min_points'] : 0;

// Card definitions for the grid.
$cards = array(
    'badges'      => array(
        'icon'  => 'award',
        'title' => __( 'My Badges', 'wb-gamification' ),
        'desc'  => sprintf( '%s %s &middot; %s %s', $badge_count, __( 'earned', 'wb-gamification' ), $locked_count, __( 'locked', 'wb-gamification' ) ),
        'pill'  => $badge_count > 0 ? sprintf( '%d %s', $badge_count, __( 'earned', 'wb-gamification' ) ) : '',
        'pill_type' => 'success',
        'link'  => __( 'View all', 'wb-gamification' ),
        'block' => 'badge-showcase',
        'attrs' => array( 'show_locked' => true ),
    ),
    'challenges'  => array(
        'icon'  => 'target',
        'title' => __( 'Challenges', 'wb-gamification' ),
        'desc'  => $active_ch > 0
            ? sprintf( '%d %s', $active_ch, __( 'active', 'wb-gamification' ) )
            : __( 'No active challenges', 'wb-gamification' ),
        'pill'      => $active_ch > 0 ? sprintf( '%d %s', $active_ch, __( 'active', 'wb-gamification' ) ) : '',
        'pill_type' => 'warning',
        'link'  => __( 'View details', 'wb-gamification' ),
        'block' => 'challenges',
        'attrs' => array(),
    ),
    'leaderboard' => array(
        'icon'  => 'trophy',
        'title' => __( 'Leaderboard', 'wb-gamification' ),
        'desc'  => $user_rank
            ? sprintf( __( "You're rank #%s this week", 'wb-gamification' ), $user_rank )
            : __( 'Not ranked yet', 'wb-gamification' ),
        'pill'      => $user_rank ? '#' . $user_rank : '',
        'pill_type' => 'accent',
        'link'  => __( 'See rankings', 'wb-gamification' ),
        'block' => 'leaderboard',
        'attrs' => array( 'period' => 'week' ),
    ),
    'earning'     => array(
        'icon'  => 'lightbulb',
        'title' => __( 'How to Earn', 'wb-gamification' ),
        'desc'  => sprintf( '%d %s', $actions_ct, __( 'ways to earn points', 'wb-gamification' ) ),
        'pill'  => '',
        'pill_type' => '',
        'link'  => __( 'Explore', 'wb-gamification' ),
        'block' => 'earning-guide',
        'attrs' => array(),
    ),
    'kudos'       => array(
        'icon'  => 'heart-handshake',
        'title' => __( 'Kudos', 'wb-gamification' ),
        'desc'  => sprintf( '%d %s &middot; %d %s', $kudos_recv, __( 'received', 'wb-gamification' ), $kudos_sent, __( 'given', 'wb-gamification' ) ),
        'pill'      => $kudos_recv > 0 ? sprintf( '%d %s', $kudos_recv, __( 'received', 'wb-gamification' ) ) : '',
        'pill_type' => 'info',
        'link'  => __( 'View feed', 'wb-gamification' ),
        'block' => 'kudos-feed',
        'attrs' => array(),
    ),
    'history'     => array(
        'icon'  => 'history',
        'title' => __( 'Activity', 'wb-gamification' ),
        'desc'  => __( 'Points history & streaks', 'wb-gamification' ),
        'pill'  => '',
        'pill_type' => '',
        'link'  => __( 'View history', 'wb-gamification' ),
        'block' => 'points-history',
        'attrs' => array(),
    ),
);

$wrapper_attrs = get_block_wrapper_attributes( array(
    'class'                => 'gam-page',
    'data-wp-interactive'  => 'wb-gamification/hub',
    'data-wp-context'      => wp_json_encode( array( 'preOpen' => $pre_open ) ),
) );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

    <!-- NUDGE BAR -->
    <div class="gam-nudge">
        <div class="gam-nudge__icon"><i class="lucide-<?php echo esc_attr( $nudge['icon'] ); ?>"></i></div>
        <div class="gam-nudge__body">
            <div class="gam-nudge__label"><?php esc_html_e( 'Your next move', 'wb-gamification' ); ?></div>
            <div class="gam-nudge__text"><?php echo esc_html( $nudge['message'] ); ?></div>
        </div>
        <button class="gam-nudge__action"
            data-wp-on--click="actions.openPanel"
            data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'panel' => $nudge['panel'] ) ) ); ?>'>
            <?php esc_html_e( 'Go', 'wb-gamification' ); ?> <i class="lucide-arrow-right"></i>
        </button>
    </div>

    <!-- STATS ROW -->
    <div class="gam-stats">
        <div class="gam-stat">
            <div class="gam-stat__icon"><i class="lucide-star"></i></div>
            <div class="gam-stat__value"><?php echo esc_html( number_format_i18n( $total_pts ) ); ?></div>
            <div class="gam-stat__label"><?php esc_html_e( 'Total Points', 'wb-gamification' ); ?></div>
        </div>
        <div class="gam-stat">
            <div class="gam-stat__icon"><i class="lucide-trending-up"></i></div>
            <div class="gam-stat__value"><?php echo esc_html( $level_name ); ?></div>
            <div class="gam-stat__label"><?php esc_html_e( 'Current Level', 'wb-gamification' ); ?></div>
            <?php if ( $next_level ) : ?>
                <div class="gam-stat__bar"><div class="gam-stat__bar-fill" style="width:<?php echo esc_attr( $progress ); ?>%;"></div></div>
                <div class="gam-stat__sub">
                    <?php
                    printf(
                        '%s / %s %s',
                        esc_html( number_format_i18n( $total_pts ) ),
                        esc_html( number_format_i18n( $next_pts ) ),
                        esc_html( sprintf( __( 'to %s', 'wb-gamification' ), $next_level['name'] ) )
                    );
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="gam-stat">
            <div class="gam-stat__icon"><i class="lucide-award"></i></div>
            <div class="gam-stat__value"><?php echo esc_html( number_format_i18n( $badge_count ) ); ?></div>
            <div class="gam-stat__label"><?php esc_html_e( 'Badges Earned', 'wb-gamification' ); ?></div>
        </div>
        <div class="gam-stat">
            <div class="gam-stat__icon"><i class="lucide-flame"></i></div>
            <div class="gam-stat__value"><?php echo esc_html( number_format_i18n( $streak['current_streak'] ?? 0 ) ); ?></div>
            <div class="gam-stat__label"><?php esc_html_e( 'Day Streak', 'wb-gamification' ); ?></div>
            <?php if ( ( $streak['longest_streak'] ?? 0 ) > 0 ) : ?>
                <div class="gam-stat__sub">
                    <?php printf( '%s: %s %s', esc_html__( 'Best', 'wb-gamification' ), esc_html( number_format_i18n( $streak['longest_streak'] ) ), esc_html__( 'days', 'wb-gamification' ) ); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CARD GRID -->
    <div class="gam-cards">
        <?php foreach ( $cards as $key => $card ) : ?>
            <div class="gam-card"
                data-wp-on--click="actions.openPanel"
                data-wp-context='<?php echo esc_attr( wp_json_encode( array( 'panel' => $key ) ) ); ?>'>
                <div class="gam-card__head">
                    <div class="gam-card__icon"><i class="lucide-<?php echo esc_attr( $card['icon'] ); ?>"></i></div>
                    <?php if ( ! empty( $card['pill'] ) ) : ?>
                        <span class="gam-pill gam-pill--<?php echo esc_attr( $card['pill_type'] ); ?>"><?php echo esc_html( $card['pill'] ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="gam-card__title"><?php echo esc_html( $card['title'] ); ?></div>
                <div class="gam-card__desc"><?php echo wp_kses_post( $card['desc'] ); ?></div>
                <div class="gam-card__link"><?php echo esc_html( $card['link'] ); ?> <i class="lucide-chevron-right"></i></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- PANEL TEMPLATES (pre-rendered, cloned into panel on click) -->
    <?php foreach ( $cards as $key => $card ) : ?>
        <template id="gam-tpl-<?php echo esc_attr( $key ); ?>">
            <?php
            echo render_block( array(
                'blockName'    => 'wb-gamification/' . $card['block'],
                'attrs'        => $card['attrs'],
                'innerBlocks'  => array(),
                'innerHTML'    => '',
                'innerContent' => array(),
            ) );
            ?>
        </template>
    <?php endforeach; ?>

    <!-- SLIDE-IN PANEL -->
    <div class="gam-panel-backdrop"
        data-wp-class--active="state.panelOpen"
        data-wp-on--click="actions.closePanel">
        <div class="gam-panel"
            role="dialog"
            aria-modal="true"
            aria-label="<?php esc_attr_e( 'Detail panel', 'wb-gamification' ); ?>"
            data-wp-on--click="actions.stopPropagation">
            <div class="gam-panel__header">
                <button class="gam-panel__back"
                    aria-label="<?php esc_attr_e( 'Close', 'wb-gamification' ); ?>"
                    data-wp-on--click="actions.closePanel">
                    <i class="lucide-x"></i>
                </button>
                <span class="gam-panel__title" data-wp-text="state.panelTitle"></span>
            </div>
            <div class="gam-panel__body" data-wp-html="state.panelContent"></div>
        </div>
    </div>

</div>
```

- [ ] **Step 3: Register the hub block in wb-gamification.php**

In `wb-gamification.php`, modify the `register_blocks()` method — add `'hub'` to the blocks array and add `'earning-guide'` if missing:

```php
public function register_blocks(): void {
    $blocks = array( 'leaderboard', 'member-points', 'badge-showcase', 'level-progress', 'challenges', 'streak', 'top-members', 'kudos-feed', 'year-recap', 'points-history', 'earning-guide', 'hub' );
    foreach ( $blocks as $block ) {
        $path = WB_GAM_PATH . 'blocks/' . $block;
        if ( file_exists( $path . '/block.json' ) ) {
            register_block_type( $path );
        }
    }
}
```

In the `enqueue_assets()` method, add hub CSS and JS registration:

```php
// Hub page assets — registered, enqueued only when hub block renders.
wp_register_style(
    'wb-gamification-hub',
    WB_GAM_URL . 'assets/css/hub.css',
    array(),
    WB_GAM_VERSION
);
wp_register_script_module(
    'wb-gamification-hub',
    WB_GAM_URL . 'assets/interactivity/hub.js',
    array( '@wordpress/interactivity' ),
    WB_GAM_VERSION
);
```

- [ ] **Step 4: Commit**

```bash
git add blocks/hub/ wb-gamification.php
git commit -m "feat: add hub block — render.php with nudge bar, stats, cards, panel templates"
```

---

## Task 3: Hub CSS — Theme-Independent Design System

**Files:**
- Create: `assets/css/hub.css`

### Overview

Complete hub stylesheet using `--gam-*` CSS custom properties. Must work on any WP theme. Responsive at 640px and 390px breakpoints.

---

- [ ] **Step 1: Create hub.css**

Create `assets/css/hub.css` — copy the full CSS from the working prototype (`.superpowers/brainstorm/98241-1776001804/content/flow-architecture-v2.html`) with these exact design tokens and class names. The prototype CSS is production-ready.

Key sections to include:
- `:root` with all `--gam-*` custom properties
- `.gam-page` max-width container
- `.gam-nudge` bar (flex layout, accent border-left, icon circle)
- `.gam-stats` (4-column grid, stat cards with icons, progress bars)
- `.gam-cards` (3-column grid, card hover effects, pills, links)
- `.gam-panel-backdrop` + `.gam-panel` (fixed overlay, slide-in transition, sticky header)
- `.gam-pill` variants (success, warning, info, accent)
- Responsive: `@media (max-width: 640px)` and `@media (max-width: 390px)`
- `.gam-page--guest` styles for logged-out state

- [ ] **Step 2: Commit**

```bash
git add assets/css/hub.css
git commit -m "feat: add hub.css — theme-independent design tokens, responsive 390px"
```

---

## Task 4: Hub Interactivity — Panel Open/Close with Accessibility

**Files:**
- Create: `assets/interactivity/hub.js`

---

- [ ] **Step 1: Create hub.js**

Create `assets/interactivity/hub.js`:

```javascript
/**
 * WB Gamification Hub — Interactivity API store.
 *
 * Handles slide-in panel open/close, ESC key, focus trap,
 * and URL param pre-open (?panel=badges).
 *
 * @since 1.0.0
 */

import { store, getContext } from '@wordpress/interactivity';

const PANEL_TITLES = {
    badges:      'My Badges',
    challenges:  'Challenges',
    leaderboard: 'Leaderboard',
    earning:     'How to Earn Points',
    kudos:       'Kudos Feed',
    history:     'Points History',
};

const { state, actions } = store( 'wb-gamification/hub', {
    state: {
        panelOpen: false,
        panelTitle: '',
        panelContent: '',
        _activePanel: '',
    },

    actions: {
        openPanel() {
            const ctx = getContext();
            const key = ctx.panel;
            if ( ! key ) return;

            const tpl = document.getElementById( 'gam-tpl-' + key );
            if ( ! tpl ) return;

            state._activePanel = key;
            state.panelTitle = PANEL_TITLES[ key ] || '';
            state.panelContent = tpl.innerHTML;
            state.panelOpen = true;

            document.body.style.overflow = 'hidden';
        },

        closePanel() {
            state.panelOpen = false;
            state.panelContent = '';
            state._activePanel = '';
            document.body.style.overflow = '';
        },

        stopPropagation( event ) {
            event.stopPropagation();
        },
    },

    callbacks: {
        init() {
            // ESC key handler.
            document.addEventListener( 'keydown', ( e ) => {
                if ( e.key === 'Escape' && state.panelOpen ) {
                    actions.closePanel();
                }
            } );

            // Pre-open panel from URL param (?panel=badges).
            const ctx = getContext();
            if ( ctx.preOpen && PANEL_TITLES[ ctx.preOpen ] ) {
                // Delay to ensure templates are in DOM.
                requestAnimationFrame( () => {
                    const fakeCtx = { panel: ctx.preOpen };
                    const tpl = document.getElementById( 'gam-tpl-' + ctx.preOpen );
                    if ( tpl ) {
                        state._activePanel = ctx.preOpen;
                        state.panelTitle = PANEL_TITLES[ ctx.preOpen ] || '';
                        state.panelContent = tpl.innerHTML;
                        state.panelOpen = true;
                        document.body.style.overflow = 'hidden';
                    }
                } );
            }
        },
    },
} );
```

- [ ] **Step 2: Commit**

```bash
git add assets/interactivity/hub.js
git commit -m "feat: add hub interactivity — panel open/close, ESC key, URL pre-open"
```

---

## Task 5: Hub Shortcode + Page Auto-Creation

**Files:**
- Modify: `src/Engine/ShortcodeHandler.php`
- Modify: `src/Engine/Installer.php`

---

- [ ] **Step 1: Add hub shortcode to ShortcodeHandler**

In `src/Engine/ShortcodeHandler.php`, add to the `init()` method:

```php
add_shortcode( 'wb_gam_hub', array( __CLASS__, 'render_hub' ) );
```

Add the render method (after the last shortcode method):

```php
/**
 * Render [wb_gam_hub].
 *
 * @param array|string $atts Shortcode attributes (none used).
 * @return string HTML output.
 */
public static function render_hub( $atts = array() ): string {
    return self::block( 'hub', array() );
}
```

- [ ] **Step 2: Add page auto-creation to Installer**

In `src/Engine/Installer.php`, add at the end of the `install()` method:

```php
// Auto-create Gamification hub page if it doesn't exist.
self::maybe_create_hub_page();
```

Add the static method:

```php
/**
 * Create the Gamification hub page if it doesn't exist.
 *
 * Checks for existing page by meta key to avoid duplicates on reactivation.
 */
private static function maybe_create_hub_page(): void {
    // Check if hub page already exists.
    $existing = get_posts( array(
        'post_type'   => 'page',
        'meta_key'    => '_wb_gam_hub_page',
        'meta_value'  => '1',
        'numberposts' => 1,
        'post_status' => array( 'publish', 'draft', 'private', 'trash' ),
        'fields'      => 'ids',
    ) );

    if ( ! empty( $existing ) ) {
        // Ensure option is set even if page was found (handles edge case).
        update_option( 'wb_gam_hub_page_id', $existing[0], false );
        return;
    }

    $page_id = wp_insert_post( array(
        'post_title'   => __( 'Gamification', 'wb-gamification' ),
        'post_content' => '<!-- wp:wb-gamification/hub /-->',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => get_current_user_id() ?: 1,
    ) );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_post_meta( $page_id, '_wb_gam_hub_page', '1' );
        update_option( 'wb_gam_hub_page_id', $page_id, false );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Engine/ShortcodeHandler.php src/Engine/Installer.php
git commit -m "feat: add [wb_gam_hub] shortcode + auto-create hub page on activation"
```

---

## Task 6: Lucide Icons — Enqueue CSS Font

**Files:**
- Modify: `wb-gamification.php`

---

- [ ] **Step 1: Register Lucide icon font**

In `wb-gamification.php`, in the `enqueue_assets()` method, add before the hub CSS registration:

```php
// Lucide icon font — used by hub page.
wp_register_style(
    'lucide-icons',
    'https://unpkg.com/lucide-static@latest/font/lucide.css',
    array(),
    '0.469.0'
);
```

Update the hub CSS registration to depend on Lucide:

```php
wp_register_style(
    'wb-gamification-hub',
    WB_GAM_URL . 'assets/css/hub.css',
    array( 'lucide-icons' ),
    WB_GAM_VERSION
);
```

- [ ] **Step 2: Commit**

```bash
git add wb-gamification.php
git commit -m "feat: register Lucide icon CSS font as hub dependency"
```

---

## Task 7: Browser Verification

**No files changed — verification only.**

---

- [ ] **Step 1: Deactivate and reactivate plugin to trigger page creation**

Via WP-CLI:
```bash
wp plugin deactivate wb-gamification && wp plugin activate wb-gamification
```

Verify: `wp post list --post_type=page --meta_key=_wb_gam_hub_page` returns one page.

- [ ] **Step 2: Navigate to hub page as logged-in user**

Use Playwright MCP: navigate to the "Gamification" page with `?autologin=1`.

Verify:
- Nudge bar visible with message and "Go" button
- 4 stat cards (Points, Level, Badges, Streak)
- 6 cards visible (Badges, Challenges, Leaderboard, How to Earn, Kudos, Activity)
- Lucide icons rendering (not broken font squares)

- [ ] **Step 3: Click each card — verify panel slides in**

Click "My Badges" card → panel slides in from right with badge-showcase block content.
Click X → panel closes.
Repeat for all 6 cards.

- [ ] **Step 4: Test keyboard — ESC closes panel**

Open any panel → press ESC → panel closes.

- [ ] **Step 5: Test at 390px viewport**

Resize browser to 390px width. Verify:
- Stats: 2 columns
- Cards: single column
- Panel: full-width
- Nudge: stacked, CTA full-width

- [ ] **Step 6: Test as logged-out user**

Visit hub page without `?autologin`. Verify:
- Shows login prompt nudge with "Log in" button
- No stats, no cards, no panel

- [ ] **Step 7: Test URL param pre-open**

Navigate to hub page with `?panel=badges&autologin=1`. Verify:
- Badges panel auto-opens on page load

- [ ] **Step 8: Screenshot and confirm all checks pass**

Take final screenshot at desktop and mobile widths.

- [ ] **Step 9: Commit all verification fixes (if any)**

```bash
git add -A
git commit -m "fix: hub page verification fixes"
```

---

## Execution Checklist

| Task | Component | Files | Estimate |
|------|-----------|-------|----------|
| 1 | NudgeEngine + tests | 2 new | 15 min |
| 2 | Hub block (block.json + render.php) | 2 new, 1 modify | 20 min |
| 3 | Hub CSS | 1 new | 10 min |
| 4 | Hub Interactivity JS | 1 new | 10 min |
| 5 | Shortcode + page auto-create | 2 modify | 10 min |
| 6 | Lucide icons | 1 modify | 5 min |
| 7 | Browser verification | 0 | 15 min |
| **Total** | | **7 new, 3 modify** | **~85 min** |
