<?php
/**
 * SubmissionService
 *
 * Business logic for the achievement-submission queue. Wraps
 * SubmissionRepository with validation, rate-limiting, and the
 * approval flow that fires a real points award through the standard
 * Engine pipeline.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Services;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;
use WBGam\Repository\SubmissionRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SubmissionService {

	/** Default per-user daily cap for spam protection. */
	public const DAILY_CAP = 5;

	private SubmissionRepository $repo;

	public function __construct( ?SubmissionRepository $repo = null ) {
		$this->repo = $repo ?? new SubmissionRepository();
	}

	/**
	 * Submit a new achievement.
	 *
	 * @param int    $user_id      Submitter.
	 * @param string $action_id    Action slug to award on approval.
	 * @param string $evidence     Free-text evidence.
	 * @param string $evidence_url Optional URL evidence.
	 * @return int|WP_Error  Insert ID on success.
	 */
	public function submit( int $user_id, string $action_id, string $evidence, string $evidence_url = '' ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'wb_gam_no_user', __( 'You must be logged in to submit.', 'wb-gamification' ), array( 'status' => 401 ) );
		}
		if ( '' === $action_id || ! Registry::get_action( $action_id ) ) {
			return new WP_Error( 'wb_gam_unknown_action', __( 'Unknown action.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $evidence ) && '' === trim( $evidence_url ) ) {
			return new WP_Error( 'wb_gam_no_evidence', __( 'Provide evidence (text or URL).', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		if ( $this->repo->count_today_for_user( $user_id ) >= self::daily_cap() ) {
			return new WP_Error(
				'wb_gam_rate_limit',
				sprintf(
					/* translators: %d: daily cap */
					__( 'Submission limit reached. Try again tomorrow (max %d/day).', 'wb-gamification' ),
					self::daily_cap()
				),
				array( 'status' => 429 )
			);
		}

		$id = $this->repo->insert( array(
			'user_id'      => $user_id,
			'action_id'    => $action_id,
			'evidence'     => sanitize_textarea_field( $evidence ),
			'evidence_url' => esc_url_raw( $evidence_url ),
		) );

		if ( ! $id ) {
			return new WP_Error( 'wb_gam_insert_failed', __( 'Could not record submission.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires immediately after a submission is queued. Use to email
		 * admin reviewers, push to Slack/Discord, etc.
		 *
		 * @since 1.0.0
		 * @param int    $id        Submission ID.
		 * @param int    $user_id   Submitter user ID.
		 * @param string $action_id Action slug.
		 */
		do_action( 'wb_gam_submission_created', $id, $user_id, $action_id );

		return $id;
	}

	/**
	 * Approve a submission — fires the matching action through Engine.
	 *
	 * @param int    $id          Submission ID.
	 * @param int    $reviewer_id Admin user ID.
	 * @param string $notes       Optional reviewer note.
	 * @return array|WP_Error
	 */
	public function approve( int $id, int $reviewer_id, string $notes = '' ) {
		$row = $this->repo->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'wb_gam_not_found', __( 'Submission not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $row['status'] ) {
			return new WP_Error( 'wb_gam_already_reviewed', __( 'Submission already reviewed.', 'wb-gamification' ), array( 'status' => 409 ) );
		}

		// Award via PointsEngine — uses the default points for this action.
		$action = Registry::get_action( (string) $row['action_id'] );
		if ( $action ) {
			$points = (int) ( get_option( 'wb_gam_points_' . $row['action_id'], $action['default_points'] ?? 0 ) );
			if ( $points > 0 ) {
				PointsEngine::award( (int) $row['user_id'], (string) $row['action_id'], $points );
			}
		}

		$this->repo->set_status( $id, 'approved', $reviewer_id, $notes );

		/**
		 * Fires after a submission is approved + points awarded.
		 *
		 * @since 1.0.0
		 * @param int    $id          Submission ID.
		 * @param int    $user_id     Submitter.
		 * @param string $action_id   Action slug.
		 * @param int    $reviewer_id Admin who approved.
		 */
		do_action( 'wb_gam_submission_approved', $id, (int) $row['user_id'], (string) $row['action_id'], $reviewer_id );

		return $this->repo->find( $id );
	}

	/**
	 * Reject a submission with optional reason.
	 *
	 * @param int    $id          Submission ID.
	 * @param int    $reviewer_id Admin user ID.
	 * @param string $notes       Reason shown to the submitter.
	 * @return array|WP_Error
	 */
	public function reject( int $id, int $reviewer_id, string $notes = '' ) {
		$row = $this->repo->find( $id );
		if ( ! $row ) {
			return new WP_Error( 'wb_gam_not_found', __( 'Submission not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $row['status'] ) {
			return new WP_Error( 'wb_gam_already_reviewed', __( 'Submission already reviewed.', 'wb-gamification' ), array( 'status' => 409 ) );
		}

		$this->repo->set_status( $id, 'rejected', $reviewer_id, $notes );

		/**
		 * Fires after a submission is rejected. Listeners send the
		 * member a notification with the reason.
		 *
		 * @since 1.0.0
		 * @param int    $id          Submission ID.
		 * @param int    $user_id     Submitter.
		 * @param string $action_id   Action slug.
		 * @param int    $reviewer_id Admin who rejected.
		 * @param string $notes       Reason.
		 */
		do_action( 'wb_gam_submission_rejected', $id, (int) $row['user_id'], (string) $row['action_id'], $reviewer_id, $notes );

		return $this->repo->find( $id );
	}

	/**
	 * Per-user daily submission cap. Filterable so site owners can
	 * relax / tighten the spam gate.
	 */
	public static function daily_cap(): int {
		/**
		 * Filter the per-user daily submission cap.
		 *
		 * @since 1.0.0
		 * @param int $cap Default DAILY_CAP.
		 */
		return (int) apply_filters( 'wb_gam_submission_daily_cap', self::DAILY_CAP );
	}
}
