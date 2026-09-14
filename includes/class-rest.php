<?php
/**
 * REST API routes.
 *
 * Replaces the legacy admin-ajax endpoints. Every route declares its
 * arguments with sanitization/validation callbacks, and admin routes are
 * gated by a permission callback so authorization cannot be bypassed.
 *
 * @package PickPack
 */

namespace PickPack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the pickpack/v1 REST namespace.
 */
class Rest {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'pickpack/v1';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers all routes.
	 *
	 * @return void
	 */
	public function register_routes() {

		// ---- Admin: bundles CRUD ------------------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/bundles',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_bundles' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_bundle' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => $this->bundle_args(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bundles/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_bundle' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_bundle' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// ---- Lightweight list for the Gutenberg block picker ------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/bundles-list',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_bundles_list' ],
					'permission_callback' => [ $this, 'can_edit_posts' ],
				],
			]
		);

		// ---- Admin: product search ----------------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/products',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'search_products' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'search' => [
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'ids'    => [
							'type'              => 'array',
							'required'          => false,
							'default'           => [],
							'items'             => [ 'type' => 'integer' ],
							'sanitize_callback' => [ $this, 'sanitize_int_list' ],
						],
					],
				],
			]
		);

		// ---- Admin: analytics ---------------------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/analytics',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_analytics' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'date_range' => [
							'type'              => 'string',
							'required'          => false,
							'default'           => '30days',
							'enum'              => [ '7days', '30days', '90days', 'this_month', 'last_month', 'this_quarter', 'this_year', 'custom' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'start_date' => [
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'pattern'           => '^\d{4}-\d{2}-\d{2}$',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'end_date'   => [
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'pattern'           => '^\d{4}-\d{2}-\d{2}$',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// ---- Admin: settings ----------------------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'enable_logging'        => [
							'type'              => 'boolean',
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'default_cart_behavior' => [
							'type'              => 'string',
							'required'          => false,
							'default'           => 'sidecart',
							'enum'              => [ 'sidecart', 'redirect' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'coupon_lifetime_hours' => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => 24,
							'enum'              => [ 24, 48, 72, 168 ],
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// ---- Admin: diagnostics -------------------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/diagnostics',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_diagnostics' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		// ---- Storefront: bundle widget payload ----------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/bundles/(?P<id>\d+)/view',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_bundle_view' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// ---- Storefront: server-side price quote --------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/quote',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'get_quote' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'bundle_id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
						'items'     => $this->items_arg(),
					],
				],
			]
		);

		// ---- Storefront: add bundle to cart -------------------------.

		register_rest_route(
			self::NAMESPACE_V1,
			'/add-to-cart',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_to_cart' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'bundle_id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
						'items'     => $this->items_arg(),
					],
				],
			]
		);
	}

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Admin routes require manage_options. Authorization lives here only,
	 * so there is no mixed/bypassable check logic downstream.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The block picker only needs to list bundle names, so any user who
	 * can edit content may read it.
	 *
	 * @return bool
	 */
	public function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Minimal id/name list used by the Gutenberg block picker.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_bundles_list() {
		$list = array_map(
			static function ( $bundle ) {
				return [
					'id'      => $bundle['id'],
					'name'    => $bundle['name'],
					'enabled' => (bool) $bundle['enabled'],
				];
			},
			Bundles::all()
		);

		return rest_ensure_response( $list );
	}

	// ------------------------------------------------------------------
	// Admin: bundles
	// ------------------------------------------------------------------

	/**
	 * Lists all bundles.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_bundles() {
		return rest_ensure_response( Bundles::all() );
	}

	/**
	 * Creates or updates a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_bundle( $request ) {
		$data = $this->extract_bundle_data( $request );

		$result = Bundles::save( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'bundle_id' => $result,
				'bundle'    => Bundles::get( $result ),
			]
		);
	}

	/**
	 * Fetches one bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_bundle( $request ) {
		$bundle = Bundles::get( absint( $request['id'] ) );

		if ( ! $bundle ) {
			return new \WP_Error( 'pickpack_not_found', __( 'Bundle not found.', 'pickpack-for-woocommerce' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $bundle );
	}

	/**
	 * Deletes a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_bundle( $request ) {
		$bundle_id = absint( $request['id'] );

		if ( ! Bundles::delete( $bundle_id ) ) {
			return new \WP_Error( 'pickpack_delete_failed', __( 'Failed to delete bundle.', 'pickpack-for-woocommerce' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	// ------------------------------------------------------------------
	// Admin: products
	// ------------------------------------------------------------------

	/**
	 * Searches products by term or fetches them by ID list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search_products( $request ) {
		$search = $request['search'];
		$ids    = $request['ids'];

		if ( empty( $search ) && empty( $ids ) ) {
			return rest_ensure_response( [] );
		}

		$query_args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( ! empty( $ids ) ) {
			$query_args['post__in']       = array_slice( array_map( 'absint', $ids ), 0, 100 );
			$query_args['orderby']        = 'post__in';
			$query_args['posts_per_page'] = count( $query_args['post__in'] );
		} else {
			$query_args['s'] = $search;
		}

		$products = get_posts( $query_args );
		$formatted = [];

		foreach ( $products as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$formatted[] = Frontend::format_admin_product( $product );
		}

		return rest_ensure_response( $formatted );
	}

	// ------------------------------------------------------------------
	// Admin: analytics, settings, diagnostics
	// ------------------------------------------------------------------

	/**
	 * Returns the analytics dataset for the requested date range.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_analytics( $request ) {
		$data = Analytics::get_data(
			$request['date_range'],
			$request['start_date'],
			$request['end_date']
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Returns current settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( Settings::get() );
	}

	/**
	 * Saves settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( $request ) {
		Settings::update(
			[
				'enable_logging'        => rest_sanitize_boolean( $request['enable_logging'] ),
				'default_cart_behavior' => $request['default_cart_behavior'],
				'coupon_lifetime_hours' => absint( $request['coupon_lifetime_hours'] ),
			]
		);

		return rest_ensure_response( Settings::get() );
	}

	/**
	 * Returns environment diagnostics for the admin health view.
	 *
	 * The SHOW TABLES probes run only on this rare, admin-only endpoint,
	 * so direct queries are acceptable here.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_diagnostics() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only environment probes on an admin-only endpoint.
		$table    = Install::table_name();
		$wc       = WC();
		$sessions = $wpdb->prefix . 'woocommerce_sessions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized custom table identifier.
		$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$diagnostics = [
			'wp_version'        => get_bloginfo( 'version' ),
			'wc_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php_version'       => PHP_VERSION,
			'plugin_version'    => PICKPACK_VERSION,
			'db_version'        => get_option( 'pickpack_db_version', '' ),
			'table_name'        => $table,
			'table_exists'      => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'bundle_count'      => (int) $row_count,
			'legacy_migrated'   => (bool) get_option( Install::LEGACY_MIGRATED_OPTION, false ),
			'legacy_table'      => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mmb_bundles' ) ),
			'sessions_table'    => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ),
			'logging_enabled'   => Settings::is_logging_enabled(),
			'currency'          => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
			'store_url'         => home_url( '/' ),
			'rest_url'          => esc_url_raw( rest_url( self::NAMESPACE_V1 ) ),
			'memory_limit'      => (string) ini_get( 'memory_limit' ),
			'timezone'          => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string' ),
			'is_wc_loaded'      => (bool) $wc,
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return rest_ensure_response( $diagnostics );
	}

	// ------------------------------------------------------------------
	// Storefront
	// ------------------------------------------------------------------

	/**
	 * Returns the full widget payload (bundle config + product catalog)
	 * for a published bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_bundle_view( $request ) {
		$bundle = Bundles::get( absint( $request['id'] ) );

		if ( ! $bundle || ! $bundle['enabled'] ) {
			return new \WP_Error( 'pickpack_not_found', __( 'Bundle not found.', 'pickpack-for-woocommerce' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( Frontend::bundle_payload( $bundle ) );
	}

	/**
	 * Server-side price quote for the selected items. The storefront never
	 * computes discounts; totals returned here are authoritative.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_quote( $request ) {
		$bundle = Bundles::get( absint( $request['bundle_id'] ) );

		if ( ! $bundle || ! $bundle['enabled'] ) {
			return new \WP_Error( 'pickpack_not_found', __( 'Bundle not found.', 'pickpack-for-woocommerce' ), [ 'status' => 404 ] );
		}

		$items = $this->normalize_items( $request['items'], $bundle );

		if ( empty( $items ) ) {
			return new \WP_Error( 'pickpack_empty_quote', __( 'No valid products selected.', 'pickpack-for-woocommerce' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response( $this->calculate_quote( $bundle, $items ) );
	}

	/**
	 * Validates selection, adds every product to the cart, and applies a
	 * coupon for the server-computed discount. The discount amount is
	 * never accepted from the client.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_to_cart( $request ) {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'pickpack_wc_missing', __( 'WooCommerce is not available.', 'pickpack-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$bundle = Bundles::get( absint( $request['bundle_id'] ) );

		if ( ! $bundle || ! $bundle['enabled'] ) {
			return new \WP_Error( 'pickpack_not_found', __( 'Bundle not found.', 'pickpack-for-woocommerce' ), [ 'status' => 404 ] );
		}

		$items = $this->normalize_items( $request['items'], $bundle );

		if ( empty( $items ) ) {
			return new \WP_Error( 'pickpack_empty_selection', __( 'No valid products selected.', 'pickpack-for-woocommerce' ), [ 'status' => 400 ] );
		}

		// Authoritative pricing, computed before anything touches the cart.
		$quote = $this->calculate_quote( $bundle, $items );

		if ( empty( $quote['products'] ) ) {
			return new \WP_Error( 'pickpack_empty_selection', __( 'No purchasable products selected.', 'pickpack-for-woocommerce' ), [ 'status' => 400 ] );
		}

		// WooCommerce skips cart/session initialization for REST requests
		// (the Store API loads its own), so bootstrap them here.
		if ( ! WC()->session || ! WC()->cart ) {
			Cart::instance()->ensure_session();
			wc_load_cart();
		}

		if ( ! WC()->session || ! WC()->cart ) {
			return new \WP_Error( 'pickpack_cart_missing', __( 'Cart is not available.', 'pickpack-for-woocommerce' ), [ 'status' => 500 ] );
		}

		// Remove any previous bundle coupon so bundles never mix.
		Cart::instance()->clear_session_bundle();

		$grouped     = $this->group_items( $quote['products'] );
		$added       = [];
		$failed      = [];

		foreach ( $grouped as $item ) {
			$product_id   = $item['product_id'];
			$variation_id = $item['variation_id'];
			$quantity     = $item['quantity'];
			$check_id     = $variation_id ? $variation_id : $product_id;
			$product      = wc_get_product( $check_id );

			if ( ! $product || ! $product->is_purchasable() ) {
				$failed[] = sprintf(
					/* translators: %d: product or variation ID */
					__( 'Product cannot be purchased: #%d', 'pickpack-for-woocommerce' ),
					$check_id
				);
				continue;
			}

			$cart_item_data = [
				'pickpack_bundle_item' => true,
				'pickpack_bundle_id'   => $bundle['id'],
			];

			$variation_attributes = [];
			if ( $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation && $variation->is_type( 'variation' ) ) {
					$variation_attributes = $variation->get_variation_attributes();
				}

				$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data );
			} else {
				$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], $cart_item_data );
			}

			if ( $cart_item_key ) {
				$added[] = [
					'cart_item_key' => $cart_item_key,
					'product_id'    => $product_id,
					'variation_id'  => $variation_id,
				];
			} else {
				$failed[] = sprintf(
					/* translators: %d: product ID */
					__( 'Failed to add product #%d to the cart.', 'pickpack-for-woocommerce' ),
					$product_id
				);
			}
		}

		if ( empty( $added ) ) {
			return new \WP_Error(
				'pickpack_add_failed',
				__( 'Failed to add any products to the cart.', 'pickpack-for-woocommerce' ),
				[ 'status' => 400, 'errors' => $failed ]
			);
		}

		// Coupon discount is the server-computed amount, never client input.
		$coupon_code   = '';
		$coupon_applied = false;

		if ( $quote['discount_amount'] > 0 ) {
			$coupon_product_ids = array_values( array_unique( wp_list_pluck( $quote['products'], 'product_id' ) ) );

			$coupon_code = Cart::instance()->create_bundle_coupon(
				$bundle['id'],
				(float) $quote['discount_amount'],
				(float) $quote['subtotal'],
				$coupon_product_ids
			);

			if ( $coupon_code && wc_get_coupon_id_by_code( $coupon_code ) ) {
				if ( ! WC()->cart->has_discount( $coupon_code ) ) {
					WC()->cart->apply_coupon( $coupon_code );
					Cart::instance()->remove_bundle_coupon_notices();
				}

				Cart::instance()->set_session_data(
					[
						'bundle_id'       => $bundle['id'],
						'discount_amount' => (float) $quote['discount_amount'],
						'product_ids'     => $coupon_product_ids,
						'coupon_code'     => $coupon_code,
					]
				);

				$coupon_applied = true;
			}
		} else {
			// Track the bundle even without a discount tier unlocked yet.
			Cart::instance()->set_session_data(
				[
					'bundle_id'       => $bundle['id'],
					'discount_amount' => 0.0,
					'product_ids'     => array_values( array_unique( wp_list_pluck( $quote['products'], 'product_id' ) ) ),
					'coupon_code'     => null,
				]
			);
		}

		WC()->cart->calculate_totals();

		do_action( 'pickpack_bundle_added_to_cart', $bundle['id'], $quote );

		return rest_ensure_response(
			[
				'added'         => $added,
				'failed'        => $failed,
				'coupon_code'   => $coupon_applied ? $coupon_code : '',
				'cart_url'      => wc_get_cart_url(),
				'cart_hash'     => WC()->cart->get_cart_hash(),
				'cart_count'    => WC()->cart->get_cart_contents_count(),
				'cart_subtotal' => (float) WC()->cart->get_subtotal( 'edit' ),
				'cart_total'    => (float) WC()->cart->get_total( 'edit' ),
				'fragments'     => Cart::instance()->cart_fragments(),
			]
		);
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	/**
	 * REST schema for the items collection.
	 *
	 * @return array
	 */
	private function items_arg() {
		return [
			'type'              => 'array',
			'required'          => true,
			'minItems'          => 1,
			'maxItems'          => 200,
			'items'             => [
				'type'       => 'object',
				'properties' => [
					'product_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
					'variation_id' => [ 'type' => 'integer', 'minimum' => 0 ],
					'quantity'     => [ 'type' => 'integer', 'minimum' => 1 ],
				],
			],
			'sanitize_callback' => [ $this, 'sanitize_items' ],
			'validate_callback' => [ $this, 'validate_items' ],
		];
	}

	/**
	 * Sanitizes the items collection to positive integers.
	 *
	 * @param mixed $value Raw value.
	 * @return array[]
	 */
	public function sanitize_items( $value ) {
		$clean = [];

		if ( is_string( $value ) ) {
			$decoded = json_decode( wp_unslash( $value ), true );
			$value   = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		foreach ( $value as $item ) {
			if ( is_object( $item ) ) {
				$item = (array) $item;
			}

			if ( ! is_array( $item ) ) {
				continue;
			}

			$clean[] = [
				'product_id'   => absint( $item['product_id'] ?? 0 ),
				'variation_id' => absint( $item['variation_id'] ?? 0 ),
				'quantity'     => max( 1, absint( $item['quantity'] ?? 1 ) ),
			];
		}

		return $clean;
	}

	/**
	 * Validation callback ensuring every item has a product ID.
	 *
	 * @param mixed $value Sanitized value.
	 * @return bool
	 */
	public function validate_items( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( empty( $item['product_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitizes an array of integers (product ID lists).
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public function sanitize_int_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	/**
	 * Collects the validated request args into a Bundles::save() payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function extract_bundle_data( $request ) {
		$bool_keys   = [ 'enabled', 'use_quantity', 'show_bundle_title', 'show_bundle_description', 'show_heading_text', 'show_hint_text', 'show_progress_text' ];
		$text_keys   = [ 'name', 'description', 'heading_text', 'hint_text', 'button_text', 'progress_text', 'cart_behavior', 'primary_color', 'accent_color', 'hover_bg_color', 'hover_accent_color', 'button_text_color' ];
		$json_keys   = [ 'product_ids', 'discount_tiers' ];

		$data = [];

		foreach ( $text_keys as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$data[ $key ] = wp_unslash( $request[ $key ] );
			}
		}

		foreach ( $bool_keys as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$data[ $key ] = rest_sanitize_boolean( $request[ $key ] );
			}
		}

		foreach ( $json_keys as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$data[ $key ] = $request[ $key ];
			}
		}

		if ( isset( $request['bundle_id'] ) ) {
			$data['bundle_id'] = absint( $request['bundle_id'] );
		}

		if ( isset( $request['max_quantity'] ) ) {
			$data['max_quantity'] = absint( $request['max_quantity'] );
		}

		return $data;
	}

	/**
	 * REST schema for bundle fields.
	 *
	 * @return array
	 */
	private function bundle_args() {
		$text = [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		];

		$bool = [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		];

		return [
			'bundle_id'              => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			],
			'name'                   => $text,
			'description'            => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'enabled'                => $bool,
			'use_quantity'           => $bool,
			'max_quantity'           => [
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 999,
				'sanitize_callback' => 'absint',
			],
			'heading_text'           => $text,
			'hint_text'              => $text,
			'button_text'            => $text,
			'progress_text'          => $text,
			'cart_behavior'          => [
				'type'              => 'string',
				'enum'              => [ 'sidecart', 'redirect' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'primary_color'          => [
				'type'              => 'string',
				'pattern'           => '^#[0-9a-fA-F]{6}$',
				'sanitize_callback' => 'sanitize_hex_color',
			],
			'accent_color'           => [
				'type'              => 'string',
				'pattern'           => '^#[0-9a-fA-F]{6}$',
				'sanitize_callback' => 'sanitize_hex_color',
			],
			'hover_bg_color'         => [
				'type'              => 'string',
				'pattern'           => '^#[0-9a-fA-F]{6}$',
				'sanitize_callback' => 'sanitize_hex_color',
			],
			'hover_accent_color'     => [
				'type'              => 'string',
				'pattern'           => '^#[0-9a-fA-F]{6}$',
				'sanitize_callback' => 'sanitize_hex_color',
			],
			'button_text_color'      => [
				'type'              => 'string',
				'pattern'           => '^#[0-9a-fA-F]{6}$',
				'sanitize_callback' => 'sanitize_hex_color',
			],
			'show_bundle_title'      => $bool,
			'show_bundle_description' => $bool,
			'show_heading_text'      => $bool,
			'show_hint_text'         => $bool,
			'show_progress_text'     => $bool,
			'product_ids'            => [
				'type'              => 'array',
				'items'             => [ 'type' => 'integer', 'minimum' => 1 ],
				'sanitize_callback' => [ $this, 'sanitize_int_list' ],
			],
			'discount_tiers'         => [
				'type'              => 'array',
				'items'             => [
					'type'       => 'object',
					'properties' => [
						'quantity' => [ 'type' => 'integer', 'minimum' => 1 ],
						'discount' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 100 ],
					],
				],
				'sanitize_callback' => [ Bundles::class, 'sanitize_tiers' ],
			],
		];
	}

	/**
	 * Enforces bundle quantity rules and drops invalid entries.
	 *
	 * @param array $items  Sanitized items from the request.
	 * @param array $bundle Bundle record.
	 * @return array[]
	 */
	private function normalize_items( $items, array $bundle ) {
		$items = $this->sanitize_items( $items );
		$clean = [];

		foreach ( $items as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}

			$quantity = $item['quantity'];

			if ( ! $bundle['use_quantity'] ) {
				$quantity = 1;
			}

			$quantity = min( $quantity, max( 1, (int) $bundle['max_quantity'] ) );

			$clean[] = [
				'product_id'   => $item['product_id'],
				'variation_id' => $item['variation_id'],
				'quantity'     => $quantity,
			];
		}

		return $clean;
	}

	/**
	 * Groups quote lines by product/variation, summing quantities.
	 *
	 * @param array $products Quote products.
	 * @return array[]
	 */
	private function group_items( array $products ) {
		$grouped = [];

		foreach ( $products as $product ) {
			$key = $product['product_id'] . '_' . $product['variation_id'];

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = [
					'product_id'   => $product['product_id'],
					'variation_id' => $product['variation_id'],
					'quantity'     => 0,
				];
			}

			$grouped[ $key ]['quantity'] += $product['quantity'];
		}

		return array_values( $grouped );
	}

	/**
	 * Computes the authoritative quote: per-line pricing from live product
	 * objects, the applicable tier, and the discount it unlocks.
	 *
	 * @param array $bundle Bundle record.
	 * @param array $items  Normalized items.
	 * @return array
	 */
	private function calculate_quote( array $bundle, array $items ) {
		$products  = [];
		$subtotal  = 0.0;
		$item_count = 0;

		foreach ( $items as $item ) {
			$actual_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
			$product   = wc_get_product( $actual_id );

			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}

			$price      = (float) $product->get_price();
			$quantity   = $item['quantity'];
			$line_total = $price * $quantity;

			$products[] = [
				'product_id'   => $item['product_id'],
				'variation_id' => $item['variation_id'],
				'name'         => $product->get_name(),
				'price'        => $price,
				'quantity'     => $quantity,
				'line_total'   => $line_total,
			];

			$subtotal   += $line_total;
			$item_count += $quantity;
		}

		if ( empty( $products ) ) {
			return [
				'products'           => [],
				'subtotal'           => 0.0,
				'total'              => 0.0,
				'discount_amount'    => 0.0,
				'discount_percentage' => 0.0,
				'item_count'         => 0,
				'tier'               => null,
			];
		}

		$tier = Bundles::applicable_tier( $bundle, $item_count );

		$discount_amount = ( $subtotal * (float) $tier['discount'] ) / 100;

		return [
			'products'            => $products,
			'subtotal'            => round( $subtotal, wc_get_price_decimals() ),
			'total'               => round( $subtotal - $discount_amount, wc_get_price_decimals() ),
			'discount_amount'     => round( $discount_amount, wc_get_price_decimals() ),
			'discount_percentage' => (float) $tier['discount'],
			'item_count'          => $item_count,
			'tier'                => $tier,
		];
	}
}
