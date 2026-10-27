<?php
/**
 * Exports only carry what the person exporting may see on this site.
 *
 * @package MigratePlus
 */
class Test_Export extends WP_UnitTestCase {

	public function test_emails_and_logins_need_list_users() {
		$writer  = self::factory()->user->create(
			array(
				'user_login'   => 'writer',
				'user_email'   => 'writer@source.test',
				'display_name' => 'Wanda Writer',
			)
		);
		$post_id = self::factory()->post->create( array( 'post_author' => $writer ) );
		$top     = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Guest',
				'comment_author_email' => 'guest@example.test',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_parent'  => $top,
				'user_id'         => $writer,
			)
		);
		$args  = MigratePlus_Exporter::sanitize_args( array( 'include_comments' => 1 ) );
		$field = array(
			'type'     => 'user',
			'multiple' => 0,
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$item = MigratePlus_Exporter::export_post( get_post( $post_id ), $args );
		$this->assertSame( '', $item['author'] );
		$this->assertSame( '', $item['author_login'] );
		$this->assertSame( 'Wanda Writer', $item['author_name'] );
		$this->assertSame( array( '', '' ), array_column( $item['comments'], 'author_email' ) );
		$this->assertSame( array( '', '' ), array_column( $item['comments'], 'user_email' ) );
		$this->assertSame( 'Wanda Writer', MigratePlus_Exporter::export_acf_value( $field, $writer ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$item = MigratePlus_Exporter::export_post( get_post( $post_id ), $args );
		$this->assertSame( 'writer', $item['author'] );
		$this->assertSame( 'writer', $item['author_login'] );
		$this->assertSame( array( 'guest@example.test', '' ), array_column( $item['comments'], 'author_email' ) );
		$this->assertSame( array( '', 'writer@source.test' ), array_column( $item['comments'], 'user_email' ) );
		$this->assertSame(
			array(
				'email' => 'writer@source.test',
				'login' => 'writer',
			),
			MigratePlus_Exporter::export_acf_value( $field, $writer )
		);
	}

	public function test_posts_the_user_cannot_edit_are_left_out() {
		// Editors may write notes but only administrators may edit other people's.
		register_post_type(
			'mp_note',
			array(
				'public'       => true,
				'map_meta_cap' => true,
				'capabilities' => array( 'edit_others_posts' => 'manage_options' ),
			)
		);
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->post->create(
			array(
				'post_type'     => 'mp_note',
				'post_title'    => 'Mine',
				'post_author'   => $editor,
				'post_password' => 'mine-secret',
			)
		);
		$theirs = self::factory()->post->create(
			array(
				'post_type'     => 'mp_note',
				'post_title'    => 'Theirs',
				'post_author'   => self::factory()->user->create( array( 'role' => 'administrator' ) ),
				'post_password' => 'their-secret',
			)
		);
		wp_set_current_user( $editor );

		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'mp_note' ) ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$log = array_column( MigratePlus_Exporter::history_entry( $state['id'] )['log'], 'm' );
		MigratePlus_Exporter::forget( $state['id'] );

		$this->assertSame( array( 'Mine' ), array_column( $doc['items'], 'title' ) );
		$this->assertSame( array( 'mine-secret' ), array_column( $doc['items'], 'password' ) );
		$this->assertSame( 1, $doc['migrateplus']['count'] );
		$this->assertContains( 'Left out 1 item you are not allowed to edit.', $log );
		$this->assertContains( 'Author logins and commenter emails are left out, because you are not allowed to list users. The importing site keeps only the authors\' names with their posts.', $log );
		$this->assertSame( '', MigratePlus_Exporter::export_post( get_post( $theirs ), $state['args'] )['password'] );

		// WP-CLI without --user exports everything, as before.
		wp_set_current_user( 0 );
		$this->assertSame( 'their-secret', MigratePlus_Exporter::export_post( get_post( $theirs ), $state['args'] )['password'] );
		unregister_post_type( 'mp_note' );
	}

	public function test_sanitize_args_refuses_statuses_dates_and_site_sections_it_cannot_read() {
		$cases = array(
			'migrateplus_export_status'        => array( 'statuses' => array( 'trash' ) ),
			'migrateplus_export_date'          => array( 'after' => '2024-02-30' ),
			'migrateplus_export_site_sections' => array(
				'include_site_settings' => 1,
				'site_sections'         => '',
			),
		);
		foreach ( $cases as $code => $args ) {
			$this->assertSame( $code, MigratePlus_Exporter::sanitize_args( $args )->get_error_code() );
		}
		$this->assertSame( array( 'publish', 'draft' ), MigratePlus_Exporter::sanitize_args( array( 'statuses' => 'publish, draft' ) )['statuses'] );
		$this->assertSame( array(), MigratePlus_Exporter::sanitize_args( array( 'site_sections' => '' ) )['site_sections'] );
	}

	public function test_export_file_name_has_a_secret_part_the_download_name_lacks() {
		self::factory()->post->create();
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state );
		}
		MigratePlus_Exporter::forget( $state['id'] );
		$this->assertMatchesRegularExpression( '/^export-' . $state['id'] . '-[a-z0-9]{20}-migrateplus-post-[0-9-]+\.json$/', wp_basename( $state['file'] ) );
		$this->assertStringStartsWith( 'migrateplus-post-', $state['filename'] );
	}

	public function test_a_batch_retried_with_an_old_state_does_not_export_posts_twice() {
		self::factory()->post->create_many( 2 );
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		$stale = $state;

		// While another request holds the export, a batch leaves it alone.
		add_option( 'migrateplus_lock_export_' . $state['id'], ( time() + 60 ) . ':other', '', false );
		MigratePlus_Exporter::run_batch( $state, 1 );
		$this->assertSame( 0, (int) $state['done'] );
		delete_option( 'migrateplus_lock_export_' . $state['id'] );

		MigratePlus_Exporter::run_batch( $state, 1 );
		$this->assertSame( 1, (int) $state['done'] );
		// A retry of the first batch, sent with the state from before it ran.
		MigratePlus_Exporter::run_batch( $stale, 1 );
		$this->assertSame( 2, (int) $stale['done'] );
		while ( empty( $stale['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $stale );
		}
		$doc = json_decode( (string) file_get_contents( $stale['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $stale['id'] );
		$this->assertCount( 2, array_unique( array_column( $doc['items'], 'id' ) ) );
		$this->assertCount( 2, $doc['items'] );
		$this->assertFalse( get_option( 'migrateplus_lock_export_' . $stale['id'] ) );
	}

	/**
	 * A draft has no GMT date until it is published: it travels with its local date instead.
	 */
	public function test_a_draft_exports_its_date() {
		$draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_date'   => '2020-03-04 05:06:07',
			)
		);
		$this->assertSame( '0000-00-00 00:00:00', get_post( $draft )->post_date_gmt );
		$item = MigratePlus_Exporter::export_post( get_post( $draft ), MigratePlus_Exporter::sanitize_args( array() ) );
		$this->assertSame( get_gmt_from_date( '2020-03-04 05:06:07', 'c' ), $item['date_gmt'] );
	}

	public function test_deactivation_removes_finished_exports_and_the_cleanup_event() {
		self::factory()->post->create();
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state );
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'migrateplus_cleanup' );
		do_action( 'deactivate_' . plugin_basename( MIGRATEPLUS_FILE ), false );
		$this->assertFileDoesNotExist( $state['file'] );
		$this->assertNull( MigratePlus_Exporter::load_state( $state['id'] ) );
		$this->assertFalse( wp_next_scheduled( 'migrateplus_cleanup' ) );
		MigratePlus_Exporter::forget( $state['id'] );
	}
}
