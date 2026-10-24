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
		$this->assertSame( 'writer@source.test', $item['author'] );
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
		$this->assertContains( 'Author and commenter emails and logins are left out, because you are not allowed to list users. The importing site matches authors by display name, so two people with the same name become one.', $log );
		$this->assertSame( '', MigratePlus_Exporter::export_post( get_post( $theirs ), $state['args'] )['password'] );

		// WP-CLI without --user exports everything, as before.
		wp_set_current_user( 0 );
		$this->assertSame( 'their-secret', MigratePlus_Exporter::export_post( get_post( $theirs ), $state['args'] )['password'] );
		unregister_post_type( 'mp_note' );
	}
}
