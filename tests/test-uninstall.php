<?php
/**
 * Uninstall removes what the plugin stored, and keeps imported content.
 *
 * @package MigratePlus
 */
class Test_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_removes_locks_pending_pull_keys_and_tracking_meta() {
		global $wpdb;
		update_option( 'migrateplus_settings', array( 'remove_meta_on_uninstall' => 1 ) );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_migrateplus_source_order_number', '1001' );
		update_post_meta( $post, '_migrateplus_block_source', '<!-- wp:block {"ref":7} /-->' );
		update_post_meta( $post, 'kept', 'yes' );
		add_option( 'migrateplus_replacelock_abc', ( time() + 60 ) . ':token', '', false );
		set_transient( MigratePlus_Settings::NEW_PULL_KEY_TRANSIENT . '1', 'secret', 10 * MINUTE_IN_SECONDS );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( MIGRATEPLUS_FILE ) );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertSame( array(), get_post_meta( $post, '_migrateplus_source_order_number', false ) );
		$this->assertSame( array(), get_post_meta( $post, '_migrateplus_block_source', false ) );
		$this->assertSame( 'yes', get_post_meta( $post, 'kept', true ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%migrateplus\\_replacelock\\_%' OR option_name LIKE '%migrateplus\\_new\\_pull\\_key\\_%'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotNull( get_post( $post ) );
	}
}
