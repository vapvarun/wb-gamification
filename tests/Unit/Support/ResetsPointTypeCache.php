<?php
/**
 * Clear PointTypeRepository's per-request static cache between tests.
 *
 * In production every request starts empty; in a test run the statics survive from one test to
 * the next, so a test that fakes $wpdb rows for something else would leak them in as point types.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Support;

use WBGam\Repository\PointTypeRepository;

trait ResetsPointTypeCache {

	/**
	 * Reset the repository's memoised rows.
	 */
	private function resetPointTypeCache(): void {
		foreach ( array( 'request_cache_all', 'request_cache_default' ) as $prop ) {
			( new \ReflectionProperty( PointTypeRepository::class, $prop ) )->setValue( null, null );
		}
	}
}
