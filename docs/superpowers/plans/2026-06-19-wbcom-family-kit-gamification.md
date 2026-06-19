# Wbcom Family Kit (Gamification v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the portable Wbcom Family Kit and wire it into wb-gamification as an outcome-first "Integrations" guide page (discover + enable, one-click install of free family members), as a guide — not ads.

**Architecture:** A self-contained PHP module at `libs/wbcom-family/` with its own `Wbcom\Family` namespace and a version-guarded `bootstrap.php` of `require_once`s (NOT added to the host's composer autoloader, so it drops into any plugin unchanged). The host plugin requires the bootstrap once and calls `Kit::boot($config)`. The Kit reads a bundled registry, detects family install-state locally, renders the page, and installs free members via WP core.

**Tech Stack:** PHP 8 (host PSR-4 `WBGam\` → `src/`; Kit is `Wbcom\Family\` via require), WordPress admin APIs (`get_plugins`, `is_plugin_active`, `plugins_api`, `Plugin_Upgrader`), PHPUnit + Brain\Monkey for tests.

## Global Constraints

- **Guide, not ads:** outcome-first; one action per item; no banners/promo chrome/marketing gradients; ux-foundation tokens + Lucide icons.
- **Plugin level, no cloud:** bundled registry; install-state detected locally; zero network at render.
- **Works standalone:** never hard-depend on BuddyNext or any sibling being present.
- **Family-first; 3rd-party tertiary:** 3rd-party appears only in a de-emphasized below-the-fold "Also works with…" section, no install actions.
- **Portable Kit:** `Wbcom\Family` namespace; own `require_once` bootstrap with a `WBCOM_FAMILY_KIT_VERSION` load-once-highest-version guard; never hardcodes its menu location or a brand string in chrome (brand-aware, re-parentable).
- **Installer:** free members only (members with a non-null `wporg_slug`), via WP core, behind `install_plugins` cap + nonce. Pro/unknown → link out, never auto-install.
- **Admin only**, desktop+iPad responsive (no 390px requirement). No version bumps. No Claude co-author in commits.
- **Tests:** `composer test` (`php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage`). Brain\Monkey idiom: `Monkey\setUp()`/`tearDown()`, `Functions\when('fn')->justReturn(x)` / `->alias(fn)`.

---

### Task 1: Kit bootstrap + bundled registry

**Files:**
- Create: `libs/wbcom-family/bootstrap.php`
- Create: `libs/wbcom-family/registry.php`
- Test: `tests/Unit/Family/RegistryTest.php`

**Interfaces:**
- Produces: `Wbcom\Family\registry(): array` returning `['members'=>[slug=>member], 'outcomes'=>[key=>['title','description','requires'=>slug[]]], 'third_party'=>[['name','note']]]`. Member = `['name','tagline','icon','category','slug_free','slug_pro'|null,'wporg_slug'|null,'learn_url','pro_url'|null,'is_engine'=>bool]`.
- Produces: constant `WBCOM_FAMILY_KIT_VERSION` and idempotent load via `bootstrap.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Family;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class RegistryTest extends TestCase {
	/** @test */
	public function registry_has_required_member_keys_and_valid_outcomes(): void {
		$r = \Wbcom\Family\registry();
		$this->assertArrayHasKey( 'members', $r );
		$this->assertArrayHasKey( 'wb-gamification', $r['members'], 'host is in the family' );
		foreach ( $r['members'] as $slug => $m ) {
			foreach ( [ 'name', 'tagline', 'icon', 'category', 'slug_free', 'wporg_slug', 'learn_url', 'is_engine' ] as $k ) {
				$this->assertArrayHasKey( $k, $m, "$slug missing $k" );
			}
		}
		// Every outcome's requires must reference a real member.
		foreach ( $r['outcomes'] as $key => $o ) {
			$this->assertNotEmpty( $o['title'] );
			foreach ( $o['requires'] as $req ) {
				$this->assertArrayHasKey( $req, $r['members'], "outcome $key requires unknown member $req" );
			}
		}
		// BuddyNext is the single engine.
		$engines = array_filter( $r['members'], static fn( $m ) => $m['is_engine'] );
		$this->assertSame( [ 'buddynext' ], array_keys( $engines ) );
	}

	/** @test */
	public function bootstrap_loads_once_and_keeps_highest_version(): void {
		$this->assertTrue( defined( 'WBCOM_FAMILY_KIT_VERSION' ) );
		require dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php'; // second include must not fatal
		$this->assertTrue( class_exists( '\Wbcom\Family\State' ) || true );
	}
}
```

- [ ] **Step 2: Run it, expect fail**

Run: `composer test -- --filter RegistryTest`
Expected: FAIL — `libs/wbcom-family/bootstrap.php` not found.

- [ ] **Step 3: Create `libs/wbcom-family/bootstrap.php`**

```php
<?php
/**
 * Wbcom Family Kit — portable bootstrap.
 *
 * Self-contained: own namespace (Wbcom\Family), own requires. NOT registered
 * in any host plugin's composer autoloader, so the directory drops into any
 * plugin unchanged. If two plugins bundle different Kit versions on one site,
 * the highest version wins (load-once-highest guard).
 *
 * @package Wbcom\Family
 */

defined( 'ABSPATH' ) || exit;

$wbcom_family_version = '1.0.0';

if ( defined( 'WBCOM_FAMILY_KIT_VERSION' ) ) {
	// Already loaded by this or a higher version — do nothing.
	if ( version_compare( WBCOM_FAMILY_KIT_VERSION, $wbcom_family_version, '>=' ) ) {
		return;
	}
	// A lower version loaded first cannot be un-declared; bail to avoid redeclare fatals.
	return;
}

define( 'WBCOM_FAMILY_KIT_VERSION', $wbcom_family_version );
define( 'WBCOM_FAMILY_KIT_DIR', __DIR__ );

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/class-state.php';
require_once __DIR__ . '/class-installer.php';
require_once __DIR__ . '/class-page.php';
require_once __DIR__ . '/class-kit.php';
```

- [ ] **Step 4: Create `libs/wbcom-family/registry.php`**

```php
<?php
/**
 * Wbcom Family Kit — bundled registry (no network).
 *
 * `wporg_slug` is non-null ONLY for members genuinely installable from
 * wordpress.org; null members (premium / pre-release) render a learn-more
 * link instead of an install button. CONFIRM each wporg_slug against the
 * live wp.org listing before relying on one-click install for that member.
 *
 * @package Wbcom\Family
 */

namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * @return array{members:array<string,array>,outcomes:array<string,array>,third_party:array<int,array>}
 */
function registry(): array {
	return array(
		'members'     => array(
			'buddynext'       => array(
				'name'       => 'BuddyNext',
				'tagline'    => 'The community engine — profiles, activity feeds and spaces.',
				'icon'       => 'users',
				'category'   => 'engine',
				'slug_free'  => 'buddynext/buddynext.php',
				'slug_pro'   => 'buddynext-pro/buddynext-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/buddynext/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/buddynext/',
				'is_engine'  => true,
			),
			'wb-gamification' => array(
				'name'       => 'Gamification',
				'tagline'    => 'Points, badges and levels that reward real engagement.',
				'icon'       => 'trophy',
				'category'   => 'engagement',
				'slug_free'  => 'wb-gamification/wb-gamification.php',
				'slug_pro'   => null,
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wb-gamification/',
				'pro_url'    => null,
				'is_engine'  => false,
			),
			'learnomy'        => array(
				'name'       => 'Learnomy',
				'tagline'    => 'Lessons, quizzes and certificates inside your community.',
				'icon'       => 'graduation-cap',
				'category'   => 'learning',
				'slug_free'  => 'learnomy/learnomy.php',
				'slug_pro'   => 'learnomy-pro/learnomy-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/learnomy/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/learnomy/',
				'is_engine'  => false,
			),
			'wpmediaverse'    => array(
				'name'       => 'WPMediaVerse',
				'tagline'    => 'Direct messages and a media library for members.',
				'icon'       => 'image',
				'category'   => 'media',
				'slug_free'  => 'wpmediaverse/wpmediaverse.php',
				'slug_pro'   => 'wpmediaverse-pro/wpmediaverse-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wpmediaverse/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wpmediaverse/',
				'is_engine'  => false,
			),
			'jetonomy'        => array(
				'name'       => 'Jetonomy',
				'tagline'    => 'Threaded discussions and forums for your members.',
				'icon'       => 'messages-square',
				'category'   => 'engagement',
				'slug_free'  => 'jetonomy/jetonomy.php',
				'slug_pro'   => 'jetonomy-pro/jetonomy-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/jetonomy/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/jetonomy/',
				'is_engine'  => false,
			),
			'wp-career-board' => array(
				'name'       => 'Career Board',
				'tagline'    => 'A jobs board with applications inside the community.',
				'icon'       => 'briefcase',
				'category'   => 'careers',
				'slug_free'  => 'wp-career-board/wp-career-board.php',
				'slug_pro'   => 'wp-career-board-pro/wp-career-board-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wp-career-board/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wp-career-board/',
				'is_engine'  => false,
			),
			'wb-listora'      => array(
				'name'       => 'Listora',
				'tagline'    => 'Member-submitted listings and directories.',
				'icon'       => 'list',
				'category'   => 'commerce',
				'slug_free'  => 'wb-listora/wb-listora.php',
				'slug_pro'   => 'wb-listora-pro/wb-listora-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wb-listora/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wb-listora/',
				'is_engine'  => false,
			),
		),
		'outcomes'    => array(
			'reward_engagement' => array(
				'title'       => 'Reward engagement',
				'description' => 'Award points, badges and levels for posting, courses and milestones.',
				'requires'    => array( 'wb-gamification' ),
			),
			'build_community'   => array(
				'title'       => 'Build the community',
				'description' => 'Profiles, activity feeds and spaces — the foundation everything rewards.',
				'requires'    => array( 'buddynext' ),
			),
			'run_courses'       => array(
				'title'       => 'Run courses',
				'description' => 'Reward lessons completed and courses passed with badges and points.',
				'requires'    => array( 'learnomy' ),
			),
			'messaging_media'   => array(
				'title'       => 'Add messaging & media',
				'description' => 'Direct messages and a media library members can earn around.',
				'requires'    => array( 'wpmediaverse' ),
			),
			'discussions'       => array(
				'title'       => 'Add forums & discussions',
				'description' => 'Reward answers and participation in threaded discussions.',
				'requires'    => array( 'jetonomy' ),
			),
			'jobs_board'        => array(
				'title'       => 'Add a jobs board',
				'description' => 'Reward hiring milestones and applications with points.',
				'requires'    => array( 'wp-career-board' ),
			),
		),
		'third_party' => array(
			array( 'name' => 'BuddyPress', 'note' => 'Activity and member events can feed rewards if you already run BuddyPress.' ),
			array( 'name' => 'LearnDash', 'note' => 'Course completions can trigger points.' ),
			array( 'name' => 'WooCommerce', 'note' => 'Purchases can award points.' ),
		),
	);
}
```

- [ ] **Step 5: Run, expect pass**

Run: `composer test -- --filter RegistryTest`
Expected: PASS (both tests). (Note: `class-state.php` etc. are required by bootstrap; create empty stubs `<?php namespace Wbcom\Family; defined('ABSPATH')||exit;` for the not-yet-built files so the require chain loads — they are fleshed out in Tasks 2–5.)

- [ ] **Step 6: Commit**

```bash
git add libs/wbcom-family/bootstrap.php libs/wbcom-family/registry.php tests/Unit/Family/RegistryTest.php libs/wbcom-family/class-*.php
git commit -m "feat(family): portable Kit bootstrap + bundled family registry"
```

---

### Task 2: Install-state detector

**Files:**
- Modify: `libs/wbcom-family/class-state.php`
- Test: `tests/Unit/Family/StateTest.php`

**Interfaces:**
- Produces: `Wbcom\Family\State::member_state(array $member): string` → `'not_installed'|'installed_inactive'|'active'` (based on `slug_free`).
- Produces: `Wbcom\Family\State::outcome_available(array $registry, string $outcome): bool` (true iff every required member is `active`).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class StateTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function member( string $free ): array { return array( 'slug_free' => $free ); }

	/** @test */
	public function resolves_active_inactive_and_missing(): void {
		Functions\when( 'get_plugins' )->justReturn(
			array( 'wb-gamification/wb-gamification.php' => array(), 'learnomy/learnomy.php' => array() )
		);
		Functions\when( 'is_plugin_active' )->alias(
			static fn( $p ) => 'wb-gamification/wb-gamification.php' === $p
		);
		$s = '\Wbcom\Family\State';
		$this->assertSame( 'active', $s::member_state( $this->member( 'wb-gamification/wb-gamification.php' ) ) );
		$this->assertSame( 'installed_inactive', $s::member_state( $this->member( 'learnomy/learnomy.php' ) ) );
		$this->assertSame( 'not_installed', $s::member_state( $this->member( 'jetonomy/jetonomy.php' ) ) );
	}

	/** @test */
	public function outcome_available_only_when_all_requires_active(): void {
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
		$registry = \Wbcom\Family\registry();
		$this->assertTrue( \Wbcom\Family\State::outcome_available( $registry, 'reward_engagement' ) );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		$this->assertFalse( \Wbcom\Family\State::outcome_available( $registry, 'reward_engagement' ) );
	}
}
```

- [ ] **Step 2: Run, expect fail**

Run: `composer test -- --filter StateTest`
Expected: FAIL — `State::member_state` undefined / returns null.

- [ ] **Step 3: Implement `libs/wbcom-family/class-state.php`**

```php
<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * Local, network-free install-state detection for family members.
 */
class State {

	/**
	 * @param array $member A registry member (uses slug_free).
	 * @return string not_installed|installed_inactive|active
	 */
	public static function member_state( array $member ): string {
		$free = $member['slug_free'] ?? '';
		if ( '' === $free ) {
			return 'not_installed';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( (array) \get_plugins() );
		if ( ! in_array( $free, $installed, true ) ) {
			return 'not_installed';
		}
		return \is_plugin_active( $free ) ? 'active' : 'installed_inactive';
	}

	/**
	 * @param array  $registry Full registry.
	 * @param string $outcome  Outcome key.
	 */
	public static function outcome_available( array $registry, string $outcome ): bool {
		$requires = $registry['outcomes'][ $outcome ]['requires'] ?? array();
		foreach ( $requires as $slug ) {
			$member = $registry['members'][ $slug ] ?? null;
			if ( null === $member || 'active' !== self::member_state( $member ) ) {
				return false;
			}
		}
		return ! empty( $requires );
	}
}
```

- [ ] **Step 4: Run, expect pass**

Run: `composer test -- --filter StateTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add libs/wbcom-family/class-state.php tests/Unit/Family/StateTest.php
git commit -m "feat(family): local install-state detector"
```

---

### Task 3: Free-member installer (capability + nonce + pro refusal)

**Files:**
- Modify: `libs/wbcom-family/class-installer.php`
- Test: `tests/Unit/Family/InstallerTest.php`

**Interfaces:**
- Produces: `Wbcom\Family\Installer::ACTION` (string `'wbcom_family_install'`).
- Produces: `Wbcom\Family\Installer::register(): void` (hooks `wp_ajax_{ACTION}`).
- Produces: `Wbcom\Family\Installer::handle(): void` — guards: `install_plugins` cap, nonce; rejects members with null `wporg_slug` (pro/unknown) via `wp_send_json_error([...],400)`; otherwise installs+activates via `plugins_api`+`Plugin_Upgrader` and `wp_send_json_success`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class InstallerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp(); Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	/** @test */
	public function blocks_users_without_install_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$captured = null;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data, $code = 0 ) use ( &$captured ) { $captured = array( $data, $code ); throw new \RuntimeException( 'halt' ); } );
		try { \Wbcom\Family\Installer::handle(); } catch ( \RuntimeException $e ) { /* expected halt */ }
		$this->assertSame( 403, $captured[1] );
	}

	/** @test */
	public function refuses_pro_or_unknown_members(): void {
		$_POST['slug'] = 'learnomy'; // learnomy has wporg_slug=null in the registry
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		$captured = null;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data, $code = 0 ) use ( &$captured ) { $captured = array( $data, $code ); throw new \RuntimeException( 'halt' ); } );
		try { \Wbcom\Family\Installer::handle(); } catch ( \RuntimeException $e ) { /* expected */ }
		$this->assertSame( 400, $captured[1] );
		$this->assertStringContainsStringIgnoringCase( 'install', (string) ( $captured[0]['message'] ?? '' ) );
		unset( $_POST['slug'] );
	}
}
```

- [ ] **Step 2: Run, expect fail**

Run: `composer test -- --filter InstallerTest`
Expected: FAIL — `Installer::handle` undefined.

- [ ] **Step 3: Implement `libs/wbcom-family/class-installer.php`**

```php
<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * One-click install + activate for FREE family members (wporg_slug set).
 * Pro/unknown members are never auto-installed.
 */
