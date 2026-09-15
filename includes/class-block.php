<?php
/**
 * Gutenberg block: bundixo/bundle.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the bundle block and its editor assets. The block is dynamic:
 * the editor shows a compact server-rendered preview, while the frontend
 * renders the same Vue widget used by the shortcode.
 */
class Block {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', [ $this, 'register_assets' ], 5 );
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Registers the editor script so block.json can reference its handle.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script(
			'bundixo-block-editor',
			BUNDIXO_PLUGIN_URL . 'assets/build/block-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render' ],
			$this->asset_version(),
			true
		);

		wp_set_script_translations( 'bundixo-block-editor', 'bundixo-for-woocommerce' );
	}

	/**
	 * Registers the block type with its render callback.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			BUNDIXO_PLUGIN_DIR . 'blocks/bundle',
			[
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$bundle_id = isset( $attributes['bundleId'] ) ? absint( $attributes['bundleId'] ) : 0;

		if ( ! $bundle_id ) {
			return '';
		}

		$bundle = Bundles::get( $bundle_id );

		if ( ! $bundle ) {
			return '<p class="bundixo-error">' . esc_html__( 'Bundle not found.', 'bundixo-for-woocommerce' ) . '</p>';
		}

		// The block editor renders a lightweight preview card instead of
		// the interactive widget (the Vue app only runs on the frontend).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return Frontend::render_editor_preview( $bundle );
		}

		if ( ! $bundle['enabled'] ) {
			return '';
		}

		return Frontend::render_bundle( $bundle );
	}

	/**
	 * Cache-busting version for the editor bundle.
	 *
	 * @return string
	 */
	private function asset_version() {
		$file = BUNDIXO_PLUGIN_DIR . 'assets/build/block-editor.js';

		return file_exists( $file )
			? BUNDIXO_VERSION . '.' . (string) filemtime( $file )
			: BUNDIXO_VERSION;
	}
}
