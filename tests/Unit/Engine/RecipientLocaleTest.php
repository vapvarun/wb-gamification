<?php
/**
 * Member-facing text is built in the reader's locale, not the request's.
 *
 * An admin in English awarding a German member used to queue the toast, the bell row and the email
 * in English: the handler ran in the actor's request. Every handler that stores or sends human text
 * is now registered through RecipientLocale, so these tests hold two things: the wrapper switches
 * and restores correctly, and each registration points at the right user id.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\CommunityNotifications;
use WBGam\Engine\NotificationBridge;
use WBGam\Engine\RecipientLocale;
use WBGam\Engine\TransactionalEmailEngine;

#[CoversClass( RecipientLocale::class )]
class RecipientLocaleTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The wrapper switches to the member's locale, runs the work, and puts the locale back.
	 *
	 * @return void
	 */
	public function test_runs_in_the_members_locale_and_restores(): void {
		$log = array();
		Functions\when( 'switch_to_user_locale' )->alias(
			static function ( $id ) use ( &$log ) {
				$log[] = 'switch:' . $id;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () use ( &$log ) {
				$log[] = 'restore';
				return 'en_US';
			}
		);

		$out = RecipientLocale::run_as_user(
			9,
			static function () use ( &$log ) {
				$log[] = 'work';
				return 'done';
			}
		);

		$this->assertSame( 'done', $out );
		$this->assertSame( array( 'switch:9', 'work', 'restore' ), $log );
	}

	/**
	 * Same language: WP reports no switch, and restoring would pop somebody else's entry.
	 *
	 * @return void
	 */
	public function test_never_restores_a_switch_that_did_not_happen(): void {
		Functions\when( 'switch_to_user_locale' )->justReturn( false );
		Functions\expect( 'restore_previous_locale' )->never();

		$this->assertSame( 1, RecipientLocale::run_as_user( 9, static fn() => 1 ) );
	}

	/**
	 * An exception in the work must not leave the request stuck in the member's locale.
	 *
	 * @return void
	 */
	public function test_restores_when_the_work_throws(): void {
		Functions\when( 'switch_to_user_locale' )->justReturn( true );
		Functions\expect( 'restore_previous_locale' )->once();

		$this->expectException( \RuntimeException::class );
		RecipientLocale::run_as_user(
			9,
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);
	}

	/**
	 * No recipient (0) means nothing to switch to.
	 *
	 * @return void
	 */
	public function test_no_user_runs_unchanged(): void {
		Functions\expect( 'switch_to_user_locale' )->never();
		Functions\expect( 'restore_previous_locale' )->never();

		$this->assertSame( 'x', RecipientLocale::run_as_user( 0, static fn() => 'x' ) );
	}

	/**
	 * Shared text (an activity post) is built in the site locale.
	 *
	 * @return void
	 */
	public function test_site_wrapper_uses_the_site_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\expect( 'switch_to_locale' )->once()->with( 'en_US' )->andReturn( true );
		Functions\expect( 'restore_previous_locale' )->once();

		$this->assertSame( 'ok', RecipientLocale::run_in_site( static fn() => 'ok' ) );
	}

	/**
	 * The wrapped callback gets the hook's own arguments untouched.
	 *
	 * @return void
	 */
	public function test_wrapped_callback_receives_the_hook_arguments(): void {
		Functions\when( 'switch_to_user_locale' )->justReturn( false );

		$seen    = null;
		$wrapped = RecipientLocale::user(
			static function ( ...$args ) use ( &$seen ) {
				$seen = $args;
			},
			1
		);
		$wrapped( 'a', 'b', 'c' );

		$this->assertSame( array( 'a', 'b', 'c' ), $seen );
	}

	/**
	 * Register a class's hooks and return each hook's callback, keyed by hook name.
	 *
	 * @param callable $init Class init().
	 * @return array<string, array<int, callable>>
	 */
	private function registered( callable $init ): array {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb ) use ( &$hooks ) {
				$hooks[ $hook ][] = $cb;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1 );
		$init();
		return $hooks;
	}

	/**
	 * Fire a registered callback; the stubbed switch records the user id it was asked for and stops
	 * there, so no handler body runs.
	 *
	 * @param callable $cb   Registered callback.
	 * @param array    $args Hook arguments.
	 * @return int User id the wrapper switched to.
	 */
	private function switched_to( callable $cb, array $args ): int {
		$id = 0;
		Functions\when( 'switch_to_user_locale' )->alias(
			static function ( $user_id ) use ( &$id ) {
				$id = (int) $user_id;
				throw new \LogicException( 'stop' );
			}
		);
		try {
			$cb( ...$args );
		} catch ( \LogicException $e ) {
			unset( $e );
		}
		return $id;
	}

	/**
	 * Every toast handler is registered through the wrapper and switches to the RECIPIENT, which for
	 * kudos and submissions is not the first hook argument.
	 *
	 * @return void
	 */
	public function test_notification_bridge_handlers_switch_to_the_recipient(): void {
		$hooks = $this->registered( array( NotificationBridge::class, 'init' ) );

		$expect = array(
			'wb_gam_points_awarded'         => array( array( 11, null, 5 ), 11 ),
			'wb_gam_badge_awarded'          => array( array( 11, array(), 'b' ), 11 ),
			'wb_gam_level_changed'          => array( array( 11, array(), array() ), 11 ),
			'wb_gam_streak_milestone'       => array( array( 11, 7 ), 11 ),
			'wb_gam_challenge_completed'    => array( array( 11, array() ), 11 ),
			'wb_gam_kudos_given'            => array( array( 5, 11, 'hi', 3 ), 11 ),
			'wb_gam_award_skipped'          => array( array( 11, 'a', 'cooldown', array() ), 11 ),
			'wb_gam_cohort_outcome'         => array( array( 11, 1, 2, 'promoted' ), 11 ),
			'wb_gam_community_goal_reached' => array( array( 11, 4, 10 ), 11 ),
			'wb_gam_submission_approved'    => array( array( 4, 11, 'a' ), 11 ),
			'wb_gam_submission_rejected'    => array( array( 4, 11, 'a', 1, 'n' ), 11 ),
		);
		foreach ( $expect as $hook => list( $args, $user ) ) {
			$this->assertNotEmpty( $hooks[ $hook ] ?? array(), $hook );
			$this->assertSame( $user, $this->switched_to( $hooks[ $hook ][0], $args ), $hook . ' must build its text in the recipient locale.' );
		}
	}

	/**
	 * The community inbox rows are built in the recipient locale too.
	 *
	 * @return void
	 */
	public function test_community_notifications_switch_to_the_recipient(): void {
		$hooks = $this->registered( array( CommunityNotifications::class, 'init' ) );

		$expect = array(
			'wb_gam_badge_awarded'       => array( array( 11, array(), 'b' ), 11 ),
			'wb_gam_kudos_given'         => array( array( 5, 11, '', 3 ), 11 ),
			'wb_gam_redemption_fulfilled' => array( array( 8, 11 ), 11 ),
			'wb_gam_submission_rejected' => array( array( 4, 11, 'a' ), 11 ),
			'wb_gam_personal_record'     => array( array( 11, 'week', 30, 20, '' ), 11 ),
		);
		foreach ( $expect as $hook => list( $args, $user ) ) {
			$this->assertSame( $user, $this->switched_to( $hooks[ $hook ][0], $args ), $hook );
		}
	}

	/**
	 * Transactional emails are rendered in the recipient locale (the redemption hook leads with the redemption id).
	 *
	 * @return void
	 */
	public function test_transactional_emails_switch_to_the_recipient(): void {
		$hooks = $this->registered( array( TransactionalEmailEngine::class, 'init' ) );

		$this->assertSame( 11, $this->switched_to( $hooks['wb_gam_level_changed'][0], array( 11, array(), array() ) ) );
		$this->assertSame( 11, $this->switched_to( $hooks['wb_gam_points_redeemed'][0], array( 8, 11, array(), null ) ) );
	}

	/**
	 * Call a (possibly private) static method and report which user the locale switch was for.
	 *
	 * @param class-string $class  Class.
	 * @param string       $method Method.
	 * @param array        $args   Arguments.
	 * @return int
	 */
	private function switched_by( string $class, string $method, array $args ): int {
		$m = new \ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $this->switched_to( static fn( ...$a ) => $m->invoke( null, ...$a ), $args );
	}

	/**
	 * Emails sent from cron or Action Scheduler are built in the member's locale, not the site's.
	 *
	 * @return void
	 */
	public function test_cron_emails_switch_to_the_member(): void {
		$this->assertSame( 11, $this->switched_by( \WBGam\Engine\WeeklyEmailEngine::class, 'send_to_user', array( 11 ) ) );
		$this->assertSame( 11, $this->switched_by( \WBGam\Engine\LeaderboardNudge::class, 'send_nudge', array( 11 ) ) );
	}

	/**
	 * Sentences handed to other plugins through a hook are built in the member's locale.
	 *
	 * @return void
	 */
	public function test_hook_payload_sentences_are_built_in_the_members_locale(): void {
		$this->assertSame(
			11,
			$this->switched_by( \WBGam\Engine\StatusRetentionEngine::class, 'send_nudge', array( 11, array(), array( 'name' => 'Gold' ), 5 ) )
		);

		Functions\when( 'get_user_meta' )->alias( static fn( $u, $key ) => 'wb_gam_pr_best_week' === $key ? 10 : ( 'wb_gam_pr_run_week' === $key ? array( 'key' => 'old', 'total' => 0 ) : '' ) );
		Functions\when( 'current_time' )->justReturn( '2026-11-27 10:00:00' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'wp_date' )->justReturn( '2026-W48' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertSame(
			11,
			$this->switched_by( \WBGam\Engine\PersonalRecordEngine::class, 'maybe_record', array( 11, 'week', 30 ) )
		);
	}

	/**
	 * Public feed text (BuddyPress activity) is built in the site locale, whoever fired the event.
	 *
	 * @return void
	 */
	public function test_activity_streams_build_in_the_site_locale(): void {
		$hooks = $this->registered( array( \WBGam\BuddyPress\Stream\BadgeStream::class, 'init' ) );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$asked = '';
		Functions\when( 'switch_to_locale' )->alias(
			static function ( $locale ) use ( &$asked ) {
				$asked = $locale;
				throw new \LogicException( 'stop' );
			}
		);
		try {
			$hooks['wb_gam_badge_awarded'][0]( 11, array(), 'b' );
		} catch ( \LogicException $e ) {
			unset( $e );
		}
		$this->assertSame( 'en_US', $asked );
	}
}