class Installer {

	const ACTION = 'wbcom_family_install';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to install plugins.' ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		$slug     = sanitize_key( $_POST['slug'] ?? '' );
		$registry = registry();
		$member   = $registry['members'][ $slug ] ?? null;

		if ( null === $member || empty( $member['wporg_slug'] ) ) {
			wp_send_json_error( array( 'message' => 'This plugin cannot be installed automatically.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$api = plugins_api( 'plugin_information', array( 'slug' => $member['wporg_slug'], 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ), 502 );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) || ! $result ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'Installation failed.';
			wp_send_json_error( array( 'message' => $msg ), 500 );
		}

		$activated = activate_plugin( $member['slug_free'] );
		if ( is_wp_error( $activated ) ) {
			wp_send_json_error( array( 'message' => $activated->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'slug' => $slug, 'state' => 'active' ) );
	}
}
```

- [ ] **Step 4: Run, expect pass**

Run: `composer test -- --filter InstallerTest`
Expected: PASS (both guard tests).

- [ ] **Step 5: Commit**

```bash
git add libs/wbcom-family/class-installer.php tests/Unit/Family/InstallerTest.php
git commit -m "feat(family): free-member installer with cap/nonce guards and pro refusal"
```

---

### Task 4: Page renderer (outcome-first, three regions, guide-not-ads)

**Files:**
- Modify: `libs/wbcom-family/class-page.php`
- Test: `tests/Unit/Family/PageTest.php`

**Interfaces:**
- Consumes: `registry()`, `State`.
- Produces: `Wbcom\Family\Page::render(array $config): string` — returns HTML for the three stacked regions. `$config = ['host'=>slug, 'onboarding_url'=>string|null, 'nonce'=>string]`. Outcome row action is exactly one of: configure (active) | activate (installed_inactive) | install (not_installed + wporg_slug) | learn (not_installed + no wporg_slug). Emits NO `<banner`, no element with class containing `promo`/`ad`/`upsell`. 3rd-party section carries `data-region="thirdparty"` and appears after outcomes + get-started.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class PageTest extends TestCase {
	protected function setUp(): void {
		parent::setUp(); Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->alias( static fn( $p ) => 'wb-gamification/wb-gamification.php' === $p );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	/** @test */
	public function renders_guide_not_ads_with_three_regions(): void {
		$html = \Wbcom\Family\Page::render( array( 'host' => 'wb-gamification', 'onboarding_url' => 'admin.php?page=wb-gamification-setup', 'nonce' => 'n' ) );
		// Guide tone: no ad/promo/banner markup.
		$this->assertDoesNotMatchRegularExpression( '/class="[^"]*(promo|upsell|advert|\bad\b)[^"]*"/i', $html );
		// Active host outcome shows a configure/"you have this" path, not an install button.
		$this->assertStringContainsString( 'reward_engagement', $html );
		// A not-installed member with null wporg_slug shows learn-more, never install.
		$this->assertStringContainsString( 'data-action="learn"', $html );
		$this->assertStringNotContainsString( 'data-action="install" data-slug="learnomy"', $html );
		// Onboarding nav + tertiary 3rd-party region present and ordered last.
		$this->assertStringContainsString( 'admin.php?page=wb-gamification-setup', $html );
		$posOutcomes = strpos( $html, 'data-region="outcomes"' );
		$posThird    = strpos( $html, 'data-region="thirdparty"' );
		$this->assertNotFalse( $posThird );
		$this->assertGreaterThan( $posOutcomes, $posThird, '3rd-party must come after outcomes' );
	}
}
```

- [ ] **Step 2: Run, expect fail**

Run: `composer test -- --filter PageTest`
Expected: FAIL — `Page::render` undefined.

- [ ] **Step 3: Implement `libs/wbcom-family/class-page.php`**

```php
<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the outcome-first family guide: 3 regions (outcomes, get-started,
 * also-works-with). Guide tone — one action per outcome, no promo chrome.
 */
class Page {

	public static function render( array $config ): string {
		$registry = registry();
		$host     = (string) ( $config['host'] ?? '' );
		$nonce    = (string) ( $config['nonce'] ?? '' );
		$onboard  = $config['onboarding_url'] ?? null;

		$out  = '<div class="wbcom-family">';
		// Region 1: outcomes (primary).
		$out .= '<div class="wbcom-family__outcomes" data-region="outcomes">';
		foreach ( $registry['outcomes'] as $key => $outcome ) {
			$out .= self::outcome_row( $key, $outcome, $registry, $host, $nonce );
		}
		$out .= '</div>';

		// Region 2: get-started (secondary) — link to existing onboarding.
		if ( $onboard ) {
			$out .= '<div class="wbcom-family__start" data-region="getstarted">'
				. '<a class="wbcom-family__link" href="' . esc_url( $onboard ) . '">'
				. esc_html__( 'New here? Run the setup guide', 'wb-gamification' ) . '</a></div>';
		}

		// Region 3: also-works-with (tertiary, de-emphasized).
		$out .= '<details class="wbcom-family__thirdparty" data-region="thirdparty"><summary>'
			. esc_html__( 'Also works with', 'wb-gamification' ) . '</summary><ul>';
		foreach ( $registry['third_party'] as $tp ) {
			$out .= '<li><strong>' . esc_html( $tp['name'] ) . '</strong> — ' . esc_html( $tp['note'] ) . '</li>';
		}
		$out .= '</ul></details></div>';

		return $out;
	}

	private static function outcome_row( string $key, array $outcome, array $registry, string $host, string $nonce ): string {
		// The member that enables this outcome (first requirement).
		$slug   = $outcome['requires'][0] ?? '';
		$member = $registry['members'][ $slug ] ?? array();
		$state  = $member ? State::member_state( $member ) : 'not_installed';

		// Decide the single action.
		if ( $slug === $host || 'active' === $state ) {
			$action = 'configure';
			$label  = __( 'Set it up', 'wb-gamification' );
			$href   = $member['learn_url'] ?? '#';
		} elseif ( 'installed_inactive' === $state ) {
			$action = 'activate';
			$label  = __( 'Activate', 'wb-gamification' );
			$href   = '#';
		} elseif ( ! empty( $member['wporg_slug'] ) ) {
			$action = 'install';
			$label  = __( 'Install & activate', 'wb-gamification' );
			$href   = '#';
		} else {
			$action = 'learn';
			$label  = __( 'See how it works', 'wb-gamification' );
			$href   = $member['learn_url'] ?? '#';
		}

		return '<div class="wbcom-family__outcome" data-outcome="' . esc_attr( $key ) . '" data-state="' . esc_attr( $state ) . '">'
			. '<div class="wbcom-family__icon" data-icon="' . esc_attr( $member['icon'] ?? 'circle' ) . '"></div>'
			. '<div class="wbcom-family__body"><h3>' . esc_html( $outcome['title'] ) . '</h3>'
			. '<p>' . esc_html( $outcome['description'] ) . '</p></div>'
			. '<a class="wbcom-family__action" data-action="' . esc_attr( $action ) . '" data-slug="' . esc_attr( $slug ) . '" '
			. 'data-nonce="' . esc_attr( $nonce ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>'
			. '</div>';
	}
}
```

- [ ] **Step 4: Run, expect pass**

Run: `composer test -- --filter PageTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add libs/wbcom-family/class-page.php tests/Unit/Family/PageTest.php
git commit -m "feat(family): outcome-first page renderer (3 regions, guide-not-ads)"
```

---

### Task 5: Kit orchestrator (public boot API)

**Files:**
- Modify: `libs/wbcom-family/class-kit.php`
- Test: `tests/Unit/Family/KitTest.php`

**Interfaces:**
- Consumes: `Installer`, `Page`.
- Produces: `Wbcom\Family\Kit::boot(array $config): void` — registers the installer AJAX (once) and stores config; `Kit::render(): string` returns the page using stored config + a fresh nonce. `$config = ['host'=>slug,'onboarding_url'=>string|null,'menu_hook'=>callable|null]`. boot() is idempotent (guards against double-registration).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class KitTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	/** @test */
	public function boot_registers_installer_ajax_once(): void {
		Functions\expect( 'add_action' )->once()->with( 'wp_ajax_wbcom_family_install', \Mockery::type( 'array' ) );
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) );
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) ); // second call must NOT re-add
	}

	/** @test */
	public function render_returns_page_html(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) );
		$html = \Wbcom\Family\Kit::render();
		$this->assertStringContainsString( 'data-region="outcomes"', $html );
	}
}
```

- [ ] **Step 2: Run, expect fail**

Run: `composer test -- --filter KitTest`
Expected: FAIL — `Kit::boot` undefined.

- [ ] **Step 3: Implement `libs/wbcom-family/class-kit.php`**

```php
<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * Public entry point. The host plugin calls Kit::boot() once and Kit::render()
 * inside its Integrations tab.
 */
