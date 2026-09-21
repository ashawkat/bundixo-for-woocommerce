=== Bundixo for WooCommerce ===
Contributors: betatech, ashawkat
Tags: woocommerce, bundle, product bundles, discount, quantity discount
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build product bundle promotions with tiered quantity discounts, a modern admin app, analytics, and a customer-facing bundle builder widget.

== Description ==

Bundixo lets you group products into attractive bundle promotions. Shoppers pick products (or set quantities), watch their savings grow as they reach discount tiers, and add the whole bundle to the cart in one click. Discounts are applied with real WooCommerce coupons, so totals are correct in every cart, sidecart, and checkout view.

Place bundles anywhere with the **native Gutenberg block** (with an in-editor preview and automatic shortcode conversion) or the classic `[bundixo_bundle id="123"]` shortcode.

**Highlights**

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

Two ways: insert the **Bundixo Bundle** block in the Gutenberg editor (it shows an in-editor preview), or use the classic shortcode `[bundixo_bundle id="123"]`. Pasting the shortcode into the block editor converts it into the block automatically.

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

== Changelog ==

= 1.0.2 =
* Added a public source repository link and build instructions to the readme (WordPress.org Guideline 4: human-readable code).
* Included the unminified `src/` directory in the plugin package alongside the generated bundles.

= 1.0.1 =
* Rebranded the plugin to Bundixo for WooCommerce.

= 1.0.0 =
* Initial release: bundle builder with tiered discounts, storefront widget, analytics dashboard, diagnostics, and REST API back end.

== Upgrade Notice ==

= 1.0.2 =
Documentation update: source code repository link and build instructions.

= 1.0.0 =
Initial release.
