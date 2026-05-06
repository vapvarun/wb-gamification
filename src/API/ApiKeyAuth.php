<?php
/**
 * REST API: API Key Authentication
 *
 * Provides API key authentication for remote sites connecting to
 * the gamification center in standalone mode.
 *
 * SECURITY MODEL (v1.1+):
 *   Keys are stored as SHA-256 hashes in a dedicated `wb_gam_api_keys`
 *   table. The full key is shown ONCE on creation; thereafter only the
 *   prefix + suffix are visible. DB backups, `wp option get`, and any
 *   other plugin with admin DB access cannot recover the live key.
 *
 *   Lookup is O(1) on the UNIQUE key_hash index. last_used is debounced
 *   to once-per-minute writes so authenticated REST hits don't thrash
 *   wp_options or the new table.
 *
 * Two deployment modes:
 *   1. Local mode — plugin on same site, uses WordPress cookie/nonce auth.
 *   2. Standalone center mode — dedicated site, remote clients authenticate
 *      via API keys sent in the X-WB-Gam-Key header or ?api_key query param.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

defined( 'ABSPATH' ) || exit;

/**
 * API key authentication handler for the WB Gamification REST API.
 *
 * @package WB_Gamification
 */
final class ApiKeyAuth {

	/**
	 * Legacy option key (pre-1.1, plaintext storage). Read-only — purged
	 * by `DbUpgrader::ensure_api_keys_table` on first upgrade. Retained
	 * here as a constant only so the migration tooling has a name to
	 * reference; nothing in this file writes to it.
	 *
	 * @var string
	 */
	private const LEGACY_OPTION_KEY = 'wb_gam_api_keys';

	/**
	 * Initialize API key authentication hooks.
	 */
	public static function init(): void {
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate' ), 20 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'set_site_context' ), 10, 3 );

		// Inject remote site_id into event metadata for cross-site attribution.
		add_filter(
			'wb_gam_event_metadata',
			static function ( $metadata ) {
				if ( ! empty( $GLOBALS['wb_gam_remote_site_id'] ) ) {
					$metadata['_site_id'] = $GLOBALS['wb_gam_remote_site_id'];
				}
				return $metadata;
			}
		);

		// CORS headers for cross-origin API key authenticated requests.
		add_action(
			'rest_api_init',
			static function () {
				add_filter(
					'rest_pre_serve_request',
					static function ( $value ) {
						if ( ! empty( $GLOBALS['wb_gam_remote_site_id'] ) ) {
							$origin = get_http_origin();
							if ( $origin ) {
								header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
								header( 'Access-Control-Allow-Credentials: true' );
								header( 'Access-Control-Allow-Headers: X-WB-Gam-Key, Content-Type, Authorization, X-WP-Nonce' );
								header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
							}
						}
						return $value;
					}
				);
			}
		);

		// Surface a one-time admin notice if the upgrade purged legacy keys
		// so admins know their paired sites need new keys.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_legacy_purge_notice' ) );
	}

	// ── Authentication ─────────────────────────────────────────────────────────