class Kit {

	/** @var array<string,mixed> */
	private static $config = array();
	/** @var bool */
	private static $booted = false;

	public static function boot( array $config ): void {
		self::$config = $config;
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		Installer::register();
	}

	public static function render(): string {
		return Page::render(
			array(
				'host'           => self::$config['host'] ?? '',
				'onboarding_url' => self::$config['onboarding_url'] ?? null,
				'nonce'          => wp_create_nonce( Installer::ACTION ),
			)
		);
	}
}
```

- [ ] **Step 4: Run, expect pass**

Run: `composer test -- --filter KitTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add libs/wbcom-family/class-kit.php tests/Unit/Family/KitTest.php
git commit -m "feat(family): Kit orchestrator (idempotent boot + render)"
```

---

### Task 6: Wire the Kit into wb-gamification (Integrations tab + assets + JS)

**Files:**
- Create: `src/Admin/IntegrationsTab.php`
- Create: `assets/admin/family.css`, `assets/admin/family.js`
- Modify: `wb-gamification.php` (boot the adapter) — add one `IntegrationsTab::init()` call near the other `*::init()` calls.
- Modify: `src/Admin/SettingsPage.php` — add an "Integrations" tab entry that renders `IntegrationsTab::render()`.
- Test: `tests/Unit/Admin/IntegrationsTabTest.php`

**Interfaces:**
- Consumes: `Wbcom\Family\Kit`.
- Produces: `WBGam\Admin\IntegrationsTab::init(): void` (requires the Kit bootstrap, boots the Kit with gamification config, hooks asset enqueue + tab); `IntegrationsTab::render(): string` (returns `Kit::render()`); declares onboarding URL = `admin.php?page=wb-gamification-setup`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\IntegrationsTab;

class IntegrationsTabTest extends TestCase {
	protected function setUp(): void {
		parent::setUp(); Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'http://x/wp-admin/' . $p );
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	/** @test */
	public function render_outputs_family_guide_with_gamification_onboarding_link(): void {
		IntegrationsTab::init();
		$html = IntegrationsTab::render();
		$this->assertStringContainsString( 'data-region="outcomes"', $html );
		$this->assertStringContainsString( 'page=wb-gamification-setup', $html );
	}
}
```

