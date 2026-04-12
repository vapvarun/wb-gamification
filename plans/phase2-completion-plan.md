# Phase 2 Completion Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the two unbuilt Phase 2 features: (1) a human-readable credential share page with LinkedIn integration, and (2) a no-code admin UI for rank automation rules.

**Status (Updated 2026-04-12): BOTH TASKS COMPLETE.**
- `src/Engine/BadgeSharePage.php` exists (9K) — rewrite rules, OG meta, LinkedIn button
- `src/Admin/SettingsPage.php` has automation tab (21 references to "automation" found)
- Both wired into `wb-gamification.php`

**Architecture:** `BadgeSharePage` registers a rewrite rule and renders a public HTML share page via `template_redirect`. The Rank Automation UI is a new `automation` tab added to the existing `SettingsPage` class — no new files, same form-POST + nonce pattern already used by Points and Levels tabs.

**Tech Stack:** PHP 8.1, WordPress rewrite API, Brain\Monkey + PHPUnit for unit tests, existing `wb_gam_rank_automation_rules` option + `RankAutomation.php` schema.

---

## Already Shipped — Do Not Re-Implement

The following Phase 2 items are **fully implemented and committed**:

| Feature | Class | Status |
|---|---|---|
| Rank automation engine | `src/Engine/RankAutomation.php` | ✅ done |
| Personal record notifications | `src/Engine/PersonalRecordEngine.php` | ✅ done |
| Cohort leagues (Duolingo-style) | `src/Engine/CohortEngine.php` | ✅ done |
| Leaderboard nudge ("You're #X") | `src/Engine/LeaderboardNudge.php` | ✅ done |

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `src/Engine/BadgeSharePage.php` | **Create** | Rewrite rules, template rendering, OG meta, LinkedIn URL |
| `src/Admin/SettingsPage.php` | **Modify** | Add `automation` tab: render, save, delete rules |
| `wb-gamification.php` | **Modify** | `use` import + `plugins_loaded` wiring for `BadgeSharePage` |
| `tests/Unit/Engine/BadgeSharePageTest.php` | **Create** | Unit tests for URL builders and tag generation |
| `tests/Unit/Admin/SettingsPageAutomationTest.php` | **Create** | Unit tests for rule normalize/validate helpers |

---

## Task 1: BadgeSharePage — Share URL + OG Meta + LinkedIn Button

**Files:**
- Create: `src/Engine/BadgeSharePage.php`
- Modify: `wb-gamification.php`
- Test: `tests/Unit/Engine/BadgeSharePageTest.php`

### Overview

Registers a rewrite rule for `/gamification/badge/{badge_id}/{user_id}/share/`.
On `template_redirect`, if the query vars are set, renders an HTML page that:
- Shows badge image, name, description, earner name, earned date
- Outputs OG meta in `wp_head` (`og:title`, `og:description`, `og:image`, `og:url`)
- Includes a "Add to LinkedIn" link using LinkedIn's certification deep-link URL
- Links to the machine-readable JSON-LD endpoint for verification

