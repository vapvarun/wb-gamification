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
		// sanitize_key, sanitize_text_field, sanitize_textarea_field, absint, wp_unslash
		// are stubs provided by Brain\Monkey automatically for WP functions.
		// If not, define them here:
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => is_array( $v ) ? array_map( 'stripslashes', $v ) : stripslashes( (string) $v ) );
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

		$this->assertNotNull( $rule );
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

		$this->assertNotNull( $rule );
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

		$this->assertNotNull( $rule );
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