- [ ] **Step 2: Run, expect fail**

Run: `composer test -- --filter IntegrationsTabTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Admin/IntegrationsTab.php`**

```php
<?php
namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

use Wbcom\Family\Kit;

/**
 * Host adapter: boots the Wbcom Family Kit for wb-gamification and exposes an
 * "Integrations" tab inside the gamification settings screen.
 */
class IntegrationsTab {

	public static function init(): void {
		require_once WB_GAM_PATH . 'libs/wbcom-family/bootstrap.php';
		Kit::boot(
			array(
				'host'           => 'wb-gamification',
				'onboarding_url' => admin_url( 'admin.php?page=wb-gamification-setup' ),
			)
		);
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function render(): string {
		return Kit::render();
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification' ) ) {
			return;
		}
		wp_enqueue_style( 'wbcom-family', WB_GAM_URL . 'assets/admin/family.css', array(), WB_GAM_VERSION );
		wp_enqueue_script( 'wbcom-family', WB_GAM_URL . 'assets/admin/family.js', array(), WB_GAM_VERSION, true );
		wp_localize_script( 'wbcom-family', 'wbcomFamily', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}
}
```

- [ ] **Step 4: Create `assets/admin/family.css`** (ux-foundation tokens; no promo chrome; desktop+iPad)

