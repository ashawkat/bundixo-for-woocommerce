<?php
/**
 * Plugin settings.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small option-backed settings store shared by admin UI, REST, and logging.
 */
class Settings {

	/**
	 * Option key.
	 */
	const OPTION_KEY = 'bundixo_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'enable_logging'        => false,
			'default_cart_behavior' => 'sidecart',
			'coupon_lifetime_hours' => 24,
		];
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Persists settings after sanitizing.
	 *
	 * @param array $settings Raw settings.
	 * @return bool
	 */
	public static function update( array $settings ) {
		return update_option( self::OPTION_KEY, self::sanitize( $settings ), false );
	}

	/**
	 * Whether WooCommerce debug logging for this plugin is enabled.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		return (bool) self::get()['enable_logging'];
	}

	/**
	 * Sanitizes settings, keeping only known keys.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function sanitize( array $settings ) {
		$clean = self::defaults();

		$clean['enable_logging'] = isset( $settings['enable_logging'] )
			? (bool) filter_var( $settings['enable_logging'], FILTER_VALIDATE_BOOLEAN )
			: false;

		$clean['default_cart_behavior'] = isset( $settings['default_cart_behavior'] )
			&& in_array( $settings['default_cart_behavior'], [ 'sidecart', 'redirect' ], true )
			? $settings['default_cart_behavior']
			: 'sidecart';

		$lifetime = isset( $settings['coupon_lifetime_hours'] ) ? absint( $settings['coupon_lifetime_hours'] ) : 24;
		$clean['coupon_lifetime_hours'] = in_array( $lifetime, [ 24, 48, 72, 168 ], true ) ? $lifetime : 24;

		/**
		 * Filters sanitized settings before they are saved.
		 *
		 * @param array $clean    Sanitized settings.
		 * @param array $settings Raw settings.
		 */
		return apply_filters( 'bundixo_sanitize_settings', $clean, $settings );
	}

	/**
	 * How long dynamic bundle coupons stay valid, in hours.
	 *
	 * @return int
	 */
	public static function coupon_lifetime_hours() {
		$lifetime = (int) self::get()['coupon_lifetime_hours'];

		return $lifetime > 0 ? $lifetime : 24;
	}
}
