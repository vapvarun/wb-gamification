<?php
/**
 * WB Gamification Shortcode Handler
 *
 * Registers [wb_gam_*] shortcodes for all blocks, delegating rendering
 * to render_block() so block logic is not duplicated.
 *
 * Usage examples:
 *   [wb_gam_leaderboard period="week" limit="5"]
 *   [wb_gam_member_points user_id="42"]
 *   [wb_gam_badge_showcase show_locked="1"]
 *   [wb_gam_level_progress]
 *   [wb_gam_challenges limit="3"]
 *   [wb_gam_streak show_longest="1"]
 *   [wb_gam_top_members limit="5" layout="list"]
 *   [wb_gam_kudos_feed limit="5"]
 *   [wb_gam_year_recap]
 *   [wb_gam_points_history limit="20"]
 *
 * @package WB_Gamification
 * @since   0.5.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress shortcodes that delegate rendering to the block render layer.
 *
 * @package WB_Gamification
 */
final class ShortcodeHandler {

	/**
	 * Register all [wb_gam_*] shortcodes.
	 *
	 * Called on the `init` action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'wb_gam_leaderboard', array( __CLASS__, 'render_leaderboard' ) );
		add_shortcode( 'wb_gam_member_points', array( __CLASS__, 'render_member_points' ) );
		add_shortcode( 'wb_gam_badge_showcase', array( __CLASS__, 'render_badge_showcase' ) );
		add_shortcode( 'wb_gam_level_progress', array( __CLASS__, 'render_level_progress' ) );
		add_shortcode( 'wb_gam_challenges', array( __CLASS__, 'render_challenges' ) );
		add_shortcode( 'wb_gam_streak', array( __CLASS__, 'render_streak' ) );
		add_shortcode( 'wb_gam_top_members', array( __CLASS__, 'render_top_members' ) );
		add_shortcode( 'wb_gam_kudos_feed', array( __CLASS__, 'render_kudos_feed' ) );
		add_shortcode( 'wb_gam_give_kudos', array( __CLASS__, 'render_give_kudos' ) );
		add_shortcode( 'wb_gam_year_recap', array( __CLASS__, 'render_year_recap' ) );
		add_shortcode( 'wb_gam_points_history', array( __CLASS__, 'render_points_history' ) );
		add_shortcode( 'wb_gam_earning_guide', array( __CLASS__, 'render_earning_guide' ) );
		add_shortcode( 'wb_gam_hub', array( __CLASS__, 'render_hub' ) );
		add_shortcode( 'wb_gam_community_challenges', array( __CLASS__, 'render_community_challenges' ) );
		add_shortcode( 'wb_gam_cohort_rank', array( __CLASS__, 'render_cohort_rank' ) );
		add_shortcode( 'wb_gam_redemption_store', array( __CLASS__, 'render_redemption_store' ) );
		add_shortcode( 'wb_gam_my_rewards', array( __CLASS__, 'render_my_rewards' ) );
	}

	// ── Shortcode renderers ───────────────────────────────────────────────────

	/**
	 * Render [wb_gam_leaderboard].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_leaderboard( $atts ): string {
		return self::block( 'leaderboard', self::normalize_leaderboard_atts( (array) $atts ) );
	}

	/**
	 * Render [wb_gam_member_points].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_member_points( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_level'        => true,
				'show_progress_bar' => true,
				'type'              => '',
			),
			(array) $atts,
			'wb_gam_member_points'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_level']        = filter_var( $atts['show_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );
		$atts['pointType']         = sanitize_key( (string) $atts['type'] );
		unset( $atts['type'] );

		return self::block( 'member-points', $atts );
	}

	/**
	 * Render [wb_gam_badge_showcase].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_badge_showcase( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'     => 0,
				'show_locked' => false,
				'category'    => '',
				'limit'       => 0,
			),
			(array) $atts,
			'wb_gam_badge_showcase'
		);

		$atts['user_id']     = (int) $atts['user_id'];
		$atts['show_locked'] = filter_var( $atts['show_locked'], FILTER_VALIDATE_BOOLEAN );
		$atts['limit']       = max( 0, (int) $atts['limit'] );
		$atts['category']    = sanitize_key( $atts['category'] );

		return self::block( 'badge-showcase', $atts );
	}

	/**
	 * Render [wb_gam_level_progress].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_level_progress( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_progress_bar' => true,
				'show_next_level'   => true,
				'show_icon'         => true,
				'type'              => '',
			),
			(array) $atts,
			'wb_gam_level_progress'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_next_level']   = filter_var( $atts['show_next_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_icon']         = filter_var( $atts['show_icon'], FILTER_VALIDATE_BOOLEAN );
		$atts['pointType']         = sanitize_key( (string) $atts['type'] );
		unset( $atts['type'] );

		return self::block( 'level-progress', $atts );
	}

	/**
	 * Render [wb_gam_challenges].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_challenges( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_completed'    => true,
				'show_progress_bar' => true,
				'limit'             => 0,
			),
			(array) $atts,
			'wb_gam_challenges'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_completed']    = filter_var( $atts['show_completed'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );
		$atts['limit']             = max( 0, (int) $atts['limit'] );

		return self::block( 'challenges', $atts );
	}

	/**
	 * Render [wb_gam_streak].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_streak( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'      => 0,
				'show_longest' => false,
				'show_heatmap' => false,
				'heatmap_days' => 90,
			),
			(array) $atts,
			'wb_gam_streak'
		);

		$atts['user_id']      = (int) $atts['user_id'];
		$atts['show_longest'] = filter_var( $atts['show_longest'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_heatmap'] = filter_var( $atts['show_heatmap'], FILTER_VALIDATE_BOOLEAN );
		$atts['heatmap_days'] = max( 1, min( 365, (int) $atts['heatmap_days'] ) );

		return self::block( 'streak', $atts );
	}

	/**
	 * Render [wb_gam_top_members].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_top_members( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'       => 3,
				'period'      => 'all_time',
				'show_badges' => false,
				'show_level'  => false,
				'layout'      => 'podium',
				'type'        => '',
			),
			(array) $atts,
			'wb_gam_top_members'
		);

		$atts['limit']       = max( 1, min( 20, (int) $atts['limit'] ) );
		$atts['show_badges'] = filter_var( $atts['show_badges'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_level']  = filter_var( $atts['show_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['layout']      = in_array( $atts['layout'], array( 'podium', 'list' ), true )
			? $atts['layout'] : 'podium';
		$atts['pointType']   = sanitize_key( (string) $atts['type'] );
		unset( $atts['type'] );

		return self::block( 'top-members', $atts );
	}

	/**
	 * Render [wb_gam_kudos_feed].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_kudos_feed( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'         => 10,
				'show_messages' => true,
			),
			(array) $atts,
			'wb_gam_kudos_feed'
		);

		$atts['limit']         = max( 1, min( 50, (int) $atts['limit'] ) );
		$atts['show_messages'] = filter_var( $atts['show_messages'], FILTER_VALIDATE_BOOLEAN );

		return self::block( 'kudos-feed', $atts );
	}

	/**
	 * Render [wb_gam_give_kudos] — thin wrapper around `wb-gamification/give-kudos` block.
	 *
	 * Attributes:
	 *   to    — recipient user_login OR user_id (optional; locks the form to
	 *           one recipient instead of showing the username field).
	 *   label — submit button label.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_give_kudos( $atts ): string {
		$atts = shortcode_atts(
			array(
				'to'    => '',
				'label' => '',
			),
			(array) $atts,
			'wb_gam_give_kudos'
		);
		return self::block( 'give-kudos', $atts );
	}

	/**
	 * Shared render for the give-kudos UI. Used by both the shortcode and the
	 * block's render.php. REST-only — no direct DB queries.
	 *
	 * @param array $atts Block / shortcode attributes (`to`, `label`).
	 * @return string HTML.
	 */
	public static function give_kudos_html( array $atts ): string {
		$atts = array_merge(
			array(
				'to'    => '',
				'label' => '',
			),
			$atts
		);
		if ( '' === $atts['label'] ) {
			$atts['label'] = __( 'Send Kudos', 'wb-gamification' );
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="wb-gam-give-kudos wb-gam-give-kudos--guest"><p>'
				. esc_html__( 'Sign in to send kudos to other members.', 'wb-gamification' )
				. '</p></div>';
		}

		$recipient_id    = 0;
		$recipient_label = '';
		if ( '' !== $atts['to'] ) {
			$user = is_numeric( $atts['to'] )
				? get_user_by( 'id', (int) $atts['to'] )
				: get_user_by( 'login', $atts['to'] );
			if ( $user ) {
				$recipient_id    = (int) $user->ID;
				$recipient_label = $user->display_name ?: $user->user_login;
			}
		}

		self::enqueue_give_kudos_assets();
		$rest_url = esc_url( rest_url( 'wb-gamification/v1/kudos' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );
		$uid      = 'wbgam-give-kudos-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<form class="wb-gam-give-kudos" data-wb-gam-give-kudos="<?php echo esc_attr( $uid ); ?>"
			data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
			data-rest-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( $recipient_id > 0 ) : ?>
				<input type="hidden" name="receiver_id" value="<?php echo (int) $recipient_id; ?>" />
				<p class="wb-gam-give-kudos__recipient">
					<?php
					printf(
						/* translators: %s: recipient display name. */
						esc_html__( 'Send a kudos to %s:', 'wb-gamification' ),
						'<strong>' . esc_html( $recipient_label ) . '</strong>'
					);
					?>
				</p>
			<?php else : ?>
				<div class="wb-gam-give-kudos__field">
					<label class="wb-gam-give-kudos__label" for="<?php echo esc_attr( $uid ); ?>-to">
						<?php esc_html_e( 'Recipient (username)', 'wb-gamification' ); ?>
					</label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-to" name="recipient_login" required
						autocomplete="off" class="wb-gam-give-kudos__input"
						placeholder="<?php esc_attr_e( 'Enter a username', 'wb-gamification' ); ?>" />
				</div>
			<?php endif; ?>

			<div class="wb-gam-give-kudos__field">
				<label class="wb-gam-give-kudos__label" for="<?php echo esc_attr( $uid ); ?>-msg">
					<?php esc_html_e( 'Message (optional)', 'wb-gamification' ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-msg" name="message" rows="3" maxlength="255"
					class="wb-gam-give-kudos__textarea"
					placeholder="<?php esc_attr_e( 'Say something nice (max 255 chars)', 'wb-gamification' ); ?>"></textarea>
			</div>

			<button type="submit" class="wb-gam-give-kudos__submit">
				<?php echo esc_html( $atts['label'] ); ?>
			</button>

			<p class="wb-gam-give-kudos__status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the give-kudos block's CSS + JS bundle.
	 *
	 * Called by `give_kudos_html()` on render so the assets are only loaded
	 * on pages that actually use the block / shortcode. Idempotent —
	 * wp_enqueue_script bails if the handle is already registered.
	 *
	 * @return void
	 */
	private static function enqueue_give_kudos_assets(): void {
		$handle = 'wb-gam-give-kudos';
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			$handle,
			plugins_url( 'assets/css/give-kudos.css', WB_GAM_FILE ),
			// Depend on the shared design tokens so the form's --wb-gam-*
			// custom properties resolve (otherwise the hex fallbacks apply).
			array( 'wb-gam-tokens' ),
			WB_GAM_VERSION
		);
		// wb-gam-mount defines wbGam.onMount(), which this script calls at parse time -- so it is a
		// hard dependency, not a nicety. Without it the kudos form binds nothing.
		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/js/give-kudos.js', WB_GAM_FILE ),
			array( 'wb-gam-mount' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'wbGamGiveKudos',
			array(
				'i18n' => array(
					'missingRecipient' => __( 'Please enter a recipient.', 'wb-gamification' ),
					'sending'          => __( 'Sending…', 'wb-gamification' ),
					'success'          => __( 'Kudos sent. Thanks for spreading kindness!', 'wb-gamification' ),
					'failure'          => __( 'Could not send kudos. Please try again.', 'wb-gamification' ),
					'network'          => __( 'Network error. Please try again.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Render [wb_gam_year_recap].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_year_recap( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'      => 0,
				'year'         => 0,
				'show_share'   => true,
				'show_badges'  => true,
				'show_kudos'   => true,
				'accent_color' => '',
			),
			(array) $atts,
			'wb_gam_year_recap'
		);

		$atts['user_id']      = (int) $atts['user_id'];
		$atts['year']         = (int) $atts['year'];
		$atts['show_share']   = filter_var( $atts['show_share'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_badges']  = filter_var( $atts['show_badges'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_kudos']   = filter_var( $atts['show_kudos'], FILTER_VALIDATE_BOOLEAN );
		$atts['accent_color'] = sanitize_hex_color( $atts['accent_color'] );

		// Map 'show_share' to the block attribute name 'show_share_button'.
		$block_atts                      = $atts;
		$block_atts['show_share_button'] = $block_atts['show_share'];
		unset( $block_atts['show_share'] );

		return self::block( 'year-recap', $block_atts );
	}

	/**
	 * Render [wb_gam_points_history].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_points_history( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'limit'             => 20,
				'show_action_label' => true,
				'type'              => '',
			),
			(array) $atts,
			'wb_gam_points_history'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['limit']             = max( 1, min( 100, (int) $atts['limit'] ) );
		$atts['show_action_label'] = filter_var( $atts['show_action_label'], FILTER_VALIDATE_BOOLEAN );
		$atts['pointType']         = sanitize_key( (string) $atts['type'] );
		unset( $atts['type'] );

		return self::block( 'points-history', $atts );
	}

	/**
	 * Render [wb_gam_hub].
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $atts Shortcode attributes (none used).
	 * @return string HTML output.
	 */
	public static function render_hub( $atts = array() ): string {
		return self::block( 'hub', array() );
	}

	/**
	 * Render the community challenges shortcode.
	 *
	 * @param array|string $atts [limit, show_progress_bar].
	 * @return string Rendered HTML.
	 */
	public static function render_community_challenges( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'             => 0,
				'show_progress_bar' => 'true',
			),
			(array) $atts,
			'wb_gam_community_challenges'
		);
		return self::block(
			'community-challenges',
			array(
				'limit'             => (int) $atts['limit'],
				'show_progress_bar' => 'false' !== $atts['show_progress_bar'],
			)
		);
	}

	/**
	 * Render the cohort rank shortcode.
	 *
	 * @param array|string $atts [user_id, limit].
	 * @return string Rendered HTML.
	 */
	public static function render_cohort_rank( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'user_id' => 0,
				'limit'   => 5,
				'type'    => '',
			),
			(array) $atts,
			'wb_gam_cohort_rank'
		);
		return self::block(
			'cohort-rank',
			array(
				'user_id'   => (int) $atts['user_id'],
				'limit'     => (int) $atts['limit'],
				'pointType' => sanitize_key( (string) $atts['type'] ),
			)
		);
	}

	/**
	 * Render the redemption store shortcode.
	 *
	 * @param array|string $atts [limit, columns, show_balance].
	 * @return string Rendered HTML.
	 */
	public static function render_redemption_store( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'        => 0,
				'columns'      => 3,
				'show_balance' => 'true',
				'type'         => '',
			),
			(array) $atts,
			'wb_gam_redemption_store'
		);
		return self::block(
			'redemption-store',
			array(
				'limit'        => (int) $atts['limit'],
				'columns'      => max( 1, min( 4, (int) $atts['columns'] ) ),
				'show_balance' => 'false' !== $atts['show_balance'],
				'pointType'    => sanitize_key( (string) $atts['type'] ),
			)
		);
	}

	/**
	 * Render [wb_gam_my_rewards] — member-facing redemption history.
	 *
	 * Closes Basecamp #9927388714 / #9925383280 issue 3. The data path
	 * already existed via {@see \WBGam\Engine\RedemptionEngine::get_user_redemptions()};
	 * this shortcode wires it to a small inline list so members can see
	 * what they've spent points on and the coupon code(s) generated.
	 *
	 * Attributes:
	 *   limit       Max rows to render (default 10, capped at 50).
	 *   show_status Show pending/fulfilled/failed pill (default true).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered HTML (empty for guests).
	 */
	public static function render_my_rewards( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="wb-gam-my-rewards wb-gam-my-rewards--guest"><p>'
				. esc_html__( 'Log in to view your redemption history.', 'wb-gamification' )
				. '</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'limit'       => 10,
				'show_status' => 'true',
			),
			(array) $atts,
			'wb_gam_my_rewards'
		);

		$limit       = max( 1, min( 50, (int) $atts['limit'] ) );
		$show_status = 'false' !== $atts['show_status'];

		$rows = \WBGam\Engine\RedemptionEngine::get_user_redemptions( get_current_user_id(), $limit );

		if ( empty( $rows ) ) {
			return '<div class="wb-gam-my-rewards wb-gam-my-rewards--empty"><p>'
				. esc_html__( 'You haven\'t redeemed any rewards yet. Visit the redemption store to spend your points.', 'wb-gamification' )
				. '</p></div>';
		}

		$status_label_map = array(
			'pending'             => __( 'Pending', 'wb-gamification' ),
			'pending_fulfillment' => __( 'Awaiting fulfilment', 'wb-gamification' ),
			'fulfilled'           => __( 'Fulfilled', 'wb-gamification' ),
			'failed'              => __( 'Failed', 'wb-gamification' ),
			'refunded'            => __( 'Refunded', 'wb-gamification' ),
		);

		ob_start();
		?>
		<div class="wb-gam-my-rewards">
			<ul class="wb-gam-my-rewards__list" role="list">
				<?php
				foreach ( $rows as $row ) :
					$row_status      = (string) ( $row['status'] ?? 'pending' );
					$row_status_text = $status_label_map[ $row_status ] ?? ucfirst( $row_status );
					$row_when        = (string) ( $row['created_at'] ?? '' );
					?>
					<li class="wb-gam-my-rewards__item" data-status="<?php echo esc_attr( $row_status ); ?>">
						<div class="wb-gam-my-rewards__title">
							<strong><?php echo esc_html( (string) ( $row['title'] ?? __( '- deleted reward -', 'wb-gamification' ) ) ); ?></strong>
							<?php if ( $show_status ) : ?>
								<span class="wb-gam-my-rewards__status wb-gam-my-rewards__status--<?php echo esc_attr( $row_status ); ?>">
									<?php echo esc_html( $row_status_text ); ?>
								</span>
							<?php endif; ?>
						</div>
						<div class="wb-gam-my-rewards__meta">
							<span class="wb-gam-my-rewards__cost">
								<?php
								printf(
									/* translators: %s: points spent */
									esc_html__( '%s pts spent', 'wb-gamification' ),
									esc_html( number_format_i18n( (int) ( $row['points_cost'] ?? 0 ) ) )
								);
								?>
							</span>
							<?php if ( $row_when ) : ?>
								<span class="wb-gam-my-rewards__when">
									<?php echo esc_html( human_time_diff( strtotime( $row_when ), time() ) . ' ' . __( 'ago', 'wb-gamification' ) ); ?>
								</span>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $row['coupon_code'] ) ) : ?>
							<div class="wb-gam-my-rewards__coupon">
								<span class="wb-gam-my-rewards__coupon-label"><?php esc_html_e( 'Use code:', 'wb-gamification' ); ?></span>
								<code class="wb-gam-my-rewards__coupon-code"><?php echo esc_html( (string) $row['coupon_code'] ); ?></code>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ── Public attribute normalizers (used by tests) ──────────────────────────

	/**
	 * Normalize leaderboard shortcode attributes.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array Normalized and sanitized attributes.
	 */
	public static function normalize_leaderboard_atts( array $atts ): array {
		$atts = shortcode_atts(
			array(
				'period'       => 'all',
				'limit'        => 10,
				'scope_type'   => '',
				'scope_id'     => 0,
				'show_avatars' => true,
				'type'         => '',
			),
			$atts,
			'wb_gam_leaderboard'
		);

		$atts['limit']        = max( 1, min( 100, (int) $atts['limit'] ) );
		$atts['scope_id']     = (int) $atts['scope_id'];
		$atts['show_avatars'] = filter_var( $atts['show_avatars'], FILTER_VALIDATE_BOOLEAN );

		// Forward `type=""` shortcode arg to block's `pointType` attribute.
		$atts['pointType'] = sanitize_key( (string) $atts['type'] );
		unset( $atts['type'] );

		return $atts;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Call render_block() for a registered wb-gamification block.
	 *
	 * Blocks are registered on `init` (via register_block_type), which fires
	 * before shortcodes are ever processed (shortcodes run on `the_content`).
	 * This call is therefore always safe.
	 *
	 * @param string $block_slug Slug matching a directory in /blocks/, e.g. 'leaderboard'.
	 * @param array  $attrs     Block attributes array.
	 * @return string HTML output.
	 */
	private static function block( string $block_slug, array $attrs ): string {
		wp_enqueue_style( 'wb-gamification' );

		return render_block(
			array(
				'blockName'    => "wb-gamification/{$block_slug}",
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Render [wb_gam_earning_guide].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_earning_guide( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'columns'               => 3,
				'show_category_headers' => 'true',
			),
			$atts,
			'wb_gam_earning_guide'
		);

		$attrs = array(
			'columns'               => max( 1, min( 4, (int) $atts['columns'] ) ),
			'show_category_headers' => 'true' === $atts['show_category_headers'],
		);

		return self::block( 'earning-guide', $attrs );
	}
}
