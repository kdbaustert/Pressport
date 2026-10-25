<?php
/**
 * The users section of MigratePlus exports: profiles, roles, custom fields and
 * password hashes travel, re-imports update, and rollback removes created users.
 *
 * @package MigratePlus
 */
class Test_Users extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function json_file( array $doc ) {
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	protected function import_file( $file, array $options = array() ) {
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		$this->assertIsArray( $preview );
		$job                  = $preview['job'];
		$job->data['options'] = array_merge( $job->data['options'], $options );
		$job->save();
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			$this->assertIsArray( $result );
		} while ( ! $result['done'] );
		return $job;
	}

	protected function export_doc( array $args ) {
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( $args ) );
		$this->assertIsArray( $state );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 10 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );
		return $doc;
	}

	protected function writer() {
		$id = self::factory()->user->create(
			array(
				'user_login'   => 'wanda',
				'user_email'   => 'wanda@old.test',
				'user_pass'    => 'pass-on-the-old-site',
				'display_name' => 'Wanda Writer',
				'first_name'   => 'Wanda',
				'last_name'    => 'Writer',
				'description'  => 'Writes things.',
				'role'         => 'editor',
			)
		);
		add_user_meta( $id, 'billing_city', 'Dundee' );
		return $id;
	}

	protected function user_record( array $doc, $login ) {
		foreach ( $doc['users'] as $i => $u ) {
			if ( $login === $u['login'] ) {
				return $i;
			}
		}
		$this->fail( 'No user ' . $login . ' in the file.' );
	}

	public function test_users_export_alone_and_round_trip_with_roles_meta_and_password() {
		$writer = $this->writer();
		$hash   = get_userdata( $writer )->user_pass;
		self::factory()->post->create(
			array(
				'post_title'  => 'By Wanda',
				'post_author' => $writer,
			)
		);

		$doc = $this->export_doc(
			array(
				'post_types'    => array(),
				'include_users' => 1,
			)
		);
		$this->assertSame( array(), $doc['items'] );
		$this->assertSame( array(), $doc['migrateplus']['post_types'] );
		$wanda = $doc['users'][ $this->user_record( $doc, 'wanda' ) ];
		$this->assertSame( 'wanda@old.test', $wanda['email'] );
		$this->assertSame( array( 'editor' ), $wanda['roles'] );
		$this->assertSame( 'Dundee', $wanda['meta']['billing_city'] );
		foreach ( array_keys( $wanda['meta'] ) as $key ) {
			$this->assertStringNotContainsString( 'capabilities', $key );
			$this->assertStringNotContainsString( 'user_level', $key );
			$this->assertNotSame( 'session_tokens', $key );
		}
		$this->assertSame( $hash, $wanda['pass'] );

		// Posts and users in one file: the post lands with its author, created first.
		$doc                        = $this->export_doc(
			array(
				'post_types'    => array( 'post' ),
				'include_users' => 1,
			)
		);
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		wp_delete_user( $writer );
		$this->assertFalse( get_user_by( 'login', 'wanda' ) );

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame( array( 'terms', 'users', 'post' ), array_column( $job->data['sections'], 'type' ) ); // Users before the posts.
		$new = get_user_by( 'login', 'wanda' );
		$this->assertInstanceOf( 'WP_User', $new );
		$this->assertSame( array( 'editor' ), $new->roles );
		$this->assertSame( 'Writes things.', $new->description );
		$this->assertSame( 'Dundee', get_user_meta( $new->ID, 'billing_city', true ) );
		$this->assertSame( $hash, $new->user_pass );
		$this->assertTrue( wp_check_password( 'pass-on-the-old-site', $new->user_pass, $new->ID ) );
		$this->assertSame( $job->data['id'], get_user_meta( $new->ID, '_migrateplus_job_id', true ) );
		$post = get_posts(
			array(
				'title'      => 'By Wanda',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertSame( $new->ID, (int) $post[0]->post_author );

		// Importing again updates the profile and keeps the account, roles and password.
		$i                                  = $this->user_record( $doc, 'wanda' );
		$doc['users'][ $i ]['display_name'] = 'Wanda W.';
		$doc['users'][ $i ]['roles']        = array( 'administrator' );
		$doc['users'][ $i ]['pass']         = 'not-a-hash';
		$file                               = $this->json_file( $doc );
		$again                              = $this->import_file( $file, array( 'duplicate' => 'update' ) );
		wp_delete_file( $file );
		$this->assertCount( 1, get_users( array( 'login' => 'wanda' ) ) );
		$updated = get_user_by( 'login', 'wanda' );
		$this->assertSame( 'Wanda W.', $updated->display_name );
		$this->assertSame( array( 'editor' ), $updated->roles );
		$this->assertSame( $hash, $updated->user_pass );
		$this->assertSame( 0, $again->data['created_total'] );

		// Rollback of the first import removes the user it created, and the posts.
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( get_user_by( 'login', 'wanda' ) );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$job->delete();
		$again->delete();
	}

	public function test_password_hashes_and_the_section_need_the_right_access() {
		$this->writer();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$args = MigratePlus_Exporter::sanitize_args(
			array(
				'post_types'    => array(),
				'include_users' => 1,
			)
		);
		// Editors may not list users: the section is empty for them.
		$this->assertSame( 0, MigratePlus_Users::count( $args ) );
		$this->assertSame( array(), MigratePlus_Users::export( $args ) );

		// A role that lists but may not edit users gets no hashes.
		add_role(
			'mp_lister',
			'Lister',
			array(
				'read'       => true,
				'list_users' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'mp_lister' ) ) );
		$users = MigratePlus_Users::export( $args );
		$this->assertNotEmpty( $users );
		foreach ( $users as $u ) {
			$this->assertArrayNotHasKey( 'pass', $u );
		}
		remove_role( 'mp_lister' );
	}

	public function test_roles_this_site_lacks_or_the_importer_may_not_give_are_dropped() {
		$doc  = array(
			'migrateplus' => array(
				'version' => '1',
				'site'    => 'http://old.test',
				'mapping' => array( 'fields' => array() ),
			),
			'items'       => array(),
			'users'       => array(
				array(
					'id'           => 7,
					'login'        => 'carl',
					'email'        => 'carl@old.test',
					'display_name' => 'Carl',
					'roles'        => array( 'shop_manager_from_elsewhere', 'administrator', 'author' ),
				),
			),
		);
		$file = $this->json_file( $doc );
		// An editor who may create and promote users, but is no administrator.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user   = get_userdata( $editor );
		$user->add_cap( 'create_users' );
		$user->add_cap( 'promote_users' );
		wp_set_current_user( $editor );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$carl = get_user_by( 'login', 'carl' );
		$this->assertInstanceOf( 'WP_User', $carl );
		$this->assertSame( array( 'author' ), $carl->roles );
		$log = implode( "\n", array_column( $job->data['log'], 'm' ) );
		$this->assertStringContainsString( 'Role "shop_manager_from_elsewhere" does not exist on this site', $log );
		$this->assertStringContainsString( 'You may not give the "administrator" role', $log );
		$job->delete();
	}
}
