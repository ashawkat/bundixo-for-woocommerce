<?php
/**
 * Block editor preview: a compact, non-interactive summary of the bundle.
 * Included from Frontend::render_editor_preview().
 *
 * @package Bundixo
 *
 * @var array $bundle   Formatted bundle.
 * @var array $products [[name, image]] sample products.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="bundixo-editor-preview" style="border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;background:#fff;font-family:inherit;">
	<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
		<strong style="font-size:14px;"><?php echo esc_html( $bundle['name'] ); ?></strong>
		<span style="font-size:11px;background:#e6f4e6;color:#2e7d32;border-radius:999px;padding:2px 9px;text-transform:uppercase;letter-spacing:.03em;">
			<?php esc_html_e( 'Live on your store', 'bundixo-for-woocommerce' ); ?>
		</span>
	</div>

	<?php if ( $products ) : ?>
		<div style="display:flex;gap:8px;margin-bottom:10px;">
			<?php foreach ( $products as $product ) : ?>
				<img
					src="<?php echo esc_url( $product['image'] ? $product['image'] : wc_placeholder_img_src() ); ?>"
					alt="<?php echo esc_attr( $product['name'] ); ?>"
					style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:1px solid #e7e7e9;"
				/>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $bundle['discount_tiers'] ) : ?>
		<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
			<?php foreach ( $bundle['discount_tiers'] as $tier ) : ?>
				<span style="font-size:11px;font-weight:600;border:1px solid #e0e0e0;border-radius:999px;padding:3px 10px;color:#50575e;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: quantity, 2: discount percentage */
							__( '%1$d+ items → %2$s%% off', 'bundixo-for-woocommerce' ),
							(int) $tier['quantity'],
							(float) $tier['discount']
						)
					);
					?>
				</span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p style="margin:0;color:#8c8f94;font-size:11.5px;">
		<?php esc_html_e( 'Preview: shoppers will see the interactive bundle builder here.', 'bundixo-for-woocommerce' ); ?>
	</p>
</div>
