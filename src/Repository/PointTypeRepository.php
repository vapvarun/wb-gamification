<?php
/**
 * PointTypeRepository
 *
 * Custom-table SQL ONLY for `wb_gam_point_types`. Per the canonical
 * Wbcom 7-layer architecture (`plan/ARCHITECTURE.md`), Repository classes
 * own DB queries — no business logic, no HTTP, no rendering.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the point-types catalogue.
 */
final class PointTypeRepository {

	private const CACHE_GROUP        = 'wb_gamification';
	private const CACHE_KEY_ALL      = 'point_types_all';
	private const CACHE_KEY_DEFAULT  = 'point_types_default';
	private const CACHE_TTL_SECONDS  = 300;
	public const DEFAULT_SLUG       = 'points';

	/**
	 * Per-request in-process cache. Populated on first read, cleared on
	 * write. Critical for 100k-user scale: a single hub render touches the
	 * catalogue from 5+ block renders + Engine + Leaderboard. Without this
	 * static, every call goes through wp_cache_get → SQL on hosts without
	 * a persistent object cache (Redis/Memcached). With this static, the
	 * second-and-onwards call within one PHP request is free.
	 *
	 * @var array<int, array<string,mixed>>|null
	 */
	private static ?array $request_cache_all = null;

	/** @var string|null */
	private static ?string $request_cache_default = null;

