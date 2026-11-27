<?php
/**
 * PointTypeService
 *
 * Business logic for point types - validation, default-type invariants,
 * and the resolve() helper that callers use to coerce an arbitrary input
 * to a known slug (or fall back to the default).
 *
 * Per the canonical Wbcom 7-layer architecture (`plan/ARCHITECTURE.md`),
 * Service classes own business logic. They depend on Repository for SQL
 * and return structured arrays - never echo HTML, never run wp_die.
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
	 * Return the registered slug list - flat array of normalised
	 * `point_type` slugs. Use this for input validation when a caller
	 * needs to reject unknown slugs explicitly (rather than relying
	 * on {@see resolve()}'s silent fallback to the default slug).
	 *
	 * Added 1.4.1 - PointsController::award uses this to fail loudly
	 * on unknown currency input instead of silently awarding the
	 * primary currency. See audit/DATA-FLOW-ADMIN-REST-2026-05-27.md §G13.
	 *
	 * @return string[]
	 */
	public function list_slugs(): array {
		return array_map(
			static fn ( $row ) => (string) $row['slug'],
			$this->repo->all()
		);
	}

	/**
	 * Resolve an arbitrary input (slug string, null, '') to a known slug.
	 *
	 * Always returns a valid slug - callers can pass user input directly
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
	 * The point type's name for an amount: the singular name for exactly one, else the plural.
	 * Every member-facing surface reads the name here (never `$row['label']`), so the seeded
	 * "Points" / "Point" comes out in the reader's locale and an owner's own wording is kept.
	 *
	 * @since 1.6.5
	 *
	 * @param int         $amount Amount the name goes with (sign ignored).
	 * @param string|null $slug   Point type; null or '' for the primary type.
	 * @return string Never empty.
	 */
	public function name_for( int $amount, ?string $slug = null ): string {
		$record   = $this->get( $this->resolve( '' === $slug ? null : $slug ) );
		$plural   = is_array( $record ) ? trim( (string) ( $record['label'] ?? '' ) ) : '';
		$singular = is_array( $record ) ? trim( (string) ( $record['label_singular'] ?? '' ) ) : '';
		if ( 'Points' === $plural && in_array( $singular, array( '', 'Point' ), true ) ) {
			// The untouched seeded default (the installer stores the English words): read in the
			// reader's own language. A name the owner typed is never in this branch, so it is shown as typed.
			$plural = '';
		}
		if ( '' === $plural ) {
			// No point type configured yet: the plugin's own words, pluralised by amount.
			return _n( 'Point', 'Points', abs( $amount ), 'wb-gamification' );
		}
		return ( 1 === abs( $amount ) && '' !== $singular ) ? $singular : $plural;
	}

	/**
	 * An amount with its name: "1 Point", "250 Points", "+10 Karma".
	 *
	 * @since 1.6.5
	 *
	 * @param int         $amount Amount.
	 * @param string|null $slug   Point type; null or '' for the primary type.
	 * @param bool        $signed Prefix a positive amount with "+" (an award).
	 * @return string Plain text; escape on output.
	 */
	public function format( int $amount, ?string $slug = null, bool $signed = false ): string {
		$number = number_format_i18n( $amount );
		if ( $signed && $amount > 0 ) {
			$number = '+' . $number;
		}
		/* translators: 1: an amount, e.g. "+10" or "250", 2: the site's name for points, e.g. "Points" or "Karma". */
		return sprintf( __( '%1$s %2$s', 'wb-gamification' ), $number, $this->name_for( $amount, $slug ) );
	}

	/**
	 * The point type's icon, from the same row as its label.
	 *
	 * One resolver for every surface that shows a balance, so a label and its icon can never
	 * come from different point types. The icon is a Lucide name the owner typed in Point Types,
	 * rendered with the bundled Lucide font like the hub tiles. A name the font does not have,
	 * or none, falls back to the plugin's default glyph, so this never returns an empty icon.
	 *
	 * @since 1.6.8
	 *
	 * @param string|null $slug  Point type; null or '' for the primary type.
	 * @param string      $class Extra class for the wrapper.
	 * @param int         $size  Icon size in px.
	 * @return string Trusted HTML (built here from a validated name); echo as is.
	 */
	public function icon( ?string $slug = null, string $class = '', int $size = 16 ): string {
		$record = $this->get( $this->resolve( '' === $slug ? null : $slug ) );
		$name   = is_array( $record ) ? strtolower( trim( (string) ( $record['icon'] ?? '' ) ) ) : '';
		$class  = trim( 'wb-gam-pt-icon ' . $class );
		$style  = sprintf( 'display:inline-flex;align-items:center;flex-shrink:0;line-height:1;margin-inline-end:.3em;font-size:%dpx', max( 8, $size ) );

		if ( '' !== $name && 1 === preg_match( '/^[a-z0-9-]+$/', $name ) && self::font_has( $name ) ) {
			wp_enqueue_style( 'lucide-icons' );
			// The wb-gam wrapper is what scopes the Lucide font rules (lucide.css), so the glyph renders anywhere.
			return '<span class="' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '" aria-hidden="true"><i class="icon-' . esc_attr( $name ) . '"></i></span>';
		}

		return '<span class="' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '" aria-hidden="true">' . \WBGam\Admin\Icon::svg( 'sparkles', array( 'size' => '1em' ) ) . '</span>';
	}

	/**
	 * Whether the bundled Lucide font has a glyph for this icon name.
	 *
	 * @param string $name Validated icon name.
	 */
	private static function font_has( string $name ): bool {
		static $css = null;
		if ( null === $css ) {
			// Local plugin file, read once per request and only when an owner configured an icon.
			$css = (string) file_get_contents( WB_GAM_PATH . 'assets/fonts/lucide.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return str_contains( $css, '.icon-' . $name . ':before' );
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
			'slug'           => $slug,
			'label'          => $label,
			'label_singular' => sanitize_text_field( (string) ( $input['label_singular'] ?? '' ) ),
			'description'    => isset( $input['description'] ) ? (string) $input['description'] : null,
			'icon'           => isset( $input['icon'] ) ? (string) $input['icon'] : null,
			'is_default'     => ! empty( $input['is_default'] ),
			'position'       => isset( $input['position'] ) ? (int) $input['position'] : 0,
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
		if ( array_key_exists( 'label_singular', $input ) ) {
			$payload['label_singular'] = sanitize_text_field( (string) $input['label_singular'] );
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
		\WBGam\Engine\Registry::clear_point_type_overrides( $slug );
		return array( 'ok' => true );
	}

	/**
	 * Get the default slug. Convenience wrapper.
	 */
	public function default_slug(): string {
		return $this->repo->default_slug();
	}
}
