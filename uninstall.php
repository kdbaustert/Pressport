<?php
/**
 * Uninstall: remove settings, import history and cached source files.
 * Imported content is always kept.
 *
 * @package PressPort
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$pressport_settings = (array) get_option( 'pressport_settings', array() );

foreach ( (array) get_option( 'pressport_job_ids', array() ) as $pressport_id ) {
	delete_option( 'pressport_job_' . sanitize_key( $pressport_id ) );
}
delete_option( 'pressport_job_ids' );
delete_option( 'pressport_settings' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pressport\\_job\\_%' OR option_name LIKE 'pressport\\_export\\_%' OR option_name LIKE 'pressport\\_exportlog\\_%' OR option_name LIKE 'pressport\\_replace\\_%' OR option_name LIKE 'pressport\\_replacelog\\_%' OR option_name LIKE 'pressport\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'pressport_cleanup' );

if ( ! empty( $pressport_settings['remove_meta_on_uninstall'] ) ) {
	foreach ( array( '_pressport_job_id', '_pressport_source_uid', '_pressport_source_id', '_pressport_source_url', '_pressport_download_source', '_pressport_parent_uid', '_pressport_acf_pending' ) as $pressport_key ) {
		delete_post_meta_by_key( $pressport_key );
	}
	// Product links waiting for a related product to be imported.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_pressport\\_pending\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$pressport_uploads = wp_upload_dir( null, false );
$pressport_dir     = trailingslashit( $pressport_uploads['basedir'] ) . 'pressport/';
if ( is_dir( $pressport_dir ) ) {
	foreach ( (array) scandir( $pressport_dir ) as $pressport_file ) {
		if ( is_file( $pressport_dir . $pressport_file ) ) {
			wp_delete_file( $pressport_dir . $pressport_file );
		}
	}
	@rmdir( $pressport_dir ); // phpcs:ignore
}
