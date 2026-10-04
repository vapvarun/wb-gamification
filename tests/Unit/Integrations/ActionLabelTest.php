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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Registry;
use WBGam\Integrations\Jetonomy\JetonomyIntegration;

#[CoversClass( \WBGam\Integrations\Jetonomy\JetonomyIntegration::class )]
#[CoversMethod( \WBGam\Integrations\Jetonomy\JetonomyIntegration::class, 'label' )]
#[CoversMethod( \WBGam\Engine\Registry::class, 'label_for' )]
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

	#[Test]
	public function forum_awards_read_as_what_happened(): void {
		$this->assertSame( 'Replied in the forum', Registry::label_for( 'jetonomy_reply_created' ) );
		$this->assertSame( 'Your forum post was upvoted', Registry::label_for( 'jetonomy_reply_upvoted' ) );
		$this->assertSame( 'Your forum post was downvoted (reversed)', Registry::label_for( 'jetonomy_post_downvoted_revoked' ) );
		$this->assertSame( 'Earned a forum badge', Registry::label_for( 'jetonomy_pro_badge_earned' ), 'Pre-1.6.5 double-award rows still read.' );
		$this->assertSame( 'Forum badge removed', Registry::label_for( 'jetonomy_badge_revoked' ), 'A reason Jetonomy named _revoked is not read as a reversal.' );
	}

	#[Test]
	public function other_ids_are_left_to_the_fallback(): void {
		$this->assertSame( '', JetonomyIntegration::label( '', 'jetonomy_something_new' ), 'Unknown forum reasons are not guessed.' );
		$this->assertSame( '', JetonomyIntegration::label( '', 'wp_publish_post' ), 'Other plugins\' ids are not touched.' );
		$this->assertSame( 'Named elsewhere', JetonomyIntegration::label( 'Named elsewhere', 'jetonomy_reply_created' ), 'A label another filter set wins.' );
		$this->assertSame( 'Jetonomy Something New', Registry::label_for( 'jetonomy_something_new' ) );
	}

	#[Test]
	public function engine_awarded_ids_have_translatable_labels(): void {
		// Every id the engine awards itself: none may fall back to the title-cased id, which
		// is not a translatable string ("Login Bonus" stayed English on a Spanish site).
		$ids = array( 'login_bonus', 'streak_milestone', 'challenge_completed', 'community_challenge_completed', 'level_up', 'badge_earned', 'points_redeemed', 'redemption_refund', 'kudos_revoked', 'manual_bulk_award', 'manual_admin_reset', 'points_decay' );
		foreach ( $ids as $id ) {
			$this->assertNotSame( ucwords( str_replace( '_', ' ', $id ) ), Registry::label_for( $id ), "$id must come from a translatable built-in label" );
		}
		$this->assertSame( 'Daily login bonus', Registry::label_for( 'login_bonus' ) );
	}
}
