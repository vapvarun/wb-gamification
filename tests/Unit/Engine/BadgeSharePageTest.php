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
        Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ): string {
            return $url . '?' . http_build_query( $args );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_linkedin_url_contains_cert_name(): void {
        $url = BadgeSharePage::build_linkedin_url(
            'Community Champion',
            'My Site',
            2024,
            6,
            'https://example.com/wp-json/wb-gamification/v1/badges/champion/credential/42',
            'champion_42'
        );

        $this->assertStringContainsString( 'linkedin.com/profile/add', $url );
        $this->assertStringContainsString( 'Community Champion', urldecode( $url ) );
        $this->assertStringContainsString( 'My Site', urldecode( $url ) );
        $this->assertStringContainsString( '2024', $url );
        $this->assertStringContainsString( '6', $url );
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
