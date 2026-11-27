<?php
/**
 * Source-level checks on the admin stylesheets: the shared [hidden] rule (cards 10551797769,
 * 10551797790) and the Wbcom Family heading gap (card 10552238545).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminCssContractTest extends TestCase {

	private function css( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $rel );
	}

	/**
	 * The user-agent [hidden] rule loses to any author display (.wbgam-earn-row grid, .wbgam-btn
	 * inline-flex), so the attribute silently did nothing. One rule in the shared admin stack restores it.
	 */
	#[Test]
	public function hidden_attribute_wins_over_any_author_display_in_the_admin_stack(): void {
		$this->assertMatchesRegularExpression(
			'/(?:^|\})\s*\[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}/m',
			$this->css( 'assets/css/admin/utilities.css' ),
			'utilities.css (loaded on every plugin admin page, after components.css) restores display:none for [hidden]'
		);
		$this->assertMatchesRegularExpression( '/\[hidden\]\{display:none!important\}/', $this->css( 'assets/css/admin/utilities.min.css' ), 'the minified build carries it' );
		$this->assertMatchesRegularExpression( '/\[hidden\]\{display:none!important\}/', $this->css( 'assets/css/admin/utilities-rtl.min.css' ), 'and so does the RTL build' );
	}

	/** The per-class copies are gone: one rule, not one per class. */
	#[Test]
	public function no_admin_page_sheet_repeats_the_hidden_rule(): void {
		foreach ( glob( dirname( __DIR__, 3 ) . '/assets/css/admin/pages/*.css' ) as $file ) {
			if ( str_ends_with( $file, '.min.css' ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/\[hidden\]\s*\{\s*display:\s*none/', (string) file_get_contents( $file ), basename( $file ) );
		}
	}

	#[Test]
	public function family_heading_gap_uses_the_spacing_token(): void {
		$css = $this->css( 'assets/admin/family.css' );
		$this->assertMatchesRegularExpression( '/\.wbcom-family__brandtext h2\s*\{[^}]*margin:\s*0 0 var\(--wbgam-gap-2xs, 4px\)/', $css );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:margin|padding|gap)[^;}]*:\s*[^;}]*\b(?:2|4|8|12|16|24|32)px/', preg_replace( '/var\([^)]*\)/', '', $css ), 'every spacing value that has a token uses it' );
	}
}