The page outputs via `wp_head()` + `wp_footer()` (uses the theme's shell — no custom template file needed).

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Engine/BadgeSharePageTest.php`:

```php
<?php

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeSharePage;

class BadgeSharePageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_linkedin_url_contains_cert_name(): void {
        $url = BadgeSharePage::build_linkedin_url(
            'Community Champion',   // badge name
            'My Site',              // org name
            2024,                   // issue year
            6,                      // issue month
            'https://example.com/wp-json/wb-gamification/v1/badges/champion/credential/42',
            'champion_42'
        );

        $this->assertStringContainsString( 'linkedin.com/profile/add', $url );
        $this->assertStringContainsString( 'Community+Champion', urldecode( $url ) );
        $this->assertStringContainsString( 'My+Site', urldecode( $url ) );
        $this->assertStringContainsString( '2024', $url );
        $this->assertStringContainsString( '6', $url );
        $this->assertStringContainsString( 'champion_42', urlencode( 'champion_42' ) );
    }

    public function test_linkedin_url_has_required_params(): void {
        $url = BadgeSharePage::build_linkedin_url( 'Badge', 'Site', 2025, 1, 'https://example.com/cred', 'b_1' );
        $parsed = parse_url( $url );
        parse_str( $parsed['query'], $params );

        $this->assertArrayHasKey( 'startTask', $params );
        $this->assertArrayHasKey( 'name', $params );
        $this->assertArrayHasKey( 'organizationName', $params );
        $this->assertArrayHasKey( 'issueYear', $params );
        $this->assertArrayHasKey( 'issueMonth', $params );
        $this->assertArrayHasKey( 'certUrl', $params );
        $this->assertArrayHasKey( 'certId', $params );
    }

    public function test_share_url_format(): void {
        Functions\when( 'home_url' )->returnArg();
        $url = BadgeSharePage::get_share_url( 'champion', 42 );
        $this->assertStringContainsString( 'gamification/badge/champion/42/share', $url );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification
vendor/bin/phpunit tests/Unit/Engine/BadgeSharePageTest.php --no-coverage
```

Expected: FAIL — `BadgeSharePage` class not found.

- [ ] **Step 3: Implement `src/Engine/BadgeSharePage.php`**

```php
<?php
/**
 * WB Gamification — Badge Share Page
 *
 * Registers a public-facing shareable credential page at:
 *   /gamification/badge/{badge_id}/{user_id}/share/
 *
 * The page renders HTML with Open Graph meta tags and a LinkedIn
 * "Add Certification" deep-link button.
 *
 * @package WB_Gamification
 * @since   0.4.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class BadgeSharePage {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function activate(): void {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	// ── Rewrite ───────────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^gamification/badge/([a-z0-9_-]+)/([0-9]+)/share/?$',
			'index.php?wb_gam_badge_share=1&wb_gam_share_badge_id=$matches[1]&wb_gam_share_user_id=$matches[2]',
			'top'
		);
	}

	public static function add_query_vars( array $vars ): array {
		$vars[] = 'wb_gam_badge_share';
		$vars[] = 'wb_gam_share_badge_id';
		$vars[] = 'wb_gam_share_user_id';
		return $vars;
	}

	// ── Template ──────────────────────────────────────────────────────────────

	public static function maybe_render(): void {
		if ( ! get_query_var( 'wb_gam_badge_share' ) ) {
			return;
		}

		$badge_id = sanitize_key( get_query_var( 'wb_gam_share_badge_id' ) );
		$user_id  = absint( get_query_var( 'wb_gam_share_user_id' ) );

		if ( ! $badge_id || ! $user_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$badge = BadgeEngine::get_badge_def( $badge_id );
		$user  = get_userdata( $user_id );

		if ( ! $badge || ! $user || ! BadgeEngine::has_badge( $user_id, $badge_id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		// Build page data.
		$share_url   = self::get_share_url( $badge_id, $user_id );
		$cred_url    = rest_url( 'wb-gamification/v1/badges/' . $badge_id . '/credential/' . $user_id );
		$earned_at   = BadgeEngine::get_badge_row( $user_id, $badge_id )['earned_at'] ?? '';
		$issued_dt   = $earned_at ? new \DateTime( $earned_at, new \DateTimeZone( 'UTC' ) ) : null;
		$issue_year  = $issued_dt ? (int) $issued_dt->format( 'Y' ) : (int) gmdate( 'Y' );
		$issue_month = $issued_dt ? (int) $issued_dt->format( 'n' ) : (int) gmdate( 'n' );

		$linkedin_url = $badge['is_credential']
			? self::build_linkedin_url(
				$badge['name'],
				get_bloginfo( 'name' ),
				$issue_year,
				$issue_month,
				$cred_url,
				$badge_id . '_' . $user_id
			)
			: '';

		// Inject OG meta + page title into wp_head.
		add_filter(
			'document_title_parts',
			static function ( array $parts ) use ( $badge, $user ): array {
				$parts['title'] = sprintf(
					/* translators: 1: badge name, 2: display name */
					__( '%1$s — earned by %2$s', 'wb-gamification' ),
					$badge['name'],
					$user->display_name
				);
				return $parts;
			}
		);

		add_action(
			'wp_head',
			static function () use ( $badge, $user, $share_url ): void {
				$title = esc_attr(
					sprintf(
						/* translators: 1: badge name, 2: display name */
						__( '%1$s — earned by %2$s', 'wb-gamification' ),
						$badge['name'],
						$user->display_name
					)
				);
				$desc = esc_attr( $badge['description'] );
				$img  = esc_attr( $badge['image_url'] ?: '' );
				$url  = esc_attr( $share_url );
				echo "<meta property=\"og:type\" content=\"website\" />\n";
				echo "<meta property=\"og:title\" content=\"{$title}\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				echo "<meta property=\"og:description\" content=\"{$desc}\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				echo "<meta property=\"og:url\" content=\"{$url}\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				if ( $img ) {
					echo "<meta property=\"og:image\" content=\"{$img}\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				}
				echo "<meta name=\"twitter:card\" content=\"summary\" />\n";
			}
		);

		// Render the page using the theme's shell.
		get_header();
		self::render_share_body( $badge, $user, $linkedin_url, $cred_url, $issued_dt );
		get_footer();
		exit;
	}

	private static function render_share_body( array $badge, \WP_User $user, string $linkedin_url, string $cred_url, ?\DateTime $issued_dt ): void {
		$issued_label = $issued_dt
			? esc_html( date_i18n( get_option( 'date_format' ), $issued_dt->getTimestamp() ) )
			: '';
		?>
		<div class="wb-gam-share-page" style="max-width:600px;margin:40px auto;padding:0 16px;font-family:sans-serif;text-align:center;">
			<?php if ( $badge['image_url'] ) : ?>
				<img src="<?php echo esc_url( $badge['image_url'] ); ?>"
					alt="<?php echo esc_attr( $badge['name'] ); ?>"
					width="160" height="160"
					style="border-radius:50%;margin-bottom:24px;" />
			<?php endif; ?>

			<h1 style="font-size:1.8em;margin:0 0 8px;"><?php echo esc_html( $badge['name'] ); ?></h1>
			<p style="color:#555;margin:0 0 16px;"><?php echo esc_html( $badge['description'] ); ?></p>

			<p style="font-size:0.95em;color:#777;margin:0 0 24px;">
				<?php
				printf(
					/* translators: 1: display name, 2: date */
					esc_html__( 'Earned by %1$s%2$s', 'wb-gamification' ),
					'<strong>' . esc_html( $user->display_name ) . '</strong>',
					$issued_label ? ( ' ' . esc_html__( 'on', 'wb-gamification' ) . ' ' . $issued_label ) : ''
				);
				?>
			</p>

			<?php if ( $linkedin_url ) : ?>
				<a href="<?php echo esc_url( $linkedin_url ); ?>"
					rel="noopener noreferrer"
					target="_blank"
					style="display:inline-block;background:#0A66C2;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;margin-bottom:16px;">
					<?php esc_html_e( 'Add to LinkedIn', 'wb-gamification' ); ?>
				</a>
				<br />
			<?php endif; ?>

			<?php if ( $badge['is_credential'] ) : ?>
				<a href="<?php echo esc_url( $cred_url ); ?>"
					rel="noopener noreferrer"
					target="_blank"
					style="font-size:0.85em;color:#666;">
					<?php esc_html_e( 'View verifiable credential (JSON-LD)', 'wb-gamification' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── URL builders (static, pure — easy to unit test) ───────────────────────

	/**
	 * Return the front-end share page URL for a badge + user.
	 *
	 * @param string $badge_id Badge identifier.
	 * @param int    $user_id  Earner user ID.
	 * @return string
	 */
	public static function get_share_url( string $badge_id, int $user_id ): string {
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' );
	}

	/**
	 * Build a LinkedIn "Add Certification" deep-link URL.
	 *
	 * @param string $badge_name  Display name of the badge/credential.
	 * @param string $org_name    Issuing organisation name.
	 * @param int    $issue_year  Year credential was issued.
	 * @param int    $issue_month Month credential was issued (1–12).
	 * @param string $cred_url    Publicly accessible credential verification URL.
	 * @param string $cert_id     Unique cert identifier (e.g. "champion_42").
	 * @return string
	 */
	public static function build_linkedin_url(
		string $badge_name,
		string $org_name,
		int $issue_year,
		int $issue_month,
		string $cred_url,
		string $cert_id
	): string {
		return add_query_arg(
			array(
				'startTask'        => 'CERTIFICATION_NAME',
				'name'             => $badge_name,
				'organizationName' => $org_name,
				'issueYear'        => $issue_year,
				'issueMonth'       => $issue_month,
				'certUrl'          => $cred_url,
				'certId'           => $cert_id,
			),
			'https://www.linkedin.com/profile/add'
		);
	}
}
```

- [ ] **Step 4: Wire `BadgeSharePage` into `wb-gamification.php`**

Add `use WBGam\Engine\BadgeSharePage;` with the other `use` statements (alphabetical, near `BadgeEngine`).

In `init_hooks()`, add after the other `plugins_loaded` lines at priority 10:
```php
add_action( 'plugins_loaded', array( BadgeSharePage::class, 'init' ), 10 );
```

In `register_activation_hook(...)`:
```php
BadgeSharePage::activate();
```

In `register_deactivation_hook(...)`:
```php
BadgeSharePage::deactivate();
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Unit/Engine/BadgeSharePageTest.php --no-coverage
```

Expected: 3 tests, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/Engine/BadgeSharePage.php wb-gamification.php tests/Unit/Engine/BadgeSharePageTest.php
git commit -m "feat(v0.4.0): add credential share page with OG meta + LinkedIn button"
```

---

## Task 2: Rank Automation Settings UI — Admin Tab

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageAutomationTest.php`

### Overview

Add an `Automation` tab to the existing Settings page. The tab lists configured level→action rules and provides a form to add new ones. No AJAX — standard form POST, consistent with the rest of the settings page.

Rules are stored as JSON in `wb_gam_rank_automation_rules` WordPress option. The schema comes directly from `RankAutomation.php`:
```json
[
  {
    "trigger_level_id": 3,
    "actions": [
      { "type": "add_bp_group", "group_id": 42 },
      { "type": "change_wp_role", "role": "contributor" }
    ]
  }
]
```

The UI allows adding one action per rule for simplicity (multiple actions per trigger level can always be configured via the filter in code). Each rule form row: **Level** (select from DB) + **Action** (dropdown) + **Params** (context-sensitive fields).

---

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Admin/SettingsPageAutomationTest.php`:

```php
<?php

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\SettingsPage;

class SettingsPageAutomationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_normalize_rule_add_bp_group(): void {
        $raw = array(
            'trigger_level_id' => '3',
            'action_type'      => 'add_bp_group',
            'group_id'         => '42',
            'role'             => '',
            'sender_id'        => '',
            'subject'          => '',
            'content'          => '',
        );

        $rule = SettingsPage::normalize_automation_rule( $raw );

        $this->assertSame( 3, $rule['trigger_level_id'] );
        $this->assertCount( 1, $rule['actions'] );
        $this->assertSame( 'add_bp_group', $rule['actions'][0]['type'] );
        $this->assertSame( 42, $rule['actions'][0]['group_id'] );
    }

    public function test_normalize_rule_change_wp_role(): void {
        $raw = array(
            'trigger_level_id' => '5',
            'action_type'      => 'change_wp_role',
            'role'             => 'contributor',
            'group_id'         => '',
            'sender_id'        => '',
            'subject'          => '',
            'content'          => '',
        );

        $rule = SettingsPage::normalize_automation_rule( $raw );

        $this->assertSame( 5, $rule['trigger_level_id'] );
        $this->assertSame( 'change_wp_role', $rule['actions'][0]['type'] );
        $this->assertSame( 'contributor', $rule['actions'][0]['role'] );
    }

    public function test_normalize_rule_send_bp_message(): void {
        $raw = array(
            'trigger_level_id' => '2',
            'action_type'      => 'send_bp_message',
            'sender_id'        => '1',
            'subject'          => 'Congrats!',
            'content'          => 'You reached level 2.',
            'group_id'         => '',
            'role'             => '',
        );

        $rule = SettingsPage::normalize_automation_rule( $raw );

        $this->assertSame( 'send_bp_message', $rule['actions'][0]['type'] );
        $this->assertSame( 1, $rule['actions'][0]['sender_id'] );
        $this->assertSame( 'Congrats!', $rule['actions'][0]['subject'] );
        $this->assertSame( 'You reached level 2.', $rule['actions'][0]['content'] );
    }

    public function test_normalize_rule_returns_null_on_invalid_level(): void {
        $raw = array(
            'trigger_level_id' => '0',
            'action_type'      => 'add_bp_group',
            'group_id'         => '1',
            'role'             => '', 'sender_id' => '', 'subject' => '', 'content' => '',
        );

        $this->assertNull( SettingsPage::normalize_automation_rule( $raw ) );
    }

    public function test_normalize_rule_returns_null_on_unknown_action(): void {
        $raw = array(
            'trigger_level_id' => '3',
            'action_type'      => 'explode_server',
            'group_id'         => '1',
            'role'             => '', 'sender_id' => '', 'subject' => '', 'content' => '',
        );

        $this->assertNull( SettingsPage::normalize_automation_rule( $raw ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Admin/SettingsPageAutomationTest.php --no-coverage
```

Expected: FAIL — `normalize_automation_rule` method not found.

- [ ] **Step 3: Add `automation` tab to `SettingsPage.php`**

**3a — Add `normalize_automation_rule` as a `public static` method** (needed for unit test and save logic). Add after `handle_save_levels()`:

```php
/**
 * Normalize and validate a single automation rule from POST data.
 *
 * @param array $raw Raw POST fields for this rule.
 * @return array|null Normalized rule array, or null if invalid.
 */
public static function normalize_automation_rule( array $raw ): ?array {
    $level_id    = (int) ( $raw['trigger_level_id'] ?? 0 );
    $action_type = sanitize_key( $raw['action_type'] ?? '' );

    if ( $level_id <= 0 ) {
        return null;
    }

    $allowed_types = array( 'add_bp_group', 'send_bp_message', 'change_wp_role' );
    if ( ! in_array( $action_type, $allowed_types, true ) ) {
        return null;
    }

    $action = array( 'type' => $action_type );

    switch ( $action_type ) {
        case 'add_bp_group':
            $action['group_id'] = absint( $raw['group_id'] ?? 0 );
            if ( ! $action['group_id'] ) {
                return null;
            }
            break;

        case 'change_wp_role':
            $action['role'] = sanitize_key( $raw['role'] ?? '' );
            if ( ! $action['role'] ) {
                return null;
            }
            break;

        case 'send_bp_message':
            $action['sender_id'] = absint( $raw['sender_id'] ?? 1 ) ?: 1;
            $action['subject']   = sanitize_text_field( wp_unslash( $raw['subject'] ?? '' ) );
            $action['content']   = sanitize_textarea_field( wp_unslash( $raw['content'] ?? '' ) );
            if ( ! $action['subject'] || ! $action['content'] ) {
                return null;
            }
            break;
    }

    return array(
        'trigger_level_id' => $level_id,
        'actions'          => array( $action ),
    );
}
```

**3b — Add `save_automation_settings()` private static method:**

```php
private static function save_automation_settings(): void {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
    $existing_rules = array();
    $stored = get_option( 'wb_gam_rank_automation_rules', '' );
    if ( is_string( $stored ) && '' !== $stored ) {
        $decoded = json_decode( $stored, true );
        if ( is_array( $decoded ) ) {
            $existing_rules = $decoded;
        }
    }

    $action = sanitize_key( $_POST['wb_gam_automation_action'] ?? 'save' );

    // Delete a rule by index.
    if ( 'delete' === $action ) {
        $index = (int) ( $_POST['wb_gam_rule_index'] ?? -1 );
        if ( isset( $existing_rules[ $index ] ) ) {
            array_splice( $existing_rules, $index, 1 );
            update_option( 'wb_gam_rank_automation_rules', wp_json_encode( array_values( $existing_rules ) ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return;
    }

    // Add a new rule.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_automation_rule sanitizes each field.
    $raw  = (array) wp_unslash( $_POST['wb_gam_new_rule'] ?? array() );
    $rule = self::normalize_automation_rule( $raw );
    if ( $rule ) {
        $existing_rules[] = $rule;
        update_option( 'wb_gam_rank_automation_rules', wp_json_encode( array_values( $existing_rules ) ) );
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
```

**3c — Route `handle_save()` for the automation tab.**

In `handle_save()`, extend the existing `if ( 'points' === $tab )` block:

```php
if ( 'points' === $tab ) {
    self::save_points_settings();
} elseif ( 'automation' === $tab ) {
    self::save_automation_settings();
}
```

**3d — Add `automation` to the tabs array in `render()`:**

```php
$tabs = array(
    'points'     => __( 'Points', 'wb-gamification' ),
    'levels'     => __( 'Levels', 'wb-gamification' ),
    'automation' => __( 'Automation', 'wb-gamification' ),
);
```

**3e — Add `automation` case to the `match` in `render()`:**

```php
match ( $tab ) {
    'levels'     => self::render_levels_tab(),
    'automation' => self::render_automation_tab(),
    default      => self::render_points_tab(),
};
```

**3f — Add `render_automation_tab()` private static method:**

```php
private static function render_automation_tab(): void {
    global $wpdb;

    // Load existing rules.
    $rules   = array();
    $stored  = get_option( 'wb_gam_rank_automation_rules', '' );
    if ( is_string( $stored ) && '' !== $stored ) {
        $decoded = json_decode( $stored, true );
        if ( is_array( $decoded ) ) {
            $rules = $decoded;
        }
    }

    // Load levels for the select dropdown.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- settings page, infrequent, small table.
    $levels = $wpdb->get_results(
        $wpdb->prepare( 'SELECT id, name FROM %i ORDER BY min_points ASC', $wpdb->prefix . 'wb_gam_levels' ),
        ARRAY_A
    );

    $action_labels = array(
        'add_bp_group'    => __( 'Add to BuddyPress group', 'wb-gamification' ),
        'send_bp_message' => __( 'Send BuddyPress message', 'wb-gamification' ),
        'change_wp_role'  => __( 'Add WordPress role', 'wb-gamification' ),
    );

    $form_url = admin_url( 'admin.php?page=wb-gamification&tab=automation' );
    ?>
    <h2><?php esc_html_e( 'Rank Automation Rules', 'wb-gamification' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Automatically trigger actions when a member reaches a level. One action per rule — add multiple rules for the same level to stack actions.', 'wb-gamification' ); ?>
    </p>

    <?php if ( $rules ) : ?>
        <table class="widefat striped" style="margin-bottom:24px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'When member reaches', 'wb-gamification' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
                    <th><?php esc_html_e( 'Parameters', 'wb-gamification' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rules as $i => $rule ) :
                $trigger = (int) ( $rule['trigger_level_id'] ?? 0 );
                $level_name = '';
                foreach ( (array) $levels as $lv ) {
                    if ( (int) $lv['id'] === $trigger ) {
                        $level_name = $lv['name'];
                        break;
                    }
                }
                foreach ( (array) ( $rule['actions'] ?? array() ) as $action ) :
                    $action_type  = $action['type'] ?? '';
                    $action_label = $action_labels[ $action_type ] ?? $action_type;
                    $params       = $action;
                    unset( $params['type'] );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $level_name ?: '#' . $trigger ); ?></td>
                        <td><?php echo esc_html( $action_label ); ?></td>
                        <td><code><?php echo esc_html( wp_json_encode( $params ) ); ?></code></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
                                <input type="hidden" name="wb_gam_automation_action" value="delete" />
                                <input type="hidden" name="wb_gam_rule_index" value="<?php echo esc_attr( $i ); ?>" />
                                <button type="submit" class="button button-small button-link-delete"
                                    onclick="return confirm('<?php esc_attr_e( 'Delete this rule?', 'wb-gamification' ); ?>')">
                                    <?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="description" style="margin-bottom:24px;"><?php esc_html_e( 'No automation rules configured yet.', 'wb-gamification' ); ?></p>
    <?php endif; ?>

    <h3><?php esc_html_e( 'Add New Rule', 'wb-gamification' ); ?></h3>
    <form method="post" action="<?php echo esc_url( $form_url ); ?>">
        <?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
        <input type="hidden" name="wb_gam_automation_action" value="add" />

        <table class="form-table">
            <tr>
                <th scope="row"><label for="wb_gam_new_rule_level"><?php esc_html_e( 'When member reaches level', 'wb-gamification' ); ?></label></th>
                <td>
                    <select name="wb_gam_new_rule[trigger_level_id]" id="wb_gam_new_rule_level" required>
                        <option value=""><?php esc_html_e( '— select level —', 'wb-gamification' ); ?></option>
                        <?php foreach ( (array) $levels as $lv ) : ?>
                            <option value="<?php echo esc_attr( $lv['id'] ); ?>"><?php echo esc_html( $lv['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wb_gam_new_rule_action"><?php esc_html_e( 'Perform action', 'wb-gamification' ); ?></label></th>
                <td>
                    <select name="wb_gam_new_rule[action_type]" id="wb_gam_new_rule_action">
                        <?php foreach ( $action_labels as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Group ID', 'wb-gamification' ); ?> <span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Add to group")', 'wb-gamification' ); ?></span></th>
                <td><input type="number" name="wb_gam_new_rule[group_id]" class="small-text" min="0" value="" placeholder="0" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Role slug', 'wb-gamification' ); ?> <span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Add role")', 'wb-gamification' ); ?></span></th>
                <td><input type="text" name="wb_gam_new_rule[role]" class="regular-text" value="" placeholder="contributor" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Message sender user ID', 'wb-gamification' ); ?> <span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Send message")', 'wb-gamification' ); ?></span></th>
                <td><input type="number" name="wb_gam_new_rule[sender_id]" class="small-text" min="1" value="1" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Message subject', 'wb-gamification' ); ?></th>
                <td><input type="text" name="wb_gam_new_rule[subject]" class="regular-text" value="" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Message content', 'wb-gamification' ); ?></th>
                <td><textarea name="wb_gam_new_rule[content]" rows="4" class="large-text"></textarea></td>
            </tr>
        </table>

        <?php submit_button( __( 'Add Rule', 'wb-gamification' ) ); ?>
    </form>
    <?php
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Unit/Admin/SettingsPageAutomationTest.php --no-coverage
```

Expected: 5 tests, 0 failures.

- [ ] **Step 5: Run full test suite to check for regressions**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all existing tests still pass.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageAutomationTest.php
git commit -m "feat(v0.4.0): add Rank Automation settings tab (no-code rule builder)"
```

---

## Task 3: WPCS Check + Version Bump

- [ ] **Step 1: Run WPCS on new/modified files**

```bash
vendor/bin/phpcs --standard=.phpcs.xml src/Engine/BadgeSharePage.php src/Admin/SettingsPage.php
```

Fix any genuine (non-PSR-4) errors reported.

- [ ] **Step 2: Bump version to 0.4.0**

In `wb-gamification.php`:
```
Version:     0.4.0
define( 'WB_GAM_VERSION', '0.4.0' );
```

- [ ] **Step 3: Commit**

```bash
git add wb-gamification.php
git commit -m "chore: bump version to 0.4.0"
```

- [ ] **Step 4: Push**

```bash
git push origin main
```
