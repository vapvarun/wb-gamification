<?php
/**
 * REST API: OpenBadges 3.0 Credential Controller
 *
 * Serves verifiable OpenBadge 3.0 JSON-LD credentials.
 *
 * GET /wb-gamification/v1/badges/{badge_id}/credential/{user_id}
 *
 * Returns a full OpenBadgeCredential JSON-LD document per the
 * IMS Global Open Badges 3.0 specification. The response is publicly
 * accessible so employers, LinkedIn, and Credly can verify it.
 *
 * The URL itself acts as the verifiable evidence link that can be
 * submitted to LinkedIn's "Add Certification" flow or any OB3-aware wallet.
 *
 * Content-Type: application/ld+json (also served as application/json)
 *
 * @see https://www.imsglobal.org/spec/ob/v3p0
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

class CredentialController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'badges';

	public function register_routes(): void {
		// GET /badges/{badge_id}/credential/{user_id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<badge_id>[a-z0-9_]+)/credential/(?P<user_id>[\d]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_credential' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'badge_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'user_id' => [
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	// ── Callback ────────────────────────────────────────────────────────────

	public function get_credential( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$badge_id = (string) $request['badge_id'];
		$user_id  = (int) $request['user_id'];

		// Validate badge exists.
		$badge = BadgeEngine::get_badge_def( $badge_id );
		if ( ! $badge ) {
			return new WP_Error( 'rest_not_found', __( 'Badge not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		// Badge must be a credential.
		if ( ! $badge['is_credential'] ) {
			return new WP_Error(
				'not_a_credential',
				__( 'This badge is not a shareable credential.', 'wb-gamification' ),
				[ 'status' => 403 ]
			);
		}

		// Validate user exists and has earned this badge.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		// Check earned status — use raw row to distinguish "never earned" from "expired".
		$badge_row = BadgeEngine::get_badge_row( $user_id, $badge_id );

		if ( ! $badge_row ) {
			return new WP_Error(
				'badge_not_earned',
				__( 'This member has not earned this badge.', 'wb-gamification' ),
				[ 'status' => 404 ]
			);
		}

		// Credential has expired → 410 Gone (triggers renewal re-engagement).
		if ( $badge_row['expires_at'] && strtotime( $badge_row['expires_at'] ) <= time() ) {
			return new WP_Error(
				'credential_expired',
				__( 'This credential has expired. Renew to restore verification.', 'wb-gamification' ),
				[ 'status' => 410 ]
			);
		}

		$earned_at  = $badge_row['earned_at'];
		$expires_at = $badge_row['expires_at'];

		$site_url    = get_site_url();
		$site_name   = get_bloginfo( 'name' );
		$issuer_url  = rest_url( $this->namespace . '/issuer' );
		$cred_url    = rest_url( $this->namespace . '/badges/' . $badge_id . '/credential/' . $user_id );
		$badge_url   = rest_url( $this->namespace . '/badges/' . $badge_id );
		$issued_on   = $earned_at
			? ( new \DateTime( $earned_at, new \DateTimeZone( 'UTC' ) ) )->format( 'c' )
			: gmdate( 'c' );
		$expires_on  = $expires_at
			? ( new \DateTime( $expires_at, new \DateTimeZone( 'UTC' ) ) )->format( 'c' )
			: null;

		// Build OpenBadgeCredential 3.0 JSON-LD document.
		$credential = [
			'@context' => [
				'https://www.w3.org/2018/credentials/v1',
				'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
			],
			'id'   => $cred_url,
			'type' => [ 'VerifiableCredential', 'OpenBadgeCredential' ],

			'issuer' => [
				'id'   => $issuer_url,
				'type' => 'Profile',
				'name' => $site_name,
				'url'  => $site_url,
			],

			'issuanceDate'   => $issued_on,
			'name'           => $badge['name'],
			'expirationDate' => $expires_on,

			'credentialSubject' => [
				'id'   => $site_url . '/?author=' . $user_id,
				'type' => 'AchievementSubject',
				'name' => $user->display_name,

				'achievement' => [
					'id'          => $badge_url,
					'type'        => 'Achievement',
					'name'        => $badge['name'],
					'description' => $badge['description'],
					'image'       => $badge['image_url'] ?: null,
					'criteria'    => [
						'narrative' => $badge['description'],
					],
					'issuer' => [
						'id'   => $issuer_url,
						'type' => 'Profile',
						'name' => $site_name,
					],
				],
			],

			/**
			 * Filter the OpenBadgeCredential document before it is returned.
			 * Allows adding digital proofs, expiry dates, or custom extensions.
			 *
			 * @param array  $credential The credential array.
			 * @param string $badge_id   Badge identifier.
			 * @param int    $user_id    Earner user ID.
			 */
		];

		$credential = (array) apply_filters( 'wb_gamification_credential_document', $credential, $badge_id, $user_id );

		$response = new WP_REST_Response( $credential, 200 );
		$response->header( 'Content-Type', 'application/ld+json' );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}
}
