<?php
/**
 * Auto-registers wb-gamification blocks from `build/Blocks/*\/block.json`.
 *
 * Phase B of the Wbcom Block Quality Standard migration introduces this
 * registrar so that blocks compiled from `src/Blocks/<slug>/` via
 * `npm run build` are discovered without hand-editing the bootstrap.
 * Hardcoded entries in `WB_Gamification::register_blocks()` shrink as
 * blocks migrate; the Registrar takes ownership of every slug listed
 * under `build/Blocks/`.
 *
 * Capital `Blocks/` is intentional — it matches both the `WBGam\Blocks\`
 * PSR-4 namespace and works on case-sensitive Linux filesystems.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase B.6
 *
 * @package WBGam\Blocks
 */

namespace WBGam\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Scans a build directory once per request and registers every block
 * whose `block.json` is present and not already registered.
 */
final class Registrar {

	/**
	 * Block names already registered by the registrar (per-request).
	 *
	 * @var array<int, string>
	 */
	private static $registered = array();

	/**
	 * Absolute path to the build directory containing `Blocks/`.
	 *
	 * @var string
	 */
	private $build_dir;

	/**
	 * Construct the registrar with the directory it should scan.
	 *
	 * The directory is `<build_dir>/Blocks/` to match the PSR-4 layout
	 * — `src/Blocks/<slug>/block.json` compiles to
	 * `build/Blocks/<slug>/block.json`. The path is intentionally
	 * case-sensitive (matters on Linux production filesystems).
	 *
	 * @param string $build_dir Absolute path. Trailing slash optional.
	 */
	public function __construct( string $build_dir ) {
		$this->build_dir = trailingslashit( $build_dir ) . 'Blocks/';
	}

	/**
	 * Hook block registration onto `init`.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
	}

	/**
	 * Scan the build directory and register every block.
	 */
	public function register_blocks(): void {
		if ( ! is_dir( $this->build_dir ) ) {
			return;
		}

		$pattern    = $this->build_dir . '*/block.json';
		$manifests  = glob( $pattern );
		if ( ! is_array( $manifests ) || empty( $manifests ) ) {
			return;
		}

		/**
		 * Filter the discovered list of block.json paths before registration.
		 *
		 * @param array<int, string> $manifests Absolute paths to `block.json` files.
		 */
		$manifests = (array) apply_filters( 'wb_gam_block_manifests', $manifests );

		foreach ( $manifests as $manifest ) {
			$this->register_one( (string) $manifest );
		}
	}

	/**
	 * Register a single block by its block.json absolute path.
	 *
	 * @param string $manifest_path Absolute path to a `block.json`.
	 */
	private function register_one( string $manifest_path ): void {
		if ( ! is_readable( $manifest_path ) ) {
			return;
		}

		$json = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			return;
		}

		$data = json_decode( $json, true );
		$name = is_array( $data ) ? (string) ( $data['name'] ?? '' ) : '';

		if ( '' === $name ) {
			return;
		}

		if ( in_array( $name, self::$registered, true ) ) {
			return;
		}

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			self::$registered[] = $name;
			return;
		}

		register_block_type( dirname( $manifest_path ) );
		self::$registered[] = $name;
	}

	/**
	 * Test-only helper: clear the per-request registration set.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$registered = array();
	}
}
