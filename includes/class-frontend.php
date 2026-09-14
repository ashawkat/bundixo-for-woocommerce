<?php
/**
 * Storefront rendering and product payload building.
 *
 * @package PickPack
 */

namespace PickPack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the JSON payloads consumed by the Vue bundle widget and renders
 * the widget shell template.
 */
class Frontend {

	/**
	 * Set when a shortcode renders after wp_enqueue_scripts, so assets can
	 * still be printed in the footer.
	 *
	 * @var bool
	 */
	private static $assets_forced = false;

	/**
	 * Marks frontend assets as required on this request and triggers a
	 * late enqueue for renders that happen after wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public static function force_assets() {
		self::$assets_forced = true;

		/**
		 * Fires when a bundle widget has just been rendered, so the plugin
		 * can enqueue its assets even outside the standard detection.
		 */
		do_action( 'pickpack_force_frontend_assets' );
	}

	/**
	 * Whether assets were forced after the standard detection ran.
	 *
	 * @return bool
	 */
	public static function assets_forced() {
		return self::$assets_forced;
	}

	/**
	 * Renders the bundle widget shell. The Vue app hydrates it from the
	 * embedded payload.
	 *
	 * @param array $bundle Formatted bundle.
	 * @return string
	 */
	public static function render_bundle( array $bundle ) {
		self::force_assets();

		$payload = self::bundle_payload( $bundle );

		ob_start();
		include PICKPACK_PLUGIN_DIR . 'templates/bundle-display.php';
		return ob_get_clean();
	}

	/**
	 * Compact, non-interactive preview used inside the block editor, where
	 * the storefront Vue app is not available.
	 *
	 * @param array $bundle Formatted bundle.
	 * @return string
	 */
	public static function render_editor_preview( array $bundle ) {
		$products = [];

		foreach ( array_slice( $bundle['product_ids'], 0, 6 ) as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product ) {
				$products[] = [
					'name'  => $product->get_name(),
					'image' => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
				];
			}
		}

		ob_start();
		include PICKPACK_PLUGIN_DIR . 'templates/editor-preview.php';
		return ob_get_clean();
	}

	/**
	 * Full widget payload: bundle config plus purchasable product data.
	 *
	 * @param array $bundle Formatted bundle.
	 * @return array
	 */
	public static function bundle_payload( array $bundle ) {
		$products = [];

		foreach ( $bundle['product_ids'] as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product && 'publish' === $product->get_status() ) {
				$products[] = self::format_product( $product );
			}
		}

		return [
			'bundle'   => [
				'id'                      => $bundle['id'],
				'name'                    => $bundle['name'],
				'description'             => $bundle['description'],
				'use_quantity'            => (bool) $bundle['use_quantity'],
				'max_quantity'            => (int) $bundle['max_quantity'],
				'discount_tiers'          => array_map(
					static function ( $tier ) {
						return [
							'quantity' => (int) $tier['quantity'],
							'discount' => (float) $tier['discount'],
						];
					},
					$bundle['discount_tiers']
				),
				'heading_text'            => $bundle['heading_text'],
				'hint_text'               => $bundle['hint_text'],
				'primary_color'           => $bundle['primary_color'],
				'accent_color'            => $bundle['accent_color'],
				'hover_bg_color'          => $bundle['hover_bg_color'],
				'hover_accent_color'      => $bundle['hover_accent_color'],
				'button_text_color'       => $bundle['button_text_color'],
				'button_text'             => $bundle['button_text'],
				'progress_text'           => $bundle['progress_text'],
				'cart_behavior'           => $bundle['cart_behavior'],
				'show_bundle_title'       => (bool) $bundle['show_bundle_title'],
				'show_bundle_description' => (bool) $bundle['show_bundle_description'],
				'show_heading_text'       => (bool) $bundle['show_heading_text'],
				'show_hint_text'          => (bool) $bundle['show_hint_text'],
				'show_progress_text'      => (bool) $bundle['show_progress_text'],
			],
			'products' => $products,
		];
	}

	/**
	 * Storefront product payload including variations for variable products.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public static function format_product( $product ) {
		$is_variable = $product->is_type( 'variable' );

		$data = [
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'permalink'    => get_permalink( $product->get_id() ),
			'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
			'price'        => (float) $product->get_price(),
			'price_html'   => $product->get_price_html(),
			'is_variable'  => $is_variable,
			'purchasable'  => $product->is_purchasable(),
			'in_stock'     => $product->is_in_stock(),
			'variations'   => [],
		];

		if ( $is_variable ) {
			$min = $product->get_variation_price( 'min' );
			$max = $product->get_variation_price( 'max' );

			$data['price_min'] = null !== $min ? (float) $min : 0.0;
			$data['price_max'] = null !== $max ? (float) $max : 0.0;

			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation || ! $variation->is_purchasable() ) {
					continue;
				}

				$data['variations'][] = self::format_variation( $variation );
			}
		}

		return $data;
	}

	/**
	 * Single variation payload for the widget's dropdown.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public static function format_variation( $variation ) {
		return [
			'id'          => $variation->get_id(),
			'attributes'  => $variation->get_variation_attributes(),
			'price'       => (float) $variation->get_price(),
			'price_html'  => $variation->get_price_html(),
			'image'       => wp_get_attachment_image_url( $variation->get_image_id(), 'woocommerce_thumbnail' ),
			'in_stock'    => $variation->is_in_stock(),
			'purchasable' => $variation->is_purchasable(),
		];
	}

	/**
	 * Lightweight product payload for the admin editor (search and lists).
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public static function format_admin_product( $product ) {
		return [
			'id'         => $product->get_id(),
			'name'       => $product->get_name(),
			'type'       => $product->get_type(),
			'price'      => (float) $product->get_price(),
			'price_html' => $product->get_price_html(),
			'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
		];
	}
}
