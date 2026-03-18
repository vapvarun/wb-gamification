<?php
/**
 * WB Gamification — Rank Automation
 *
 * Executes configurable automation rules when a member levels up.
 * No UI builder required — rules are defined via the
 * `wb_gamification_rank_automation_rules` filter or the
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
 *   add_filter( 'wb_gamification_rank_automation_rules', function( array $rules ): array {
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
		add_action( 'wb_gamification_level_changed', array( __CLASS__, 'on_level_changed' ), 20, 3 );
	}

	// ── Hook handler ────────────────────────────────────────────────────────────

	/**
	 * Called when a member advances to a new level.
	 *
	 * @param int $user_id      User who levelled up.
	 * @param int $old_level_id Previous level ID.
	 * @param int $new_level_id New level ID.
	 */
	public static function on_level_changed( int $user_id, int $old_level_id, int $new_level_id ): void {
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
	 *   2. `wb_gamification_rank_automation_rules` filter — code-level rules
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
		return (array) apply_filters( 'wb_gamification_rank_automation_rules', $option_rules );
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
				do_action( 'wb_gamification_rank_automation_action', $user_id, $action, $type );
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
