<?php
/**
 * Bundle shortcode.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [bundixo_bundle] shortcode.
 */
class Shortcode {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'bundixo_bundle', [ $this, 'render' ] );
	}

	/**
	 * Renders a bundle widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			[
				'id'    => 0,
				'style' => '', // 'full' (builder) or 'compact' (FBT list); empty follows the bundle setting.
			],
			$atts,
			'bundixo_bundle'
		);

		$bundle = Bundles::get( absint( $atts['id'] ) );

		if ( ! $bundle ) {
			return '<p class="bundixo-error">' . esc_html__( 'Bundle not found.', 'bundixo-for-woocommerce' ) . '</p>';
		}

		if ( ! $bundle['enabled'] ) {
			return '';
		}

		$args   = [];
		$style  = strtolower( trim( $atts['style'] ) );

		if ( in_array( $style, [ 'full', 'compact' ], true ) ) {
			$args['layout'] = $style;
		}

		return Frontend::render_bundle( $bundle, $args );
	}
}