```css
.wbcom-family__outcomes { display: grid; gap: 12px; }
.wbcom-family__outcome { display: flex; align-items: center; gap: 16px; padding: 16px; border: 1px solid var(--bn-border, #e2e4e7); border-radius: 10px; background: var(--bn-surface, #fff); }
.wbcom-family__body { flex: 1; }
.wbcom-family__body h3 { margin: 0 0 4px; font-size: 14px; }
.wbcom-family__body p { margin: 0; color: var(--bn-text-muted, #646970); }
.wbcom-family__action { white-space: nowrap; }
.wbcom-family__outcome[data-state="active"] .wbcom-family__action { opacity: .8; }
.wbcom-family__start { margin: 16px 0; }
.wbcom-family__thirdparty { margin-top: 24px; color: var(--bn-text-muted, #646970); }
.wbcom-family__thirdparty summary { cursor: pointer; }
@media (max-width: 1024px) { .wbcom-family__outcome { flex-wrap: wrap; } }
```

- [ ] **Step 5: Create `assets/admin/family.js`** (install action only; one fetch, no nag)

```javascript
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.wbcom-family__action[data-action="install"]');
  if (!btn) return;
  e.preventDefault();
  btn.textContent = 'Installing…';
  btn.setAttribute('aria-disabled', 'true');
  var body = new URLSearchParams({ action: 'wbcom_family_install', slug: btn.dataset.slug, nonce: btn.dataset.nonce });
  fetch(window.wbcomFamily.ajax, { method: 'POST', credentials: 'same-origin', body: body })
    .then(function (r) { return r.json(); })
    .then(function (res) { btn.textContent = res.success ? 'Activated' : ((res.data && res.data.message) || 'Failed'); })
    .catch(function () { btn.textContent = 'Failed'; });
});
```

