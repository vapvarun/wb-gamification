<?php
/**
 * WP-CLI: Actions commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * List registered gamification actions from the command line.
 *
 * @package WB_Gamification
 */
class ActionsCommand {

	/**
	 * List all registered gamification actions.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts table, csv, json, count. Default: table.
	 *
	 * [--category=<cat>]
	 * : Filter by category (e.g. buddypress, wordpress, commerce).
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification actions list
	 *   wp wb-gamification actions list --format=json
	 *   wp wb-gamification actions list --category=buddypress
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function list( array $args, array $assoc_args ): void {
		$actions  = Registry::get_actions();
		$category = $assoc_args['category'] ?? '';
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $actions ) ) {
			\WP_CLI::line( 'No actions registered. Are the plugin hooks loaded?' );
			return;
		}

		$rows = array();
		foreach ( $actions as $id => $action ) {
			if ( $category && ( $action['category'] ?? '' ) !== $category ) {
				continue;
			}

			$enabled     = (bool) get_option( 'wb_gam_enabled_' . $id, true );
			$pts_option  = get_option( 'wb_gam_points_' . $id, null );
			$pts_display = null !== $pts_option ? (int) $pts_option : ( $action['default_points'] ?? '—' );

			$rows[] = array(
				'id'        => $id,
				'label'     => $action['label'] ?? $id,
				'category'  => $action['category'] ?? 'general',
				'points'    => $pts_display,
				'daily_cap' => $action['daily_cap'] > 0 ? $action['daily_cap'] : '∞',
				'cooldown'  => $action['cooldown'] > 0 ? $action['cooldown'] . 's' : '—',
				'enabled'   => $enabled ? 'yes' : 'no',
			);
		}

		if ( empty( $rows ) ) {
			\WP_CLI::line( 'No actions match the given filter.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'id', 'label', 'category', 'points', 'daily_cap', 'cooldown', 'enabled' )
		);
	}
}
