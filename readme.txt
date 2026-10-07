=== Bundixo for WooCommerce ===
Contributors: betatech, adnanshawkat
Tags: woocommerce, bundle, product bundles, discount, quantity discount
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build bundle promotions with tiered quantity discounts and automatic frequently-bought-together offers on product pages.

== Description ==

Bundixo lets you group products into attractive bundle promotions. Shoppers pick products (or set quantities), watch their savings grow as they reach discount tiers, and add the whole bundle to the cart in one click. Discounts are applied with real WooCommerce coupons, so totals are correct in every cart, sidecart, and checkout view.

Place bundles anywhere with the **native Gutenberg block** (with an in-editor preview and automatic shortcode conversion) or the classic `[bundixo_bundle id="123"]` shortcode.

**Highlights**

* Frequently bought together: show any bundle automatically on product pages — right after the Add to Cart button. The viewed product is the anchor: pre-selected, added at full price, never counting toward the discount tiers.
* Place by rule: follow the bundle's own products, pin to additional product pages, or show on every product page — tailored placements always win over catch-alls.
* Recommended mode: offers with no fixed product list that fill automatically from each viewed product's cross-sells (fallbacks: upsells, same-category products). No external service — all data stays on your store.
* Compact FBT layout (checkbox rows, live bundle price and savings) or the full bundle builder; pick per bundle, and force either with the `style` shortcode attribute or block option.
* Tiered quantity discounts: "Buy 2+ get 10% off, 3+ get 15% off…" with a live progress bar for shoppers.
* Quantity mode with per-product steppers or a simple select-all checkbox mode.
* Full control over texts, button label, and five theme colors — with a live preview in the editor.
* Product search with drag-and-drop ordering for bundle contents.
* Variable products supported: shoppers choose variations inside the widget.
* Cart behavior per bundle: open the cart/sidecart or redirect to the cart page.
* Gutenberg block and shortcode placement, in posts, pages, and block-based widget areas.
* Analytics dashboard: coupons created, bundle revenue, orders, cart share, and top bundles over time.
* Health-check diagnostics page and optional WooCommerce debug logging.
* Built with Vue 3 and the WordPress REST API; all pricing math runs server-side.

**Coming soon**

First-class widgets for Elementor, Bricks, and other page builders are on the roadmap.

**Discounts that stay correct**

Bundle discounts are implemented as dynamically created WooCommerce coupons, restricted to the bundle's products and recalculated server-side from live product prices. They show up naturally in cart totals, emails, and orders — and expire automatically if unused.

**Privacy**

Bundixo does not send data to any third-party service. All processing happens on your own site.

**Development and source code**

