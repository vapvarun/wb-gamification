<?php
/**
 * WP Sell Services triggers: who is paid, and who is refused.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class SellServicesManifestTest extends TestCase {

	private function triggers(): array {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/' );
		}
		if ( ! defined( 'WPSS_VERSION' ) ) {
			define( 'WPSS_VERSION', '1.7.2' );
		}
		$manifest = include dirname( __DIR__, 3 ) . '/integrations/wp-sell-services.php';
		$out      = array();
		foreach ( $manifest['triggers'] as $t ) {
			$out[ $t['id'] ] = $t;
		}
		return $out;
	}

	public function test_the_seller_is_paid_when_an_order_completes(): void {
		$cb = $this->triggers()['wpss_order_completed']['user_callback'];

		$this->assertSame( 7, $cb( 100, (object) array( 'vendor_id' => 7, 'customer_id' => 9 ) ) );
	}

	public function test_a_seller_ordering_from_themselves_earns_nothing(): void {
		$cb = $this->triggers()['wpss_order_completed']['user_callback'];

		$this->assertSame( 0, $cb( 100, (object) array( 'vendor_id' => 7, 'customer_id' => 7 ) ) );
	}

	public function test_a_missing_or_malformed_order_earns_nothing_and_does_not_fatal(): void {
		$cb = $this->triggers()['wpss_order_completed']['user_callback'];

		$this->assertSame( 0, $cb( 100, null ) );
		$this->assertSame( 0, $cb( 100, (object) array() ) );
		$this->assertSame( 0, $cb( 100, (object) array( 'vendor_id' => 0, 'customer_id' => 0 ) ) );
	}

	public function test_a_review_without_the_sell_services_classes_earns_nothing(): void {
		$cb = $this->triggers()['wpss_review_created']['user_callback'];

		$this->assertSame( 0, $cb( 5, 100 ) );
	}

	public function test_every_trigger_has_lazy_translatable_words_and_a_known_category(): void {
		foreach ( $this->triggers() as $id => $t ) {
			$this->assertInstanceOf( \Closure::class, $t['label'], "$id label must be lazy" );
			$this->assertInstanceOf( \Closure::class, $t['description'], "$id description must be lazy" );
			$this->assertSame( 'commerce', $t['category'] );
		}
	}

	public function test_a_moderated_review_pays_only_when_approved(): void {
		$cb = $this->triggers()['wpss_review_approved']['user_callback'];

		$this->assertSame( 0, $cb( 5, 'rejected' ), 'A rejection never pays.' );
		$this->assertSame( 0, $cb( 5, 'pending' ) );
		$this->assertSame( 0, $cb( 5, 'approved' ), 'Without the Sell Services classes nothing resolves, and nothing fatals.' );
		$this->assertSame( 'wpss_review_moderated', $this->triggers()['wpss_review_approved']['hook'] );
	}
}
