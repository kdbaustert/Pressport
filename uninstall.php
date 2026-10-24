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
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pressport\\_export\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'pressport_cleanup' );

if ( ! empty( $pressport_settings['remove_meta_on_uninstall'] ) ) {
	foreach ( array( '_pressport_job_id', '_pressport_source_uid', '_pressport_source_id', '_pressport_source_url' ) as $pressport_key ) {
		delete_post_meta_by_key( $pressport_key );
	}
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
