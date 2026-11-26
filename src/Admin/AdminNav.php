<?php
/**
 * One admin menu for the plugin, in six groups.
 *
 * Before 1.7.0 an owner met two menus: the WordPress submenu (every page registered itself, so the
 * order was load order) and a second hand-written sidebar inside Settings, with six screens in
 * both. This class is the single list. It builds the WordPress submenu (one entry per group) and
 * the side menu every gamification screen shows, and keeps the Gamification menu open on every
 * screen, hidden ones included. Page slugs never change, so old links keep working.
 *
 * An item is either a page (`page` slug) or a section of the Settings page (`section`, shown as
 * admin.php?page=wb-gamification#section). A page item shows only when WordPress registered that
 * page for this user (a module that is off removes its page; a missing capability hides it). A
 * section item shows when the user can manage options and its module, if any, is on.
 *
 * @package WB_Gamification
 * @since   1.7.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's admin menu.
 */
final class AdminNav {

	/** Top-level menu slug. */
	public const PARENT = 'wb-gamification';

	/**
	 * Pages that open from inside another item and are not listed themselves: page slug => the
	 * item they belong to.
	 */
	private const CHILD_OF = array(
		'wb-gam-community-challenges' => 'challenges',
	);

	/**
	 * Slugs WordPress registered under the plugin menu for this request (filled in arrange()).
	 *
	 * @var array<string, array{0:string,1:string,2:string}>|null
	 */
	private static ?array $registered = null;

