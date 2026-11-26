<?php
/**
 * WB Gamification — Jetonomy (Free) Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Jetonomy free is active.
 * No dependency on WB Gamification at load time.
 *
 * Forum and content reputation events (post/reply/vote/idea/flag) flow
 * through `jetonomy_reputation_changed` and are mirrored by
 * `WBGam\Integrations\Jetonomy\JetonomyIntegration::on_reputation_changed`.
 * This manifest covers the FREE Jetonomy events that do NOT route through
 * Reputation: space membership, gated-space admission, trust-level
 * progression, and host-plugin membership activation.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/jetonomy/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'JETONOMY_VERSION' ) && ! class_exists( '\\Jetonomy\\Plugin' ) ) {
	return array();
}

// Jetonomy Pro's Learnomy adapter reports every course, cohort and space seat as a membership, so a
// free enrolment paid "Activate a membership" on top of Learnomy's own enrolment award. Only a paid
// Learnomy plan (lrn_membership_) is a membership here (owner decision, 18 Nov 2026).
add_filter(
	'wb_gam_membership_level_earns',
	static fn( $earns, $level_id, $source ): bool => (bool) $earns && ! ( 'learnomy' === $source && preg_match( '/^lrn_(course|cohort|space)_/', (string) $level_id ) ),
	10,
	3
);

/*
 * Forum reputation events, as real actions the owner controls.
 *
 * Jetonomy decides WHETHER a forum event counts (its Reputation settings, anti-abuse and
 * moderation rules) and announces it on `jetonomy_reputation_changed`. Each event below is an
 * action here: the owner sets what it pays (or, for a penalty, takes), switches it off, and caps
 * or cools it down, on the same Actions screen as every other action. Until the owner saves a
 * value, the points follow what Jetonomy already pays on the site (its own default or the owner's
 * Reputation setting), so installing this changes no number. A zero in Jetonomy means the event
 * is not announced, so it stays off here too.
 *
 * Exits: an award is taken back when what earned it goes away (a topic or reply deleted or
 * removed, a vote or acceptance withdrawn); a penalty is given back when the penalty is lifted.
 */
$jt_key = static function ( $type, $id, $actor = 0 ): int {
	return \WBGam\Engine\Registry::object_key( 'jetonomy', (string) $type, (int) $id, (int) $actor );
};
// The object a reputation event is about, from its context; $actor names the context field that
// tells two members' awards for the same object apart (who voted, who reported).
$jt_object = static function ( $context, string $actor = '' ) use ( $jt_key ): int {
	$context = is_array( $context ) ? $context : array();
	$type    = (string) ( $context['object_type'] ?? '' );
	$id      = (int) ( $context['object_id'] ?? 0 );
	$who     = '' === $actor ? 0 : (int) ( $context[ $actor ] ?? 0 );
	if ( '' === $type || $id <= 0 || ( '' !== $actor && $who <= 0 ) ) {
		return 0;
	}
	return $jt_key( $type, $id, $who );
};

// Jetonomy's current value for an event, as a positive number; 0 when this Jetonomy has no reputation
// system (older than 1.4.3), which leaves the action off rather than guessing a number.
$jt_points = static fn( string $event ): int => class_exists( '\\Jetonomy\\Trust\\Reputation' )
	? abs( (int) \Jetonomy\Trust\Reputation::points_for( $event ) )
	: 0;

