<?php
/**
 * Cart and coupon handling.
 *
 * Bundle discounts are implemented as dynamically created WooCommerce
 * coupons, so the discount renders correctly in every cart view (classic
 * cart, sidecarts, block cart, checkout). The coupon amount is always
 * computed server-side from live product prices.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the dynamic coupon lifecycle and the WooCommerce cart hooks that
 * keep the session coupon in sync with the cart contents.
 */
class Cart {

	/**
	 * Singleton instance.
	 *
	 * @var Cart|null
	 */
	private static $instance = null;

	/**
	 * Re-entrancy guard for the coupon re-apply cycle. WC_Cart fires
	 * woocommerce_after_calculate_totals from inside calculate_totals(),
	 * so re-applying a coupon and recalculating from those hooks without a
	 * guard recurses until the stack limit when the coupon cannot apply.
	 *
	 * @var bool
	 */
	private $applying_session_coupon = false;

	/**
	 * WC session key holding the active bundle discount.
	 */
	const SESSION_KEY = 'bundixo_bundle_discount';

	/**
	 * Cart item meta flagging a product as part of a bundle.
	 */
	const ITEM_META = 'bundixo_bundle_item';

	/**
	 * Cart item meta holding the bundle ID.
	 */
	const BUNDLE_META = 'bundixo_bundle_id';

	/**
	 * Prefix for every dynamically generated coupon code.
	 */
	const COUPON_PREFIX = 'bundixo_bundle_';

	/**
	 * Cache group shared by cart lookups.
	 */
	const CACHE_GROUP = 'bundixo';

