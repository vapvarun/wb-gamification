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
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->alias( static fn( $p ) => 'wb-gamification/wb-gamification.php' === $p );
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'http://x/wp-admin/' . $p );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	/** @test */
	public function renders_guide_not_ads_with_three_regions(): void {
		$html = \Wbcom\Family\Page::render( array( 'host' => 'wb-gamification', 'onboarding_url' => 'admin.php?page=wb-gamification-setup', 'nonce' => 'n' ) );
		// Guide tone: no ad/promo/banner markup.
		$this->assertDoesNotMatchRegularExpression( '/class="[^"]*(promo|upsell|advert|\bad\b)[^"]*"/i', $html );
		// Active host outcome shows a configure/"you have this" path, not an install button.
		$this->assertStringContainsString( 'reward_engagement', $html );
		// A not-installed premium member (null wporg_slug) shows a "Get" action, never install.
		$this->assertStringContainsString( 'data-action="get"', $html );
		$this->assertStringNotContainsString( 'data-action="install" data-slug="learnomy"', $html );
		// Onboarding nav + tertiary 3rd-party region present and ordered last.
		$this->assertStringContainsString( 'admin.php?page=wb-gamification-setup', $html );
		$posOutcomes = strpos( $html, 'data-region="outcomes"' );
		$posThird    = strpos( $html, 'data-region="thirdparty"' );
		$this->assertNotFalse( $posThird );
		$this->assertGreaterThan( $posOutcomes, $posThird, '3rd-party must come after outcomes' );
		// Active host renders configure action, never install.
		$this->assertStringContainsString( 'data-action="configure" data-slug="wb-gamification"', $html );
		$this->assertStringNotContainsString( 'data-action="install" data-slug="wb-gamification"', $html );
	}

	/** @test */
	public function renders_without_getstarted_when_onboarding_url_is_null(): void {
		$html = \Wbcom\Family\Page::render( array( 'host' => 'wb-gamification', 'onboarding_url' => null, 'nonce' => 'n' ) );
		// getstarted region must be absent when no onboarding URL.
		$this->assertStringNotContainsString( 'data-region="getstarted"', $html );
		// outcomes and thirdparty still present and ordered correctly.
		$this->assertStringContainsString( 'data-region="outcomes"', $html );
		$this->assertStringContainsString( 'data-region="thirdparty"', $html );
		$posOutcomes = strpos( $html, 'data-region="outcomes"' );
		$posThird    = strpos( $html, 'data-region="thirdparty"' );
		$this->assertGreaterThan( $posOutcomes, $posThird, '3rd-party must come after outcomes even without onboarding' );
	}

	/** @test */
	public function brands_as_wbcom_family_and_names_each_product(): void {
		$html = \Wbcom\Family\Page::render( array( 'host' => 'wb-gamification', 'onboarding_url' => null, 'nonce' => 'n' ) );
		// Brand header present — establishes "Wbcom Family" (new in domain).
		$this->assertStringContainsString( 'data-region="brand"', $html );
		$this->assertStringContainsString( 'Wbcom Family', $html );
		// Each outcome names the product that powers it (users do not know icons).
		$this->assertStringContainsString( 'wbcom-family__member', $html );
		$this->assertStringContainsString( 'Learnomy', $html );
		// Premium, not installed → "Get" action plus a "View details" link to the Pro page.
		$this->assertStringContainsString( 'data-action="get" data-slug="learnomy"', $html );
		$this->assertStringContainsString( 'wbcom-family__details', $html );
		// BuddyNext is not on the store yet → honest "coming soon", no broken link.
		$this->assertStringContainsString( 'data-action="soon"', $html );
	}
}
