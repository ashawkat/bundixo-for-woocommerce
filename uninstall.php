<?php
/**
 * Uninstall routine for PickPack for WooCommerce.
 *
 * Removes all plugin data when uninstalled via WordPress.
 *
 * @package PickPack
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Clear the scheduled coupon cleanup.
$pickpack_timestamp = wp_next_scheduled( 'pickpack_daily_coupon_cleanup' );
if ( $pickpack_timestamp ) {
	wp_unschedule_event( $pickpack_timestamp, 'pickpack_daily_coupon_cleanup' );
}

// Delete dynamically created bundle coupons. The coupon code is stored as
// the post_title of shop_coupon posts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$pickpack_coupon_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE %s",
		'pickpack_bundle_%'
	)
);

if ( is_array( $pickpack_coupon_ids ) ) {
	foreach ( $pickpack_coupon_ids as $pickpack_coupon_id ) {
		wp_delete_post( (int) $pickpack_coupon_id, true );
	}
}

// Drop the bundles table. The table name is built from the sanitized
// wpdb prefix, which WordPress guarantees is [A-Za-z0-9_].
$pickpack_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix );
$pickpack_table  = $pickpack_prefix . 'pickpack_bundles';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from the sanitized wpdb prefix.
$wpdb->query( 'DROP TABLE IF EXISTS `' . $pickpack_table . '`' );
// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

// Delete plugin options.
delete_option( 'pickpack_settings' );
delete_option( 'pickpack_db_version' );
delete_option( 'pickpack_legacy_migrated' );

wp_cache_flush();
