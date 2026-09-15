<?php
/**
 * Uninstall routine for Bundixo for WooCommerce.
 *
 * Removes all plugin data when uninstalled via WordPress.
 *
 * @package Bundixo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Clear the scheduled coupon cleanup.
$bundixo_timestamp = wp_next_scheduled( 'bundixo_daily_coupon_cleanup' );
if ( $bundixo_timestamp ) {
	wp_unschedule_event( $bundixo_timestamp, 'bundixo_daily_coupon_cleanup' );
}

// Delete dynamically created bundle coupons. The coupon code is stored as
// the post_title of shop_coupon posts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$bundixo_coupon_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE %s",
		'bundixo_bundle_%'
	)
);

if ( is_array( $bundixo_coupon_ids ) ) {
	foreach ( $bundixo_coupon_ids as $bundixo_coupon_id ) {
		wp_delete_post( (int) $bundixo_coupon_id, true );
	}
}

// Drop the bundles table. The table name is built from the sanitized
// wpdb prefix, which WordPress guarantees is [A-Za-z0-9_].
$bundixo_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix );
$bundixo_table  = $bundixo_prefix . 'bundixo_bundles';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from the sanitized wpdb prefix.
$wpdb->query( 'DROP TABLE IF EXISTS `' . $bundixo_table . '`' );
// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

// Delete plugin options.
delete_option( 'bundixo_settings' );
delete_option( 'bundixo_db_version' );
delete_option( 'bundixo_legacy_migrated' );

wp_cache_flush();
