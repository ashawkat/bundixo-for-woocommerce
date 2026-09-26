<?php
/**
 * Plugin Name: Bundixo for WooCommerce
 * Plugin URI: https://github.com/ashawkat/bundixo-for-woocommerce
 * Description: Build product bundle promotions with tiered quantity discounts — Gutenberg block, shortcode, Vue-powered admin, analytics, and a customer-facing bundle builder widget.
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Betatech
 * Author URI: https://betatech.co
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bundixo-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 *
 * @package Bundixo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BUNDIXO_VERSION', '1.0.3' );
define( 'BUNDIXO_DB_VERSION', '1.2' );
define( 'BUNDIXO_PLUGIN_FILE', __FILE__ );
define( 'BUNDIXO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUNDIXO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BUNDIXO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for Bundixo classes.
 *
 * Maps Bundixo\Foo_Bar to includes/class-foo-bar.php.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
function bundixo_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Bundixo\\' ) ) {
		return;
	}

	$slug = strtolower( str_replace( [ 'Bundixo\\', '_' ], [ '', '-' ], $class_name ) );
	$file = BUNDIXO_PLUGIN_DIR . 'includes/class-' . $slug . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'bundixo_autoload' );

/**
 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage).
 *
 * @return void
 */
function bundixo_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BUNDIXO_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'bundixo_declare_hpos_compatibility' );

/**
 * Activation: create the bundles table and migrate legacy data if present.
 *
 * @return void
 */
function bundixo_activate_plugin() {
	Bundixo\Install::activate();
}
register_activation_hook( __FILE__, 'bundixo_activate_plugin' );

/**
 * Deactivation: clear the scheduled coupon cleanup event.
 *
 * @return void
 */
function bundixo_deactivate_plugin() {
	$timestamp = wp_next_scheduled( 'bundixo_daily_coupon_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'bundixo_daily_coupon_cleanup' );
	}
}
register_deactivation_hook( __FILE__, 'bundixo_deactivate_plugin' );

/**
 * Boot the plugin once WooCommerce is available.
 *
 * @return void
 */
function bundixo_boot_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'bundixo_woocommerce_missing_notice' );
		return;
	}

	Bundixo\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'bundixo_boot_plugin', 20 );

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function bundixo_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php echo esc_html__( 'Bundixo for WooCommerce', 'bundixo-for-woocommerce' ); ?></strong>
			<?php echo esc_html__( 'requires WooCommerce to be installed and active.', 'bundixo-for-woocommerce' ); ?>
			<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">
				<?php echo esc_html__( 'Install WooCommerce', 'bundixo-for-woocommerce' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Schedule the daily cleanup of expired bundle coupons.
 *
 * @return void
 */
function bundixo_schedule_coupon_cleanup() {
	if ( ! wp_next_scheduled( 'bundixo_daily_coupon_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'bundixo_daily_coupon_cleanup' );
	}
}
add_action( 'wp', 'bundixo_schedule_coupon_cleanup' );

/**
 * Delete unused bundle coupons (cron callback).
 *
 * @return void
 */
function bundixo_run_coupon_cleanup() {
	if ( class_exists( 'WooCommerce' ) ) {
		Bundixo\Cart::instance()->cleanup_unused_coupons();
	}
}
add_action( 'bundixo_daily_coupon_cleanup', 'bundixo_run_coupon_cleanup' );
