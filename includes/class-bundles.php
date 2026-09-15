<?php
/**
 * Bundle CRUD and pricing-tier logic.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes bundles from the custom table, including the
 * tier/discount business rules shared by admin and storefront code.
 */
class Bundles {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'bundixo';

	/**
	 * Cache key for the full bundle list.
	 */
	const CACHE_ALL = 'bundixo_all_bundles';

	/**
	 * Cache key for enabled bundles.
	 */
	const CACHE_ENABLED = 'bundixo_enabled_bundles';

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 300;

	/**
	 * Default bundle values, shared by the editor UI and save routine.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'bundle_id'              => 0,
			'name'                   => '',
			'description'            => '',
			'enabled'                => 1,
			'use_quantity'           => 0,
			'max_quantity'           => 10,
			'product_ids'            => [],
			'discount_tiers'         => [],
			'heading_text'           => __( 'Select Your Products Below', 'bundixo-for-woocommerce' ),
			'hint_text'              => __( 'Bundle 2, 3, 4 or 5 items and watch the savings grow.', 'bundixo-for-woocommerce' ),
			'primary_color'          => '#6366f1',
			'accent_color'           => '#4f46e5',
			'hover_bg_color'         => '#eef2ff',
			'hover_accent_color'     => '#4338ca',
			'button_text_color'      => '#ffffff',
			'button_text'            => __( 'Add Bundle to Cart', 'bundixo-for-woocommerce' ),
			'progress_text'          => __( 'Your Savings Progress', 'bundixo-for-woocommerce' ),
			'cart_behavior'          => 'sidecart',
			'show_bundle_title'      => 1,
			'show_bundle_description' => 1,
			'show_heading_text'      => 1,
			'show_hint_text'         => 1,
			'show_progress_text'     => 1,
		];
	}

	/**
	 * Insert or update a bundle. Input is an associative array with the
	 * bundle fields; values are sanitized defensively even though the REST
	 * layer already validated them.
	 *
	 * @param array $data Raw bundle data.
	 * @return int|\WP_Error Bundle ID on success.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$bundle_id = isset( $data['bundle_id'] ) ? absint( $data['bundle_id'] ) : 0;
		$name      = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'bundixo_invalid_name', __( 'Bundle name is required.', 'bundixo-for-woocommerce' ) );
		}

		$product_ids = self::sanitize_product_ids( $data['product_ids'] ?? [] );
		$tiers       = self::sanitize_tiers( $data['discount_tiers'] ?? [] );

		if ( empty( $tiers ) ) {
			return new \WP_Error( 'bundixo_invalid_tiers', __( 'At least one discount tier is required.', 'bundixo-for-woocommerce' ) );
		}

		$defaults = self::defaults();

		$record = [
			'name'                    => $name,
			'description'             => sanitize_textarea_field( $data['description'] ?? $defaults['description'] ),
			'enabled'                 => self::to_bool_int( $data['enabled'] ?? 1 ),
			'use_quantity'            => self::to_bool_int( $data['use_quantity'] ?? 0 ),
			'max_quantity'            => max( 1, absint( $data['max_quantity'] ?? 10 ) ),
			'product_ids'             => (string) wp_json_encode( array_values( $product_ids ) ),
			'discount_tiers'          => (string) wp_json_encode( $tiers ),
			'heading_text'            => sanitize_text_field( $data['heading_text'] ?? $defaults['heading_text'] ),
			'hint_text'               => sanitize_text_field( $data['hint_text'] ?? $defaults['hint_text'] ),
			'primary_color'           => self::sanitize_color( $data['primary_color'] ?? '', $defaults['primary_color'] ),
			'accent_color'            => self::sanitize_color( $data['accent_color'] ?? '', $defaults['accent_color'] ),
			'hover_bg_color'          => self::sanitize_color( $data['hover_bg_color'] ?? '', $defaults['hover_bg_color'] ),
			'hover_accent_color'      => self::sanitize_color( $data['hover_accent_color'] ?? '', $defaults['hover_accent_color'] ),
			'button_text_color'       => self::sanitize_color( $data['button_text_color'] ?? '', $defaults['button_text_color'] ),
			'button_text'             => sanitize_text_field( $data['button_text'] ?? $defaults['button_text'] ),
			'progress_text'           => sanitize_text_field( $data['progress_text'] ?? $defaults['progress_text'] ),
			'cart_behavior'           => self::sanitize_cart_behavior( $data['cart_behavior'] ?? '' ),
			'show_bundle_title'       => self::to_bool_int( $data['show_bundle_title'] ?? 1 ),
			'show_bundle_description' => self::to_bool_int( $data['show_bundle_description'] ?? 1 ),
			'show_heading_text'       => self::to_bool_int( $data['show_heading_text'] ?? 1 ),
			'show_hint_text'          => self::to_bool_int( $data['show_hint_text'] ?? 1 ),
			'show_progress_text'      => self::to_bool_int( $data['show_progress_text'] ?? 1 ),
		];

		$formats   = self::record_formats();
		$table     = Install::table_name();
		$old_error = $wpdb->last_error;

		if ( $bundle_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $record, [ 'id' => $bundle_id ], $formats, [ '%d' ] );

			if ( false === $result ) {
				return new \WP_Error( 'bundixo_db_error', self::db_error_message( $wpdb->last_error ? $wpdb->last_error : $old_error ) );
			}

			self::flush_bundle_cache( $bundle_id );

			return $bundle_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $record, $formats );

		if ( false === $result || $wpdb->insert_id <= 0 ) {
			return new \WP_Error( 'bundixo_db_error', self::db_error_message( $wpdb->last_error ? $wpdb->last_error : $old_error ) );
		}

		$bundle_id = (int) $wpdb->insert_id;
		self::flush_bundle_cache( $bundle_id );

		return $bundle_id;
	}

	/**
	 * Returns all bundles, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;

		$cached = wp_cache_get( self::CACHE_ALL, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$table = Install::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized custom table identifier.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE 1 = %d ORDER BY id DESC", 1 ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bundles = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$bundles[] = self::format_row( $row );
			}
		}

		wp_cache_set( self::CACHE_ALL, $bundles, self::CACHE_GROUP, self::CACHE_TTL );

		return $bundles;
	}

	/**
	 * Returns a single bundle by ID, or null.
	 *
	 * @param int $bundle_id Bundle ID.
	 * @return array|null
	 */
	public static function get( $bundle_id ) {
		global $wpdb;

		$bundle_id = absint( $bundle_id );

		if ( ! $bundle_id ) {
			return null;
		}

		$cache_key = 'bundixo_bundle_' . $bundle_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$table = Install::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized custom table identifier.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $bundle_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		$bundle = self::format_row( $row );
		wp_cache_set( $cache_key, $bundle, self::CACHE_GROUP, self::CACHE_TTL );

		return $bundle;
	}