	/**
	 * Singleton accessor.
	 *
	 * @return Cart
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers cart hooks. Private to force instantiation through
	 * instance(), which the plugin boots once per request.
	 */
	private function __construct() {
		// Re-apply the session coupon wherever the cart is rebuilt. One
		// implementation replaces the four duplicated legacy shims.
		add_action( 'woocommerce_before_checkout_process', [ $this, 'reapply_session_coupon' ], 10 );
		add_action( 'woocommerce_before_cart', [ $this, 'reapply_session_coupon' ], 10 );
		add_action( 'woocommerce_before_mini_cart_contents', [ $this, 'reapply_session_coupon' ], 10 );
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'reapply_session_coupon' ], 10 );

		// Remove the coupon when bundle items leave the cart.
		add_action( 'woocommerce_cart_item_removed', [ $this, 'maybe_remove_bundle_coupon' ], 10, 2 );
		add_action( 'woocommerce_cart_emptied', [ $this, 'remove_bundle_coupon' ], 10 );

		// Keep our dynamic coupon valid and quiet in validation flows.
		add_filter( 'woocommerce_coupon_is_valid', [ $this, 'validate_bundle_coupon' ], 10, 3 );
		add_filter( 'woocommerce_coupon_error', [ $this, 'suppress_bundle_coupon_error' ], 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_cart', [ $this, 'validate_bundle_coupon_for_cart' ], 10, 2 );

		// Coexist with "individual use" coupons (e.g. store-wide auto-applied
		// coupons): never reject ours next to them, and keep ours on the cart
		// when an individual-use coupon is applied afterwards.
		add_filter( 'woocommerce_apply_with_individual_use_coupon', [ $this, 'allow_with_individual_use_coupon' ], 10, 2 );
		add_filter( 'woocommerce_apply_individual_use_coupon', [ $this, 'keep_bundle_coupons_for_individual_use' ], 10, 3 );

		// Scrub coupon error notices as soon as they hit the session.
		add_action( 'woocommerce_before_checkout_process', [ $this, 'remove_bundle_coupon_notices' ], 5 );
		add_action( 'woocommerce_after_calculate_totals', [ $this, 'remove_bundle_coupon_notices' ], 5 );
		add_action( 'woocommerce_applied_coupon', [ $this, 'remove_bundle_coupon_notices' ], 5 );
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'remove_bundle_coupon_notices' ], 5 );

		// Make sure the coupon discount reaches block-based carts.
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'update_block_mini_cart_fragments' ], 20 );

		add_action( 'woocommerce_after_calculate_totals', [ $this, 'verify_coupon_applied' ], 10 );

		// Drop stale coupon/error state for carts that no longer contain
		// bundle items, before any notice can render.
		add_action( 'template_redirect', [ $this, 'cleanup_invalid_bundle_coupons' ], 5 );
		add_action( 'wp_loaded', [ $this, 'cleanup_invalid_bundle_coupons' ], 5 );
	}

	// ------------------------------------------------------------------
	// Session handling
	// ------------------------------------------------------------------

	/**
	 * Whether a WooCommerce session is available. WooCommerce initializes
	 * the session itself for every storefront and AJAX request, which
	 * covers all of our REST routes; no manual construction is attempted.
	 *
	 * @return bool
	 */
	public function ensure_session() {
		return function_exists( 'WC' ) && null !== WC()->session;
	}

	/**
	 * Active bundle session record, or null.
	 *
	 * @return array|null
	 */
	public function get_session_data() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$data = WC()->session->get( self::SESSION_KEY );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Persists the active bundle session record.
	 *
	 * @param array $data Session record.
	 * @return void
	 */
	public function set_session_data( array $data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, $data );
	}

	/**
	 * Removes the previous bundle coupon and clears the session record so
	 * two different bundles never combine discounts.
	 *
	 * @return void
	 */
	public function clear_session_bundle() {
		$data = $this->get_session_data();

		if ( $data && ! empty( $data['coupon_code'] ) && function_exists( 'WC' ) && WC()->cart ) {
			$coupon_code = $data['coupon_code'];

			if ( WC()->cart->has_discount( $coupon_code ) ) {
				WC()->cart->remove_coupon( $coupon_code );
			}
		}

		$this->set_session_data( [] );
	}

	// ------------------------------------------------------------------
	// Coupon lifecycle
	// ------------------------------------------------------------------

	/**
	 * Creates (or updates) the dynamic coupon carrying the bundle discount.
	 *
	 * @param int     $bundle_id       Bundle ID.
	 * @param float   $discount_amount Server-computed discount.
	 * @param float   $subtotal        Bundle subtotal at quote time.
	 * @param array   $product_ids     Product IDs the coupon is limited to.
	 * @return string Coupon code, or empty string on failure.
	 */
	public function create_bundle_coupon( $bundle_id, $discount_amount, $subtotal, array $product_ids = [] ) {
		try {
			if ( ! $this->ensure_session() ) {
				return '';
			}

			if ( $discount_amount <= 0 ) {
				return '';
			}

			$coupon_code = $this->get_bundle_coupon_code( $bundle_id );
			$coupon_id   = wc_get_coupon_id_by_code( $coupon_code );

			if ( $coupon_id ) {
				$coupon = new \WC_Coupon( $coupon_id );
			} else {
				$coupon = new \WC_Coupon();
				$coupon->set_code( $coupon_code );
			}

			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( (float) $discount_amount );
			$coupon->set_individual_use( false );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_limit_usage_to_x_items( null );
			$coupon->set_free_shipping( false );
			$coupon->set_exclude_sale_items( false );
			$coupon->set_minimum_amount( 0 );
			$coupon->set_maximum_amount( '' );
			$coupon->set_date_expires( time() + Settings::coupon_lifetime_hours() * HOUR_IN_SECONDS );

			if ( ! empty( $product_ids ) ) {
				$coupon->set_product_ids( array_map( 'absint', $product_ids ) );
			}

			$coupon->set_status( 'publish' );
			$coupon->save();

			wp_cache_delete( 'wc_coupon_' . $coupon_code );

			$this->log(
				sprintf( 'Coupon created: %1$s (amount %2$s, subtotal %3$s)', $coupon_code, $discount_amount, $subtotal )
			);

			return $coupon_code;
		} catch ( \Exception $e ) {
			$this->log( 'Coupon creation failed: ' . $e->getMessage(), 'error' );

			return '';
		}
	}

	/**
	 * Reuses the coupon stored in the session or generates a unique code.
	 *
	 * @param int $bundle_id Bundle ID.
	 * @return string
	 */
	private function get_bundle_coupon_code( $bundle_id ) {
		$data = $this->get_session_data();

		if ( ! empty( $data['coupon_code'] ) ) {
			return $data['coupon_code'];
		}

		$code = sprintf( '%1$s%2$d_%3$d_%4$s', self::COUPON_PREFIX, $bundle_id, time(), wp_generate_password( 6, false ) );

		$data              = is_array( $data ) ? $data : [];
		$data['coupon_code'] = $code;
		$this->set_session_data( $data );

		return $code;
	}

	/**
	 * Re-applies the session coupon when the cart is (re)built without it.
	 *
	 * @return void
	 */
	public function reapply_session_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! $this->ensure_session() ) {
			return;
		}

		if ( $this->applying_session_coupon ) {
			return;
		}

		$data = $this->get_session_data();

		if ( ! $data ) {
			return;
		}

		$bundle_id       = isset( $data['bundle_id'] ) ? absint( $data['bundle_id'] ) : 0;
		$discount_amount = isset( $data['discount_amount'] ) ? (float) $data['discount_amount'] : 0.0;
		$coupon_code     = isset( $data['coupon_code'] ) ? $data['coupon_code'] : '';

		if ( $bundle_id <= 0 || $discount_amount <= 0 || '' === $coupon_code ) {
			return;
		}

		if ( WC()->cart->has_discount( $coupon_code ) ) {
			return;
		}

		if ( ! wc_get_coupon_id_by_code( $coupon_code ) ) {
			return;
		}

		$this->applying_session_coupon = true;

		try {
			if ( WC()->cart->apply_coupon( $coupon_code ) ) {
				$this->remove_bundle_coupon_notices();
				WC()->cart->calculate_totals();
			} else {
				// The coupon cannot apply to this cart anymore (contents
				// changed, another plugin rejected it). Drop the reference
				// instead of retrying on every hook and page load.
				$this->set_session_data( [] );
				$this->remove_bundle_coupon_notices();
			}
		} finally {
			$this->applying_session_coupon = false;
		}
	}

	/**
	 * Drops the coupon once the cart no longer contains bundle items.
	 *
	 * @param string $cart_item_key Removed item key.
	 * @param \WC_Cart $cart        Cart instance.
	 * @return void
	 */
	public function maybe_remove_bundle_coupon( $cart_item_key, $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}

		$data = $this->get_session_data();

		if ( ! $data ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::ITEM_META ] ) ) {
				return;
			}
		}

		$coupon_code = isset( $data['coupon_code'] ) ? $data['coupon_code'] : '';

		if ( $coupon_code && WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->remove_coupon( $coupon_code );
		}

		$this->set_session_data( [] );
	}

	/**
	 * Removes the coupon when the cart is emptied.
	 *
	 * @return void
	 */
	public function remove_bundle_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$data = $this->get_session_data();

		if ( ! $data ) {
			return;
		}

		$coupon_code = isset( $data['coupon_code'] ) ? $data['coupon_code'] : '';

		if ( $coupon_code && WC()->cart && WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->remove_coupon( $coupon_code );
		}

		$this->set_session_data( [] );
	}

	/**
	 * If the session references a bundle coupon but the cart has no bundle
	 * items anymore, remove the coupon and clear the session.
	 *
	 * @return void
	 */
	public function cleanup_invalid_bundle_coupons() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$data        = $this->get_session_data();
		$coupon_code = $data['coupon_code'] ?? '';

		$has_bundle_items = false;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::ITEM_META ] ) ) {
				$has_bundle_items = true;
				break;
			}
		}

		if ( ! $has_bundle_items ) {
			if ( $coupon_code && WC()->cart->has_discount( $coupon_code ) ) {
				WC()->cart->remove_coupon( $coupon_code );
			}

			if ( $data ) {
				$this->set_session_data( [] );
			}
		}

		$this->remove_bundle_coupon_notices();
	}

	// ------------------------------------------------------------------
	// Coupon validation and notice handling
	// ------------------------------------------------------------------

	/**
	 * Forces our dynamic coupons through validation even when cached state
	 * looks stale.
	 *
	 * @param bool     $is_valid Original validity.
	 * @param mixed    $coupon   Coupon object or code.
	 * @param \WC_Discounts $discount Discounts context.
	 * @return bool
	 */
	public function validate_bundle_coupon( $is_valid, $coupon, $discount ) {
		unset( $discount );

		if ( $this->is_bundle_coupon_code( $this->coupon_code_from( $coupon ) ) ) {
			return true;
		}

		return $is_valid;
	}

	/**
	 * Suppresses error output for our own coupons so shoppers never see
	 * validation noise from the dynamic coupon system.
	 *
	 * @param string $error      Error message.
	 * @param int    $error_code Error code.
	 * @param mixed  $coupon     Coupon object or code.
	 * @return string|false
	 */
	public function suppress_bundle_coupon_error( $error, $error_code, $coupon ) {
		unset( $error_code );

		if ( $this->is_bundle_coupon_code( $this->coupon_code_from( $coupon ) ) ) {
			return false;
		}

		return $error;
	}

	/**
	 * Validity check used during cart application.
	 *
	 * @param bool  $is_valid Original validity.
	 * @param mixed $coupon   Coupon object or code.
	 * @return bool
	 */
	public function validate_bundle_coupon_for_cart( $is_valid, $coupon ) {
		if ( $this->is_bundle_coupon_code( $this->coupon_code_from( $coupon ) ) ) {
			return true;
		}

		return $is_valid;
	}

	/**
	 * Lets our dynamic coupons be applied while an "individual use" coupon
	 * is already on the cart, instead of being rejected with a visible
	 * "cannot be used in conjunction with other coupons" error.
	 *
	 * @param bool             $allow             Default rejection bypass.
	 * @param \WC_Coupon       $new_coupon        Coupon being applied.
	 * @param \WC_Coupon|null  $individual_coupon Individual-use coupon on the cart.
	 * @return bool
	 */
	public function allow_with_individual_use_coupon( $allow, $new_coupon, $individual_coupon = null ) {
		unset( $individual_coupon );

		if ( $this->is_bundle_coupon_code( $this->coupon_code_from( $new_coupon ) ) ) {
			return true;
		}

		return $allow;
	}

	/**
	 * Keeps our dynamic coupons on the cart when an "individual use" coupon
	 * is applied afterwards (core removes every other coupon in that case).
	 *
	 * @param string[]      $keep              Coupon codes to preserve.
	 * @param \WC_Coupon    $new_coupon        Individual-use coupon being applied.
	 * @param string[]      $applied_coupons   Coupons currently on the cart.
	 * @return string[]
	 */
	public function keep_bundle_coupons_for_individual_use( $keep, $new_coupon, $applied_coupons = [] ) {
		unset( $new_coupon );

		foreach ( (array) $applied_coupons as $code ) {
			if ( $this->is_bundle_coupon_code( (string) $code ) ) {
				$keep[] = $code;
			}
		}

		return $keep;
	}

	/**
	 * Removes notices referencing our dynamic coupons from the session.
	 *
	 * @return void
	 */
	public function remove_bundle_coupon_notices() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$notices = WC()->session->get( 'wc_notices', [] );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		$updated = false;

		foreach ( [ 'error', 'success', 'notice', 'info' ] as $type ) {
			if ( empty( $notices[ $type ] ) || ! is_array( $notices[ $type ] ) ) {
				continue;
			}

			$kept = [];

			foreach ( $notices[ $type ] as $notice ) {
				$text = $this->notice_text( $notice );

				if ( '' !== $text && false !== strpos( $text, self::COUPON_PREFIX ) ) {
					$updated = true;
					continue;
				}

				$kept[] = $notice;
			}

			$notices[ $type ] = $kept;
		}

		if ( $updated ) {
			WC()->session->set( 'wc_notices', $notices );
		}
	}

	/**
	 * Recalculates totals and refreshes the block mini-cart amount so the
	 * coupon discount is visible in block-based carts.
	 *
	 * @param array $fragments Fragments passed through WooCommerce.
	 * @return array
	 */
	public function update_block_mini_cart_fragments( $fragments ) {
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->calculate_totals();

			$fragments['cart_hash'] = WC()->cart->get_cart_hash();

			ob_start();
			echo wp_kses_post( WC()->cart->get_cart_subtotal() );
			$fragments['.wc-block-mini-cart__amount'] = ob_get_clean();
		}

		return $fragments;
	}

	/**
	 * Re-applies the session coupon after totals are calculated if it
	 * somehow got dropped along the way.
	 *
	 * Runs inside woocommerce_after_calculate_totals, so it must never
	 * blindly recalculate: the guard and the apply_coupon() result keep
	 * the verify → apply → calculate → verify cycle from recursing when
	 * the coupon cannot apply (e.g. after the shopper removed bundle
	 * items and WooCommerce dropped the coupon as invalid).
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function verify_coupon_applied( $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! $cart ) {
			return;
		}

		if ( $this->applying_session_coupon ) {
			return;
		}

		$data = $this->get_session_data();

		if ( ! $data || empty( $data['coupon_code'] ) ) {
			return;
		}

		$coupon_code = $data['coupon_code'];
		$applied     = $cart->get_applied_coupons();

		// WooCommerce stores applied codes lowercased while the session
		// keeps the generated case, so compare through the same formatter.
		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$coupon_code = wc_format_coupon_code( $coupon_code );
		}

		if ( in_array( $coupon_code, $applied, true ) ) {
			return;
		}

		if ( ! wc_get_coupon_id_by_code( $coupon_code ) ) {
			return;
		}

		if ( $cart->has_discount( $coupon_code ) ) {
			return;
		}

		$this->applying_session_coupon = true;

		try {
			if ( $cart->apply_coupon( $coupon_code ) ) {
				$this->remove_bundle_coupon_notices();
				$cart->calculate_totals();
			} else {
				// Give up on this coupon instead of re-trying on every
				// calculate_totals() pass, which never terminates.
				$this->set_session_data( [] );
				$this->remove_bundle_coupon_notices();
			}
		} finally {
			$this->applying_session_coupon = false;
		}
	}

	// ------------------------------------------------------------------
	// Fragments
	// ------------------------------------------------------------------

	/**
	 * Builds WooCommerce cart fragments for classic themes without
	 * terminating the request (WC_AJAX::get_refreshed_fragments() exits).
	 *
	 * @return array
	 */
	public function cart_fragments() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		if ( ! function_exists( 'woocommerce_mini_cart' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
		}

		if ( ! function_exists( 'woocommerce_mini_cart' ) ) {
			return [];
		}

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
			'woocommerce_add_to_cart_fragments',
			[
				'div.widget_shopping_cart_content' => $mini_cart,
			]
		);
	}

	// ------------------------------------------------------------------
	// Cron cleanup
	// ------------------------------------------------------------------

	/**
	 * Deletes unused bundle coupons past their configured lifetime. Used
	 * coupons are kept for order history and analytics.
	 *
	 * @return array{deleted:int, kept:int}
	 */
	public function cleanup_unused_coupons() {
		global $wpdb;

		$cached = wp_cache_get( 'bundixo_coupons_cleanup', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		// Coupon codes live in post_title for shop_coupon posts, so a
		// direct prefix match on the title is both correct and cheap.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$coupon_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = 'shop_coupon'
				AND post_status = 'publish'
				AND post_title LIKE %s",
				self::COUPON_PREFIX . '%'
			)
		);

		$deleted = 0;
		$kept    = 0;

		if ( is_array( $coupon_posts ) ) {
			foreach ( $coupon_posts as $coupon_post ) {
				if ( 0 !== strpos( (string) $coupon_post->post_title, self::COUPON_PREFIX ) ) {
					continue;
				}

				$coupon = new \WC_Coupon( $coupon_post->ID );

				if ( $coupon->get_usage_count() > 0 ) {
					$kept++;
					continue;
				}

				$created = (int) get_post_time( 'U', true, $coupon_post->ID );
				$lifetime = Settings::coupon_lifetime_hours() * HOUR_IN_SECONDS;

				if ( $created && ( current_time( 'timestamp' ) - $created ) < $lifetime ) {
					continue;
				}

				wp_delete_post( $coupon_post->ID, true );
				$deleted++;
			}
		}

		$result = [
			'deleted' => $deleted,
			'kept'    => $kept,
		];

		wp_cache_set( 'bundixo_coupons_cleanup', $result, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $result;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Extracts a coupon code from whatever shape WooCommerce hands over.
	 *
	 * @param mixed $coupon Coupon object, code string, or ID.
	 * @return string
	 */
	private function coupon_code_from( $coupon ) {
		if ( is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ) {
			return (string) $coupon->get_code();
		}

		if ( is_string( $coupon ) ) {
			return $coupon;
		}

		if ( is_numeric( $coupon ) ) {
			try {
				$coupon_obj = new \WC_Coupon( (int) $coupon );

				return (string) $coupon_obj->get_code();
			} catch ( \Exception $e ) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Whether a code belongs to this plugin's dynamic coupon family.
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	private function is_bundle_coupon_code( $code ) {
		return '' !== $code && 0 === strpos( $code, self::COUPON_PREFIX );
	}

	/**
	 * Pulls the display text out of a stored notice.
	 *
	 * @param mixed $notice Stored notice.
	 * @return string
	 */
	private function notice_text( $notice ) {
		if ( is_array( $notice ) ) {
			return (string) ( $notice['notice'] ?? $notice['message'] ?? '' );
		}

		if ( is_string( $notice ) ) {
			return $notice;
		}

		return '';
	}

	/**
	 * Conditional WooCommerce logger.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @return void
	 */
	private function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) || ! Settings::is_logging_enabled() ) {
			return;
		}

		wc_get_logger()->log( $level, $message, [ 'source' => 'bundixo-for-woocommerce' ] );
	}
}