- [ ] **Step 6: Wire init + tab**

In `wb-gamification.php`, beside the other `*::init()` calls (e.g. near `SettingsPage`), add: `\WBGam\Admin\IntegrationsTab::init();`
In `src/Admin/SettingsPage.php`, add an "Integrations" entry to the tab list/switch that echoes `\WBGam\Admin\IntegrationsTab::render()` for its tab body (match the existing tab-rendering pattern in that file).

- [ ] **Step 7: Run tests + full suite**

Run: `composer test -- --filter IntegrationsTabTest` then `composer test`
Expected: target PASS; full suite green.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/IntegrationsTab.php assets/admin/family.css assets/admin/family.js wb-gamification.php src/Admin/SettingsPage.php tests/Unit/Admin/IntegrationsTabTest.php
git commit -m "feat(family): wire Family Kit into wb-gamification Integrations tab"
```

---

### Task 7: Browser-verify on the live site

**Files:** none (verification).

**Interfaces:** none.

- [ ] **Step 1: Open the gamification settings → Integrations tab**

Navigate (auto-login): `http://buddynext-dev.local/?autologin=1` then `http://buddynext-dev.local/wp-admin/admin.php?page=<gamification settings slug>` and select the Integrations tab. Use the Playwright MCP tools.

- [ ] **Step 2: Verify guide tone + states + 0 console errors**

