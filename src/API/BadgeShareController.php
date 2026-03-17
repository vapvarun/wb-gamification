<?php
/**
 * REST API: Badge Share Controller
 *
 * Provides a shareable public endpoint for individual badge awards.
 *
 * GET /wb-gamification/v1/badges/{badge_id}/share/{user_id}
 *
 * Returns the badge definition + member's earn date, suitable for:
 *   - Rendering a standalone badge card page (via page template + REST fetch)
 *   - Open Graph / LinkedIn meta tag population
 *   - External verification of a credential badge
 *
 * This is the "Phase 2 share URL" layer. Phase 4 upgrades it with full
 * OpenBadges 3.0 JSON-LD and a verification endpoint.
 *
 * Access: public (no auth required). Opt-out users' share pages still work
 * for their own badges — they opted out of the leaderboard, not credential sharing.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\BadgeEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class BadgeShareController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'badges';

	public function register_routes(): void {
		// GET /badges/{badge_id}/share/{user_id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<badge_id>[a-z0-9_-]+)/share/(?P<user_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_share_card' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'badge_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'user_id'  => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	// ── Callbacks ──────────────────────────────────────────────────────────────

	/**
	 * GET /badges/{badge_id}/share/{user_id}
	 *
	 * Returns all data needed to render a shareable badge card.
	 */
	public function get_share_card( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$badge_id = sanitize_key( $request['badge_id'] );
		$user_id  = (int) $request['user_id'];

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Member not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$def = BadgeEngine::get_badge_def( $badge_id );
		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		// Check the user actually earned this badge.
		global $wpdb;
		$earned_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT earned_at FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id = %s",
				$user_id,
				$badge_id
			)
		);

		if ( null === $earned_at ) {
			return new WP_Error(
				'rest_badge_not_earned',
				__( 'This member has not earned this badge.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$site_name   = get_bloginfo( 'name' );
		$site_url    = home_url();
		$profile_url = function_exists( 'bp_core_get_user_domain' )
			? bp_core_get_user_domain( $user_id )
			: get_author_posts_url( $user_id );

		// LinkedIn share URL — pre-filled with badge name and site.
		$linkedin_url = add_query_arg(
			array(
				'mini'   => 'true',
				'url'    => rawurlencode( rest_url( $this->namespace . '/badges/' . $badge_id . '/share/' . $user_id ) ),
				'title'  => rawurlencode(
					sprintf(
						/* translators: 1: badge name, 2: site name */
						__( 'I earned the %1$s badge on %2$s', 'wb-gamification' ),
						$def['name'],
						$site_name
					)
				),
				'source' => rawurlencode( $site_url ),
			),
			'https://www.linkedin.com/shareArticle'
		);

		return rest_ensure_response(
			array(
				'badge'      => array(
					'id'            => $def['id'],
					'name'          => $def['name'],
					'description'   => $def['description'],
					'image_url'     => $def['image_url'],
					'is_credential' => $def['is_credential'],
					'category'      => $def['category'],
				),
				'earner'     => array(
					'user_id'      => $user_id,
					'display_name' => $user->display_name,
					'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 128 ) ),
					'profile_url'  => $profile_url,
				),
				'earned_at'  => $earned_at,
				'site'       => array(
					'name' => $site_name,
					'url'  => $site_url,
				),
				'share_urls' => array(
					'linkedin' => $linkedin_url,
					'self'     => rest_url( $this->namespace . '/badges/' . $badge_id . '/share/' . $user_id ),
				),
				// Open Graph tags for server-side rendering.
				'og'         => array(
					'title'       => sprintf(
						/* translators: 1: member name, 2: badge name, 3: site name */
						__( '%1$s earned the %2$s badge on %3$s', 'wb-gamification' ),
						$user->display_name,
						$def['name'],
						$site_name
					),
					'description' => $def['description'] ?? '',
					'image'       => $def['image_url'] ?? get_avatar_url( $user_id, array( 'size' => 512 ) ),
					'url'         => rest_url( $this->namespace . '/badges/' . $badge_id . '/share/' . $user_id ),
				),
			)
		);
	}
}