$jt_forum_events = array(
	'post_created'    => array(
		false,
		static fn(): string => __( 'Started a forum topic', 'wb-gamification' ),
		static fn(): string => __( 'Awarded when a member\'s forum topic is published. Taken back if the topic is deleted or removed by a moderator.', 'wb-gamification' ),
		'icon-message-square',
		'',
	),
	'reply_created'   => array(
		false,
		static fn(): string => __( 'Replied in the forum', 'wb-gamification' ),
		static fn(): string => __( 'Awarded when a member\'s forum reply is published. Taken back if the reply is deleted or removed by a moderator.', 'wb-gamification' ),
		'icon-message-circle',
		'',
	),
	'post_upvoted'    => array(
		false,
		static fn(): string => __( 'Your forum post was upvoted', 'wb-gamification' ),
		static fn(): string => __( 'Awarded to the author each time another member upvotes their topic. Taken back when that vote is withdrawn.', 'wb-gamification' ),
		'icon-heart',
		'voter_id',
	),
	'reply_upvoted'   => array(
		false,
		static fn(): string => __( 'Your forum reply was upvoted', 'wb-gamification' ),
		static fn(): string => __( 'Awarded to the author each time another member upvotes their reply. Taken back when that vote is withdrawn.', 'wb-gamification' ),
		'icon-heart',
		'voter_id',
	),
	'reply_accepted'  => array(
		false,
		static fn(): string => __( 'Your answer was accepted', 'wb-gamification' ),
		static fn(): string => __( 'Awarded when the asker marks a member\'s reply as the accepted answer. Taken back if the acceptance is removed.', 'wb-gamification' ),
		'icon-circle-check',
		'',
	),
	'idea_planned'    => array(
		false,
		static fn(): string => __( 'Your idea was planned', 'wb-gamification' ),
		static fn(): string => __( 'Awarded when a moderator moves a member\'s idea onto the roadmap. Taken back if it is moved off.', 'wb-gamification' ),
		'icon-trending-up',
		'',
	),
	'flag_validated'  => array(
		false,
		static fn(): string => __( 'Your report was upheld', 'wb-gamification' ),
		static fn(): string => __( 'Awarded to a member whose report of a forum post led a moderator to remove it.', 'wb-gamification' ),
		'icon-check',
		'',
	),
	'post_downvoted'  => array(
		true,
		static fn(): string => __( 'Your forum post was downvoted', 'wb-gamification' ),
		static fn(): string => __( 'Takes points from the author each time another member downvotes their topic. Given back when that vote is withdrawn. Never takes a balance below zero.', 'wb-gamification' ),
		'icon-trending-up',
		'voter_id',
	),
	'reply_downvoted' => array(
		true,
		static fn(): string => __( 'Your forum reply was downvoted', 'wb-gamification' ),
		static fn(): string => __( 'Takes points from the author each time another member downvotes their reply. Given back when that vote is withdrawn. Never takes a balance below zero.', 'wb-gamification' ),
		'icon-trending-up',
		'voter_id',
	),
	'post_reported'   => array(
		true,
		static fn(): string => __( 'Forum post reported', 'wb-gamification' ),
		static fn(): string => __( 'Takes points from the author each time another member reports their post or reply. Given back if the report is lifted. Never takes a balance below zero.', 'wb-gamification' ),
		'icon-mail',
		'reporter_id',
	),
	'post_removed'    => array(
		true,
		static fn(): string => __( 'Post removed by a moderator', 'wb-gamification' ),
		static fn(): string => __( 'Takes points from the author when a moderator removes their topic or reply as spam or by trashing it. Given back if the moderator restores it. Never takes a balance below zero.', 'wb-gamification' ),
		'icon-user',
		'',
	),
);

$jt_forum_triggers = array();
foreach ( $jt_forum_events as $jt_event => $jt_def ) {
	list( $jt_penalty, $jt_label, $jt_description, $jt_icon, $jt_actor ) = $jt_def;

	// Taken back when the thing that earned it goes away. A topic or reply earns once per object and
	// leaves with it; the rest leave on the withdrawal Jetonomy announces as "{event}_revoked".
	$jt_reverse = array(
		'jetonomy_reputation_changed' => static fn( $user_id, $reason, $delta, $context = array() ): int => ( $jt_event . '_revoked' ) === (string) $reason ? $jt_object( $context, $jt_actor ) : 0,
	);
	$jt_restore = array();
	if ( in_array( $jt_event, array( 'post_created', 'reply_created' ), true ) ) {
		$jt_type    = 'post_created' === $jt_event ? 'post' : 'reply';
		$jt_reverse = array(
			'jetonomy_' . $jt_type . '_deleted' => static fn( $id ): int => $jt_key( $jt_type, (int) $id ),
			'jetonomy_content_moderated'        => static fn( $action, $type, $id ): int => $jt_type === (string) $type && in_array( (string) $action, array( 'trash', 'spam' ), true ) ? $jt_key( $jt_type, (int) $id ) : 0,
		);
		$jt_restore = array(
			'jetonomy_content_moderated' => static fn( $action, $type, $id ): int => $jt_type === (string) $type && 'approve' === (string) $action ? $jt_key( $jt_type, (int) $id ) : 0,
		);
	}

	$jt_trigger = array(
		'id'                => 'jetonomy_' . $jt_event,
		'label'             => $jt_label,
		'description'       => $jt_description,
		// Free fires: do_action( 'jetonomy_reputation_changed', int $user_id, string $reason, int $delta, array $context ).
		'hook'              => 'jetonomy_reputation_changed',
		'user_callback'     => static function ( $user_id, $reason, $delta, $context = array() ) use ( $jt_event, $jt_penalty ): int {
			// The event itself: not its withdrawal ("{event}_revoked"), and in the direction the action expects.
			if ( (string) $reason !== $jt_event || ( $jt_penalty ? (int) $delta >= 0 : (int) $delta <= 0 ) ) {
				return 0;
			}
			// A BuddyNext feed comment mirrored into a forum reply was already paid as a feed comment.
			return \WBGam\Engine\HostActivity::is_copy() ? 0 : (int) $user_id;
		},
		'metadata_callback' => static function ( $user_id, $reason, $delta, $context = array() ): array {
			$context = is_array( $context ) ? $context : array();
			return array(
				'object_type' => (string) ( $context['object_type'] ?? '' ),
				'object_id'   => (int) ( $context['object_id'] ?? 0 ),
			);
		},
		'once_per_object'   => static fn( $user_id, $reason, $delta, $context = array() ): int => $jt_object( $context, $jt_actor ),
		'reverse_on'        => $jt_reverse,
		// What Jetonomy pays today (its default or the owner's Reputation setting): the starting value only.
		'default_points'    => $jt_points( $jt_event ),
		'category'          => 'community',
		'icon'              => $jt_icon,
		'repeatable'        => true,
		// In the request, not the queue: a withdrawal must find the award it undoes.
		'async'             => false,
		'penalty'           => $jt_penalty,
	);
	if ( $jt_restore ) {
		$jt_trigger['restore_on'] = $jt_restore;
	}
	// Starting limits so a run of throwaway topics or replies cannot farm points (Jetonomy caps
	// neither). The owner changes or clears them on the Actions screen.
	if ( 'post_created' === $jt_event ) {
		$jt_trigger['daily_cap'] = 5;
	} elseif ( 'reply_created' === $jt_event ) {
		$jt_trigger['cooldown']  = 30;
		$jt_trigger['daily_cap'] = 20;
	}
	$jt_forum_triggers[] = $jt_trigger;
}

