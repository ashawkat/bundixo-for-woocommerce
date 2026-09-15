<?php
/**
 * Analytics queries powering the admin dashboard.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates coupon, bundle, revenue, cart, checkout, and performance
 * metrics for a date range. All methods return JSON-ready arrays.
 */
class Analytics {

	/**
	 * Full analytics dataset for the dashboard.
	 *
	 * @param string $date_range Preset range key.
	 * @param string $start_date Optional custom start (Y-m-d).
	 * @param string $end_date   Optional custom end (Y-m-d).
	 * @return array
	 */
	public static function get_data( $date_range = '30days', $start_date = '', $end_date = '' ) {
		$now = current_time( 'timestamp' );

		if ( $start_date && $end_date ) {
			$start_timestamp = strtotime( $start_date . ' 00:00:00' );
			$end_timestamp   = strtotime( $end_date . ' 23:59:59' );
		} else {
			switch ( $date_range ) {
				case '7days':
					$end_timestamp   = strtotime( 'today 23:59:59', $now );
					$start_timestamp = strtotime( '-7 days', $end_timestamp );
					break;
				case '90days':
					$end_timestamp   = strtotime( 'today 23:59:59', $now );
					$start_timestamp = strtotime( '-90 days', $end_timestamp );
					break;
				case 'this_month':
					$start_timestamp = strtotime( 'first day of this month 00:00:00', $now );
					$end_timestamp   = strtotime( 'last day of this month 23:59:59', $now );
					break;
				case 'last_month':
					$start_timestamp = strtotime( 'first day of last month 00:00:00', $now );
					$end_timestamp   = strtotime( 'last day of last month 23:59:59', $now );
					break;
				case 'this_quarter':
					$month  = (int) gmdate( 'n', $now );
					$year   = (int) gmdate( 'Y', $now );
					$quarter_month = (int) ( floor( ( $month - 1 ) / 3 ) * 3 + 1 );
					$start_timestamp = strtotime( sprintf( '%d-%02d-01 00:00:00', $year, $quarter_month ) );
					$end_timestamp   = strtotime( 'today 23:59:59', $now );
					break;
				case 'this_year':
					$start_timestamp = strtotime( gmdate( 'Y-01-01 00:00:00', $now ) );
					$end_timestamp   = strtotime( 'today 23:59:59', $now );
					break;
				case '30days':
				default:
					$end_timestamp   = strtotime( 'today 23:59:59', $now );
					$start_timestamp = strtotime( '-30 days', $end_timestamp );
					break;
			}
		}

		$start_sql = gmdate( 'Y-m-d H:i:s', $start_timestamp );
		$end_sql   = gmdate( 'Y-m-d H:i:s', $end_timestamp );

		return [
			'coupon_analytics'     => self::coupon_analytics( $start_sql, $end_sql ),
			'bundle_analytics'     => self::bundle_analytics(),
			'purchase_analytics'   => self::purchase_analytics( $start_sql, $end_sql ),
			'cart_analytics'       => self::cart_analytics( $start_sql, $end_sql ),
			'checkout_analytics'   => self::checkout_analytics( $start_sql, $end_sql ),
			'bundle_performance'   => self::bundle_performance( $start_sql, $end_sql ),
			'date_range'           => [
				'start' => $start_date ? $start_date : gmdate( 'Y-m-d', $start_timestamp ),
				'end'   => $end_date ? $end_date : gmdate( 'Y-m-d', $end_timestamp ),
				'label' => self::date_range_label( $date_range, $start_timestamp, $end_timestamp ),
			],
		];
	}

	/**
	 * Human-readable label for a range key.
	 *
	 * @param string $date_range Range key.
	 * @param int    $start_ts   Resolved start timestamp.
	 * @param int    $end_ts     Resolved end timestamp.
	 * @return string
	 */
	private static function date_range_label( $date_range, $start_ts, $end_ts ) {
		switch ( $date_range ) {
			case '7days':
				return __( 'Last 7 Days', 'bundixo-for-woocommerce' );
			case '30days':
				return __( 'Last 30 Days', 'bundixo-for-woocommerce' );
			case '90days':
				return __( 'Last 90 Days', 'bundixo-for-woocommerce' );
			case 'this_month':
				return __( 'This Month', 'bundixo-for-woocommerce' );
			case 'last_month':
				return __( 'Last Month', 'bundixo-for-woocommerce' );
			case 'this_quarter':
				return __( 'This Quarter', 'bundixo-for-woocommerce' );
			case 'this_year':
				return __( 'This Year', 'bundixo-for-woocommerce' );
			case 'custom':
				return sprintf(
					/* translators: 1: start date, 2: end date */
					__( 'Custom: %1$s to %2$s', 'bundixo-for-woocommerce' ),
					gmdate( 'M j, Y', $start_ts ),
					gmdate( 'M j, Y', $end_ts )
				);
			default:
				return __( 'Custom Range', 'bundixo-for-woocommerce' );
		}
	}

