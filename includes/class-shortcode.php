<?php
/**
 * Bundle shortcode.
 *
 * @package PickPack
 */

namespace PickPack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [pickpack_bundle] shortcode.
 */
class Shortcode {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'pickpack_bundle', [ $this, 'render' ] );
	}

	/**
	 * Renders a bundle widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			[ 'id' => 0 ],
			$atts,
			'pickpack_bundle'
		);

		$bundle = Bundles::get( absint( $atts['id'] ) );

		if ( ! $bundle ) {
			return '<p class="pickpack-error">' . esc_html__( 'Bundle not found.', 'pickpack-for-woocommerce' ) . '</p>';
		}

		if ( ! $bundle['enabled'] ) {
			return '';
		}

		return Frontend::render_bundle( $bundle );
	}
}