	/**
	 * Returns all enabled bundles.
	 *
	 * @return array[]
	 */
	public static function enabled() {
		global $wpdb;

		$cached = wp_cache_get( self::CACHE_ENABLED, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$table = Install::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized custom table identifier.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = %d ORDER BY id DESC", 1 ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bundles = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$bundles[] = self::format_row( $row );
			}
		}

		wp_cache_set( self::CACHE_ENABLED, $bundles, self::CACHE_GROUP, self::CACHE_TTL );

		return $bundles;
	}

	/**
	 * Deletes a bundle.
	 *
	 * @param int $bundle_id Bundle ID.
	 * @return bool
	 */
	public static function delete( $bundle_id ) {
		global $wpdb;

		$bundle_id = absint( $bundle_id );

		if ( ! $bundle_id ) {
			return false;
		}

		$table = Install::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, [ 'id' => $bundle_id ], [ '%d' ] );

		if ( false !== $deleted ) {
			self::flush_bundle_cache( $bundle_id );
		}

		return false !== $deleted;
	}

	/**
	 * Highest discount tier whose quantity threshold is met.
	 *
	 * @param array $bundle     Formatted bundle.
	 * @param int   $item_count Total units selected.
	 * @return array{quantity:int, discount:float}
	 */
	public static function applicable_tier( array $bundle, $item_count ) {
		$tiers = $bundle['discount_tiers'] ?? [];

		if ( empty( $tiers ) ) {
			return [ 'quantity' => 1, 'discount' => 0 ];
		}

		usort(
			$tiers,
			static function ( $a, $b ) {
				return $b['quantity'] - $a['quantity'];
			}
		);

		foreach ( $tiers as $tier ) {
			if ( $item_count >= (int) $tier['quantity'] ) {
				return $tier;
			}
		}

		return [ 'quantity' => 1, 'discount' => 0 ];
	}

	/**
	 * Drops every cached bundle lookup.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		wp_cache_delete( self::CACHE_ALL, self::CACHE_GROUP );
		wp_cache_delete( self::CACHE_ENABLED, self::CACHE_GROUP );
	}

	/**
	 * Drops cache entries affected by a single bundle write.
	 *
	 * @param int $bundle_id Bundle ID.
	 * @return void
	 */
	private static function flush_bundle_cache( $bundle_id ) {
		wp_cache_delete( 'bundixo_bundle_' . absint( $bundle_id ), self::CACHE_GROUP );
		self::flush_cache();
	}

	/**
	 * Converts a database row into the canonical bundle array.
	 *
	 * @param object $row Raw database row.
	 * @return array
	 */
	private static function format_row( $row ) {
		$defaults = self::defaults();

		return [
			'id'                      => (int) $row->id,
			'name'                    => (string) $row->name,
			'description'             => (string) $row->description,
			'enabled'                 => (int) $row->enabled,
			'use_quantity'            => (int) ( $row->use_quantity ?? $defaults['use_quantity'] ),
			'max_quantity'            => (int) ( $row->max_quantity ?? $defaults['max_quantity'] ),
			'product_ids'             => self::sanitize_product_ids( json_decode( (string) $row->product_ids, true ) ?: [] ),
			'discount_tiers'          => self::sanitize_tiers( json_decode( (string) $row->discount_tiers, true ) ?: [] ),
			'heading_text'            => (string) ( $row->heading_text ?? $defaults['heading_text'] ),
			'hint_text'               => (string) ( $row->hint_text ?? $defaults['hint_text'] ),
			'primary_color'           => (string) ( $row->primary_color ?? $defaults['primary_color'] ),
			'accent_color'            => (string) ( $row->accent_color ?? $defaults['accent_color'] ),
			'hover_bg_color'          => (string) ( $row->hover_bg_color ?? $defaults['hover_bg_color'] ),
			'hover_accent_color'      => (string) ( $row->hover_accent_color ?? $defaults['hover_accent_color'] ),
			'button_text_color'       => (string) ( $row->button_text_color ?? $defaults['button_text_color'] ),
			'button_text'             => (string) ( $row->button_text ?? $defaults['button_text'] ),
			'progress_text'           => (string) ( $row->progress_text ?? $defaults['progress_text'] ),
			'cart_behavior'           => (string) ( $row->cart_behavior ?? $defaults['cart_behavior'] ),
			'show_bundle_title'       => (int) ( $row->show_bundle_title ?? 1 ),
			'show_bundle_description' => (int) ( $row->show_bundle_description ?? 1 ),
			'show_heading_text'       => (int) ( $row->show_heading_text ?? 1 ),
			'show_hint_text'          => (int) ( $row->show_hint_text ?? 1 ),
			'show_progress_text'      => (int) ( $row->show_progress_text ?? 1 ),
			'created_at'              => (string) $row->created_at,
			'updated_at'              => (string) $row->updated_at,
		];
	}

	/**
	 * Normalizes a list of product IDs to unique positive integers.
	 *
	 * @param mixed $raw Array, JSON string, or comma-separated string.
	 * @return int[]
	 */
	public static function sanitize_product_ids( $raw ) {
		$ids = [];

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
			$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		}

		if ( is_array( $raw ) ) {
			foreach ( $raw as $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = is_object( $value ) ? $value->product_id ?? 0 : ( $value['product_id'] ?? 0 );
				}
				$id = absint( $value );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalizes discount tiers to [{quantity, discount}] sorted by quantity.
	 *
	 * @param mixed $raw Array or JSON string of tiers.
	 * @return array[]
	 */
	public static function sanitize_tiers( $raw ) {
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( wp_unslash( $raw ), true );
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$tiers = [];

		foreach ( $raw as $tier ) {
			if ( is_object( $tier ) ) {
				$tier = (array) $tier;
			}

			if ( ! is_array( $tier ) || ! isset( $tier['quantity'], $tier['discount'] ) ) {
				continue;
			}

			$quantity = absint( $tier['quantity'] );
			$discount = min( 100.0, max( 0.0, (float) $tier['discount'] ) );

			if ( $quantity > 0 ) {
				$tiers[] = [
					'quantity' => $quantity,
					'discount' => round( $discount, 2 ),
				];
			}
		}

		usort(
			$tiers,
			static function ( $a, $b ) {
				return $a['quantity'] - $b['quantity'];
			}
		);

		return $tiers;
	}

	/**
	 * Validates hex colors, falling back to a default when invalid.
	 *
	 * @param string $color    Raw color value.
	 * @param string $fallback Default color.
	 * @return string
	 */
	private static function sanitize_color( $color, $fallback ) {
		$hex = sanitize_hex_color( $color );

		return $hex ? $hex : $fallback;
	}

	/**
	 * Only "sidecart" or "redirect" are valid cart behaviors.
	 *
	 * @param string $behavior Raw value.
	 * @return string
	 */
	private static function sanitize_cart_behavior( $behavior ) {
		return in_array( $behavior, [ 'sidecart', 'redirect' ], true ) ? $behavior : 'sidecart';
	}

	/**
	 * Casts truthy input to a 0/1 integer for tinyint columns.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function to_bool_int( $value ) {
		return (int) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * wpdb insert/update formats for the bundle record columns, in order.
	 *
	 * @return string[]
	 */
	private static function record_formats() {
		return [
			'%s', // name.
			'%s', // description.
			'%d', // enabled.
			'%d', // use_quantity.
			'%d', // max_quantity.
			'%s', // product_ids.
			'%s', // discount_tiers.
			'%s', // heading_text.
			'%s', // hint_text.
			'%s', // primary_color.
			'%s', // accent_color.
			'%s', // hover_bg_color.
			'%s', // hover_accent_color.
			'%s', // button_text_color.
			'%s', // button_text.
			'%s', // progress_text.
			'%s', // cart_behavior.
			'%d', // show_bundle_title.
			'%d', // show_bundle_description.
			'%d', // show_heading_text.
			'%d', // show_hint_text.
			'%d', // show_progress_text.
		];
	}

	/**
	 * Maps a database failure to a user-facing message.
	 *
	 * @param string $last_error Raw database error.
	 * @return string
	 */
	private static function db_error_message( $last_error ) {
		return $last_error
			? sprintf(
				/* translators: %s: database error message */
				__( 'Failed to save bundle: %s', 'bundixo-for-woocommerce' ),
				$last_error
			)
			: __( 'Failed to save bundle.', 'bundixo-for-woocommerce' );
	}
}
