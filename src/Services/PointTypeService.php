<?php
/**
 * PointTypeService
 *
 * Business logic for point types — validation, default-type invariants,
 * and the resolve() helper that callers use to coerce an arbitrary input
 * to a known slug (or fall back to the default).
 *
 * Per the canonical Wbcom 7-layer architecture (`plan/ARCHITECTURE.md`),
 * Service classes own business logic. They depend on Repository for SQL
 * and return structured arrays — never echo HTML, never run wp_die.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Services;

use WBGam\Repository\PointTypeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and orchestrates point-type CRUD around the canonical default invariant.
 */
final class PointTypeService {

	private PointTypeRepository $repo;

	/**
	 * @param PointTypeRepository|null $repo Optional repository (DI for tests).
	 */
	public function __construct( ?PointTypeRepository $repo = null ) {
		$this->repo = $repo ?? new PointTypeRepository();
	}

	/**
	 * Return every point type for display / API consumption.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list(): array {
		return $this->repo->all();
	}

	/**
	 * Resolve an arbitrary input (slug string, null, '') to a known slug.
	 *
	 * Always returns a valid slug — callers can pass user input directly
	 * and trust the result. Resolution order:
	 *   1. Provided slug, if it exists in the catalogue
	 *   2. Default slug (`is_default = 1`)
	 *   3. PointTypeRepository::DEFAULT_SLUG fallback constant
	 *
	 * @param string|null $input Raw input.
	 */
	public function resolve( ?string $input ): string {
		// Hot-path fast-return: empty/null input is the most common case
		// (every block render that doesn't filter by type, every action
		// without an admin-set point_type override). Skip the normalise +
		// exists() round-trip and go straight to the cached default.
		if ( null === $input || '' === $input ) {
			return $this->repo->default_slug();
		}

		$slug = PointTypeRepository::normalise_slug( $input );
		if ( '' !== $slug && $this->repo->exists( $slug ) ) {
			return $slug;
		}
		return $this->repo->default_slug();
	}

	/**
	 * Look up a point type by slug.
	 *
	 * @param string $slug Slug to find.
	 */
	public function get( string $slug ): ?array {
		return $this->repo->find( $slug );
	}

	/**
	 * Create a new point type. Returns a structured result.
	 *
	 * @param array<string,mixed> $input Untrusted input (sanitised here).
	 * @return array{ok:bool,error?:string,slug?:string}
	 */
	public function create( array $input ): array {
		$slug = PointTypeRepository::normalise_slug( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_slug',
			);
		}
		if ( $this->repo->exists( $slug ) ) {
			return array(
				'ok'    => false,
				'error' => 'slug_taken',
			);
		}

		$label = trim( (string) ( $input['label'] ?? '' ) );
		if ( '' === $label ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_label',
			);
		}

		$data = array(
			'slug'        => $slug,
			'label'       => $label,
			'description' => isset( $input['description'] ) ? (string) $input['description'] : null,
			'icon'        => isset( $input['icon'] ) ? (string) $input['icon'] : null,
			'is_default'  => ! empty( $input['is_default'] ),
			'position'    => isset( $input['position'] ) ? (int) $input['position'] : 0,
		);

		if ( ! $this->repo->insert( $data ) ) {
			return array(
				'ok'    => false,
				'error' => 'insert_failed',
			);
		}

		// If the new row claims default, promote it (clears the flag elsewhere).
		if ( $data['is_default'] ) {
			$this->repo->set_default( $slug );
		}

		return array(
			'ok'   => true,
			'slug' => $slug,
		);
	}

	/**
	 * Update an existing point type.
	 *
	 * @param string              $slug  Slug of the type to update.
	 * @param array<string,mixed> $input Fields to update.
	 * @return array{ok:bool,error?:string}
	 */
	public function update( string $slug, array $input ): array {
		$slug = PointTypeRepository::normalise_slug( $slug );
		if ( ! $this->repo->exists( $slug ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}

		$payload = array();

		if ( array_key_exists( 'label', $input ) ) {
			$label = trim( (string) $input['label'] );
			if ( '' === $label ) {
				return array(
					'ok'    => false,
					'error' => 'invalid_label',
				);
			}
			$payload['label'] = $label;
		}
		if ( array_key_exists( 'description', $input ) ) {
			$payload['description'] = null === $input['description'] ? null : (string) $input['description'];
		}
		if ( array_key_exists( 'icon', $input ) ) {
			$payload['icon'] = null === $input['icon'] ? null : (string) $input['icon'];
		}
		if ( array_key_exists( 'position', $input ) ) {
			$payload['position'] = (int) $input['position'];
		}

		if ( ! $this->repo->update( $slug, $payload ) ) {
			return array(
				'ok'    => false,
				'error' => 'update_failed',
			);
		}

		if ( ! empty( $input['is_default'] ) ) {
			$this->repo->set_default( $slug );
		}

		return array( 'ok' => true );
	}

	/**
	 * Delete a non-default point type.
	 *
	 * @param string $slug Slug to delete.
	 * @return array{ok:bool,error?:string}
	 */
	public function delete( string $slug ): array {
		$slug = PointTypeRepository::normalise_slug( $slug );
		$row  = $this->repo->find( $slug );

		if ( null === $row ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		if ( (int) $row['is_default'] === 1 ) {
			return array(
				'ok'    => false,
				'error' => 'cannot_delete_default',
			);
		}

		if ( ! $this->repo->delete( $slug ) ) {
			return array(
				'ok'    => false,
				'error' => 'delete_failed',
			);
		}
		return array( 'ok' => true );
	}

	/**
	 * Get the default slug. Convenience wrapper.
	 */
	public function default_slug(): string {
		return $this->repo->default_slug();
	}
}
