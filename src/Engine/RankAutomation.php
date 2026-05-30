<?php
/**
 * WB Gamification — Rank Automation
 *
 * Executes configurable automation rules when a member levels up.
 * No UI builder required — rules are defined via the
 * `wb_gam_rank_automation_rules` filter or the
 * `wb_gam_rank_automation_rules` option (JSON array).
 *
 * Rule schema (each rule is an array):
 * {
 *   "trigger_level_id": 3,          // Fire when member reaches this level ID
 *   "actions": [
 *     {
 *       "type": "add_bp_group",      // Add member to a BuddyPress group
 *       "group_id": 42
 *     },
 *     {
 *       "type": "send_bp_message",   // Send a private BP message
 *       "sender_id": 1,              // Admin/bot user ID
 *       "subject": "You made it!",
 *       "content": "Congrats on reaching Level 3..."
 *     },
 *     {
 *       "type": "change_wp_role",    // Add a WordPress role
 *       "role": "contributor"
 *     }
 *   ]
 * }
 *
 * Usage in a plugin or functions.php:
 *
 *   add_filter( 'wb_gam_rank_automation_rules', function( array $rules ): array {
 *       $rules[] = [
 *           'trigger_level_id' => 3,
 *           'actions' => [
 *               [ 'type' => 'add_bp_group', 'group_id' => 42 ],
 *               [ 'type' => 'change_wp_role', 'role' => 'contributor' ],
 *           ],
 *       ];
 *       return $rules;
 *   } );
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Executes configurable automation rules when a member levels up.
 *
 * @package WB_Gamification
 */
final class RankAutomation {

	/**
	 * Register the level-changed action hook.
	 */
	public static function init(): void {
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 20, 3 );
		// Also handle the very first level assignment (Newcomer / 0 pts) —
		// the canonical "level changed" hook deliberately skips that case so
		// brand-new users don't get a level-up toast. But rank-automation
		// rules with `trigger_level_id = Newcomer` need to run, so listen
		// here too and reuse the existing handler (Basecamp 9925298656).
		add_action( 'wb_gam_level_assigned', array( __CLASS__, 'on_level_assigned' ), 20, 2 );
	}

	/**
	 * Bridge `wb_gam_level_assigned` (2-arg) into the existing
	 * `on_level_changed` handler signature.
	 *
	 * @param int   $user_id   Member who was just assigned their first level.
	 * @param array $new_level Level data (id, name, min_points).
	 */
	public static function on_level_assigned( int $user_id, array $new_level ): void {
		self::on_level_changed( $user_id, $new_level, null );
	}

	// ── Hook handler ────────────────────────────────────────────────────────────

	/**
	 * Called when a member advances to a new level.
	 *
	 * Receives the canonical 1.0.0 wb_gam_level_changed signature: array
	 * level data, not int IDs. Use `$new_level['id']` to match against
	 * rule `trigger_level_id`.
	 *
	 * @param int        $user_id   User who levelled up.
	 * @param array|null $new_level New level data (id, name, min_points) or null.
	 * @param array|null $old_level Previous level data or null.
	 */
	public static function on_level_changed( int $user_id, ?array $new_level = null, ?array $old_level = null ): void {
		$new_level_id = is_array( $new_level ) ? (int) ( $new_level['id'] ?? 0 ) : 0;
		if ( $new_level_id <= 0 ) {
			return;
		}

		$rules = self::get_rules();

		foreach ( $rules as $rule ) {
			if ( (int) ( $rule['trigger_level_id'] ?? 0 ) !== $new_level_id ) {
				continue;
			}

			foreach ( (array) ( $rule['actions'] ?? array() ) as $action ) {
				self::execute_action( $user_id, $action );
			}
		}
	}

	// ── Rule loading ────────────────────────────────────────────────────────────

	/**
	 * Return the active automation rules.
	 *
	 * Rules come from two sources (merged):
	 *   1. `wb_gam_rank_automation_rules` option — JSON array (admin-configurable)
	 *   2. `wb_gam_rank_automation_rules` filter — code-level rules
	 *
	 * @return array[]
	 */
	private static function get_rules(): array {
		$option_rules = array();
		$stored       = get_option( 'wb_gam_rank_automation_rules', '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$option_rules = $decoded;
			}
		}

		/**
		 * Filter the full set of rank automation rules.
		 *
		 * @param array[] $rules Array of rule definitions (see class docblock for schema).
		 */
		return (array) apply_filters( 'wb_gam_rank_automation_rules', $option_rules );
	}

	// ── Action execution ────────────────────────────────────────────────────────

	/**
	 * Execute a single automation action for a user.
	 *
	 * @param int   $user_id User to act on.
	 * @param array $action  Action definition array.
	 */
	private static function execute_action( int $user_id, array $action ): void {
		$type = $action['type'] ?? '';

		switch ( $type ) {

			case 'add_bp_group':
				self::action_add_bp_group( $user_id, (int) ( $action['group_id'] ?? 0 ) );
				break;

			case 'send_bp_message':
				self::action_send_bp_message(
					$user_id,
					(int) ( $action['sender_id'] ?? 1 ),
					(string) ( $action['subject'] ?? '' ),
					(string) ( $action['content'] ?? '' )
				);
				break;

			case 'change_wp_role':
				self::action_change_wp_role( $user_id, (string) ( $action['role'] ?? '' ) );
				break;

			default:
				/**
				 * Execute a custom automation action type.
				 *
				 * @param int    $user_id User to act on.
				 * @param array  $action  Full action definition.
				 * @param string $type    Action type string.
				 */
				do_action( 'wb_gam_rank_automation_action', $user_id, $action, $type );
				break;
		}
	}

	// ── Action implementations ──────────────────────────────────────────────────

	/**
	 * Add a member to a BuddyPress group.
	 *
	 * @param int $user_id  User to add to the group.
	 * @param int $group_id BuddyPress group ID.
	 */
	private static function action_add_bp_group( int $user_id, int $group_id ): void {
		if ( $group_id <= 0 || ! function_exists( 'groups_join_group' ) ) {
			return;
		}

		// Already a member — skip.
		if ( function_exists( 'groups_is_user_member' ) && groups_is_user_member( $user_id, $group_id ) ) {
			return;
		}

		groups_join_group( $group_id, $user_id );
	}

	/**
	 * Send a private BuddyPress message.
	 *
	 * @param int    $recipient_id User ID of the message recipient.
	 * @param int    $sender_id    User ID of the message sender.
	 * @param string $subject      Message subject line.
	 * @param string $content      Message body content.
	 */
	private static function action_send_bp_message( int $recipient_id, int $sender_id, string $subject, string $content ): void {
		if ( '' === $subject || '' === $content ) {
			return;
		}

		if ( ! function_exists( 'messages_new_message' ) ) {
			return;
		}

		messages_new_message(
			array(
				'sender_id'  => $sender_id > 0 ? $sender_id : 1,
				'recipients' => array( $recipient_id ),
				'subject'    => $subject,
				'content'    => $content,
			)
		);
	}

	/**
	 * Add a WordPress role to a user.
	 *
	 * Uses add_role (additive) rather than set_role (replacement) to avoid
	 * accidentally revoking capabilities.
	 *
	 * @param int    $user_id User to modify.
	 * @param string $role    WordPress role slug to add.
	 */
	private static function action_change_wp_role( int $user_id, string $role ): void {
		if ( '' === $role ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Only add if the role exists and user doesn't already have it.
		if ( get_role( $role ) && ! in_array( $role, (array) $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}
}
