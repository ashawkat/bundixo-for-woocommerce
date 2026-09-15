<?php
/**
 * Bundle widget shell.
 *
 * The Vue storefront app mounts into this wrapper and hydrates from the
 * embedded JSON payload. Included from Frontend::render_bundle().
 *
 * @package Bundixo
 *
 * @var array $payload Bundle + products payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bundixo_instance_id = 'bundixo-widget-' . (int) $payload['bundle']['id'] . '-' . wp_rand( 100, 999 );
?>
<div class="bundixo-bundle-wrapper" id="<?php echo esc_attr( $bundixo_instance_id ); ?>" data-bundixo-payload="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>">
	<div class="bundixo-widget-loading" aria-hidden="true">
		<div class="bundixo-skeleton bundixo-skeleton--title"></div>
		<div class="bundixo-skeleton-grid">
			<span class="bundixo-skeleton"></span>
			<span class="bundixo-skeleton"></span>
			<span class="bundixo-skeleton"></span>
		</div>
	</div>
	<noscript>
		<p><?php esc_html_e( 'This bundle builder requires JavaScript.', 'bundixo-for-woocommerce' ); ?></p>
	</noscript>
</div>