	/**
	 * Return every active point type, ordered by position then slug.
	 *
	 * Resolution order: in-process static → wp_cache → SQL.
	 *
	 * @return array<int, array{slug:string,label:string,description:?string,icon:?string,is_default:int,position:int,created_at:string}>
	 */
	public function all(): array {
		if ( null !== self::$request_cache_all ) {
			return self::$request_cache_all;
		}

		$cached = wp_cache_get( self::CACHE_KEY_ALL, self::CACHE_GROUP );
		if ( false !== $cached ) {
			self::$request_cache_all = (array) $cached;
			return self::$request_cache_all;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; result is cached above.
		$rows = $wpdb->get_results(
			"SELECT slug, label, description, icon, is_default, position, created_at
			   FROM {$wpdb->prefix}wb_gam_point_types
			  ORDER BY position ASC, slug ASC",
			ARRAY_A
		) ?: array();

		wp_cache_set( self::CACHE_KEY_ALL, $rows, self::CACHE_GROUP, self::CACHE_TTL_SECONDS );
		self::$request_cache_all = $rows;

		return $rows;
	}

	/**
	 * Look up a single type by slug.
	 *
	 * @param string $slug Point-type slug.
	 * @return array<string,mixed>|null
	 */
	public function find( string $slug ): ?array {
		$slug = self::normalise_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->all() as $row ) {
			if ( $row['slug'] === $slug ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Return whether a slug refers to a known type.
	 *
	 * @param string $slug Slug to check.
	 */
	public function exists( string $slug ): bool {
		return null !== $this->find( $slug );
	}

	/**
	 * Return the default type slug.
	 *
	 * Falls back to the constant {@see PointTypeRepository::DEFAULT_SLUG} if no
	 * row is flagged as default — guarantees callers always have a usable slug.
	 */
	public function default_slug(): string {
		if ( null !== self::$request_cache_default ) {
			return self::$request_cache_default;
		}

		$cached = wp_cache_get( self::CACHE_KEY_DEFAULT, self::CACHE_GROUP );
		if ( is_string( $cached ) && '' !== $cached ) {
			self::$request_cache_default = $cached;
			return $cached;
		}

		foreach ( $this->all() as $row ) {
			if ( (int) $row['is_default'] === 1 ) {
				wp_cache_set( self::CACHE_KEY_DEFAULT, $row['slug'], self::CACHE_GROUP, self::CACHE_TTL_SECONDS );
				self::$request_cache_default = $row['slug'];
				return $row['slug'];
			}
		}

		self::$request_cache_default = self::DEFAULT_SLUG;
		return self::DEFAULT_SLUG;
	}

	/**
	 * Insert a new point type.
	 *
	 * Returns false if the slug already exists (uniqueness via PRIMARY KEY).
	 *
	 * @param array{slug:string,label:string,description?:string,icon?:string,is_default?:bool,position?:int} $data Sanitised input.
	 */
	public function insert( array $data ): bool {
		global $wpdb;

		$slug = self::normalise_slug( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_point_types',
			array(
				'slug'        => $slug,
				'label'       => (string) $data['label'],
				'description' => isset( $data['description'] ) ? (string) $data['description'] : null,
				'icon'        => isset( $data['icon'] ) ? (string) $data['icon'] : null,
				'is_default'  => ! empty( $data['is_default'] ) ? 1 : 0,
				'position'    => isset( $data['position'] ) ? (int) $data['position'] : 0,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( $inserted ) {
			$this->flush_cache();
		}

		return (bool) $inserted;
	}

	/**
	 * Update mutable fields on an existing point type. Slug is the PK and immutable.
	 *
	 * @param string                                                                $slug Existing slug.
	 * @param array{label?:string,description?:?string,icon?:?string,position?:int} $data Fields to update.
	 */
	public function update( string $slug, array $data ): bool {
		$slug = self::normalise_slug( $slug );
		if ( '' === $slug || ! $this->exists( $slug ) ) {
			return false;
		}

		$payload = array();
		$format  = array();

		if ( array_key_exists( 'label', $data ) ) {
			$payload['label'] = (string) $data['label'];
			$format[]         = '%s';
		}
		if ( array_key_exists( 'description', $data ) ) {
			$payload['description'] = null === $data['description'] ? null : (string) $data['description'];
			$format[]               = '%s';
		}
		if ( array_key_exists( 'icon', $data ) ) {
			$payload['icon'] = null === $data['icon'] ? null : (string) $data['icon'];
			$format[]        = '%s';
		}
		if ( array_key_exists( 'position', $data ) ) {
			$payload['position'] = (int) $data['position'];
			$format[]            = '%d';
		}

		if ( empty( $payload ) ) {
			return true;
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'wb_gam_point_types',
			$payload,
			array( 'slug' => $slug ),
			$format,
			array( '%s' )
		);

		if ( false !== $updated ) {
			$this->flush_cache();
			return true;
		}
		return false;
	}

	/**
	 * Mark one type as default; clear the flag on every other row.
	 *
	 * @param string $slug Slug to promote.
	 */
	public function set_default( string $slug ): bool {
		$slug = self::normalise_slug( $slug );
		if ( '' === $slug || ! $this->exists( $slug ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrapped table name; cache flushed below.
		$wpdb->query( "UPDATE {$wpdb->prefix}wb_gam_point_types SET is_default = 0" );

		$promoted = $wpdb->update(
			$wpdb->prefix . 'wb_gam_point_types',
			array( 'is_default' => 1 ),
			array( 'slug' => $slug ),
			array( '%d' ),
			array( '%s' )
		);

		$this->flush_cache();
		return false !== $promoted;
	}

	/**
	 * Delete a point type. Refuses to delete the default type — that's a
	 * service-level invariant; callers should set a different default first.
	 *
	 * @param string $slug Slug to delete.
	 */
	public function delete( string $slug ): bool {
		$slug = self::normalise_slug( $slug );
		$row  = $this->find( $slug );
		if ( null === $row || (int) $row['is_default'] === 1 ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wb_gam_point_types',
			array( 'slug' => $slug ),
			array( '%s' )
		);

		if ( $deleted ) {
			$this->flush_cache();
		}

		return (bool) $deleted;
	}

	/**
	 * Normalise + validate a slug for storage.
	 *
	 * Lowercase, alphanumeric + dash + underscore, max 60 chars. Empty string
	 * means "invalid" — callers should treat as a validation failure.
	 *
	 * @param string $raw Untrusted input.
	 */
	public static function normalise_slug( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		$slug = (string) preg_replace( '/[^a-z0-9_-]/', '', $slug );
		return substr( $slug, 0, 60 );
	}

	/**
	 * Drop cached lookups. Called after every write.
	 *
	 * Invalidates BOTH the persistent wp_cache layer AND the in-process
	 * static — otherwise an admin who creates a new currency would see
	 * stale list output for the rest of the request.
	 */
	private function flush_cache(): void {
		wp_cache_delete( self::CACHE_KEY_ALL, self::CACHE_GROUP );
		wp_cache_delete( self::CACHE_KEY_DEFAULT, self::CACHE_GROUP );
		self::$request_cache_all     = null;
		self::$request_cache_default = null;
	}
}