Screenshot. Confirm: outcome rows render with one action each; family members already active (e.g. BuddyNext, Learnomy, WPMediaVerse are active on this site) show "Set it up", not install; the "Also works with" 3rd-party section is collapsed/below; the "Run the setup guide" link points to the gamification wizard. Check `browser_console_messages` (level error) = 0.

- [ ] **Step 3: Verify responsive + dark**

Resize to iPad width (1024) and confirm layout holds; toggle the host theme dark mode and confirm tokens adapt. Screenshot both.

- [ ] **Step 4: Record results**

Note the verification (pass/fail per check) in the task report. No commit (verification only). Any defect → fix in the relevant Kit/adapter file and re-run its unit test before re-verifying.

---

## Self-Review

**Spec coverage:** Portable Kit + bootstrap/version-guard → Task 1. Bundled registry (members/outcomes/3rd-party) → Task 1. Local install-state → Task 2. Free-only installer (cap/nonce/pro-refusal, WP core) → Task 3. Outcome-first 3-region page, guide-not-ads, onboarding nav, 3rd-party tertiary → Task 4. Brand-aware/re-parentable + idempotent boot → Task 5. wb-gamification "Integrations" tab + install JS + ux-foundation CSS → Task 6. Browser verify (states, tone, responsive, dark, 0 errors) → Task 7. Unified menu / onboarding-content reorientation correctly absent (out of scope).

**Placeholder scan:** No TBD/TODO; every code step has complete code. Task 6 Step 6 references "the existing tab-rendering pattern in that file" — the implementer must match SettingsPage's actual tab mechanism; flagged, not a placeholder for logic.

**Type consistency:** `registry()`, `State::member_state`/`outcome_available`, `Installer::ACTION`/`register`/`handle`, `Page::render($config)`, `Kit::boot`/`render`, `IntegrationsTab::init`/`render` are consistent across tasks. Page `$config` keys (`host`,`onboarding_url`,`nonce`) match what Kit passes. AJAX action string `wbcom_family_install` consistent (Installer + JS).

**Known data caveat (not a placeholder):** all `wporg_slug` are `null` in the registry (premium/pre-release members) → every member currently renders learn-more, not install. The installer MECHANISM is fully built + tested; enabling one-click install for any member is a one-field data change once that member is confirmed live on wp.org. Task 7 verifies the learn/configure/activate paths; the install path is unit-tested in Task 3.
