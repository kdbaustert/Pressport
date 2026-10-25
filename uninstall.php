<?php
/**
 * Uninstall: remove settings, import history and cached source files.
 * Imported content is always kept.
 *
 * @package MigratePlus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the current site's settings, history and files, and the tracking meta on its
 * content when its settings ask for that.
 */
function migrateplus_uninstall_site() {
	global $wpdb;
	$settings = (array) get_option( 'migrateplus_settings', array() );

	foreach ( (array) get_option( 'migrateplus_job_ids', array() ) as $id ) {
		delete_option( 'migrateplus_job_' . sanitize_key( $id ) );
	}
	delete_option( 'migrateplus_job_ids' );
	delete_option( 'migrateplus_settings' );
	delete_option( 'migrateplus_mappings' );
	delete_option( 'migrateplus_renamed' );
	delete_option( 'migrateplus_redirects' );
	delete_transient( 'migrateplus_storage_probe' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'migrateplus\\_job\\_%' OR option_name LIKE 'migrateplus\\_export\\_%' OR option_name LIKE 'migrateplus\\_exportlog\\_%' OR option_name LIKE 'migrateplus\\_replace\\_%' OR option_name LIKE 'migrateplus\\_replacelog\\_%' OR option_name LIKE 'migrateplus\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_clear_scheduled_hook( 'migrateplus_cleanup' );

	if ( ! empty( $settings['remove_meta_on_uninstall'] ) ) {
		foreach ( array( '_migrateplus_job_id', '_migrateplus_source_uid', '_migrateplus_source_id', '_migrateplus_source_url', '_migrateplus_download_source', '_migrateplus_parent_uid', '_migrateplus_acf_pending', '_migrateplus_pending_ref', '_migrateplus_incomplete', '_migrateplus_source_author', '_migrateplus_source_author_name' ) as $key ) {
			delete_post_meta_by_key( $key );
		}
		foreach ( array( '_migrateplus_job_id', '_migrateplus_source_comment_uid' ) as $key ) {
			delete_metadata( 'comment', 0, $key, '', true );
		}
		// Menus and terms an import created, and term fields waiting for the post they name.
		foreach ( array( '_migrateplus_job_id', '_migrateplus_source_uid', '_migrateplus_acf_pending' ) as $key ) {
			delete_metadata( 'term', 0, $key, '', true );
		}
		// Users imported by versions that still had a users section.
		foreach ( array( '_migrateplus_job_id', '_migrateplus_source_uid' ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		// Product links waiting for a related product to be imported.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_migrateplus\\_pending\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Orders an import created, when WooCommerce keeps them in its own tables.
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key LIKE '\\_migrateplus\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is WordPress's prefix and a fixed name.
		}
	}

	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'migrateplus/';
	if ( is_dir( $dir ) ) {
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_file( $dir . $file ) ) {
				wp_delete_file( $dir . $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}

// On a network every site has its own options, tables and uploads folder.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $migrateplus_site ) {
		switch_to_blog( (int) $migrateplus_site );
		migrateplus_uninstall_site();
		restore_current_blog();
	}
} else {
	migrateplus_uninstall_site();
}
