<?php
/**
 * QA pages — single source of truth for the canonical block ↔ shortcode
 * mapping consumed by the seed command, the dispatch unit test, and the
 * Playwright journey walker.
 *
 * Adding a new block? Add ONE entry here. Every consumer picks it up
 * automatically:
 *
 *   - `wp wb-gamification qa:seed-pages` creates a new test page.
 *   - `tests/Unit/Engine/ShortcodeHandlerTest` adds a dispatch case.
 *   - `audit/journeys/qa/01-all-units-render.md` walks it on next CI run.
 *
 * @package WBGam\CLI
 * @since   1.2.0
 */

namespace WBGam\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical {block-slug => shortcode-handler} mapping.
 *
 * Every block migrated to the Wbcom Block Quality Standard registers
 * here so the QA tooling stays in lockstep with the actual block surface.
 */
final class QAPages {

	/**
	 * Slug for the index page that lists every QA test page.
	 */
	const INDEX_SLUG = 'wb-gamification-qa';

	/**
	 * Per-page slug prefix. The full slug is `{PAGE_PREFIX}-{block-slug}`.
	 */
	const PAGE_PREFIX = 'wb-gamification-qa';

	/**
	 * The 15 canonical units. Keys are block slugs (matching
	 * `src/Blocks/<slug>/block.json#name` minus the `wb-gamification/`
	 * namespace). Values describe how each unit renders on the QA page.
	 *
	 * - `shortcode`   — the registered `[wb_gam_*]` tag (without brackets).
	 * - `title`       — human-readable heading on the QA page.
	 * - `block_attrs` — JSON object literal injected into the block markup.
	 * - `shortcode_attrs` — k=v string for the shortcode invocation.
	 *
	 * @var array<string, array{shortcode: string, title: string, block_attrs: string, shortcode_attrs: string}>
	 */
	const MAP = array(
		'leaderboard'          => array(
			'shortcode'       => 'wb_gam_leaderboard',
			'title'           => 'Leaderboard',
			'block_attrs'     => '{"period":"week","limit":5,"show_avatars":true}',
			'shortcode_attrs' => 'period="week" limit="5" show_avatars="1"',
		),
		'member-points'        => array(
			'shortcode'       => 'wb_gam_member_points',
			'title'           => 'Member Points',
			'block_attrs'     => '{"show_level":true,"show_progress_bar":true}',
			'shortcode_attrs' => 'show_level="1" show_progress_bar="1"',
		),
		'badge-showcase'       => array(
			'shortcode'       => 'wb_gam_badge_showcase',
			'title'           => 'Badge Showcase',
			'block_attrs'     => '{"show_locked":true,"limit":12}',
			'shortcode_attrs' => 'show_locked="1" limit="12"',
		),
		'level-progress'       => array(
			'shortcode'       => 'wb_gam_level_progress',
			'title'           => 'Level Progress',
			'block_attrs'     => '{"show_progress_bar":true,"show_next_level":true,"show_icon":true}',
			'shortcode_attrs' => 'show_progress_bar="1" show_next_level="1" show_icon="1"',
		),
		'challenges'           => array(
			'shortcode'       => 'wb_gam_challenges',
			'title'           => 'Challenges',
			'block_attrs'     => '{"show_completed":true,"show_progress_bar":true,"limit":3}',
			'shortcode_attrs' => 'show_completed="1" show_progress_bar="1" limit="3"',
		),
		'streak'               => array(
			'shortcode'       => 'wb_gam_streak',
			'title'           => 'Streak',
			'block_attrs'     => '{"show_longest":true,"show_heatmap":false}',
			'shortcode_attrs' => 'show_longest="1" show_heatmap="0"',
		),
		'top-members'          => array(
			'shortcode'       => 'wb_gam_top_members',
			'title'           => 'Top Members',
			'block_attrs'     => '{"limit":5,"period":"all_time","layout":"podium","show_badges":true,"show_level":true}',
			'shortcode_attrs' => 'limit="5" period="all_time" layout="podium" show_badges="1" show_level="1"',
		),
		'kudos-feed'           => array(
			'shortcode'       => 'wb_gam_kudos_feed',
			'title'           => 'Kudos Feed',
			'block_attrs'     => '{"limit":10,"show_messages":true}',
			'shortcode_attrs' => 'limit="10" show_messages="1"',
		),
		'year-recap'           => array(
			'shortcode'       => 'wb_gam_year_recap',
			'title'           => 'Year Recap',
			'block_attrs'     => '{"show_share_button":true,"show_badges":true,"show_kudos":true}',
			'shortcode_attrs' => 'show_share_button="1" show_badges="1" show_kudos="1"',
		),
		'points-history'       => array(
			'shortcode'       => 'wb_gam_points_history',
			'title'           => 'Points History',
			'block_attrs'     => '{"limit":20,"show_action_label":true}',
			'shortcode_attrs' => 'limit="20" show_action_label="1"',
		),
		'earning-guide'        => array(
			'shortcode'       => 'wb_gam_earning_guide',
			'title'           => 'Earning Guide',
			'block_attrs'     => '{"columns":3,"show_category_headers":true}',
			'shortcode_attrs' => 'columns="3" show_category_headers="1"',
		),
		'hub'                  => array(
			'shortcode'       => 'wb_gam_hub',
			'title'           => 'Gamification Hub',
			'block_attrs'     => '{}',
			'shortcode_attrs' => '',
		),
		'community-challenges' => array(
			'shortcode'       => 'wb_gam_community_challenges',
			'title'           => 'Community Challenges',
			'block_attrs'     => '{"limit":5,"show_progress_bar":true}',
			'shortcode_attrs' => 'limit="5" show_progress_bar="1"',
		),
		'cohort-rank'          => array(
			'shortcode'       => 'wb_gam_cohort_rank',
			'title'           => 'Cohort Rank',
			'block_attrs'     => '{"limit":5}',
			'shortcode_attrs' => 'limit="5"',
		),
		'redemption-store'     => array(
			'shortcode'       => 'wb_gam_redemption_store',
			'title'           => 'Redemption Store',
			'block_attrs'     => '{"columns":3,"showBalance":true,"showStock":true}',
			'shortcode_attrs' => 'columns="3" show_balance="1" show_stock="1"',
		),
	);

