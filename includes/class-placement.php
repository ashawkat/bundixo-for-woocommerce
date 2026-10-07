<?php
/**
 * Automatic bundle placement on single product pages.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders bundles automatically on product pages ("frequently bought
 * together" style) based on each bundle's placement configuration.
 *
 * Manual placement via the shortcode or block is unaffected by this
 * class and remains available for every bundle.
 */
class Placement {

	/**
	 * Matched bundles for the current product page, computed once.
	 *
	 * @var array[]|null
	 */
	private static $matches = null;

	/**
	 * Hook registration. Each position gets its own callback bound to its
	 * position key, because two positions share a hook at different
	 * priorities and must not render each other's bundles.
	 *
	 * @return void
	 */
	public function register_hooks() {
		foreach ( Bundles::placement_positions() as $key => $position ) {
			$callback = static function () use ( $key ) {
				( new self() )->render_position( $key );
			};

			add_action( $position['hook'], $callback, $position['priority'] );
		}
	}

	/**
	 * Renders every bundle placed at the given position on the product
	 * being viewed, honoring the per-page limit and the duplicate-render
	 * guard.
	 *
	 * @param string $position_key Position key from Bundles::placement_positions().
	 * @return void
	 */
	public function render_position( $position_key ) {
		$product_id = get_the_ID();

		if ( ! $product_id ) {
			return;
		}

		$matches = self::matches_for( $product_id );

		if ( empty( $matches ) ) {
			return;
		}

		/**
		 * Filters how many auto-placed bundles may render per product page.
		 *
		 * @param int $limit Default 1.
		 */
		$limit    = max( 1, (int) apply_filters( 'bundixo_max_auto_bundles_per_page', 1 ) );
		$rendered = 0;

		foreach ( $matches as $bundle ) {
			if ( $rendered >= $limit ) {
				break;
			}

			if ( (string) $position_key !== (string) $bundle['placement']['position'] ) {
				continue;
			}

			// Frontend::render_bundle() no-ops when the same bundle was
			// already rendered on this request (e.g. via the shortcode).
			$markup = Frontend::render_bundle( $bundle );

			if ( '' !== $markup ) {
				$rendered++;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully-formed widget markup built through escaping inside Frontend::render_bundle().
			echo $markup;
		}
	}

	/**
	 * Whether any auto-placed bundle matches the given product. Used by
	 * the asset loader before the template hooks fire.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function has_match( $product_id ) {
		return ! empty( self::matches_for( $product_id ) );
	}

	/**
	 * Enabled auto-display bundles whose rules match the product, computed
	 * at most once per request. Specifically targeted bundles (own
	 * products, pinned pages) come before catch-all ones ("every product
	 * page", recommended), so a tailored offer is never shadowed.
	 *
	 * @param int $product_id Product ID.
	 * @return array[]
	 */
	private static function matches_for( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return [];
		}

		if ( null === self::$matches ) {
			$specific  = [];
			$catch_all = [];

			foreach ( Bundles::enabled() as $bundle ) {
				$placement = $bundle['placement'];

				if ( ! $placement['auto_display'] ) {
					continue;
				}

				$is_catch_all = $placement['all_products'] || 'recommended' === $placement['product_source'];

				if ( ! $is_catch_all && ! self::bundle_matches_product( $bundle, $placement, $product_id ) ) {
					continue;
				}

				if ( $is_catch_all ) {
					$catch_all[] = $bundle;
				} else {
					$specific[] = $bundle;
				}
			}

			self::$matches = array_merge( $specific, $catch_all );
		}

		return self::$matches;
	}

	/**
	 * Whether a bundle's placement rules target the viewed product: either
	 * the product is part of the bundle, or it was assigned explicitly.
	 * Catch-all rules ("every product page", recommended mode) are handled
	 * by the caller.
	 *
	 * @param array $bundle     Formatted bundle.
	 * @param array $placement  Placement config.
	 * @param int   $product_id Viewed product ID.
	 * @return bool
	 */
	private static function bundle_matches_product( array $bundle, array $placement, $product_id ) {
		if ( $placement['include_products'] && in_array( $product_id, $bundle['product_ids'], true ) ) {
			return true;
		}

		return in_array( $product_id, $placement['extra_product_ids'], true );
	}
}
