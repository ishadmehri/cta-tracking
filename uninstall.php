<?php
/**
 * Removes clicks and settings only when the admin asked for it in the settings: deleting the plugin to upload a
 * new version must not lose months of data.
 *
 * @package CtaTracking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cta_tracking_settings = get_option( 'cta_tracking_settings' );
if ( is_array( $cta_tracking_settings ) && ! empty( $cta_tracking_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cta_clicks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( 'cta_tracking_settings' );
	delete_option( 'cta_tracking_charts' );
	delete_option( 'cta_tracking_db' );
	$cta_tracking_admin = get_role( 'administrator' );
	if ( $cta_tracking_admin ) {
		$cta_tracking_admin->remove_cap( 'view_cta_reports' );
	}
}
