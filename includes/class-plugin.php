<?php
/**
 * Plugin orchestrator.
 *
 * @package Bundixo
 */

namespace Bundixo;

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
	const MENU_SLUG = 'bundixo';

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
		add_action( 'bundixo_force_frontend_assets', [ $this, 'enqueue_frontend_assets_now' ] );
		add_filter( 'plugin_action_links_' . BUNDIXO_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
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
			__( 'Bundixo', 'bundixo-for-woocommerce' ),
			__( 'Bundixo', 'bundixo-for-woocommerce' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_bundles_page' ],
			BUNDIXO_PLUGIN_URL . 'assets/img/bundixo-icon.svg',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bundles', 'bundixo-for-woocommerce' ),
			__( 'Bundles', 'bundixo-for-woocommerce' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_bundles_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bundle Analytics', 'bundixo-for-woocommerce' ),
			__( 'Analytics', 'bundixo-for-woocommerce' ),
			'manage_options',
			'bundixo-analytics',
			[ $this, 'render_analytics_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'bundixo-for-woocommerce' ),
			__( 'Settings', 'bundixo-for-woocommerce' ),
			'manage_options',
			'bundixo-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostics', 'bundixo-for-woocommerce' ),
			__( 'Diagnostics', 'bundixo-for-woocommerce' ),
			'manage_options',
			'bundixo-diagnostics',
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
		wp_register_style( 'bundixo-admin-chrome', '', [], BUNDIXO_VERSION );
		wp_enqueue_style( 'bundixo-admin-chrome' );
		wp_add_inline_style(
			'bundixo-admin-chrome',
			'#adminmenu #toplevel_page_bundixo .wp-menu-image img{width:20px;height:20px;padding:6px 0;opacity:.6}'
			. '#adminmenu #toplevel_page_bundixo:hover .wp-menu-image img,'
			. '#adminmenu #toplevel_page_bundixo.wp-has-current-submenu .wp-menu-image img{opacity:1}'
		);

		if ( false === strpos( $hook, 'bundixo' ) ) {
			return;
		}

		$css_version = $this->asset_version( 'assets/build/admin.css' );
		$js_version  = $this->asset_version( 'assets/build/admin.js' );

		wp_enqueue_style( 'bundixo-admin', BUNDIXO_PLUGIN_URL . 'assets/build/admin.css', [], $css_version );
		wp_enqueue_script( 'bundixo-admin', BUNDIXO_PLUGIN_URL . 'assets/build/admin.js', [], $js_version, true );

		wp_localize_script(
			'bundixo-admin',
			'bundixoAdmin',
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

		if ( wp_script_is( 'bundixo-frontend', 'done' ) ) {
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

		wp_enqueue_style( 'bundixo-frontend', BUNDIXO_PLUGIN_URL . 'assets/build/frontend.css', [], $css_version );
		wp_enqueue_script( 'bundixo-frontend', BUNDIXO_PLUGIN_URL . 'assets/build/frontend.js', [], $js_version, true );

		$session_discount = [];

		if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC()->session ) {
			$session = Cart::instance()->get_session_data();

			if ( $session && ! empty( $session['bundle_id'] ) ) {
				$session_discount = $session;
			}
		}

		wp_localize_script(
			'bundixo-frontend',
			'bundixoFrontend',
			[
				'restUrl'         => esc_url_raw( rest_url( Rest::NAMESPACE_V1 ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'cartUrl'         => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
				'sessionDiscount' => $session_discount,
				'discountLabel'   => __( 'Bundle Discount', 'bundixo-for-woocommerce' ),
				'currency'        => $this->currency_config(),
				'i18n'            => $this->frontend_i18n(),
			]
		);

		/**
		 * Fires after the frontend widget assets have been enqueued.
		 */
		do_action( 'bundixo_frontend_assets_enqueued' );
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
				$has_bundle = has_shortcode( $post->post_content, 'bundixo_bundle' )
					|| has_block( 'bundixo/bundle', $post );
			}
		} else {
			foreach ( $GLOBALS['posts'] ?? [] as $post ) {
				if ( is_a( $post, 'WP_Post' )
					&& ( has_shortcode( $post->post_content, 'bundixo_bundle' )
						|| has_block( 'bundixo/bundle', $post ) ) ) {
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
		return apply_filters( 'bundixo_should_enqueue_frontend_assets', $has_bundle );
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
				esc_html__( 'Bundles', 'bundixo-for-woocommerce' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=bundixo-settings' ) ),
				esc_html__( 'Settings', 'bundixo-for-woocommerce' )
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
		<div id="bundixo-admin-app" class="bundixo-admin-app" data-page="<?php echo esc_attr( $page ); ?>">
			<p><?php esc_html_e( 'Loading Bundixo…', 'bundixo-for-woocommerce' ); ?></p>
		</div>
		<noscript>
			<div class="notice notice-error"><p><?php esc_html_e( 'Bundixo requires JavaScript.', 'bundixo-for-woocommerce' ); ?></p></div>
		</noscript>
		<?php
	}

	/**
	 * Resolves the current Bundixo admin page identifier.
	 *
	 * @return string
	 */
	private function current_admin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = [
			self::MENU_SLUG          => 'bundles',
			'bundixo-analytics'  => 'analytics',
			'bundixo-settings'   => 'settings',
			'bundixo-diagnostics' => 'diagnostics',
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
		$file = BUNDIXO_PLUGIN_DIR . $relative_path;

		if ( file_exists( $file ) ) {
			return BUNDIXO_VERSION . '.' . (string) filemtime( $file );
		}

		return BUNDIXO_VERSION;
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
			'cancel'            => __( 'Cancel', 'bundixo-for-woocommerce' ),
			'confirm'           => __( 'Confirm', 'bundixo-for-woocommerce' ),
			'add'               => __( 'Add', 'bundixo-for-woocommerce' ),
			'addedLabel'        => __( 'Added', 'bundixo-for-woocommerce' ),
			'edit'              => __( 'Edit', 'bundixo-for-woocommerce' ),
			'delete'            => __( 'Delete', 'bundixo-for-woocommerce' ),
			'remove'            => __( 'Remove', 'bundixo-for-woocommerce' ),
			'loading'           => __( 'Loading…', 'bundixo-for-woocommerce' ),
			'saving'            => __( 'Saving…', 'bundixo-for-woocommerce' ),
			'searching'         => __( 'Searching…', 'bundixo-for-woocommerce' ),
			'error'             => __( 'Something went wrong. Please try again.', 'bundixo-for-woocommerce' ),
			'loadError'         => __( 'Could not load data.', 'bundixo-for-woocommerce' ),
			'ok'                => __( 'OK', 'bundixo-for-woocommerce' ),
			'warning'           => __( 'Warning', 'bundixo-for-woocommerce' ),
			'yes'               => __( 'Yes', 'bundixo-for-woocommerce' ),
			'no'                => __( 'No', 'bundixo-for-woocommerce' ),
			'enabled'           => __( 'Enabled', 'bundixo-for-woocommerce' ),
			'disabled'          => __( 'Disabled', 'bundixo-for-woocommerce' ),
			'status'            => __( 'Status', 'bundixo-for-woocommerce' ),
			'date'              => __( 'Date', 'bundixo-for-woocommerce' ),
			'total'             => __( 'Total', 'bundixo-for-woocommerce' ),
			'discount'          => __( 'Discount', 'bundixo-for-woocommerce' ),

			// Bundles view.
			'yourBundles'       => __( 'Your Bundles', 'bundixo-for-woocommerce' ),
			'bundlesHero'       => __( 'Group products into promotions with tiered quantity discounts, then drop them anywhere with a shortcode.', 'bundixo-for-woocommerce' ),
			'total'             => __( 'total', 'bundixo-for-woocommerce' ),
			'enabledLabel'      => __( 'enabled', 'bundixo-for-woocommerce' ),
			'unnamed'           => __( 'Untitled', 'bundixo-for-woocommerce' ),
			'emptyTitle'        => __( 'No bundles yet', 'bundixo-for-woocommerce' ),
			'emptyHint'         => __( 'Create your first bundle: pick products, set quantity tiers, and publish it with a shortcode.', 'bundixo-for-woocommerce' ),
			'backToList'        => __( 'All bundles', 'bundixo-for-woocommerce' ),
			'tabProducts'       => __( 'Products', 'bundixo-for-woocommerce' ),
			'tabDiscounts'      => __( 'Discounts', 'bundixo-for-woocommerce' ),
			'tabDesign'         => __( 'Design', 'bundixo-for-woocommerce' ),
			'livePreview'       => __( 'Live preview', 'bundixo-for-woocommerce' ),
			'previewNote'       => __( 'A rough sketch of the storefront widget — the real one inherits your theme styles.', 'bundixo-for-woocommerce' ),
			'tierItemsOrMore'   => __( 'items +', 'bundixo-for-woocommerce' ),
			'tierOff'           => __( 'off', 'bundixo-for-woocommerce' ),
			'colorsLabel'       => __( 'Colors', 'bundixo-for-woocommerce' ),
			'addToCart'         => __( 'Add to Cart', 'bundixo-for-woocommerce' ),
			'updated'           => __( 'Bundle updated.', 'bundixo-for-woocommerce' ),
			'newBundle'         => __( 'New Bundle', 'bundixo-for-woocommerce' ),
			'editBundle'        => __( 'Edit Bundle', 'bundixo-for-woocommerce' ),
			'products'          => __( 'products', 'bundixo-for-woocommerce' ),
			'tiers'             => __( 'tiers', 'bundixo-for-woocommerce' ),
			'copyShortcode'     => __( 'Copy shortcode', 'bundixo-for-woocommerce' ),
			'copied'            => __( 'Shortcode copied!', 'bundixo-for-woocommerce' ),
			'deleteBundleTitle' => __( 'Delete bundle?', 'bundixo-for-woocommerce' ),
			'deleteConfirm'     => __( 'Delete this bundle? This cannot be undone.', 'bundixo-for-woocommerce' ),
			'deleted'           => __( 'Bundle deleted.', 'bundixo-for-woocommerce' ),
			'saved'             => __( 'Bundle saved.', 'bundixo-for-woocommerce' ),
			'save'              => __( 'Save Bundle', 'bundixo-for-woocommerce' ),
			'needName'          => __( 'A name is required.', 'bundixo-for-woocommerce' ),
			'needProducts'      => __( 'Add at least one product.', 'bundixo-for-woocommerce' ),
			'needTier'          => __( 'Add at least one discount tier.', 'bundixo-for-woocommerce' ),
			'bundleName'        => __( 'Bundle name', 'bundixo-for-woocommerce' ),
			'description'       => __( 'Description', 'bundixo-for-woocommerce' ),
			'showOnStorefront'  => __( 'Show on storefront', 'bundixo-for-woocommerce' ),
			'showTitle'         => __( 'Show title', 'bundixo-for-woocommerce' ),
			'productsSection'   => __( 'Products', 'bundixo-for-woocommerce' ),
			'searchProducts'    => __( 'Search products', 'bundixo-for-woocommerce' ),
			'searchPlaceholder' => __( 'Type at least 2 characters…', 'bundixo-for-woocommerce' ),
			'selectedProducts'  => __( 'Selected products (drag to reorder)', 'bundixo-for-woocommerce' ),
			'noProductsSelected' => __( 'No products selected yet.', 'bundixo-for-woocommerce' ),
			'discountSection'   => __( 'Discount rules', 'bundixo-for-woocommerce' ),
			'useQuantity'       => __( 'Use quantity mode (steppers instead of checkboxes)', 'bundixo-for-woocommerce' ),
			'maxQuantity'       => __( 'Maximum quantity per product', 'bundixo-for-woocommerce' ),
			'tiersHint'         => __( 'Tiers: buy at least this many items, unlock this percentage off. Items count total units in the bundle.', 'bundixo-for-woocommerce' ),
			'tierQuantity'      => __( 'Items', 'bundixo-for-woocommerce' ),
			'tierDiscount'      => __( '% Off', 'bundixo-for-woocommerce' ),
			'addTier'           => __( 'Add tier', 'bundixo-for-woocommerce' ),
			'appearanceSection' => __( 'Appearance & behavior', 'bundixo-for-woocommerce' ),
			'headingText'       => __( 'Heading text', 'bundixo-for-woocommerce' ),
			'showHeading'       => __( 'Show heading', 'bundixo-for-woocommerce' ),
			'hintText'          => __( 'Hint text', 'bundixo-for-woocommerce' ),
			'showHint'          => __( 'Show hint', 'bundixo-for-woocommerce' ),
			'progressText'      => __( 'Progress section text', 'bundixo-for-woocommerce' ),
			'showProgress'      => __( 'Show progress', 'bundixo-for-woocommerce' ),
			'buttonText'        => __( 'Button text', 'bundixo-for-woocommerce' ),
			'cartBehavior'      => __( 'After adding to cart', 'bundixo-for-woocommerce' ),
			'openSidecart'      => __( 'Open cart / sidecart', 'bundixo-for-woocommerce' ),
			'redirectToCart'    => __( 'Redirect to cart', 'bundixo-for-woocommerce' ),
			'primaryColor'      => __( 'Primary', 'bundixo-for-woocommerce' ),
			'accentColor'       => __( 'Accent', 'bundixo-for-woocommerce' ),
			'hoverBgColor'      => __( 'Card tint', 'bundixo-for-woocommerce' ),
			'hoverAccentColor'  => __( 'Button hover', 'bundixo-for-woocommerce' ),
			'buttonTextColor'   => __( 'Button text', 'bundixo-for-woocommerce' ),

			// Analytics view.
			'dateRange'         => __( 'Date range', 'bundixo-for-woocommerce' ),
			'startDate'         => __( 'Start date', 'bundixo-for-woocommerce' ),
			'endDate'           => __( 'End date', 'bundixo-for-woocommerce' ),
			'apply'             => __( 'Apply', 'bundixo-for-woocommerce' ),
			'statCoupons'       => __( 'Coupons Created', 'bundixo-for-woocommerce' ),
			'statRevenue'       => __( 'Bundle Revenue', 'bundixo-for-woocommerce' ),
			'statOrders'        => __( 'Bundle Orders', 'bundixo-for-woocommerce' ),
			'statUsageRate'     => __( 'Coupon Usage Rate', 'bundixo-for-woocommerce' ),
			'statUsed'          => __( 'Used', 'bundixo-for-woocommerce' ),
			'statUnused'        => __( 'Unused', 'bundixo-for-woocommerce' ),
			'statUsage'         => __( 'Times used', 'bundixo-for-woocommerce' ),
			'withBundle'        => __( 'With Bundle', 'bundixo-for-woocommerce' ),
			'withoutBundle'     => __( 'Without Bundle', 'bundixo-for-woocommerce' ),
			'chartCoupons'      => __( 'Coupon Usage', 'bundixo-for-woocommerce' ),
			'chartRevenue'      => __( 'Bundle Revenue Over Time', 'bundixo-for-woocommerce' ),
			'chartCart'         => __( 'Cart Share', 'bundixo-for-woocommerce' ),
			'chartTopBundles'   => __( 'Top Bundles', 'bundixo-for-woocommerce' ),
			'recentOrders'      => __( 'Recent Bundle Orders', 'bundixo-for-woocommerce' ),
			'noOrders'          => __( 'No bundle orders in this period yet.', 'bundixo-for-woocommerce' ),
			'order'             => __( 'Order', 'bundixo-for-woocommerce' ),
			'range_7days'       => __( 'Last 7 Days', 'bundixo-for-woocommerce' ),
			'range_30days'      => __( 'Last 30 Days', 'bundixo-for-woocommerce' ),
			'range_90days'      => __( 'Last 90 Days', 'bundixo-for-woocommerce' ),
			'range_this_month'  => __( 'This Month', 'bundixo-for-woocommerce' ),
			'range_last_month'  => __( 'Last Month', 'bundixo-for-woocommerce' ),
			'range_this_quarter' => __( 'This Quarter', 'bundixo-for-woocommerce' ),
			'range_this_year'   => __( 'This Year', 'bundixo-for-woocommerce' ),
			'range_custom'      => __( 'Custom Range', 'bundixo-for-woocommerce' ),

			// Settings view.
			'settingsTitle'     => __( 'Settings', 'bundixo-for-woocommerce' ),
			'saveSettings'      => __( 'Save Settings', 'bundixo-for-woocommerce' ),
			'settingsSaved'     => __( 'Settings saved.', 'bundixo-for-woocommerce' ),
			'enableLogging'     => __( 'Enable debug logging', 'bundixo-for-woocommerce' ),
			'loggingHint'       => __( 'When enabled, Bundixo writes diagnostic messages to the WooCommerce log (WooCommerce → Status → Logs, source "bundixo-for-woocommerce"). Leave this off on production stores unless support asks you to enable it.', 'bundixo-for-woocommerce' ),
			'settingsHero'      => __( 'Tune how Bundixo behaves on your store. Changes save instantly.', 'bundixo-for-woocommerce' ),
			'stateSaved'        => __( 'All changes saved', 'bundixo-for-woocommerce' ),
			'stateSaving'       => __( 'Saving…', 'bundixo-for-woocommerce' ),
			'stateUnsaved'      => __( 'Unsaved changes', 'bundixo-for-woocommerce' ),
			'generalGroup'      => __( 'General', 'bundixo-for-woocommerce' ),
			'cartGroup'         => __( 'Cart & discounts', 'bundixo-for-woocommerce' ),
			'defaultBehavior'   => __( 'Default cart behavior', 'bundixo-for-woocommerce' ),
			'defaultBehaviorHint' => __( 'Preselected for every new bundle you create. You can still change it per bundle in the editor.', 'bundixo-for-woocommerce' ),
			'couponLifetime'    => __( 'Coupon lifetime', 'bundixo-for-woocommerce' ),
			'couponLifetimeHint' => __( 'How long a bundle discount coupon stays valid before it is automatically cleaned up. Longer windows leave more unused coupons behind.', 'bundixo-for-woocommerce' ),
			'debugLogging'      => __( 'Debug logging', 'bundixo-for-woocommerce' ),
			'loggingHintShort'  => __( 'Write diagnostic messages to the WooCommerce log while troubleshooting.', 'bundixo-for-woocommerce' ),
			'lifetime24'        => __( '24 hours', 'bundixo-for-woocommerce' ),
			'lifetime48'        => __( '48 hours', 'bundixo-for-woocommerce' ),
			'lifetime72'        => __( '3 days', 'bundixo-for-woocommerce' ),
			'lifetime168'       => __( '1 week', 'bundixo-for-woocommerce' ),
			'openSidecart'      => __( 'Open cart / sidecart', 'bundixo-for-woocommerce' ),
			'redirectToCart'    => __( 'Redirect to cart', 'bundixo-for-woocommerce' ),

			// Diagnostics view.
			'environment'       => __( 'Environment', 'bundixo-for-woocommerce' ),
			'healthChecks'      => __( 'Health Checks', 'bundixo-for-woocommerce' ),
			'diag_wp_version'   => __( 'WordPress Version', 'bundixo-for-woocommerce' ),
			'diag_wc_version'   => __( 'WooCommerce Version', 'bundixo-for-woocommerce' ),
			'diag_php_version'  => __( 'PHP Version', 'bundixo-for-woocommerce' ),
			'diag_plugin_version' => __( 'Plugin Version', 'bundixo-for-woocommerce' ),
			'diag_db_version'   => __( 'Database Schema Version', 'bundixo-for-woocommerce' ),
			'diag_memory_limit' => __( 'Memory Limit', 'bundixo-for-woocommerce' ),
			'diag_timezone'     => __( 'Timezone', 'bundixo-for-woocommerce' ),
			'diag_store_url'    => __( 'Store URL', 'bundixo-for-woocommerce' ),
			'diag_rest_url'     => __( 'REST Endpoint', 'bundixo-for-woocommerce' ),
			'check_table_exists' => __( 'Bundles table exists', 'bundixo-for-woocommerce' ),
			'check_sessions_table' => __( 'WooCommerce sessions table exists', 'bundixo-for-woocommerce' ),
			'check_is_wc_loaded' => __( 'WooCommerce loaded', 'bundixo-for-woocommerce' ),
			'check_logging_enabled' => __( 'Debug logging enabled', 'bundixo-for-woocommerce' ),
			'check_bundle_count' => __( 'Bundles stored', 'bundixo-for-woocommerce' ),
			'check_legacy_migrated' => __( 'Legacy data migrated', 'bundixo-for-woocommerce' ),
			'check_legacy_table' => __( 'Legacy "mmb" table still present', 'bundixo-for-woocommerce' ),
		];
	}

	/**
	 * Localized strings for the storefront widget.
	 *
	 * @return array
	 */
	private function frontend_i18n() {
		return [
			'items'         => __( 'items', 'bundixo-for-woocommerce' ),
			'item'          => __( 'item', 'bundixo-for-woocommerce' ),
			'subtotal'      => __( 'Subtotal', 'bundixo-for-woocommerce' ),
			'discount'      => __( 'Discount', 'bundixo-for-woocommerce' ),
			'total'         => __( 'Total', 'bundixo-for-woocommerce' ),
			'addToCart'     => __( 'Add to Cart', 'bundixo-for-woocommerce' ),
			'adding'        => __( 'Adding…', 'bundixo-for-woocommerce' ),
			'added'         => __( 'Added to cart!', 'bundixo-for-woocommerce' ),
			'selectVariation' => __( 'Select variation', 'bundixo-for-woocommerce' ),
			'chooseProduct' => __( 'Select this product', 'bundixo-for-woocommerce' ),
			'summary'       => __( 'Summary', 'bundixo-for-woocommerce' ),
			'emptySummary'  => __( 'Select products to see your bundle pricing.', 'bundixo-for-woocommerce' ),
			/* translators: 1: number of items, 2: discount percentage */
			'unlockMore'    => __( 'Add %1$s more item(s) to unlock %2$s off', 'bundixo-for-woocommerce' ),
			/* translators: %s: discount percentage */
			'unlocked'      => __( '%1$s off unlocked!', 'bundixo-for-woocommerce' ),
			/* translators: %d: maximum quantity */
			'maxReached'    => __( 'Maximum of %d items per bundle reached.', 'bundixo-for-woocommerce' ),
			'addError'      => __( 'Could not add the bundle to your cart.', 'bundixo-for-woocommerce' ),
			'viewCart'      => __( 'View Cart', 'bundixo-for-woocommerce' ),
			'outOfStock'    => __( 'Out of stock', 'bundixo-for-woocommerce' ),
		];
	}
}
