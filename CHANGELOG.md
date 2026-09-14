# Changelog

## 1.0.1

- Renamed the plugin to PickPack for WooCommerce (slug `pickpack-for-woocommerce`).
- Existing bundles stored in the previous table are renamed in place on upgrade; no data is lost.

## 1.0.0

Initial release.

- Bundle builder admin app (Vue 3): products with drag-and-drop ordering, tiered discount rules, appearance controls with live preview.
- Storefront bundle widget (Vue 3): variation selects, quantity steppers or select mode, tier progress, summary, mobile sticky cart.
- Server-side pricing through the `pickpack/v1` REST API; discounts applied as dynamic WooCommerce coupons.
- Analytics dashboard with date-range filtering and Chart.js visualizations.
- Diagnostics page and optional WooCommerce debug logging.
- One-time migration from the legacy `mmb_bundles` table.
