<?php
/**
 * Plugin orchestrator.
 *
 * @package PickPack
 */

namespace PickPack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the sub-systems, registers admin/menu/enqueue hooks, and renders
 * the admin page shells.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the frontend widget assets have been enqueued this request.
	 *
	 * @var bool
	 */
	private $frontend_assets_enqueued = false;

	/**
	 * Slug of the top-level admin page.
	 */
	const MENU_SLUG = 'pickpack';

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots every sub-system and hooks into WordPress/WooCommerce.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', [ $this, 'init_extensions' ], 1 );

		Cart::instance();

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'pickpack_force_frontend_assets', [ $this, 'enqueue_frontend_assets_now' ] );
		add_filter( 'plugin_action_links_' . PICKPACK_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
	}

	/**
	 * Runs first thing on init: schema upgrades, shortcode, and block
	 * registration, so their own init hooks land at safe priorities.
	 *
	 * @return void
	 */
	public function init_extensions() {
		Install::maybe_upgrade();
		$this->register_shortcode();
	}

	/**
	 * Registers the storefront shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		$shortcode = new Shortcode();
		$shortcode->register_hooks();

		$block = new Block();
		$block->register_hooks();
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$rest = new Rest();
		$rest->register_hooks();
		$rest->register_routes();
	}

	/**
	 * Registers the admin menu and page callbacks.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'PickPack', 'pickpack-for-woocommerce' ),
			__( 'PickPack', 'pickpack-for-woocommerce' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_bundles_page' ],
			PICKPACK_PLUGIN_URL . 'assets/img/pickpack-icon.svg',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bundles', 'pickpack-for-woocommerce' ),
			__( 'Bundles', 'pickpack-for-woocommerce' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_bundles_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bundle Analytics', 'pickpack-for-woocommerce' ),
			__( 'Analytics', 'pickpack-for-woocommerce' ),
			'manage_options',
			'pickpack-analytics',
			[ $this, 'render_analytics_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'pickpack-for-woocommerce' ),
			__( 'Settings', 'pickpack-for-woocommerce' ),
			'manage_options',
			'pickpack-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostics', 'pickpack-for-woocommerce' ),
			__( 'Diagnostics', 'pickpack-for-woocommerce' ),
			'manage_options',
			'pickpack-diagnostics',
			[ $this, 'render_diagnostics_page' ]
		);
	}

	/**
	 * Enqueues the admin app and shared admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Inline-only style handle carrying the menu icon sizing rules on
		// every admin screen (replaces the legacy echoed <style> block).
		wp_register_style( 'pickpack-admin-chrome', '', [], PICKPACK_VERSION );
		wp_enqueue_style( 'pickpack-admin-chrome' );
		wp_add_inline_style(
			'pickpack-admin-chrome',
			'#adminmenu #toplevel_page_pickpack .wp-menu-image img{width:20px;height:20px;padding:6px 0;opacity:.6}'
			. '#adminmenu #toplevel_page_pickpack:hover .wp-menu-image img,'
			. '#adminmenu #toplevel_page_pickpack.wp-has-current-submenu .wp-menu-image img{opacity:1}'
		);

		if ( false === strpos( $hook, 'pickpack' ) ) {
			return;
		}

		$css_version = $this->asset_version( 'assets/build/admin.css' );
		$js_version  = $this->asset_version( 'assets/build/admin.js' );

		wp_enqueue_style( 'pickpack-admin', PICKPACK_PLUGIN_URL . 'assets/build/admin.css', [], $css_version );
		wp_enqueue_script( 'pickpack-admin', PICKPACK_PLUGIN_URL . 'assets/build/admin.js', [], $js_version, true );

		wp_localize_script(
			'pickpack-admin',
			'pickpackAdmin',
			[
				'restUrl'    => esc_url_raw( rest_url( Rest::NAMESPACE_V1 ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'page'       => $this->current_admin_page(),
				'dateFormat' => get_option( 'date_format' ),
				'currency'   => $this->currency_config(),
				'settings'   => Settings::get(),
				'i18n'       => $this->admin_i18n(),
			]
		);
	}

	/**
	 * Enqueues the frontend widget assets when the current view can contain
	 * a bundle widget.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() || ! $this->should_enqueue_frontend_assets() ) {
			return;
		}

		$this->localize_frontend_assets();
	}

	/**
	 * Late enqueue for widgets rendered outside the standard detection
	 * window (blocks in widgets, filters that render late). Footer
	 * scripts still print after this point.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets_now() {
		if ( is_admin() || $this->frontend_assets_enqueued ) {
			return;
		}

		if ( wp_script_is( 'pickpack-frontend', 'done' ) ) {
			return;
		}

		$this->localize_frontend_assets();
	}

	/**
	 * Registers, localizes, and prints the frontend widget assets.
	 *
	 * @return void
	 */
	private function localize_frontend_assets() {
		if ( $this->frontend_assets_enqueued ) {
			return;
		}

		$this->frontend_assets_enqueued = true;

		$css_version = $this->asset_version( 'assets/build/frontend.css' );
		$js_version  = $this->asset_version( 'assets/build/frontend.js' );

		wp_enqueue_style( 'pickpack-frontend', PICKPACK_PLUGIN_URL . 'assets/build/frontend.css', [], $css_version );
		wp_enqueue_script( 'pickpack-frontend', PICKPACK_PLUGIN_URL . 'assets/build/frontend.js', [], $js_version, true );

		$session_discount = [];

		if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC()->session ) {
			$session = Cart::instance()->get_session_data();

			if ( $session && ! empty( $session['bundle_id'] ) ) {
				$session_discount = $session;
			}
		}

		wp_localize_script(
			'pickpack-frontend',
			'pickpackFrontend',
			[
				'restUrl'         => esc_url_raw( rest_url( Rest::NAMESPACE_V1 ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'cartUrl'         => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
				'sessionDiscount' => $session_discount,
				'discountLabel'   => __( 'Bundle Discount', 'pickpack-for-woocommerce' ),
				'currency'        => $this->currency_config(),
				'i18n'            => $this->frontend_i18n(),
			]
		);

		/**
		 * Fires after the frontend widget assets have been enqueued.
		 */
		do_action( 'pickpack_frontend_assets_enqueued' );
	}

	/**
	 * Detects whether the current request renders a bundle widget.
	 *
	 * @return bool
	 */
	private function should_enqueue_frontend_assets() {
		if ( Frontend::assets_forced() ) {
			return true;
		}

		$has_bundle = false;

		if ( is_singular() ) {
			$post = get_post();

			if ( is_a( $post, 'WP_Post' ) ) {
				$has_bundle = has_shortcode( $post->post_content, 'pickpack_bundle' )
					|| has_block( 'pickpack/bundle', $post );
			}
		} else {
			foreach ( $GLOBALS['posts'] ?? [] as $post ) {
				if ( is_a( $post, 'WP_Post' )
					&& ( has_shortcode( $post->post_content, 'pickpack_bundle' )
						|| has_block( 'pickpack/bundle', $post ) ) ) {
					$has_bundle = true;
					break;
				}
			}
		}

		/**
		 * Filters whether the bundle widget assets should load on this request.
		 *
		 * @param bool $should_load
		 */
		return apply_filters( 'pickpack_should_enqueue_frontend_assets', $has_bundle );
	}

	/**
	 * Adds a Settings/Bundles quick link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$custom = [
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Bundles', 'pickpack-for-woocommerce' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=pickpack-settings' ) ),
				esc_html__( 'Settings', 'pickpack-for-woocommerce' )
			),
		];

		return array_merge( $custom, $links );
	}

	/**
	 * Page renderers. Each is a thin shell the Vue admin app mounts into.
	 *
	 * @return void
	 */
	public function render_bundles_page() {
		$this->render_page_shell( 'bundles' );
	}

	/**
	 * Renders the analytics page shell.
	 *
	 * @return void
	 */
	public function render_analytics_page() {
		$this->render_page_shell( 'analytics' );
	}

	/**
	 * Renders the settings page shell.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->render_page_shell( 'settings' );
	}

	/**
	 * Renders the diagnostics page shell.
	 *
	 * @return void
	 */
	public function render_diagnostics_page() {
		$this->render_page_shell( 'diagnostics' );
	}

	/**
	 * Prints the admin app mount point.
	 *
	 * @param string $page Page identifier for the Vue app.
	 * @return void
	 */
	private function render_page_shell( $page ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div id="pickpack-admin-app" class="pickpack-admin-app" data-page="<?php echo esc_attr( $page ); ?>">
			<p><?php esc_html_e( 'Loading PickPack…', 'pickpack-for-woocommerce' ); ?></p>
		</div>
		<noscript>
			<div class="notice notice-error"><p><?php esc_html_e( 'PickPack requires JavaScript.', 'pickpack-for-woocommerce' ); ?></p></div>
		</noscript>
		<?php
	}

	/**
	 * Resolves the current PickPack admin page identifier.
	 *
	 * @return string
	 */
	private function current_admin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = [
			self::MENU_SLUG          => 'bundles',
			'pickpack-analytics'  => 'analytics',
			'pickpack-settings'   => 'settings',
			'pickpack-diagnostics' => 'diagnostics',
		];

		return $map[ $page ] ?? 'bundles';
	}

	/**
	 * Cache-busting version string for an asset file.
	 *
	 * @param string $relative_path Path relative to the plugin dir.
	 * @return string
	 */
	private function asset_version( $relative_path ) {
		$file = PICKPACK_PLUGIN_DIR . $relative_path;

		if ( file_exists( $file ) ) {
			return PICKPACK_VERSION . '.' . (string) filemtime( $file );
		}

		return PICKPACK_VERSION;
	}

	/**
	 * Currency formatting configuration for the JS apps.
	 *
	 * @return array
	 */
	private function currency_config() {
		return [
			// Symbols arrive HTML-encoded (e.g. "&#36;"); the JS apps render
			// them as text, so hand over the decoded form.
			'symbol'        => function_exists( 'get_woocommerce_currency_symbol' )
				? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				: '$',
			'position'      => get_option( 'woocommerce_currency_pos', 'left' ),
			'thousand_sep'  => get_option( 'woocommerce_price_thousand_sep', ',' ),
			'decimal_sep'   => get_option( 'woocommerce_price_decimal_sep', '.' ),
			'decimals'      => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
		];
	}

	/**
	 * Localized strings for the admin app.
	 *
	 * @return array
	 */
	private function admin_i18n() {
		return [
			// Generic.
			'cancel'            => __( 'Cancel', 'pickpack-for-woocommerce' ),
			'confirm'           => __( 'Confirm', 'pickpack-for-woocommerce' ),
			'add'               => __( 'Add', 'pickpack-for-woocommerce' ),
			'addedLabel'        => __( 'Added', 'pickpack-for-woocommerce' ),
			'edit'              => __( 'Edit', 'pickpack-for-woocommerce' ),
			'delete'            => __( 'Delete', 'pickpack-for-woocommerce' ),
			'remove'            => __( 'Remove', 'pickpack-for-woocommerce' ),
			'loading'           => __( 'Loading…', 'pickpack-for-woocommerce' ),
			'saving'            => __( 'Saving…', 'pickpack-for-woocommerce' ),
			'searching'         => __( 'Searching…', 'pickpack-for-woocommerce' ),
			'error'             => __( 'Something went wrong. Please try again.', 'pickpack-for-woocommerce' ),
			'loadError'         => __( 'Could not load data.', 'pickpack-for-woocommerce' ),
			'ok'                => __( 'OK', 'pickpack-for-woocommerce' ),
			'warning'           => __( 'Warning', 'pickpack-for-woocommerce' ),
			'yes'               => __( 'Yes', 'pickpack-for-woocommerce' ),
			'no'                => __( 'No', 'pickpack-for-woocommerce' ),
			'enabled'           => __( 'Enabled', 'pickpack-for-woocommerce' ),
			'disabled'          => __( 'Disabled', 'pickpack-for-woocommerce' ),
			'status'            => __( 'Status', 'pickpack-for-woocommerce' ),
			'date'              => __( 'Date', 'pickpack-for-woocommerce' ),
			'total'             => __( 'Total', 'pickpack-for-woocommerce' ),
			'discount'          => __( 'Discount', 'pickpack-for-woocommerce' ),

			// Bundles view.
			'yourBundles'       => __( 'Your Bundles', 'pickpack-for-woocommerce' ),
			'bundlesHero'       => __( 'Group products into promotions with tiered quantity discounts, then drop them anywhere with a shortcode.', 'pickpack-for-woocommerce' ),
			'total'             => __( 'total', 'pickpack-for-woocommerce' ),
			'enabledLabel'      => __( 'enabled', 'pickpack-for-woocommerce' ),
			'unnamed'           => __( 'Untitled', 'pickpack-for-woocommerce' ),
			'emptyTitle'        => __( 'No bundles yet', 'pickpack-for-woocommerce' ),
			'emptyHint'         => __( 'Create your first bundle: pick products, set quantity tiers, and publish it with a shortcode.', 'pickpack-for-woocommerce' ),
			'backToList'        => __( 'All bundles', 'pickpack-for-woocommerce' ),
			'tabProducts'       => __( 'Products', 'pickpack-for-woocommerce' ),
			'tabDiscounts'      => __( 'Discounts', 'pickpack-for-woocommerce' ),
			'tabDesign'         => __( 'Design', 'pickpack-for-woocommerce' ),
			'livePreview'       => __( 'Live preview', 'pickpack-for-woocommerce' ),
			'previewNote'       => __( 'A rough sketch of the storefront widget — the real one inherits your theme styles.', 'pickpack-for-woocommerce' ),
			'tierItemsOrMore'   => __( 'items +', 'pickpack-for-woocommerce' ),
			'tierOff'           => __( 'off', 'pickpack-for-woocommerce' ),
			'colorsLabel'       => __( 'Colors', 'pickpack-for-woocommerce' ),
			'addToCart'         => __( 'Add to Cart', 'pickpack-for-woocommerce' ),
			'updated'           => __( 'Bundle updated.', 'pickpack-for-woocommerce' ),
			'newBundle'         => __( 'New Bundle', 'pickpack-for-woocommerce' ),
			'editBundle'        => __( 'Edit Bundle', 'pickpack-for-woocommerce' ),
			'products'          => __( 'products', 'pickpack-for-woocommerce' ),
			'tiers'             => __( 'tiers', 'pickpack-for-woocommerce' ),
			'copyShortcode'     => __( 'Copy shortcode', 'pickpack-for-woocommerce' ),
			'copied'            => __( 'Shortcode copied!', 'pickpack-for-woocommerce' ),
			'deleteBundleTitle' => __( 'Delete bundle?', 'pickpack-for-woocommerce' ),
			'deleteConfirm'     => __( 'Delete this bundle? This cannot be undone.', 'pickpack-for-woocommerce' ),
			'deleted'           => __( 'Bundle deleted.', 'pickpack-for-woocommerce' ),
			'saved'             => __( 'Bundle saved.', 'pickpack-for-woocommerce' ),
			'save'              => __( 'Save Bundle', 'pickpack-for-woocommerce' ),
			'needName'          => __( 'A name is required.', 'pickpack-for-woocommerce' ),
			'needProducts'      => __( 'Add at least one product.', 'pickpack-for-woocommerce' ),
			'needTier'          => __( 'Add at least one discount tier.', 'pickpack-for-woocommerce' ),
			'bundleName'        => __( 'Bundle name', 'pickpack-for-woocommerce' ),
			'description'       => __( 'Description', 'pickpack-for-woocommerce' ),
			'showOnStorefront'  => __( 'Show on storefront', 'pickpack-for-woocommerce' ),
			'showTitle'         => __( 'Show title', 'pickpack-for-woocommerce' ),
			'productsSection'   => __( 'Products', 'pickpack-for-woocommerce' ),
			'searchProducts'    => __( 'Search products', 'pickpack-for-woocommerce' ),
			'searchPlaceholder' => __( 'Type at least 2 characters…', 'pickpack-for-woocommerce' ),
			'selectedProducts'  => __( 'Selected products (drag to reorder)', 'pickpack-for-woocommerce' ),
			'noProductsSelected' => __( 'No products selected yet.', 'pickpack-for-woocommerce' ),
			'discountSection'   => __( 'Discount rules', 'pickpack-for-woocommerce' ),
			'useQuantity'       => __( 'Use quantity mode (steppers instead of checkboxes)', 'pickpack-for-woocommerce' ),
			'maxQuantity'       => __( 'Maximum quantity per product', 'pickpack-for-woocommerce' ),
			'tiersHint'         => __( 'Tiers: buy at least this many items, unlock this percentage off. Items count total units in the bundle.', 'pickpack-for-woocommerce' ),
			'tierQuantity'      => __( 'Items', 'pickpack-for-woocommerce' ),
			'tierDiscount'      => __( '% Off', 'pickpack-for-woocommerce' ),
			'addTier'           => __( 'Add tier', 'pickpack-for-woocommerce' ),
			'appearanceSection' => __( 'Appearance & behavior', 'pickpack-for-woocommerce' ),
			'headingText'       => __( 'Heading text', 'pickpack-for-woocommerce' ),
			'showHeading'       => __( 'Show heading', 'pickpack-for-woocommerce' ),
			'hintText'          => __( 'Hint text', 'pickpack-for-woocommerce' ),
			'showHint'          => __( 'Show hint', 'pickpack-for-woocommerce' ),
			'progressText'      => __( 'Progress section text', 'pickpack-for-woocommerce' ),
			'showProgress'      => __( 'Show progress', 'pickpack-for-woocommerce' ),
			'buttonText'        => __( 'Button text', 'pickpack-for-woocommerce' ),
			'cartBehavior'      => __( 'After adding to cart', 'pickpack-for-woocommerce' ),
			'openSidecart'      => __( 'Open cart / sidecart', 'pickpack-for-woocommerce' ),
			'redirectToCart'    => __( 'Redirect to cart', 'pickpack-for-woocommerce' ),
			'primaryColor'      => __( 'Primary', 'pickpack-for-woocommerce' ),
			'accentColor'       => __( 'Accent', 'pickpack-for-woocommerce' ),
			'hoverBgColor'      => __( 'Card tint', 'pickpack-for-woocommerce' ),
			'hoverAccentColor'  => __( 'Button hover', 'pickpack-for-woocommerce' ),
			'buttonTextColor'   => __( 'Button text', 'pickpack-for-woocommerce' ),

			// Analytics view.
			'dateRange'         => __( 'Date range', 'pickpack-for-woocommerce' ),
			'startDate'         => __( 'Start date', 'pickpack-for-woocommerce' ),
			'endDate'           => __( 'End date', 'pickpack-for-woocommerce' ),
			'apply'             => __( 'Apply', 'pickpack-for-woocommerce' ),
			'statCoupons'       => __( 'Coupons Created', 'pickpack-for-woocommerce' ),
			'statRevenue'       => __( 'Bundle Revenue', 'pickpack-for-woocommerce' ),
			'statOrders'        => __( 'Bundle Orders', 'pickpack-for-woocommerce' ),
			'statUsageRate'     => __( 'Coupon Usage Rate', 'pickpack-for-woocommerce' ),
			'statUsed'          => __( 'Used', 'pickpack-for-woocommerce' ),
			'statUnused'        => __( 'Unused', 'pickpack-for-woocommerce' ),
			'statUsage'         => __( 'Times used', 'pickpack-for-woocommerce' ),
			'withBundle'        => __( 'With Bundle', 'pickpack-for-woocommerce' ),
			'withoutBundle'     => __( 'Without Bundle', 'pickpack-for-woocommerce' ),
			'chartCoupons'      => __( 'Coupon Usage', 'pickpack-for-woocommerce' ),
			'chartRevenue'      => __( 'Bundle Revenue Over Time', 'pickpack-for-woocommerce' ),
			'chartCart'         => __( 'Cart Share', 'pickpack-for-woocommerce' ),
			'chartTopBundles'   => __( 'Top Bundles', 'pickpack-for-woocommerce' ),
			'recentOrders'      => __( 'Recent Bundle Orders', 'pickpack-for-woocommerce' ),
			'noOrders'          => __( 'No bundle orders in this period yet.', 'pickpack-for-woocommerce' ),
			'order'             => __( 'Order', 'pickpack-for-woocommerce' ),
			'range_7days'       => __( 'Last 7 Days', 'pickpack-for-woocommerce' ),
			'range_30days'      => __( 'Last 30 Days', 'pickpack-for-woocommerce' ),
			'range_90days'      => __( 'Last 90 Days', 'pickpack-for-woocommerce' ),
			'range_this_month'  => __( 'This Month', 'pickpack-for-woocommerce' ),
			'range_last_month'  => __( 'Last Month', 'pickpack-for-woocommerce' ),
			'range_this_quarter' => __( 'This Quarter', 'pickpack-for-woocommerce' ),
			'range_this_year'   => __( 'This Year', 'pickpack-for-woocommerce' ),
			'range_custom'      => __( 'Custom Range', 'pickpack-for-woocommerce' ),

			// Settings view.
			'settingsTitle'     => __( 'Settings', 'pickpack-for-woocommerce' ),
			'saveSettings'      => __( 'Save Settings', 'pickpack-for-woocommerce' ),
			'settingsSaved'     => __( 'Settings saved.', 'pickpack-for-woocommerce' ),
			'enableLogging'     => __( 'Enable debug logging', 'pickpack-for-woocommerce' ),
			'loggingHint'       => __( 'When enabled, PickPack writes diagnostic messages to the WooCommerce log (WooCommerce → Status → Logs, source "pickpack-for-woocommerce"). Leave this off on production stores unless support asks you to enable it.', 'pickpack-for-woocommerce' ),
			'settingsHero'      => __( 'Tune how PickPack behaves on your store. Changes save instantly.', 'pickpack-for-woocommerce' ),
			'stateSaved'        => __( 'All changes saved', 'pickpack-for-woocommerce' ),
			'stateSaving'       => __( 'Saving…', 'pickpack-for-woocommerce' ),
			'stateUnsaved'      => __( 'Unsaved changes', 'pickpack-for-woocommerce' ),
			'generalGroup'      => __( 'General', 'pickpack-for-woocommerce' ),
			'cartGroup'         => __( 'Cart & discounts', 'pickpack-for-woocommerce' ),
			'defaultBehavior'   => __( 'Default cart behavior', 'pickpack-for-woocommerce' ),
			'defaultBehaviorHint' => __( 'Preselected for every new bundle you create. You can still change it per bundle in the editor.', 'pickpack-for-woocommerce' ),
			'couponLifetime'    => __( 'Coupon lifetime', 'pickpack-for-woocommerce' ),
			'couponLifetimeHint' => __( 'How long a bundle discount coupon stays valid before it is automatically cleaned up. Longer windows leave more unused coupons behind.', 'pickpack-for-woocommerce' ),
			'debugLogging'      => __( 'Debug logging', 'pickpack-for-woocommerce' ),
			'loggingHintShort'  => __( 'Write diagnostic messages to the WooCommerce log while troubleshooting.', 'pickpack-for-woocommerce' ),
			'lifetime24'        => __( '24 hours', 'pickpack-for-woocommerce' ),
			'lifetime48'        => __( '48 hours', 'pickpack-for-woocommerce' ),
			'lifetime72'        => __( '3 days', 'pickpack-for-woocommerce' ),
			'lifetime168'       => __( '1 week', 'pickpack-for-woocommerce' ),
			'openSidecart'      => __( 'Open cart / sidecart', 'pickpack-for-woocommerce' ),
			'redirectToCart'    => __( 'Redirect to cart', 'pickpack-for-woocommerce' ),

			// Diagnostics view.
			'environment'       => __( 'Environment', 'pickpack-for-woocommerce' ),
			'healthChecks'      => __( 'Health Checks', 'pickpack-for-woocommerce' ),
			'diag_wp_version'   => __( 'WordPress Version', 'pickpack-for-woocommerce' ),
			'diag_wc_version'   => __( 'WooCommerce Version', 'pickpack-for-woocommerce' ),
			'diag_php_version'  => __( 'PHP Version', 'pickpack-for-woocommerce' ),
			'diag_plugin_version' => __( 'Plugin Version', 'pickpack-for-woocommerce' ),
			'diag_db_version'   => __( 'Database Schema Version', 'pickpack-for-woocommerce' ),
			'diag_memory_limit' => __( 'Memory Limit', 'pickpack-for-woocommerce' ),
			'diag_timezone'     => __( 'Timezone', 'pickpack-for-woocommerce' ),
			'diag_store_url'    => __( 'Store URL', 'pickpack-for-woocommerce' ),
			'diag_rest_url'     => __( 'REST Endpoint', 'pickpack-for-woocommerce' ),
			'check_table_exists' => __( 'Bundles table exists', 'pickpack-for-woocommerce' ),
			'check_sessions_table' => __( 'WooCommerce sessions table exists', 'pickpack-for-woocommerce' ),
			'check_is_wc_loaded' => __( 'WooCommerce loaded', 'pickpack-for-woocommerce' ),
			'check_logging_enabled' => __( 'Debug logging enabled', 'pickpack-for-woocommerce' ),
			'check_bundle_count' => __( 'Bundles stored', 'pickpack-for-woocommerce' ),
			'check_legacy_migrated' => __( 'Legacy data migrated', 'pickpack-for-woocommerce' ),
			'check_legacy_table' => __( 'Legacy "mmb" table still present', 'pickpack-for-woocommerce' ),
		];
	}

	/**
	 * Localized strings for the storefront widget.
	 *
	 * @return array
	 */
	private function frontend_i18n() {
		return [
			'items'         => __( 'items', 'pickpack-for-woocommerce' ),
			'item'          => __( 'item', 'pickpack-for-woocommerce' ),
			'subtotal'      => __( 'Subtotal', 'pickpack-for-woocommerce' ),
			'discount'      => __( 'Discount', 'pickpack-for-woocommerce' ),
			'total'         => __( 'Total', 'pickpack-for-woocommerce' ),
			'addToCart'     => __( 'Add to Cart', 'pickpack-for-woocommerce' ),
			'adding'        => __( 'Adding…', 'pickpack-for-woocommerce' ),
			'added'         => __( 'Added to cart!', 'pickpack-for-woocommerce' ),
			'selectVariation' => __( 'Select variation', 'pickpack-for-woocommerce' ),
			'chooseProduct' => __( 'Select this product', 'pickpack-for-woocommerce' ),
			'summary'       => __( 'Summary', 'pickpack-for-woocommerce' ),
			'emptySummary'  => __( 'Select products to see your bundle pricing.', 'pickpack-for-woocommerce' ),
			/* translators: 1: number of items, 2: discount percentage */
			'unlockMore'    => __( 'Add %1$s more item(s) to unlock %2$s off', 'pickpack-for-woocommerce' ),
			/* translators: %s: discount percentage */
			'unlocked'      => __( '%1$s off unlocked!', 'pickpack-for-woocommerce' ),
			/* translators: %d: maximum quantity */
			'maxReached'    => __( 'Maximum of %d items per bundle reached.', 'pickpack-for-woocommerce' ),
			'addError'      => __( 'Could not add the bundle to your cart.', 'pickpack-for-woocommerce' ),
			'viewCart'      => __( 'View Cart', 'pickpack-for-woocommerce' ),
			'outOfStock'    => __( 'Out of stock', 'pickpack-for-woocommerce' ),
		];
	}
}