	/**
	 * The slug used for the per-block QA page (under PAGE_PREFIX).
	 *
	 * @param string $block_slug Block slug from MAP.
	 * @return string Full WP page slug, e.g. `wb-gamification-qa-leaderboard`.
	 */
	public static function page_slug_for( string $block_slug ): string {
		return self::PAGE_PREFIX . '-' . $block_slug;
	}

	/**
	 * Build the wide-aligned block + shortcode markup for one unit.
	 *
	 * The page presents both renderings side-by-side under labelled
	 * headings so QA can spot block-vs-shortcode divergence at a
	 * glance — the parity check that would have caught the
	 * earning-guide-shortcode-fatal bug.
	 *
	 * @param string               $block_slug Block slug from MAP.
	 * @param array<string, mixed> $unit       Map entry for that slug.
	 * @return string HTML/block markup ready for `post_content`.
	 */
	public static function build_post_content( string $block_slug, array $unit ): string {
		$block_name     = 'wb-gamification/' . $block_slug;
		$block_attrs    = $unit['block_attrs'] ?: '';
		$shortcode_open = '[' . $unit['shortcode']
			. ( '' !== $unit['shortcode_attrs'] ? ' ' . $unit['shortcode_attrs'] : '' )
			. ']';

		// Caption text uses HTML-entity brackets so do_shortcode can't
		// match the literal `[wb_gam_*]` in a heading and render it
		// twice (once in the heading, once in the wp:shortcode block).
		$shortcode_caption = '&#91;' . $unit['shortcode']
			. ( '' !== $unit['shortcode_attrs'] ? ' ' . $unit['shortcode_attrs'] : '' )
			. '&#93;';

		// Two H2-led sections — block then shortcode — so QA can scroll
		// vertically and confirm both halves render identically.
		return <<<HTML
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Block — <code>wp:{$block_name}</code></h2>
<!-- /wp:heading -->

<!-- wp:{$block_name} {$block_attrs} /-->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Shortcode — <code>{$shortcode_caption}</code></h2>
<!-- /wp:heading -->

<!-- wp:shortcode -->
{$shortcode_open}
<!-- /wp:shortcode -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:paragraph {"className":"wb-gam-qa-footer"} -->
<p class="wb-gam-qa-footer"><em>Both halves above must render identically. Mismatch = bug.</em></p>
<!-- /wp:paragraph -->
HTML;
	}

	/**
	 * Build the auto-generated index page content listing every unit.
	 *
	 * Renders as a Gutenberg list block of links so the page owner can
	 * tweak intro copy without touching the seeder.
	 *
	 * @param array<string, int> $page_ids Map of block-slug → page ID
	 *                                     (post ID after creation).
	 * @return string Block markup for the index page body.
	 */
	public static function build_index_content( array $page_ids ): string {
		$rows = '';
		foreach ( self::MAP as $slug => $unit ) {
			$page_id = $page_ids[ $slug ] ?? 0;
			$url     = $page_id ? get_permalink( $page_id ) : '#';
			// HTML-entity-encode the brackets so do_shortcode() can't
			// match the literal `[wb_gam_*]` in this navigational text.
			// Without the encoding, every list item renders its full
			// shortcode inline — turning the index into a gallery
			// instead of a navigation page.
			$rows .= sprintf(
				'<li><a href="%s">%s</a> — <code>wp:wb-gamification/%s</code> + <code>&#91;%s&#93;</code></li>',
				esc_url( $url ),
				esc_html( $unit['title'] ),
				esc_html( $slug ),
				esc_html( $unit['shortcode'] )
			);
		}

		return <<<HTML
<!-- wp:paragraph -->
<p>QA test pages — one per Wbcom Block Quality Standard unit. Each page renders the Gutenberg block and its matching <code>[wb_gam_*]</code> shortcode side-by-side. They must look identical.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>{$rows}</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p><em>Generated by <code>wp wb-gamification qa:seed-pages</code>. Removed by <code>wp wb-gamification qa:remove-pages</code>.</em></p>
<!-- /wp:paragraph -->
HTML;
	}
}
