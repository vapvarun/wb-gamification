<?php
/**
 * WP-CLI: seed / remove the per-unit QA test pages.
 *
 * Generates one page per Wbcom Block Quality Standard unit (15 today),
 * each rendering the Gutenberg block side-by-side with its matching
 * `[wb_gam_*]` shortcode so QA can verify every unit in isolation.
 * Catches the class of bug we just hit (`[wb_gam_earning_guide]`
 * fatal) by producing a real customer-visible URL for each unit.
 *
 * Idempotent — re-running updates content but never duplicates pages.
 *
 * @package WB_Gamification
 * @since   1.2.0
 */

namespace WBGam\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Seed and remove the per-unit QA test pages used by the journey
 * walker, the support team, and the visual regression baseline.
 *
 * Usage:
 *   wp wb-gamification qa seed-pages
 *   wp wb-gamification qa remove-pages
 *   wp wb-gamification qa list-pages
 */
class QASeedCommand {

	/**
	 * Create or update the index page + 15 per-unit QA pages.
	 *
	 * Each unit page renders the Gutenberg block alongside its
	 * matching shortcode so block-vs-shortcode parity can be eyeballed
	 * (the canonical regression check for page-builder compatibility).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print what would be created / updated without touching the DB.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification qa seed-pages
	 *     wp wb-gamification qa seed-pages --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function seed_pages( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$created = 0;
		$updated = 0;
		$ids     = array();

		// Index page first — its ID becomes post_parent for every unit page,
		// so themes that auto-include published pages in their primary
		// nav nest the 15 unit pages under one expandable entry rather
		// than cluttering the top-level menu.
		$index_id = $this->ensure_index_page( array(), $dry_run );

		foreach ( QAPages::MAP as $block_slug => $unit ) {
			$page_slug = QAPages::page_slug_for( $block_slug );
			$existing  = $this->find_page_by_slug( $page_slug );
			$content   = QAPages::build_post_content( $block_slug, $unit );

			$post_data = array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => 'QA — ' . $unit['title'],
				'post_name'      => $page_slug,
				'post_content'   => $content,
				'post_excerpt'   => '',
				'post_parent'    => (int) $index_id,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'meta_input'     => array(
					'_wb_gam_qa_seeded'     => '1',
					'_wb_gam_qa_block_slug' => $block_slug,
				),
			);

			if ( $existing ) {
				$post_data['ID'] = $existing->ID;
				if ( $dry_run ) {
					\WP_CLI::log( sprintf( '[dry] would UPDATE page %d (%s)', $existing->ID, $page_slug ) );
				} else {
					wp_update_post( $post_data );
				}
				$ids[ $block_slug ] = $existing->ID;
				++$updated;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log( sprintf( '[dry] would CREATE page (%s)', $page_slug ) );
				$ids[ $block_slug ] = 0;
				++$created;
				continue;
			}

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( sprintf( 'Failed to create %s: %s', $page_slug, $post_id->get_error_message() ) );
				continue;
			}
			$ids[ $block_slug ] = (int) $post_id;
			++$created;
		}

		// Re-render the index now that we know every unit page's ID, so
		// its body links resolve to real permalinks.
		$this->ensure_index_page( $ids, $dry_run );

		\WP_CLI::success(
			sprintf(
				'%s%d unit pages (%d created, %d updated) + index page.',
				$dry_run ? '[dry] ' : '',
				count( QAPages::MAP ),
				$created,
				$updated
			)
		);
	}

	/**
	 * Move every QA-seeded page to trash.
	 *
	 * Pages are detected by the `_wb_gam_qa_seeded` post meta key, so
	 * any page the admin manually edited still gets cleaned up — the
	 * meta marker is the source of truth, not the slug.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Permanently delete instead of trashing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification qa remove-pages
	 *     wp wb-gamification qa remove-pages --force
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function remove_pages( array $args, array $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );
		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wb_gam_qa_seeded', 'compare' => 'EXISTS' ),
					array( 'key' => '_wb_gam_qa_index',  'compare' => 'EXISTS' ),
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$removed = 0;
		foreach ( $query->posts as $post_id ) {
			if ( wp_delete_post( (int) $post_id, $force ) ) {
				++$removed;
			}
		}

		\WP_CLI::success(
			sprintf(
				'Removed %d QA page(s)%s.',
				$removed,
				$force ? ' (permanently)' : ' (trashed)'
			)
		);
	}

	/**
	 * Print the URL of every seeded QA page so QA / support can copy
	 * a smoke-test list straight from the terminal.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification qa list-pages
	 *
	 * @when after_wp_load
	 */
	public function list_pages(): void {
		$rows = array();
		foreach ( QAPages::MAP as $block_slug => $unit ) {
			$page = $this->find_page_by_slug( QAPages::page_slug_for( $block_slug ) );
			$rows[] = array(
				'unit'  => $unit['title'],
				'slug'  => $block_slug,
				'url'   => $page ? get_permalink( $page->ID ) : '(not seeded)',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'unit', 'slug', 'url' ) );

		$index = $this->find_page_by_slug( QAPages::INDEX_SLUG );
		if ( $index ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Index: ' . get_permalink( $index->ID ) );
		}
	}

	/**
	 * Create or update the QA index page.
	 *
	 * Called twice during a seed: once before unit pages exist (to
	 * obtain a parent ID for them) and once after (to render the
	 * index body with resolved permalinks).
	 *
	 * @param array<string, int> $page_ids Map of block-slug → post ID
	 *                                     for the just-seeded unit pages.
	 *                                     Empty on the first call.
	 * @param bool               $dry_run  Skip DB writes when true.
	 * @return int Index page ID, or 0 in dry-run mode when it doesn't exist yet.
	 */
	private function ensure_index_page( array $page_ids, bool $dry_run ): int {
		$existing = $this->find_page_by_slug( QAPages::INDEX_SLUG );
		$content  = QAPages::build_index_content( $page_ids );
		$data     = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => 'WB Gamification QA',
			'post_name'      => QAPages::INDEX_SLUG,
			'post_content'   => $content,
			'post_excerpt'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'meta_input'     => array( '_wb_gam_qa_index' => '1' ),
		);

		if ( $existing ) {
			$data['ID'] = $existing->ID;
			if ( ! $dry_run ) {
				wp_update_post( $data );
			}
			return (int) $existing->ID;
		}

		if ( $dry_run ) {
			return 0;
		}

		$id = wp_insert_post( $data, true );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Look up an existing page by its slug.
	 *
	 * @param string $slug Slug to find.
	 * @return \WP_Post|null
	 */
	private function find_page_by_slug( string $slug ): ?\WP_Post {
		$q = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $q->posts[0] ?? null;
	}
}