	/**
	 * Authenticate via X-WB-Gam-Key header or ?api_key query param.
	 *
	 * @param \WP_Error|null|true $result Existing authentication result.
	 * @return \WP_Error|null|true
	 */
	public static function authenticate( $result ) {
		// Don't override existing auth (cookie/nonce).
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		// Only apply to our namespace.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URI path comparison only, no output.
		$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( strpos( $rest_route, 'wb-gamification/v1' ) === false ) {
			return $result;
		}

		$api_key = self::get_key_from_request();
		if ( '' === $api_key ) {
			return $result; // No key provided — let WP handle auth normally.
		}

		$row = self::find_by_key( $api_key );

		if ( ! $row || 1 !== (int) $row['is_active'] ) {
			return new \WP_Error(
				'wb_gam_invalid_api_key',
				__( 'Invalid or inactive API key.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( (int) $row['user_id'] );

		if ( ! empty( $row['site_id'] ) ) {
			$GLOBALS['wb_gam_remote_site_id'] = sanitize_text_field( (string) $row['site_id'] );
		}

		// Debounce last_used — write at most once per 60s per key. Without
		// this every authenticated REST hit writes to the keys table; mobile
		// app polling could push thousands of writes/min on busy sites.
		self::touch_last_used_if_stale( (int) $row['id'], (string) ( $row['last_used'] ?? '' ) );

		return true;
	}

	/**
	 * Inject site_id into event metadata for remote events.
	 *
	 * @param mixed            $result  Response to replace the requested response.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed Unmodified result.
	 */
	public static function set_site_context( $result, $server, $request ) {
		if ( isset( $GLOBALS['wb_gam_remote_site_id'] ) ) {
			$request->set_param( '_site_id', $GLOBALS['wb_gam_remote_site_id'] );
		}
		return $result;
	}

	/**
	 * One-time admin notice when the v1.1 migration purged legacy plaintext
	 * keys. Auto-clears after the admin sees it once.
	 */
	public static function maybe_show_legacy_purge_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( 'wb_gam_api_keys_legacy_purged' ) ) {
			return;
		}
		delete_transient( 'wb_gam_api_keys_legacy_purged' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'WB Gamification — API keys were rotated for security.', 'wb-gamification' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Your previously-issued API keys were stored in plaintext. They have been removed and you\'ll need to issue new keys for any paired remote sites. The new storage format hashes keys at rest — even DB backups and admin DB access cannot recover them.', 'wb-gamification' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-api-keys' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Generate new keys', 'wb-gamification' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── Public CRUD (used by ApiKeysController + ApiKeysPage) ──────────────────

	/**
	 * List all keys (admin-facing). Returns metadata only — never the
	 * hashed key or any value that could be reversed into the plaintext.
	 *
	 * @return array<int, array{id:int,label:string,user_id:int,site_id:string,key_prefix:string,key_suffix:string,is_active:int,created_at:string,last_used:?string}>
	 */
	public static function get_keys(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin-only listing of issued keys.
		$rows = (array) $wpdb->get_results(
			"SELECT id, label, user_id, site_id, key_prefix, key_suffix, is_active, created_at, last_used
			   FROM {$wpdb->prefix}wb_gam_api_keys
			   ORDER BY created_at DESC",
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'label'      => (string) $row['label'],
					'user_id'    => (int) $row['user_id'],
					'site_id'    => (string) ( $row['site_id'] ?? '' ),
					'key_prefix' => (string) $row['key_prefix'],
					'key_suffix' => (string) $row['key_suffix'],
					'is_active'  => (int) $row['is_active'],
					'created_at' => (string) $row['created_at'],
					'last_used'  => $row['last_used'] ? (string) $row['last_used'] : null,
				);
			},
			$rows
		);
	}

	/**
	 * Generate + persist a new API key. Returns the FULL key — caller is
	 * responsible for showing it to the admin exactly once. After this
	 * returns, the key cannot be recovered from the database.
	 *
	 * @param string $label   Human-readable label.
	 * @param int    $user_id WordPress user ID associated with the key.
	 * @param string $site_id Optional remote site identifier.
	 * @return string The full plaintext key (display once, then forget).
	 */
	public static function create_key( string $label, int $user_id, string $site_id = '' ): string {
		$key = 'wbgam_' . wp_generate_password( 40, false );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin write.
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_api_keys',
			array(
				'key_hash'   => self::hash_key( $key ),
				'key_prefix' => substr( $key, 0, 14 ),                       // 'wbgam_' + first 8 chars.
				'key_suffix' => substr( $key, -4 ),
				'label'      => sanitize_text_field( $label ),
				'user_id'    => $user_id,
				'site_id'    => sanitize_text_field( $site_id ),
				'is_active'  => 1,
				'created_at' => current_time( 'mysql', true ),
				'last_used'  => null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return $key;
	}

	/**
	 * Revoke (deactivate) a key by its DB id — keeps the audit row but
	 * stops authentication. Use delete_key for full removal.
	 *
	 * @param int $key_id Row id from get_keys().
	 */
	public static function revoke_key( int $key_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin write.
		$ok = $wpdb->update(
			$wpdb->prefix . 'wb_gam_api_keys',
			array( 'is_active' => 0 ),
			array( 'id' => $key_id ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Permanently delete a key by its DB id. Loses the audit row.
	 *
	 * @param int $key_id Row id from get_keys().
	 */
	public static function delete_key( int $key_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin write.
		$ok = $wpdb->delete(
			$wpdb->prefix . 'wb_gam_api_keys',
			array( 'id' => $key_id ),
			array( '%d' )
		);
		return false !== $ok;
	}

	// ── Internals ──────────────────────────────────────────────────────────────

	/**
	 * Hash an API key for storage / lookup.
	 *
	 * SHA-256 with the WP auth salt — deterministic so the same input maps
	 * to the same hash, but unrecoverable without the plaintext. PBKDF2
	 * isn't worth the cost here because the keyspace is 40 random alphanum
	 * chars (~232 bits) — brute force is computationally infeasible.
	 *
	 * @param string $key Full plaintext key.
	 */
	private static function hash_key( string $key ): string {
		return hash( 'sha256', wp_salt( 'auth' ) . $key );
	}

	/**
	 * Look up a key row by its plaintext value. Hashes the input, queries
	 * the unique index. Returns null if not found.
	 *
	 * @param string $key Plaintext key from the request.
	 * @return array<string,mixed>|null
	 */
	private static function find_by_key( string $key ): ?array {
		global $wpdb;
		$hash = self::hash_key( $key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- single-row lookup on UNIQUE index; not cacheable per-request.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, site_id, is_active, last_used FROM {$wpdb->prefix}wb_gam_api_keys WHERE key_hash = %s LIMIT 1",
				$hash
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Update last_used at most once per 60 seconds per key. Without this
	 * debounce every authenticated REST hit writes a row — for a mobile
	 * app polling every 10s on a busy site, that's thousands of writes/min.
	 *
	 * @param int    $key_id    Row id.
	 * @param string $last_used Current last_used value (MySQL DATETIME or '').
	 */
	private static function touch_last_used_if_stale( int $key_id, string $last_used ): void {
		$now           = time();
		$last_used_ts  = '' !== $last_used ? (int) strtotime( $last_used . ' UTC' ) : 0;
		$staleness_sec = 60;

		if ( $last_used_ts > 0 && ( $now - $last_used_ts ) < $staleness_sec ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- single-row update on PK; debounced to once-per-60s.
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_api_keys',
			array( 'last_used' => gmdate( 'Y-m-d H:i:s', $now ) ),
			array( 'id' => $key_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Extract the API key from request headers or query params.
	 */
	private static function get_key_from_request(): string {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( strtolower( (string) $name ) === 'x-wb-gam-key' ) {
						return sanitize_text_field( (string) $value );
					}
				}
			}
		}

		// Fallback header lookup via $_SERVER for hosts where getallheaders is unavailable.
		if ( isset( $_SERVER['HTTP_X_WB_GAM_KEY'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WB_GAM_KEY'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- API key auth, not a form submission.
		return isset( $_GET['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['api_key'] ) ) : '';
	}
}
