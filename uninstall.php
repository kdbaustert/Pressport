<?php
/**
 * Uninstall: remove job records and cached source files. Imported content is kept.
 *
 * @package PressPort
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( (array) get_option( 'pressport_job_ids', array() ) as $pressport_id ) {
	delete_option( 'pressport_job_' . sanitize_key( $pressport_id ) );
}
delete_option( 'pressport_job_ids' );

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
