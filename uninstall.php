<?php
/**
 * Uninstall: remove settings, import history and cached source files.
 * Imported content is always kept.
 *
 * @package MigratePlus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$migrateplus_settings = (array) get_option( 'migrateplus_settings', array() );

foreach ( (array) get_option( 'migrateplus_job_ids', array() ) as $migrateplus_id ) {
	delete_option( 'migrateplus_job_' . sanitize_key( $migrateplus_id ) );
}
delete_option( 'migrateplus_job_ids' );
delete_option( 'migrateplus_settings' );
delete_option( 'migrateplus_mappings' );
delete_option( 'migrateplus_renamed' );
delete_transient( 'migrateplus_storage_probe' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'migrateplus\\_job\\_%' OR option_name LIKE 'migrateplus\\_export\\_%' OR option_name LIKE 'migrateplus\\_exportlog\\_%' OR option_name LIKE 'migrateplus\\_replace\\_%' OR option_name LIKE 'migrateplus\\_replacelog\\_%' OR option_name LIKE 'migrateplus\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'migrateplus_cleanup' );

if ( ! empty( $migrateplus_settings['remove_meta_on_uninstall'] ) ) {
	foreach ( array( '_migrateplus_job_id', '_migrateplus_source_uid', '_migrateplus_source_id', '_migrateplus_source_url', '_migrateplus_download_source', '_migrateplus_parent_uid', '_migrateplus_acf_pending' ) as $migrateplus_key ) {
		delete_post_meta_by_key( $migrateplus_key );
	}
	foreach ( array( '_migrateplus_job_id', '_migrateplus_source_comment_uid' ) as $migrateplus_key ) {
		delete_metadata( 'comment', 0, $migrateplus_key, '', true );
	}
	delete_metadata( 'term', 0, '_migrateplus_job_id', '', true ); // Menus an import created.
	// Product links waiting for a related product to be imported.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_migrateplus\\_pending\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$migrateplus_uploads = wp_upload_dir( null, false );
$migrateplus_dir     = trailingslashit( $migrateplus_uploads['basedir'] ) . 'migrateplus/';
if ( is_dir( $migrateplus_dir ) ) {
	foreach ( (array) scandir( $migrateplus_dir ) as $migrateplus_file ) {
		if ( is_file( $migrateplus_dir . $migrateplus_file ) ) {
			wp_delete_file( $migrateplus_dir . $migrateplus_file );
		}
	}
	@rmdir( $migrateplus_dir ); // phpcs:ignore
}
