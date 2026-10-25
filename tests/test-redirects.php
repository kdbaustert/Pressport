<?php
/**
 * Redirects from an imported post's old address to its new one.
 *
 * @package MigratePlus
 */
class Test_Redirects extends WP_UnitTestCase {

	/**
	 * Where wp_safe_redirect() wanted to go.
	 *
	 * @var string
	 */
	protected $redirected = '';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MigratePlus_Redirects::flush(); // What imports in earlier tests left unwritten.
		delete_option( MigratePlus_Redirects::OPTION );
		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );
		delete_option( MigratePlus_Redirects::OPTION );
		parent::tear_down();
	}

	public function catch_redirect( $location, $status ) {
		$this->redirected = $status . ' ' . $location;
		return false; // No header, no exit.
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

	protected function doc( $slug, $link ) {
		return array(
			'migrateplus' => array(
				'version' => '1',
				'site'    => 'http://old.test/blog',
				'mapping' => array(
					'fields' => array(
						'source_id'   => 'id',
						'post_title'  => 'title',
						'post_name'   => 'slug',
						'post_status' => 'status',
						'source_url'  => 'link',
					),
				),
			),
			'items'       => array(
				array(
					'id'     => 41,
					'title'  => 'Moved',
					'slug'   => $slug,
					'status' => 'publish',
					'link'   => $link,
				),
			),
		);
	}

	public function test_paths_compare_without_scheme_host_query_or_slashes() {
		$this->assertSame( 'news/hello', MigratePlus_Redirects::path( 'https://old.test/News/Hello/?utm=1', 'https://old.test' ) );
		$this->assertSame( 'hello', MigratePlus_Redirects::path( 'http://old.test/blog/hello', 'http://old.test/blog/' ) );
		$this->assertSame( '', MigratePlus_Redirects::path( 'http://old.test/blog/', 'http://old.test/blog' ) );
		$this->assertSame( 'café', MigratePlus_Redirects::path( 'http://old.test/caf%C3%A9/' ) );
	}

	public function test_a_post_whose_address_changed_redirects_and_rollback_forgets_it() {
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_title' => 'Taken',
				'post_name'  => 'hello',
			)
		);
		// The same slug is taken here, so the import lands on hello-2: the old address needs a redirect.
		$file = $this->json_file( $this->doc( 'hello', 'http://old.test/blog/2024/hello/' ) );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$posts = get_posts(
			array(
				'title'      => 'Moved',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$post  = $posts[0];
		$this->assertSame( 'hello-2', $post->post_name );
		$this->assertSame( array( '2024/hello' => array( $post->ID, $job->data['id'] ) ), MigratePlus_Redirects::all() );
		$this->assertSame( 1, MigratePlus_Redirects::count_for_job( $job->data['id'] ) );

		$this->go_to( home_url( '/2024/hello/' ) );
		$this->assertTrue( is_404() );
		MigratePlus_Redirects::maybe_redirect();
		$this->assertSame( '301 ' . get_permalink( $post ), $this->redirected );

		// A page that exists is never touched, nor one nobody imported.
		$this->redirected = '';
		$this->go_to( home_url( '/hello/' ) );
		MigratePlus_Redirects::maybe_redirect();
		$this->assertSame( '', $this->redirected );
		$this->go_to( home_url( '/nowhere/' ) );
		MigratePlus_Redirects::maybe_redirect();
		$this->assertSame( '', $this->redirected );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( array(), MigratePlus_Redirects::all() );
		$job->delete();
	}

	public function test_no_redirect_when_the_address_is_the_same_or_the_option_is_off() {
		$this->set_permalink_structure( '/%postname%/' );
		$file = $this->json_file( $this->doc( 'same', 'http://old.test/blog/same/' ) );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( array(), MigratePlus_Redirects::all() );
		$job->delete();

		self::factory()->post->create( array( 'post_name' => 'other' ) );
		$file = $this->json_file( $this->doc( 'other', 'http://old.test/blog/other/' ) );
		$job  = $this->import_file( $file, array( 'import_redirects' => 0 ) );
		wp_delete_file( $file );
		$this->assertSame( array(), MigratePlus_Redirects::all() );
		$job->delete();
	}
	public function test_redirects_fire_when_wordpress_lives_in_a_subdirectory() {
		update_option( 'home', 'http://example.org/blog' );
		$this->set_permalink_structure( '/%postname%/' );
		$post = self::factory()->post->create( array( 'post_name' => 'hello-2' ) );
		update_option( MigratePlus_Redirects::OPTION, array( '2024/hello' => array( $post, 'abc' ) ) );

		$this->go_to( home_url( '/2024/hello/' ) );
		$this->assertTrue( is_404() );
		MigratePlus_Redirects::maybe_redirect();
		$this->assertSame( '301 http://example.org/blog/hello-2/', $this->redirected );
	}

	public function test_an_old_query_string_address_redirects() {
		$this->set_permalink_structure( '/%postname%/' );
		$file = $this->json_file( $this->doc( 'moved', 'http://old.test/blog/?p=987654' ) );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$post = get_posts(
			array(
				'title'      => 'Moved',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		)[0];
		$this->assertSame( array( '?p=987654' => array( $post->ID, $job->data['id'] ) ), MigratePlus_Redirects::all() );

		$this->go_to( home_url( '/?p=987654' ) );
		$this->assertTrue( is_404() );
		MigratePlus_Redirects::maybe_redirect();
		$this->assertSame( '301 ' . get_permalink( $post ), $this->redirected );
		$job->delete();
	}

	public function test_redirects_are_written_once_at_the_end_of_the_request() {
		$job  = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$post = self::factory()->post->create( array( 'post_name' => 'new-name' ) );
		MigratePlus_Redirects::record( $post, 'http://old.test/old-name/', $job );
		$this->assertFalse( get_option( MigratePlus_Redirects::OPTION ) );
		$this->assertSame( array( 'old-name' => array( $post, $job->data['id'] ) ), MigratePlus_Redirects::all() );

		MigratePlus_Redirects::flush();
		$this->assertSame( array( 'old-name' => array( $post, $job->data['id'] ) ), get_option( MigratePlus_Redirects::OPTION ) );
		$job->delete();
	}

	public function test_rolling_back_a_later_import_of_the_same_path_keeps_the_earlier_redirect() {
		$first  = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$second = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$one    = self::factory()->post->create( array( 'post_name' => 'one' ) );
		$two    = self::factory()->post->create( array( 'post_name' => 'two' ) );
		MigratePlus_Redirects::record( $one, 'http://old.test/shared/', $first );
		MigratePlus_Redirects::record( $two, 'http://old.test/shared/', $second );
		$this->assertSame( 1, MigratePlus_Redirects::count_for_job( $second->data['id'] ) );
		$this->assertSame( 0, MigratePlus_Redirects::count_for_job( $first->data['id'] ) );

		$this->assertSame( 1, MigratePlus_Redirects::forget_job( $second->data['id'] ) );
		$this->assertSame( array( 'shared' => array( $one, $first->data['id'] ) ), MigratePlus_Redirects::all() );
		$first->delete();
		$second->delete();
	}
}
