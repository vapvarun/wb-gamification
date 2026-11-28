<?php
/**
 * The toast title's join phrases come from the server, one entry per count, built with _n().
 *
 * toast.js joined "and a new badge" from strings localised in the page language while the number and
 * the point name before it were already in the recipient's, so a German member on an English site read
 * "+6.000 Punkte and a new badge". NotificationBridge::toast_i18n() now builds every phrase in the
 * recipient's locale, as a list indexed by the count so languages with more plural forms are right.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

#[CoversMethod( NotificationBridge::class, 'toast_i18n' )]
class NotificationToastI18nTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( '__' )->returnArg( 1 );
		// A fake locale switch: records that the table was built while the recipient's locale was active.
		$GLOBALS['wbgam_test_locale'] = 'site';
		Functions\when( 'switch_to_user_locale' )->alias(
			static function () {
				$GLOBALS['wbgam_test_locale'] = 'de_DE';
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () {
				$GLOBALS['wbgam_test_locale'] = 'site';
				return 'site';
			}
		);
		Functions\when( '_n' )->alias(
			static fn( $s, $p, $n ) => 'de_DE' === $GLOBALS['wbgam_test_locale'] ? ( 1 === (int) $n ? 'und ein neues Abzeichen' : 'und %d neue Abzeichen' ) : ( 1 === (int) $n ? $s : $p )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wbgam_test_locale'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function the_join_phrases_are_built_in_the_recipients_locale_one_per_count(): void {
		$i18n = NotificationBridge::toast_i18n();

		$this->assertSame( 'site', $GLOBALS['wbgam_test_locale'], 'the locale is put back' );
		$this->assertSame( 'und ein neues Abzeichen', $i18n['andBadges'][1] );
		$this->assertSame( 'und 2 neue Abzeichen', $i18n['andBadges'][2], 'the count is filled in per entry' );
		$this->assertSame( 'und 21 neue Abzeichen', $i18n['andBadges'][21] );
		$this->assertSame( '', $i18n['andBadges'][0] );
		$this->assertCount( 22, $i18n['andRewards'] );
		$this->assertIsString( $i18n['andKudos'] );
	}
}
