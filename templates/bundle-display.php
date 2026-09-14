<?php
/**
 * Bundle widget shell.
 *
 * The Vue storefront app mounts into this wrapper and hydrates from the
 * embedded JSON payload. Included from Frontend::render_bundle().
 *
 * @package PickPack
 *
 * @var array $payload Bundle + products payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pickpack_instance_id = 'pickpack-widget-' . (int) $payload['bundle']['id'] . '-' . wp_rand( 100, 999 );
?>
<div class="pickpack-bundle-wrapper" id="<?php echo esc_attr( $pickpack_instance_id ); ?>" data-pickpack-payload="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>">
	<div class="pickpack-widget-loading" aria-hidden="true">
		<div class="pickpack-skeleton pickpack-skeleton--title"></div>
		<div class="pickpack-skeleton-grid">
			<span class="pickpack-skeleton"></span>
			<span class="pickpack-skeleton"></span>
			<span class="pickpack-skeleton"></span>
		</div>
	</div>
	<noscript>
		<p><?php esc_html_e( 'This bundle builder requires JavaScript.', 'pickpack-for-woocommerce' ); ?></p>
	</noscript>
</div>