return array(
	'plugin'   => 'Jetonomy',
	'version'  => '1.0.0',
	'triggers' => array_merge(
		$jt_forum_triggers,
		array(

			array(
				'id'                => 'jetonomy_space_joined',
				'label'             => static fn(): string => __( 'Join a space', 'wb-gamification' ),
				'description'       => static fn(): string => __( 'Awarded once when a member joins a Jetonomy community space. Daily cap prevents bulk-join farming.', 'wb-gamification' ),
				// Free fires: do_action( 'jetonomy_user_joined_space', int $space_id, int $user_id, string $role ).
				'hook'              => 'jetonomy_user_joined_space',
				'user_callback'     => function ( int $space_id, int $user_id, string $role ): int {
					// A BuddyNext space joining its linked forum is one join, paid by bn_space_joined.
					return \WBGam\Engine\HostActivity::is_copy() ? 0 : $user_id;
				},
				'metadata_callback' => function ( int $space_id, int $user_id, string $role ): array {
					return array(
						'space_id' => $space_id,
						'role'     => $role,
					);
				},
				'once_per_object'   => static fn( int $space_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $space_id ),
				'reverse_on'        => array(
					'jetonomy_user_left_space' => static fn( $space_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $space_id ),
				),
				'async'             => false,
				'default_points'    => 10,
				'category'          => 'community',
				'icon'              => 'icon-users',
				'repeatable'        => true,
				'cooldown'          => 60,
				'daily_cap'         => 5,
			),

			array(
				'id'                => 'jetonomy_join_request_approved',
				'label'             => static fn(): string => __( 'Get approved into a gated space', 'wb-gamification' ),
				'description'       => static fn(): string => __( 'Awarded once when a member is approved into a request-to-join space.', 'wb-gamification' ),
				// Free fires: do_action( 'jetonomy_join_request_approved', int $space_id, int $user_id, int $reviewed_by ).
				'hook'              => 'jetonomy_join_request_approved',
				'user_callback'     => function ( int $space_id, int $user_id, int $reviewed_by ): int {
					return $user_id;
				},
				'metadata_callback' => function ( int $space_id, int $user_id, int $reviewed_by ): array {
					return array(
						'space_id'    => $space_id,
						'reviewed_by' => $reviewed_by,
					);
				},
				'once_per_object'   => static fn( int $space_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, $space_id ),
				'reverse_on'        => array(
					'jetonomy_user_left_space' => static fn( $space_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, (int) $space_id ),
				),
				'async'             => false,
				'default_points'    => 10,
				'category'          => 'community',
				'icon'              => 'icon-circle-check',
				'repeatable'        => true,
				'cooldown'          => 300,
			),

			array(
				'id'                => 'jetonomy_trust_level_up',
				'async'             => false,
				'label'             => static fn(): string => __( 'Get promoted to a higher trust level', 'wb-gamification' ),
				'description'       => static fn(): string => __( 'Awarded when a member is promoted to a higher Jetonomy trust level (TL0 -> TL5). Demotions never award.', 'wb-gamification' ),
				// Free fires: do_action( 'jetonomy_trust_level_changed', int $user_id, int $old_level, int $new_level ).
				'hook'              => 'jetonomy_trust_level_changed',
				'user_callback'     => function ( int $user_id, int $old_level, int $new_level ): int {
					// Only the upward direction earns. Demotions / no-op fire the same
					// action; returning 0 makes Engine::process drop the event silently.
					return $new_level > $old_level ? $user_id : 0;
				},
				'metadata_callback' => function ( int $user_id, int $old_level, int $new_level ): array {
					return array(
						'old_level' => $old_level,
						'new_level' => $new_level,
						'delta'     => $new_level - $old_level,
					);
				},
				'permanent'         => 'A trust level reached is a milestone; a later demotion does not undo it.',
				'default_points'    => 50,
				'category'          => 'community',
				'icon'              => 'icon-trending-up',
				'repeatable'        => true,
			),

			array(
				'id'                => 'jetonomy_membership_activated',
				'label'             => static fn(): string => __( 'Activate a membership', 'wb-gamification' ),
				'description'       => static fn(): string => __( 'Awarded once per plan when a paid membership becomes active for the member (RCP / PMPro / MemberPress / WooCommerce Subscriptions / Sensei / LearnDash / MasterStudy / Tutor / LifterLMS).', 'wb-gamification' ),
				// Free + Pro adapters fire: do_action( 'jetonomy_membership_activated', int $user_id, mixed $level_id, string $source ).
				'hook'              => 'jetonomy_membership_activated',
				'user_callback'     => function ( int $user_id, $level_id, string $source ): int {
					/**
					 * Whether activating this membership level earns points.
					 *
					 * The rule rewards a paid membership. Adapters also report free
					 * levels (a default plan every member is placed on), which would
					 * hand out points just for signing up. The source that knows its
					 * levels answers false for a free one.
					 *
					 * @since 1.6.6
					 *
					 * @param bool   $earns    Default true.
					 * @param mixed  $level_id Level id as the adapter reported it.
					 * @param string $source   Adapter source, e.g. 'buddynext-pro'.
					 * @param int    $user_id  Member.
					 */
					if ( ! apply_filters( 'wb_gam_membership_level_earns', true, $level_id, $source, $user_id ) ) {
						return 0;
					}

					// Awards from before 1.6.8 carry no object id: those count as earned per plan here.
					// Newer ones are keyed per member and plan (once_per_object) and taken back on
					// deactivation. Adapters re-announce a membership on every
					// renewal, date change and monthly/yearly switch (BuddyNext Pro maps
					// subscription_extended to this hook too), and each paid 25 points
					// again. A different plan still earns, once. The check reads the
					// ledger joined to its event, so an event that paid nothing (a cap
					// blocked it) does not count as earned.
					global $wpdb;
					$needle = substr( (string) wp_json_encode( array( 'level_id' => is_scalar( $level_id ) ? (string) $level_id : '' ) ), 1, -1 );
					$earned = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points p
						 JOIN {$wpdb->prefix}wb_gam_events e ON e.id = p.event_id
						 WHERE p.user_id = %d AND p.action_id = 'jetonomy_membership_activated' AND p.points > 0
						   AND p.object_id IS NULL AND e.metadata LIKE %s",
							$user_id,
							'%' . $wpdb->esc_like( $needle ) . '%'
						)
					);
					return $earned > 0 ? 0 : $user_id;
				},
				'metadata_callback' => function ( int $user_id, $level_id, string $source ): array {
					return array(
						'level_id' => is_scalar( $level_id ) ? (string) $level_id : '',
						'source'   => $source,
					);
				},
				'once_per_object'   => static fn( int $user_id, $level_id ): int => \WBGam\Engine\Registry::object_key( $user_id, is_scalar( $level_id ) ? (string) $level_id : '' ),
				// Per member and plan: deactivation (cancel, refund, removal) takes it back; reactivating pays again.
				'reverse_on'        => array(
					'jetonomy_membership_deactivated' => static fn( $user_id, $level_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, is_scalar( $level_id ) ? (string) $level_id : '' ),
				),
				'default_points'    => 25,
				'category'          => 'community',
				'icon'              => 'icon-key',
				'repeatable'        => true,
				// Award in the request, not via the async queue: the once-per-plan check
				// above must see the first award before a second event (a plan switch
				// fires two within seconds) is checked.
				'async'             => false,
			),

		),
	),
);