The JavaScript and CSS files in `assets/build/` are generated (minified) bundles. The human-readable source code — a Vue 3 application built with [Vite](https://vitejs.dev/) — is maintained in the public repository at https://github.com/ashawkat/bundixo-for-woocommerce (see the `src/` directory). Clone the repository and run `npm install && npm run build` to regenerate the bundles.

== Installation ==

1. Install WooCommerce (required) and Bundixo through the Plugins screen, then activate Bundixo.
2. Go to **Bundixo → Bundles** and create your first bundle: add products, set discount tiers, style it, and save.
3. Place the shortcode `[bundixo_bundle id="1"]` in any post, page, or builder section.
4. Optionally review **Bundixo → Analytics** and **Bundixo → Settings**.

== Frequently Asked Questions ==

= How are the discounts calculated? =

Server-side, always. When a shopper changes their selection the widget asks the store for a fresh quote; when the bundle is added to the cart the exact same calculation runs again and a matching WooCommerce coupon is applied. The browser never decides the discount.

= How do I place a bundle on a page? =

Three ways: insert the **Bundixo Bundle** block in the Gutenberg editor (it shows an in-editor preview), use the classic shortcode `[bundixo_bundle id="123"]`, or — new in 1.1.0 — let Bundixo place it for you: open the bundle's **Placement** tab, enable "Show automatically on product pages", and pick a position (after the Add to Cart button, before the tabs, or after related products). The bundle then appears on the product pages of its own products, plus any extra products you assign, with the viewed product pre-selected. Pasting the shortcode into the block editor converts it into the block automatically.

= Does it work with variable products? =

Yes. Variable products show a variation dropdown inside the bundle widget and the chosen variation is added to the cart.

= Can I use my own theme or a sidecart plugin? =

The widget ships with neutral styling that inherits your theme's typography. After adding to cart, Bundixo refreshes classic cart fragments and the WooCommerce Blocks cart store, and tries to open popular sidecarts. Use the `bundixo_should_enqueue_frontend_assets` filter if you need custom asset loading.

= What happens to unused discount coupons? =

A daily cleanup task deletes bundle coupons that were created more than 24 hours ago and never used. Used coupons are kept for order history and analytics.

= I used an older "Mix & Match"/"Bundle Builder" plugin from the same author. Will my bundles be kept? =

Yes. On activation, Bundixo migrates bundles and settings from the legacy table automatically.

= Where is the source code for the JavaScript and CSS bundles? =

The files in `assets/build/` are generated with Vite from human-readable Vue 3 source. The full unminified source and build tooling are publicly available at https://github.com/ashawkat/bundixo-for-woocommerce — the source lives in the `src/` directory, and running `npm install && npm run build` in the repository root regenerates the bundles. The `src/` directory is also included in the plugin package.

== Screenshots ==

1. Bundle editor with product picker, drag-and-drop ordering, and a live preview of the storefront widget.
2. Tiered discount builder: set quantity thresholds and percentage discounts.
3. Analytics dashboard: coupons, revenue, orders, cart share, and top bundles with date-range filtering.
4. Storefront bundle widget: quantity steppers, unlocked tiers, and a live summary with the discount applied.
5. Native Gutenberg block: insert the "Bundixo Bundle" block with an in-editor preview.
6. Frequently bought together on a product page: the viewed product is the pre-selected anchor at full price, recommended products unlock the tiered discount, with live bundle price and savings.
7. Placement tab: where the offer appears (follow the bundle's products, every product page, or pinned pages) and whether the offer uses the bundle's products or recommended ones.

== Changelog ==

= 1.1.0 =
* New: frequently bought together — automatic bundle placement on single product pages (after the Add to Cart button, before the tabs, or after related products).
* New "Placement" tab per bundle: auto-display toggle, position, own-product pages, extra product pages, and widget layout.
* New compact FBT widget layout with the viewed product pre-selected, tier progress, and live bundle price/savings.
* Anchor pricing: on product pages the viewed product is always the anchor — pre-selected, full price, never counting toward tiers.
* New placement rules: "every product page" catch-all, and a recommended mode that fills the offer from the viewed product's cross-sells (fallbacks: upsells, same-category).
* Clearer Placement tab with a "Where should this offer appear?" section explaining each rule.
* Shortcode `style` attribute (`[bundixo_bundle id="123" style="compact"]`) and a matching Gutenberg block layout option.
* A bundle renders only once per page when automatic placement and a manual shortcode overlap (filterable via `bundixo_allow_duplicate_renders`).
* Fixed: guest carts built through the widget were lost on the next page load because the WooCommerce session cookie was never sent with the add-to-cart response.
* Fixed: critical error when removing cart items left the session referencing a bundle coupon that could no longer be applied — coupon re-apply is now re-entrancy-guarded and drops stale references.
* Database schema 1.3: adds the `placement` column. Existing bundles keep manual-only placement.

= 1.0.4 =
* Corrected the WordPress.org contributor handle in the readme.

= 1.0.3 =
* Removed the `.wordpress-org` assets folder from the distribution package (plugin review feedback); screenshots and banners remain available in the public repository.

= 1.0.2 =
* Added a public source repository link and build instructions to the readme (WordPress.org Guideline 4: human-readable code).
* Included the unminified `src/` directory in the plugin package alongside the generated bundles.

= 1.0.1 =
* Rebranded the plugin to Bundixo for WooCommerce.

= 1.0.0 =
* Initial release: bundle builder with tiered discounts, storefront widget, analytics dashboard, diagnostics, and REST API back end.

== Upgrade Notice ==

= 1.1.0 =
New: show any bundle automatically on product pages as a "frequently bought together" offer. Existing bundles are unaffected until you enable it.

= 1.0.4 =
Readme fix: corrected the contributor handle.

= 1.0.2 =
Documentation update: source code repository link and build instructions.

= 1.0.0 =
Initial release.
