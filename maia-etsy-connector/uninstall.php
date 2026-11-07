<?php
/**
 * Fired on plugin deletion. Removes all plugin data.
 *
 * WooCommerce product descriptions/prices/stock are never touched — only the
 * Etsy-specific meta this plugin added is removed.
 *
 * @package MaiaEtsyConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Options.
delete_option( 'mec_settings' );
delete_option( 'mec_tokens' );
delete_option( 'mec_category_map' );
delete_option( 'mec_db_version' );

// Log table.
$table = $wpdb->prefix . 'mec_sync_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

// Plugin product meta (Etsy-only keys).
$meta_keys = array(
	'_mec_listing_id',
	'_mec_status',
	'_mec_last_sync',
	'_mec_retries',
	'_mec_uploaded_images',
	'_mec_image_stats',
	'_mec_etsy_title',
	'_mec_etsy_desc_en',
	'_mec_etsy_tags',
	'_mec_etsy_price',
	'_mec_etsy_taxonomy_id',
	'_mec_etsy_materials',
);
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
}

// Unschedule any queued actions.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'mec_sync_product', array(), 'mec-sync' );
}

// Transients.
delete_transient( 'mec_taxonomy_nodes' );
