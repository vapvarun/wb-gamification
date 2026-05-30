<?php
/**
 * Boot-order contract for the plugin's `plugins_loaded` registration phase.
 *
 * Closes Finding A from plan/STABILITY-AND-ARCHITECTURE-V2.md.
 *
 * Symptoms before this class: a new engine registered at the wrong
 * priority could fire before its dependency had loaded, producing
 * silent no-ops (the class-hoist boot bug fixed in `61f62ca` was a
 * cousin of this same failure mode). The boot order was documented in
 * CLAUDE.md prose; nothing in the code asserted it.
 *
 * Symptoms after: every `plugins_loaded` registration in
 * `wb-gamification.php` uses one of the SLOT_* constants below. A
 * boot-time check at `plugins_loaded@99` (after every registration
 * has fired) records which slugs registered at which slot. If a
 * slug declared a dependency on a slug at a LATER slot, we log a
 * warning. Wrong-priority bugs become noisy at activation time
 * instead of silent at first-use.
 *
 * The slot constants are also the only authorised values — a future
 * coding-rules-check.sh rule can grep for raw integer priorities in
 * `add_action('plugins_loaded', …)` calls and flag them.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class BootOrder {

	/** Schema migrations + DB readiness. DbUpgrader::init runs here. */
	public const SLOT_SCHEMA = 1;

	/** Manifest discovery + Registry seeding (deps for everything later). */
	public const SLOT_REGISTRY = 6;

	/** Core engines + admin REST surface. Deps: SLOT_REGISTRY. */
	public const SLOT_CORE = 8;

	/** Integrations + display layer (BuddyPress, WC, Jetonomy, etc.).
	 *  Deps: SLOT_CORE. */
	public const SLOT_INTEGRATIONS = 10;

	/** Feature-flag-gated optional engines (cohort, weekly emails, etc.).
	 *  Deps: SLOT_CORE. Same priority as integrations but logically distinct. */
	public const SLOT_OPTIONAL = 10;

	/**
	 * Registered slugs, keyed by slug. Value: array(
	 *   'slot'        => int,
	 *   'depends_on'  => list<string>,
	 * ).
	 *
	 * @var array<string, array{slot: int, depends_on: list<string>}>
	 */
	private static array $registrations = array();

	/**
	 * Hook the boot-time validator. Call once from the plugin's
	 * register_hooks(). The validator fires at plugins_loaded@99 — after
	 * every legitimate registration has had a chance to add itself.
	 */
	public static function bind_validator(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'validate' ), 99 );
	}

	/**
	 * Register an engine with the boot-order contract.
	 *
	 * Optional. Engines that don't register here aren't validated, but
	 * also aren't a dependency target for other engines. Use only when
	 * the engine wants to declare a dependency contract OR when other
	 * engines depend on it.
	 *
	 * @param string        $slug       Stable slug (e.g. 'engine', 'registry').
	 * @param int           $slot       One of the SLOT_* constants.
	 * @param array<string> $depends_on Slugs of engines this one needs.
	 */
	public static function register( string $slug, int $slot, array $depends_on = array() ): void {
		self::$registrations[ $slug ] = array(
			'slot'       => $slot,
			'depends_on' => array_values( $depends_on ),
		);
	}

	/**
	 * Validate the registration graph. Runs at plugins_loaded@99.
	 *
	 * Checks:
	 *   - Every declared dependency is itself registered.
	 *   - Every dependency's slot is <= the dependent's slot. A
	 *     dependency at a LATER slot would mean the dependent fires
	 *     before its dependency exists.
	 *
	 * Violations are logged at WARNING level. They never break the
	 * page — silent boot was the bug we're fixing, not a new fatal
	 * surface. Logged warnings show up in monitoring + debug.log.
	 */
	public static function validate(): void {
		foreach ( self::$registrations as $slug => $entry ) {
			foreach ( $entry['depends_on'] as $dep ) {
				if ( ! isset( self::$registrations[ $dep ] ) ) {
					Log::warning(
						sprintf( 'BootOrder: %s declares dependency on unregistered slug %s.', $slug, $dep ),
						array(
							'slug'       => $slug,
							'depends_on' => $dep,
						)
					);
					continue;
				}
				if ( self::$registrations[ $dep ]['slot'] > $entry['slot'] ) {
					Log::warning(
						sprintf(
							'BootOrder: %s (slot %d) depends on %s (slot %d) which loads LATER — dependency fires after dependent. Move %s to a later slot OR move %s earlier.',
							$slug,
							$entry['slot'],
							$dep,
							self::$registrations[ $dep ]['slot'],
							$slug,
							$dep
						),
						array(
							'dependent'       => $slug,
							'dependent_slot'  => $entry['slot'],
							'dependency'      => $dep,
							'dependency_slot' => self::$registrations[ $dep ]['slot'],
						)
					);
				}
			}
		}
	}

	/**
	 * Test-only helper to reset the registration state between tests.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$registrations = array();
	}

	/**
	 * Inspect the current registration graph.
	 *
	 * @internal
	 * @return array<string, array{slot: int, depends_on: list<string>}>
	 */
	public static function get_registrations(): array {
		return self::$registrations;
	}
}