	/**
	 * Hook the menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'arrange' ), 1000 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
	}

	/**
	 * The six groups and their items, in the order an owner works through them.
	 *
	 * @return array<string, array{label:string, icon:string, items:array<int, array<string, string>>}>
	 */
	public static function groups(): array {
		return array(
			'overview'   => array(
				'label' => __( 'Overview', 'wb-gamification' ),
				'icon'  => 'icon-layout-dashboard',
				'items' => array(
					array(
						'key'     => 'dashboard',
						'label'   => __( 'Dashboard', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'dashboard',
					),
					array(
						'key'   => 'analytics',
						'label' => __( 'Analytics', 'wb-gamification' ),
						'page'  => 'wb-gamification-analytics',
					),
				),
			),
			'earning'    => array(
				'label' => __( 'Earning', 'wb-gamification' ),
				'icon'  => 'icon-star',
				'items' => array(
					array(
						'key'     => 'points',
						'label'   => __( 'Actions and points', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'points',
					),
					array(
						'key'   => 'point-types',
						'label' => __( 'Point types', 'wb-gamification' ),
						'page'  => 'wb-gam-point-types',
					),
					array(
						'key'   => 'conversions',
						'label' => __( 'Conversions', 'wb-gamification' ),
						'page'  => 'wb-gam-conversions',
					),
					array(
						'key'   => 'multipliers',
						'label' => __( 'Multipliers', 'wb-gamification' ),
						'page'  => 'wb-gam-multipliers',
					),
					array(
						'key'     => 'rules',
						'label'   => __( 'Rules', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'rules',
					),
				),
			),
			'rewards'    => array(
				'label' => __( 'Rewards', 'wb-gamification' ),
				'icon'  => 'icon-award',
				'items' => array(
					array(
						'key'   => 'levels',
						'label' => __( 'Levels', 'wb-gamification' ),
						'page'  => 'wb-gam-levels',
					),
					array(
						'key'   => 'badges',
						'label' => __( 'Badges', 'wb-gamification' ),
						'page'  => 'wb-gamification-badges',
					),
					array(
						'key'   => 'challenges',
						'label' => __( 'Challenges', 'wb-gamification' ),
						'page'  => 'wb-gam-challenges',
					),
					array(
						'key'   => 'streaks',
						'label' => __( 'Streaks', 'wb-gamification' ),
						'page'  => 'wb-gamification-streaks',
					),
					array(
						'key'     => 'leagues',
						'label'   => __( 'Leagues', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'cohort',
						'module'  => 'cohort_leagues',
					),
					array(
						'key'   => 'store',
						'label' => __( 'Redemption store', 'wb-gamification' ),
						'page'  => 'wb-gam-redemption',
					),
				),
			),
			'members'    => array(
				'label' => __( 'Members', 'wb-gamification' ),
				'icon'  => 'icon-users',
				'items' => array(
					array(
						'key'   => 'members',
						'label' => __( 'Members', 'wb-gamification' ),
						'page'  => 'wb-gamification-members',
					),
					array(
						'key'   => 'award',
						'label' => __( 'Award points', 'wb-gamification' ),
						'page'  => 'wb-gamification-award',
					),
					array(
						'key'   => 'submissions',
						'label' => __( 'Submissions', 'wb-gamification' ),
						'page'  => 'wb-gam-submissions',
					),
					array(
						'key'   => 'kudos-moderation',
						'label' => __( 'Kudos moderation', 'wb-gamification' ),
						'page'  => 'wb-gamification-kudos-moderation',
					),
					array(
						'key'     => 'kudos',
						'label'   => __( 'Kudos settings', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'kudos',
						'module'  => 'kudos',
					),
					array(
						'key'     => 'access',
						'label'   => __( 'Access', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'access',
					),
				),
			),
			'experience' => array(
				'label' => __( 'Experience', 'wb-gamification' ),
				'icon'  => 'icon-bell',
				'items' => array(
					array(
						'key'     => 'notifications',
						'label'   => __( 'Notifications', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'realtime',
					),
					array(
						'key'     => 'emails',
						'label'   => __( 'Emails', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'emails',
					),
					array(
						'key'     => 'profiles',
						'label'   => __( 'Profiles and habits', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'engagement',
					),
					array(
						'key'     => 'appearance',
						'label'   => __( 'Appearance', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'appearance',
					),
				),
			),
			'tools'      => array(
				'label' => __( 'Tools', 'wb-gamification' ),
				'icon'  => 'icon-wrench',
				'items' => array(
					array(
						'key'     => 'integrations',
						'label'   => __( 'Integrations', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'integrations',
					),
					array(
						'key'     => 'modules',
						'label'   => __( 'Modules', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'modules',
					),
					array(
						'key'   => 'import',
						'label' => __( 'Import from another plugin', 'wb-gamification' ),
						'page'  => 'wb-gamification-import',
					),
					array(
						'key'     => 'tools',
						'label'   => __( 'Export and maintenance', 'wb-gamification' ),
						'page'    => self::PARENT,
						'section' => 'tools',
					),
					array(
						'key'   => 'webhooks',
						'label' => __( 'Webhooks', 'wb-gamification' ),
						'page'  => 'wb-gam-webhooks',
					),
					array(
						'key'   => 'api-keys',
						'label' => __( 'API keys', 'wb-gamification' ),
						'page'  => 'wb-gam-api-keys',
					),
					// Setup again on a configured site: it opens with the site's current values.
					array(
						'key'   => 'setup',
						'label' => __( 'Setup', 'wb-gamification' ),
						'page'  => SetupWizard::PAGE_SLUG,
					),
				),
			),
		);
	}

	/**
	 * Where an item opens: a page slug, or the Settings page with its section.
	 *
	 * @param array<string, string> $item Item from groups().
	 * @return string Submenu slug (WordPress links a slug with "admin.php" as given).
	 */
	public static function target( array $item ): string {
		return empty( $item['section'] ) || 'dashboard' === $item['section']
			? $item['page']
			: 'admin.php?page=' . $item['page'] . '#' . $item['section'];
	}

	/**
	 * Keep only the items this user can open, given the pages WordPress registered for them.
	 *
	 * @param array<string, array<string, mixed>> $groups     From groups().
	 * @param array<string, string>               $registered Registered page slug => required capability.
	 * @param callable                            $can        fn( string $capability ): bool.
	 * @param callable                            $module_on  fn( string $module ): bool.
	 * @return array<string, array<string, mixed>> Groups with at least one item left.
	 */
	public static function visible( array $groups, array $registered, callable $can, callable $module_on ): array {
		foreach ( $groups as $key => $group ) {
			$group['items'] = array_values(
				array_filter(
					$group['items'],
					static function ( array $item ) use ( $registered, $can, $module_on ): bool {
						if ( ! isset( $registered[ $item['page'] ] ) || ! $can( $registered[ $item['page'] ] ) ) {
							return false;
						}
						return empty( $item['module'] ) || $module_on( $item['module'] );
					}
				)
			);
			if ( $group['items'] ) {
				$groups[ $key ] = $group;
			} else {
				unset( $groups[ $key ] );
			}
		}
		return $groups;
	}

	/**
	 * The WordPress submenu: one entry per group, opening its first item, then any page another
	 * plugin added under ours, in its existing order.
	 *
	 * @param array<int, array<int, string>>      $rows   Registered submenu rows (title, capability, slug).
	 * @param array<string, array<string, mixed>> $groups Visible groups.
	 * @return array<int, array<int, string>>
	 */
	public static function build_submenu( array $rows, array $groups ): array {
		$known = array( self::PARENT => true );
		foreach ( self::groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$known[ $item['page'] ] = true;
			}
		}
		foreach ( array_keys( self::CHILD_OF ) as $slug ) {
			$known[ $slug ] = true;
		}
		$caps = array_column( $rows, 1, 2 );

		$out = array();
		foreach ( $groups as $group ) {
			$first = $group['items'][0];
			$out[] = array( $group['label'], $caps[ $first['page'] ] ?? 'manage_options', self::target( $first ), $group['label'] );
		}
		foreach ( $rows as $row ) {
			if ( ! isset( $known[ $row[2] ?? '' ] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The item a screen belongs to.
	 *
	 * @param string $page    Current admin page slug.
	 * @param string $section Settings section, when on the Settings page.
	 * @return array{group:string, item:string}|null
	 */
	public static function locate( string $page, string $section = '' ): ?array {
		$want = self::CHILD_OF[ $page ] ?? null;
		foreach ( self::groups() as $group_key => $group ) {
			foreach ( $group['items'] as $item ) {
				$hit = null !== $want
					? $item['key'] === $want
					: ( $item['page'] === $page && ( empty( $item['section'] ) || ( $item['section'] === ( '' === $section ? 'dashboard' : $section ) ) ) );
				if ( $hit ) {
					return array(
						'group' => $group_key,
						'item'  => $item['key'],
					);
				}
			}
		}
		return null;
	}

	/**
	 * On admin_menu (after every page registered): note what is registered and give each page the
	 * shared shell. The submenu itself is rebuilt later, on admin_head: WordPress checks access to
	 * a page by finding its row in the submenu, so removing rows here would lock owners out of
	 * the hidden pages (Setup answered "not allowed").
	 */
	public static function arrange(): void {
		global $submenu;
		$rows = isset( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ? $submenu[ self::PARENT ] : array();

		self::$registered = array( self::PARENT => 'manage_options' );
		foreach ( $rows as $row ) {
			if ( ! empty( $row[2] ) ) {
				self::$registered[ $row[2] ] = (string) $row[1];
			}
		}

		self::wrap_pages();
		add_action( 'admin_head', array( __CLASS__, 'relist' ) );
	}

	/**
	 * On admin_head (after the access check, before the menu prints): list the six groups.
	 * Hidden pages stay registered, so every old address still opens its screen.
	 */
	public static function relist(): void {
		global $submenu;
		$rows = isset( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ? $submenu[ self::PARENT ] : array();

		$submenu[ self::PARENT ] = self::build_submenu( $rows, self::visible_for_current_user() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- arranging our own submenu is the point of this hook.
	}

	/**
	 * Give every plugin screen the same side menu. WordPress renders an admin page on its page
	 * hook, so opening the shell before (priority 0) and closing it after the page's own callback
	 * wraps all of them here; a page added later gets the shell without touching its file. The
	 * Settings page draws its own (it switches sections in place), and Setup is full screen.
	 */
	private static function wrap_pages(): void {
		global $_registered_pages;
		$slugs = array_keys( self::CHILD_OF );
		foreach ( self::groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$slugs[] = $item['page'];
			}
		}
		foreach ( array_unique( $slugs ) as $slug ) {
			if ( self::PARENT === $slug || SetupWizard::PAGE_SLUG === $slug ) {
				continue;
			}
			foreach ( array( self::PARENT, '' ) as $parent ) {
				$hook = get_plugin_page_hookname( $slug, $parent );
				if ( isset( $_registered_pages[ $hook ] ) ) {
					add_action( $hook, array( __CLASS__, 'open_shell' ), 0 );
					add_action( $hook, array( __CLASS__, 'close_shell' ), PHP_INT_MAX );
					break;
				}
			}
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_shell' ) );
	}

	/**
	 * Open the shared shell: side menu, then the page's own content.
	 */
	public static function open_shell(): void {
		echo '<div class="wbgam-settings-wrap wbgam-shell">';
		self::render_sidebar( self::current_page() );
		echo '<div class="wbgam-settings-content">';
	}

	/**
	 * Close the shared shell.
	 */
	public static function close_shell(): void {
		echo '</div></div>';
	}

	/**
	 * The shell's styles live with the Settings page's (one set of rules for one side menu).
	 *
	 * @return void
	 */
	public static function enqueue_shell(): void {
		$page = self::current_page();
		if ( '' === $page || self::PARENT === $page || SetupWizard::PAGE_SLUG === $page || null === self::locate( $page ) ) {
			return;
		}
		wp_enqueue_style( 'wb-gam-page-settings', plugins_url( 'assets/css/admin/pages/settings.css', WB_GAM_FILE ), array( 'wb-gam-admin-utilities' ), WB_GAM_VERSION );
	}

	/**
	 * Groups the current user can open on this request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function visible_for_current_user(): array {
		return self::visible(
			self::groups(),
			self::$registered ?? array( self::PARENT => 'manage_options' ),
			static fn( string $cap ): bool => current_user_can( $cap ),
			static fn( string $module ): bool => \WBGam\Engine\ModuleToggles::enabled( $module )
		);
	}

	/**
	 * Keep the Gamification menu open on every plugin screen, hidden ones included.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		return null !== self::locate( self::current_page() ) ? self::PARENT : $parent_file;
	}

	/**
	 * Highlight the group the current screen belongs to.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Parent menu file.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		$at = self::locate( self::current_page() );
		if ( null === $at || self::PARENT !== $parent_file ) {
			return $submenu_file;
		}
		$groups = self::visible_for_current_user();
		return isset( $groups[ $at['group'] ] ) ? self::target( $groups[ $at['group'] ]['items'][0] ) : $submenu_file;
	}

	/**
	 * The current admin page slug.
	 */
	private static function current_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
	}

	/**
	 * The side menu every gamification screen shows. On the Settings page section links stay
	 * in-page anchors (its script switches sections); elsewhere they open the Settings page.
	 *
	 * @param string $page    Current admin page slug.
	 * @param string $section Current Settings section ('' when not on Settings).
	 */
	public static function render_sidebar( string $page, string $section = '' ): void {
		$at          = self::locate( $page, $section );
		$on_settings = self::PARENT === $page;
		?>
		<nav class="wbgam-settings-sidebar" aria-label="<?php esc_attr_e( 'Gamification', 'wb-gamification' ); ?>">
			<?php foreach ( self::visible_for_current_user() as $group_key => $group ) : ?>
				<details class="wbgam-settings-nav-group" <?php echo ( null === $at || $at['group'] === $group_key ) ? 'open' : ''; ?>>
					<summary class="wbgam-settings-nav-group__label">
						<span class="<?php echo esc_attr( $group['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $group['label'] ); ?>
					</summary>
					<?php
					foreach ( $group['items'] as $item ) :
						$current     = null !== $at && $at['item'] === $item['key'];
						$section_key = $item['section'] ?? '';
						$href        = ( $on_settings && '' !== $section_key )
							? '#' . $section_key
							: admin_url( self::target( $item ) === $item['page'] ? 'admin.php?page=' . $item['page'] : self::target( $item ) );
						?>
						<a class="wbgam-settings-nav-item<?php echo $current ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( $href ); ?>"
							<?php echo ( $on_settings && '' !== $section_key ) ? 'data-section="' . esc_attr( $section_key ) . '"' : ''; ?>
							<?php echo $current ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( $item['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
