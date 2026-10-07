# Changelog

## 1.1.0

- Frequently bought together: display any bundle automatically on single product pages, right after the Add to Cart button (or before the tabs / after related products).
- New "Placement" tab per bundle: auto-display toggle, position, show on the bundle's own product pages, additional product pages, and widget layout.
- Compact "frequently bought together" widget layout: checkbox rows with thumbnails, the viewed product pre-selected, tier progress, live bundle price and savings.
- When the widget appears on a product that is not part of the bundle ("also show on" placement), the viewed product joins the offer automatically as the "This item" row: it is added to the cart with the bundle, at full price, and never counts toward the discount tiers.
- Anchor pricing everywhere on product pages: the viewed product is always the anchor of a compact offer — pre-selected, added at full price, and never counting toward the discount tiers. Discounts unlock only through the extra products the customer picks.
- New "Show on every product page" placement rule (catch-all). Tailored placements (own products, pinned pages) always win over catch-alls when several bundles match one page.
- New "Recommended for the viewed product" offer mode: no fixed product list — each product page fills the offer automatically from that product's cross-sells, falling back to upsells, then to same-category products. All data stays on the store; no external service is used.
- Clearer Placement tab: "Where should this offer appear?" section with explained rules (follow the bundle's products / every product page / pin to additional pages) and a "Products in the offer" chooser.
- Tested up to WordPress 7.1 and WooCommerce 11.1. Passed Plugin Check with no errors (removed stray .DS_Store files, escaped output, shortened the readme short description); new screenshots for the frequently-bought-together widget and the Placement tab.
- Shortcode and Gutenberg block remain fully manual: `[bundixo_bundle id="123" style="compact"]` and a block layout option force either layout.
- A bundle never renders twice on the same page when automatic placement and a manual shortcode overlap (`bundixo_allow_duplicate_renders` filter to opt out).
- Fixed: guest carts built through the widget were lost on the next page load because the WooCommerce session cookie was never sent with the add-to-cart response.
- Fixed: critical error (infinite recursion → fatal) when removing cart items could leave the session referencing a bundle coupon that no longer applies. Coupon re-apply is now guarded against re-entrancy, checks the apply result, and drops the stale reference instead of retrying forever; coupon code comparisons are also case-normalized to match WooCommerce's lowercase applied codes.
- Fixed: bundle discount coupons are no longer rejected next to "individual use" coupons — the raw "cannot be used in conjunction with other coupons" error is gone, and both coupons coexist regardless of which was applied first.
- The bundle widget now warns ("Some items could not be added to the cart.") when an add is only partially successful, instead of showing plain success.
- New `placement` column on the bundles table (schema version 1.3); existing bundles keep manual-only placement after upgrade.

## 1.0.1

- Renamed the plugin to Bundixo for WooCommerce (slug `bundixo-for-woocommerce`).
- Existing bundles stored in the previous table are renamed in place on upgrade; no data is lost.

## 1.0.0

Initial release.

- Bundle builder admin app (Vue 3): products with drag-and-drop ordering, tiered discount rules, appearance controls with live preview.
- Storefront bundle widget (Vue 3): variation selects, quantity steppers or select mode, tier progress, summary, mobile sticky cart.
- Server-side pricing through the `bundixo/v1` REST API; discounts applied as dynamic WooCommerce coupons.
- Analytics dashboard with date-range filtering and Chart.js visualizations.
- Diagnostics page and optional WooCommerce debug logging.
- One-time migration from the legacy `mmb_bundles` table.
