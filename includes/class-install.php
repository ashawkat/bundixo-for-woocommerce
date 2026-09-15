<?php
/**
 * Installation and database schema handling.
 *
 * @package Bundixo
 */

namespace Bundixo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the bundles table, and migrates data from the
 * legacy "mmb" plugin if it was previously installed.
 */
class Install {

	/**
	 * Option key holding the legacy-migration flag.
	 */
	const LEGACY_MIGRATED_OPTION = 'bundixo_legacy_migrated';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		try {
			self::rename_pre_rebrand_table();
			self::run_db_delta();

			update_option( 'bundixo_db_version', BUNDIXO_DB_VERSION, false );

			if ( ! get_option( self::LEGACY_MIGRATED_OPTION, false ) ) {
				self::migrate_legacy();
			}

			flush_rewrite_rules();
		} catch ( \Exception $e ) {
			self::log( 'Activation error: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Upgrades the schema when the plugin or DB version changed.
	 * Hooked to admin_init from Plugin::boot().
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( ! is_admin() ) {
			return;
		}

		$db_version = get_option( 'bundixo_db_version', '0' );

		if ( version_compare( (string) $db_version, BUNDIXO_DB_VERSION, '>=' ) ) {
			return;
		}

		self::rename_pre_rebrand_table();
		self::run_db_delta();
		update_option( 'bundixo_db_version', BUNDIXO_DB_VERSION, false );
		self::log( 'Database schema updated to version ' . BUNDIXO_DB_VERSION );
	}

	/**
	 * Applies the schema via dbDelta().
	 *
	 * @return void
	 */
	private static function run_db_delta() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_schema_sql() );
	}

	/**
	 * Renames the bundles table from any pre-rebrand name ("bundlecraft_
	 * bundles", "pickpack_bundles") so bundles created before the plugin
	 * was renamed to Bundixo are carried over instead of being orphaned.
	 *
	 * @return void
	 */
	private static function rename_pre_rebrand_table() {
		global $wpdb;

		$new = self::table_name();

		foreach ( [ 'pickpack_bundles', 'bundlecraft_bundles' ] as $legacy ) {
			$old = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix . $legacy );

			if ( ! self::table_exists( $old ) || self::table_exists( $new ) ) {
				continue;
			}

			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- identifiers sanitized via preg_replace above.
			$renamed = $wpdb->query( "RENAME TABLE {$old} TO {$new}" );

			if ( false !== $renamed ) {
				self::log( 'Renamed the bundles table from the previous plugin name.' );
				Bundles::flush_cache();
			} else {
				self::log( 'Bundles table rename failed: ' . $wpdb->last_error, 'error' );
			}
		}
	}

	/**
	 * Schema for the bundles table. Column order matches the legacy table
	 * so a straight INSERT INTO ... SELECT migration is possible.
	 *
	 * @return string
	 */
	public static function get_schema_sql() {
		global $wpdb;

		$table = self::table_name();

		return "CREATE TABLE {$table} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description longtext,
			enabled tinyint(1) DEFAULT 1,
			use_quantity tinyint(1) DEFAULT 0,
			max_quantity int DEFAULT 10,
			discount_tiers longtext,
			product_ids longtext,
			heading_text varchar(255) DEFAULT 'Select Your Products Below',
			hint_text varchar(255) DEFAULT 'Bundle 2, 3, 4 or 5 items and watch the savings grow.',
			primary_color varchar(7) DEFAULT '#6366f1',
			accent_color varchar(7) DEFAULT '#4f46e5',
			hover_bg_color varchar(7) DEFAULT '#eef2ff',
			hover_accent_color varchar(7) DEFAULT '#4338ca',
			button_text_color varchar(7) DEFAULT '#ffffff',
			button_text varchar(255) DEFAULT 'Add Bundle to Cart',
			progress_text varchar(255) DEFAULT 'Your Savings Progress',
			cart_behavior varchar(20) DEFAULT 'sidecart',
			show_bundle_title tinyint(1) DEFAULT 1,
			show_bundle_description tinyint(1) DEFAULT 1,
			show_heading_text tinyint(1) DEFAULT 1,
			show_hint_text tinyint(1) DEFAULT 1,
			show_progress_text tinyint(1) DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			primary key (id)
		)";
	}

	/**
	 * Fully-qualified, sanitized table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		$raw  = $wpdb->prefix . 'bundixo_bundles';
		$name = preg_replace( '/[^A-Za-z0-9_]/', '', $raw );

		return $name ? $name : $raw;
	}

	/**
	 * Copies bundles and settings from the legacy plugin (table prefix
	 * "mmb_") when that plugin was installed before this one.
	 *
	 * @return void
	 */
	public static function migrate_legacy() {
		global $wpdb;

		$legacy_table = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix . 'mmb_bundles' );

		if ( ! self::table_exists( $legacy_table ) ) {
			update_option( self::LEGACY_MIGRATED_OPTION, true, false );
			return;
		}

		$target = self::table_name();

		// Only import when the new table is still empty, so re-activations
		// never overwrite bundles created in Bundixo.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sanitized custom table identifier, read-only check.
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$target}" );

		if ( 0 === $existing ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are sanitized identifiers above.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifiers sanitized via preg_replace above.
			$copied = $wpdb->query( "INSERT INTO {$target} ( name, description, enabled, use_quantity, max_quantity, discount_tiers, product_ids, heading_text, hint_text, primary_color, accent_color, hover_bg_color, hover_accent_color, button_text_color, button_text, progress_text, cart_behavior, show_bundle_title, show_bundle_description, show_heading_text, show_hint_text, show_progress_text, created_at, updated_at ) SELECT name, description, enabled, use_quantity, max_quantity, discount_tiers, product_ids, heading_text, hint_text, primary_color, accent_color, hover_bg_color, hover_accent_color, button_text_color, button_text, progress_text, cart_behavior, show_bundle_title, show_bundle_description, show_heading_text, show_hint_text, show_progress_text, created_at, updated_at FROM {$legacy_table}" );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( false !== $copied ) {
				self::log( sprintf( 'Migrated %d bundle(s) from the legacy plugin.', (int) $copied ) );
				Bundles::flush_cache();
			} else {
				self::log( 'Legacy bundle migration failed: ' . $wpdb->last_error, 'error' );
			}
		}

		// Carry over the legacy logging preference.
		$legacy_logging = get_option( 'mmb_enable_logging' );
		if ( null !== $legacy_logging ) {
			$settings         = Settings::get();
			$settings['enable_logging'] = ( 'yes' === $legacy_logging );
			Settings::update( $settings );
		}

		update_option( self::LEGACY_MIGRATED_OPTION, true, false );
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table_name Sanitized table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return strtolower( (string) $found ) === strtolower( $table_name );
	}

	/**
	 * Logs a message through the WooCommerce logger when logging is enabled.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 * @return void
	 */
	private static function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) || ! Settings::is_logging_enabled() ) {
			return;
		}

		wc_get_logger()->log( $level, $message, [ 'source' => 'bundixo-for-woocommerce' ] );
	}
}
