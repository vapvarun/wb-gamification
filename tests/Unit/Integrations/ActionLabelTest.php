<?php
/**
 * One label per action id on every surface (card 10344406246).
 *
 * Jetonomy's mirrored reputation ids are awarded without being registered actions, so the toast
 * said "Points awarded" and the history showed a title-cased id. Both now read the one resolver.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Registry;
use WBGam\Integrations\Jetonomy\JetonomyIntegration;

/**
 * @coversDefaultClass \WBGam\Integrations\Jetonomy\JetonomyIntegration
 */
class ActionLabelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// Route the resolver's filter to the integration, as add_filter() would.
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value, ...$args ) => 'wb_gam_action_label' === $hook ? JetonomyIntegration::label( $value, ...$args ) : $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::label
	 * @covers \WBGam\Engine\Registry::label_for
	 */
	public function forum_awards_read_as_what_happened(): void {
		$this->assertSame( 'Replied in the forum', Registry::label_for( 'jetonomy_reply_created' ) );
		$this->assertSame( 'Your forum post was upvoted', Registry::label_for( 'jetonomy_reply_upvoted' ) );
		$this->assertSame( 'Your forum post was downvoted (reversed)', Registry::label_for( 'jetonomy_post_downvoted_revoked' ) );
	}

	/**
	 * @test
	 * @covers ::label
	 */
	public function other_ids_are_left_to_the_fallback(): void {
		$this->assertSame( '', JetonomyIntegration::label( '', 'jetonomy_something_new' ), 'Unknown forum reasons are not guessed.' );
		$this->assertSame( '', JetonomyIntegration::label( '', 'wp_publish_post' ), 'Other plugins\' ids are not touched.' );
		$this->assertSame( 'Named elsewhere', JetonomyIntegration::label( 'Named elsewhere', 'jetonomy_reply_created' ), 'A label another filter set wins.' );
		$this->assertSame( 'Jetonomy Something New', Registry::label_for( 'jetonomy_something_new' ) );
	}
}