	/**
	 * Coupon creation and usage totals for the range.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return array
	 */
	private static function coupon_analytics( $start_sql, $end_sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$coupons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title AS coupon_code, p.post_date,
					pm_amount.meta_value AS discount_amount,
					pm_usage.meta_value AS usage_count
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = 'coupon_amount'
				LEFT JOIN {$wpdb->postmeta} pm_usage ON p.ID = pm_usage.post_id AND pm_usage.meta_key = 'usage_count'
				WHERE p.post_type = 'shop_coupon'
					AND p.post_status = 'publish'
					AND p.post_title LIKE %s
					AND p.post_date BETWEEN %s AND %s
				ORDER BY p.post_date DESC",
				Cart::COUPON_PREFIX . '%',
				$start_sql,
				$end_sql
			)
		);

		$coupons = is_array( $coupons ) ? $coupons : [];

		$total_created  = count( $coupons );
		$total_used     = 0;
		$total_unused   = 0;
		$total_discount = 0.0;

		$recent = [];

		foreach ( $coupons as $coupon ) {
			$usage_count    = isset( $coupon->usage_count ) ? (int) $coupon->usage_count : 0;
			$discount_value = isset( $coupon->discount_amount ) ? (float) $coupon->discount_amount : 0.0;

			if ( $usage_count > 0 ) {
				$total_used++;
				$total_discount += $discount_value * $usage_count;
			} else {
				$total_unused++;
			}

			if ( count( $recent ) < 10 ) {
				$recent[] = [
					'coupon_code'    => (string) $coupon->coupon_code,
					'discount'       => $discount_value,
					'usage_count'    => $usage_count,
					'created_at'     => (string) $coupon->post_date,
					'status'         => $usage_count > 0 ? 'used' : 'unused',
				];
			}
		}

		return [
			'total_created'  => $total_created,
			'total_used'     => $total_used,
			'total_unused'   => $total_unused,
			'total_discount' => $total_discount,
			'usage_rate'     => $total_created > 0 ? round( ( $total_used / $total_created ) * 100, 2 ) : 0,
			'recent_coupons' => $recent,
		];
	}

	/**
	 * Bundle inventory totals (not range-limited).
	 *
	 * @return array
	 */
	private static function bundle_analytics() {
		$bundles = Bundles::all();

		$enabled = 0;

		foreach ( $bundles as $bundle ) {
			if ( ! empty( $bundle['enabled'] ) ) {
				$enabled++;
			}
		}

		return [
			'total_bundles'   => count( $bundles ),
			'enabled_bundles' => $enabled,
		];
	}

	/**
	 * Orders containing a bundle coupon, with revenue totals.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return array
	 */
	private static function purchase_analytics( $start_sql, $end_sql ) {
		$order_ids = self::order_ids_in_range( $start_sql, $end_sql );

		$orders         = [];
		$total_revenue  = 0.0;
		$total_discount = 0.0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$has_bundle_coupon = false;

			foreach ( $order->get_coupon_codes() as $coupon_code ) {
				if ( 0 === strpos( $coupon_code, Cart::COUPON_PREFIX ) ) {
					$has_bundle_coupon = true;
					break;
				}
			}

			if ( ! $has_bundle_coupon ) {
				continue;
			}

			$order_total = (float) $order->get_total();

			$total_revenue  += $order_total;
			$total_discount += (float) $order->get_discount_total();

			$orders[] = [
				'order_id'     => $order->get_id(),
				'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'timestamp'    => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
				'status'       => $order->get_status(),
				'order_total'  => $order_total,
				'cart_discount' => (float) $order->get_discount_total(),
			];
		}

		return [
			'total_orders'   => count( $orders ),
			'total_revenue'  => $total_revenue,
			'total_discount' => $total_discount,
			'series'         => self::revenue_series( $orders, $start_sql, $end_sql ),
			'orders'         => array_slice( $orders, 0, 20 ),
		];
	}

	/**
	 * Buckets bundle order revenue over time for the trend chart. Weekly
	 * buckets up to ~4 months, monthly beyond that.
	 *
	 * @param array  $orders    Full list of bundle orders in the range.
	 * @param string $start_sql Range start.
	 * @param string $end_sql   Range end.
	 * @return array{labels:string[], revenue:float[], orders:int[]}
	 */
	private static function revenue_series( array $orders, $start_sql, $end_sql ) {
		$start_ts = strtotime( $start_sql );
		$end_ts   = strtotime( $end_sql );

		if ( ! $start_ts || ! $end_ts ) {
			return [ 'labels' => [], 'revenue' => [], 'orders' => [] ];
		}

		$monthly = ( $end_ts - $start_ts ) > 120 * DAY_IN_SECONDS;

		$buckets = [];

		if ( $monthly ) {
			$cursor = strtotime( gmdate( 'Y-m-01 00:00:00', $start_ts ) );

			while ( $cursor <= $end_ts ) {
				$key            = gmdate( 'Y-m', $cursor );
				$buckets[ $key ] = [
					'label'   => gmdate( 'M Y', $cursor ),
					'revenue' => 0.0,
					'orders'  => 0,
				];
				$cursor = strtotime( '+1 month', $cursor );
			}
		} else {
			$cursor = strtotime( 'monday this week 00:00:00', $start_ts );

			while ( $cursor <= $end_ts ) {
				$key            = gmdate( 'Y-m-d', $cursor );
				$buckets[ $key ] = [
					'label'   => gmdate( 'M j', $cursor ),
					'revenue' => 0.0,
					'orders'  => 0,
				];
				$cursor = strtotime( '+7 days', $cursor );
			}
		}

		foreach ( $orders as $order ) {
			$ts = (int) ( $order['timestamp'] ?? 0 );

			if ( ! $ts ) {
				continue;
			}

			$key = $monthly ? gmdate( 'Y-m', $ts ) : gmdate( 'Y-m-d', strtotime( 'monday this week 00:00:00', $ts ) );

			if ( isset( $buckets[ $key ] ) ) {
				$buckets[ $key ]['revenue'] += (float) ( $order['order_total'] ?? 0 );
				$buckets[ $key ]['orders']  += 1;
			}
		}

		return [
			'labels'  => wp_list_pluck( array_values( $buckets ), 'label' ),
			'revenue' => wp_list_pluck( array_values( $buckets ), 'revenue' ),
			'orders'  => wp_list_pluck( array_values( $buckets ), 'orders' ),
		];
	}

	/**
	 * Cart activity proxies: order-based totals plus live sessions.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return array
	 */
	private static function cart_analytics( $start_sql, $end_sql ) {
		global $wpdb;

		$order_ids     = self::order_ids_in_range( $start_sql, $end_sql );
		$total_orders  = count( $order_ids );
		$bundle_orders = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_coupon_codes() as $coupon_code ) {
				if ( 0 === strpos( $coupon_code, Cart::COUPON_PREFIX ) ) {
					$bundle_orders++;
					break;
				}
			}
		}

		// Live carts from active sessions.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_sessions = $wpdb->get_results(
			"SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions
			WHERE session_expiry > UNIX_TIMESTAMP()
			LIMIT 200"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$active_carts        = 0;
		$active_bundle_carts = 0;

		if ( is_array( $active_sessions ) ) {
			foreach ( $active_sessions as $session ) {
				if ( empty( $session->session_value ) ) {
					continue;
				}

				$session_value = maybe_unserialize( $session->session_value );

				if ( ! is_array( $session_value ) || empty( $session_value['cart'] ) ) {
					continue;
				}

				$cart_contents = maybe_unserialize( $session_value['cart'] );

				if ( empty( $cart_contents ) || ! is_array( $cart_contents ) ) {
					continue;
				}

				$active_carts++;

				foreach ( $cart_contents as $cart_item ) {
					if ( is_array( $cart_item ) && isset( $cart_item[ Cart::BUNDLE_META ] ) ) {
						$active_bundle_carts++;
						break;
					}
				}
			}
		}

		return [
			'total_orders'       => $total_orders,
			'orders_with_bundle' => $bundle_orders,
			'active_carts'       => $active_carts,
			'active_bundle_carts' => $active_bundle_carts,
		];
	}

	/**
	 * Checkout outcomes and customer mix for bundle orders.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return array
	 */
	private static function checkout_analytics( $start_sql, $end_sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.ID, o.post_date, o.post_status,
					pm2.meta_value AS order_total,
					pm3.meta_value AS discount_amount,
					pm4.meta_value AS customer_email
				FROM {$wpdb->posts} o
				INNER JOIN {$wpdb->postmeta} pm ON o.ID = pm.post_id AND pm.meta_key = '_used_coupons' AND pm.meta_value LIKE %s
				LEFT JOIN {$wpdb->postmeta} pm2 ON o.ID = pm2.post_id AND pm2.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} pm3 ON o.ID = pm3.post_id AND pm3.meta_key = '_cart_discount'
				LEFT JOIN {$wpdb->postmeta} pm4 ON o.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
				WHERE o.post_type = 'shop_order'
					AND o.post_status IN ( 'wc-completed', 'wc-processing' )
					AND o.post_date BETWEEN %s AND %s
				ORDER BY o.post_date DESC",
				'%' . $wpdb->esc_like( Cart::COUPON_PREFIX ) . '%',
				$start_sql,
				$end_sql
			)
		);

		$orders = is_array( $orders ) ? $orders : [];

		$completed = 0;
		$revenue   = 0.0;
		$discount  = 0.0;
		$new_customers = 0;
		$returning_customers = 0;

		foreach ( $orders as $order ) {
			$order_total = isset( $order->order_total ) ? (float) $order->order_total : 0.0;

			if ( 'wc-completed' === $order->post_status ) {
				$completed++;
				$revenue  += $order_total;
				$discount += isset( $order->discount_amount ) ? (float) $order->discount_amount : 0.0;
			}

			if ( ! empty( $order->customer_email ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$customer_orders = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} o
						INNER JOIN {$wpdb->postmeta} pm ON o.ID = pm.post_id AND pm.meta_key = '_billing_email' AND pm.meta_value = %s
						WHERE o.post_type = 'shop_order'
						AND o.post_status IN ( 'wc-completed', 'wc-processing' )",
						$order->customer_email
					)
				);

				if ( (int) $customer_orders <= 1 ) {
					$new_customers++;
				} else {
					$returning_customers++;
				}
			}
		}

		return [
			'total_orders'        => count( $orders ),
			'completed_orders'    => $completed,
			'total_revenue'       => $revenue,
			'total_discount'      => $discount,
			'new_customers'       => $new_customers,
			'returning_customers' => $returning_customers,
		];
	}

	/**
	 * Per-bundle usage ranking over the selected range.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return array
	 */
	private static function bundle_performance( $start_sql, $end_sql ) {
		global $wpdb;

		$bundles = Bundles::all();
		$usage   = [];
		$total_usage = 0;

		foreach ( $bundles as $bundle ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$usage_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT o.ID)
					FROM {$wpdb->posts} o
					INNER JOIN {$wpdb->postmeta} pm ON o.ID = pm.post_id AND pm.meta_key = '_used_coupons' AND pm.meta_value LIKE %s
					WHERE o.post_type IN ( 'shop_order', 'shop_order_placehold' )
					AND o.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'draft' )
					AND o.post_date BETWEEN %s AND %s",
					'%' . $wpdb->esc_like( Cart::COUPON_PREFIX . $bundle['id'] . '_' ) . '%',
					$start_sql,
					$end_sql
				)
			);

			$total_usage += $usage_count;

			$usage[] = [
				'id'          => $bundle['id'],
				'name'        => '' !== $bundle['name'] ? $bundle['name'] : __( 'Unnamed Bundle', 'bundixo-for-woocommerce' ),
				'usage_count' => $usage_count,
				'created_at'  => $bundle['created_at'],
			];
		}

		usort(
			$usage,
			static function ( $a, $b ) {
				return $b['usage_count'] - $a['usage_count'];
			}
		);

		$total_bundles = count( $bundles );
		$used_bundles  = count( array_filter( $usage, static function ( $row ) { return $row['usage_count'] > 0; } ) );

		return [
			'total_bundles'    => $total_bundles,
			'used_bundles'     => $used_bundles,
			'unused_bundles'   => $total_bundles - $used_bundles,
			'total_usage'      => $total_usage,
			'average_usage'    => $total_bundles > 0 ? round( $total_usage / $total_bundles, 2 ) : 0,
			'popular_bundles'  => array_slice( $usage, 0, 10 ),
		];
	}

	/**
	 * IDs of shop orders (including HPOS placeholders) in the range.
	 *
	 * @param string $start_sql Start datetime.
	 * @param string $end_sql   End datetime.
	 * @return int[]
	 */
	private static function order_ids_in_range( $start_sql, $end_sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ( 'shop_order', 'shop_order_placehold' )
				AND post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'draft' )
				AND ( post_date BETWEEN %s AND %s OR post_date_gmt BETWEEN %s AND %s )",
				$start_sql,
				$end_sql,
				$start_sql,
				$end_sql
			)
		);

		return array_map( 'absint', is_array( $ids ) ? $ids : [] );
	}
}
