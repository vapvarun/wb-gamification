<?php
/**
 * Every toast title the server builds fits one line at 360px: a short fixed phrase, never a name.
 * Card 10550161421 ("You earned poin...", "Not approved: Replied in the forum" were cut).
 *
 * Source-level, like ManifestExitContractTest: it reads every `'message' =>` in NotificationBridge
 * and checks the translatable template. Names (badges, challenges, actions, members) go in the
 * detail line, so a title template never contains %s, and is under 40 characters.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class ToastTitleLengthTest extends TestCase {

	/**
	 * Titles of the full-screen celebration card (level up, league promotion, community goal), not
	 * a toast: that card has room and wraps. Named here so a toast cannot borrow the exception.
	 */
	private const POPUP_TITLES = array( 'You reached %s!', 'You moved up to %s League', 'Goal reached: %s' );

	/**
	 * Title templates: the string literals inside __()/_n() on the right of a `'message' =>` or
	 * assigned to $message / $title in NotificationBridge.
	 *
	 * @return array<int, string>
	 */
	private function titles(): array {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/NotificationBridge.php' );
		// The title expression itself: `'message' => __( ... )`, `$message = sprintf( __( ... )`,
		// `$title = sprintf( _n( ... )` - never a string further along the line.
		preg_match_all( '/(?:\'message\'\s*=>|\$message\s*=|\$title\s*=)\s*(?:sprintf\(\s*)?(?:\/\*.*?\*\/\s*)?(?:__|_n)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $src, $m );
		return array_values( array_diff( array_unique( $m[2] ), self::POPUP_TITLES ) );
	}

	#[Test]
	public function the_source_has_titles_to_check(): void {
		$this->assertGreaterThanOrEqual( 8, count( $this->titles() ), 'The pattern must keep finding the titles, or this test checks nothing.' );
	}

	#[Test]
	public function every_title_is_short_and_names_nothing(): void {
		foreach ( $this->titles() as $title ) {
			$this->assertStringNotContainsString( '%s', $title, "A name in a title can make it any length: \"{$title}\". Put it in the detail line." );
			$this->assertLessThan( 40, mb_strlen( str_replace( '%d', '9999', $title ) ), "Title too long for one line: \"{$title}\"." );
		}
	}
}
