<?php
/**
 * The admin AJAX endpoints: nonce and capability checks, who may use a job or an
 * export, and what each endpoint answers.
 *
 * media_preview and export_download exit() once they stream their file, so only
 * their refusals are covered.
 *
 * @package MigratePlus
 */

require_once __DIR__ . '/class-migrateplus-test-source.php';

class Test_Admin_Ajax extends WP_Ajax_UnitTestCase {

	protected $files = array();

	public function set_up() {
		parent::set_up();
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		MigratePlus_Admin::init();
		add_filter( 'migrateplus_sources', array( $this, 'add_books_source' ) );
	}

	public function tear_down() {
		// Jobs, exports and runs keep files in the storage folder, which the DB rollback leaves behind.
		foreach ( MigratePlus_Job::all() as $job ) {
			$job->delete();
		}
		foreach ( MigratePlus_Exporter::history() as $entry ) {
			MigratePlus_Exporter::forget( $entry['id'] );
		}
		foreach ( MigratePlus_Replace::history() as $entry ) {
			MigratePlus_Replace::forget( $entry['id'] );
		}
		array_map( 'wp_delete_file', $this->files );
		$_FILES = array();
		delete_option( MigratePlus_Settings::OPTION );
		MigratePlus_Settings::flush_cache();
		parent::tear_down();
	}

	public function add_books_source( $sources ) {
		$sources['books'] = 'MigratePlus_Test_Source';
		return $sources;
	}

	/**
	 * Call an endpoint as the current user.
	 *
	 * @param string            $action Action without the migrateplus_ prefix.
	 * @param array             $post   Request fields, unslashed.
	 * @param string|false|null $nonce  Nonce to send: null for a valid one, false for none.
	 * @return array The JSON response, or { die: message } when the endpoint died without one.
	 */
	protected function ajax( $action, array $post = array(), $nonce = null ) {
		$_POST = wp_slash( $post );
		if ( false !== $nonce ) {
			$_POST['nonce'] = null === $nonce ? wp_create_nonce( 'migrateplus_admin' ) : $nonce;
		}
		$_GET                 = array();
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'migrateplus_' . $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			return array( 'die' => $e->getMessage() );
		}
		$out = json_decode( $this->_last_response, true );
		$this->assertIsArray( $out, 'Not JSON: ' . $this->_last_response );
		return $out;
	}

	protected function assertRefused( $response, $message ) {
		$this->assertFalse( $response['success'] );
		$this->assertSame( $message, $response['data']['message'] );
	}

	protected function login( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	protected function give_editors_access() {
		update_option( MigratePlus_Settings::OPTION, array( 'capability' => 'edit_others_posts' ) );
		MigratePlus_Settings::flush_cache();
	}

	protected function preview_books( $count = 3 ) {
		$out = $this->ajax(
			'preview',
			array(
				'source'   => 'books',
				'settings' => array( 'count' => (string) $count ),
			)
		);
		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		return $out['data']['job']['id'];
	}

	protected function import_books( $count = 3 ) {
		$id = $this->preview_books( $count );
		$this->ajax(
			'save',
			array(
				'job'     => $id,
				'mapping' => wp_json_encode(
					array(
						'fields' => array(
							'source_id'  => 'isbn',
							'post_title' => 'title',
						),
					)
				),
				'options' => wp_json_encode( array() ),
			)
		);
		do {
			$run = $this->ajax( 'run', array( 'job' => $id ) );
		} while ( ! $run['data']['done'] );
		return $id;
	}

	protected function finished_export() {
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 10 );
		}
		return $state['id'];
	}

	protected function json_job( array $doc ) {
		$file = wp_tempnam( 'migrateplus-ajax.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->files[] = $file;
		return MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'];
	}

	/**
	 * Save a job's mapping and options as the mapping screen does, then run it to the end.
	 *
	 * @param string $id      Job id.
	 * @param array  $mapping Mapping.
	 * @param array  $options Options.
	 * @return array The last run's data.
	 */
	protected function save_and_run( $id, array $mapping, array $options ) {
		$saved = $this->ajax(
			'save',
			array(
				'job'     => $id,
				'mapping' => wp_json_encode( $mapping ),
				'options' => wp_json_encode( $options ),
			)
		);
		$this->assertTrue( $saved['success'], wp_json_encode( $saved ) );
		$batches = 0;
		do {
			$run = $this->ajax( 'run', array( 'job' => $id ) );
			$this->assertTrue( $run['success'], wp_json_encode( $run ) );
			if ( ++$batches > 10 ) {
				$this->fail( 'The import was still not done after 10 batches.' );
			}
		} while ( ! $run['data']['done'] );
		return $run['data'];
	}

	public function actions() {
		$actions = array( 'preview', 'users', 'records', 'duplicates', 'media_preview', 'save', 'run', 'rollback', 'delete', 'history_delete', 'jobs', 'job', 'restart', 'export_count', 'export_posts', 'export_start', 'export_run', 'export_download', 'exports', 'export_log', 'export_forget', 'replace_start', 'replace_run', 'replace_history', 'replace_rollback', 'replace_forget', 'mapping_save', 'mapping_rename', 'mapping_delete' );
		return array_combine( $actions, array_chunk( $actions, 1 ) );
	}

	/**
	 * @dataProvider actions
	 *
	 * @param string $action Action.
	 */
	public function test_refuses_bad_nonces_and_users_without_access( $action ) {
		$this->login( 'administrator' );
		$this->assertRefused( $this->ajax( $action, array(), false ), 'Your session expired. Reload the page.' );
		$this->assertRefused( $this->ajax( $action, array(), 'abc123' ), 'Your session expired. Reload the page.' );
		$this->login( 'editor' );
		$this->assertRefused( $this->ajax( $action ), 'You are not allowed to do this.' );
		$this->give_editors_access();
		$this->login( 'subscriber' );
		$this->assertRefused( $this->ajax( $action ), 'You are not allowed to do this.' );
	}

	public function test_find_and_replace_stays_admin_only() {
		$this->give_editors_access();
		$this->login( 'editor' );
		foreach ( array( 'replace_start', 'replace_run', 'replace_history', 'replace_rollback', 'replace_forget' ) as $action ) {
			$this->assertRefused( $this->ajax( $action ), 'Only administrators can use Find & Replace.' );
		}
		$this->assertTrue( $this->ajax( 'jobs' )['success'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_find_and_replace_is_for_network_administrators_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$this->login( 'administrator' );
		$this->assertRefused( $this->ajax( 'replace_history' ), 'Only network administrators can use Find & Replace.' );
		grant_super_admin( get_current_user_id() );
		$this->assertTrue( $this->ajax( 'replace_history' )['success'] );
	}

	public function test_preview() {
		$this->login( 'administrator' );
		$out = $this->ajax(
			'preview',
			array(
				'source'   => 'books',
				'settings' => array( 'count' => '3' ),
			)
		);
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'draft', $out['data']['job']['status'] );
		$this->assertSame( 3, $out['data']['job']['total'] );
		$this->assertArrayNotHasKey( 'cursor', $out['data']['job'] );
		$this->assertSame( array( 'Dune', 'Emma', 'Ulysses' ), array_column( $out['data']['records'], 'title' ) );
		$this->assertSame( array( 'isbn', 'title', 'blurb' ), $out['data']['fields'] );
		$this->assertSame( '', $out['data']['savedMapping'] );

		MigratePlus_Mappings::save( 'Books', array(), array(), array( 'title', 'blurb', 'isbn' ) );
		$this->preview_books();
		$this->assertSame( 'Books', json_decode( $this->_last_response, true )['data']['savedMapping'] );
	}

	public function test_preview_refusals() {
		$this->login( 'administrator' );
		$this->assertRefused( $this->ajax( 'preview', array( 'source' => 'nope' ) ), 'Choose a source.' );
		$this->assertRefused( $this->ajax( 'preview', array( 'source' => 'books' ) ), 'Books is required.' );
		$this->assertRefused( $this->ajax( 'preview', array( 'source' => 'json' ) ), 'Upload a JSON file.' );
		$this->assertSame( array(), MigratePlus_Job::all() );
	}

	public function test_preview_of_a_source_whose_class_is_missing() {
		$this->login( 'administrator' );
		add_filter(
			'migrateplus_sources',
			static function ( $sources ) {
				return $sources + array( 'ghost' => 'MigratePlus_No_Such_Source' );
			}
		);
		$out = $this->ajax( 'preview', array( 'source' => 'ghost' ) );
		$this->assertFalse( $out['success'] );
	}

	/**
	 * A $_FILES entry made up in a test is refused the way a forged one is, by
	 * is_uploaded_file(), so only the checks before it can be seen working.
	 */
	public function test_preview_checks_the_upload() {
		$this->login( 'administrator' );
		$tmp = wp_tempnam( 'migrateplus-upload' );
		file_put_contents( $tmp, '[{"title":"Hello"}]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->files[] = $tmp;
		$stored        = glob( MigratePlus_Util::ensure_storage_dir() . 'upload-*' );
		$cases         = array(
			array( 'posts.json', UPLOAD_ERR_INI_SIZE, 'Upload failed (error 1). Check the file size limit.' ),
			array( 'posts.php', UPLOAD_ERR_OK, 'Unsupported file type. Allowed: json, gz, txt' ),
			array( 'posts.json', UPLOAD_ERR_OK, 'Invalid upload.' ),
		);
		foreach ( $cases as list( $name, $error, $message ) ) {
			$_FILES['file_file'] = array(
				'name'     => $name,
				'type'     => 'application/json',
				'tmp_name' => $tmp,
				'error'    => $error,
				'size'     => filesize( $tmp ),
			);
			$this->assertRefused( $this->ajax( 'preview', array( 'source' => 'json' ) ), $message );
		}
		$this->assertSame( $stored, glob( MigratePlus_Util::storage_dir() . 'upload-*' ) );
		$this->assertFileExists( $tmp );
	}

	public function test_save_cleans_the_mapping_and_options() {
		$this->login( 'administrator' );
		$job   = $this->json_job(
			array(
				array(
					'id'    => 1,
					'title' => 'Hello',
				),
			)
		);
		$out   = $this->ajax(
			'save',
			array(
				'job'     => $job->data['id'],
				'mapping' => wp_json_encode(
					array(
						'fields' => array(
							'post_title' => 'title',
							'bogus'      => 'id',
						),
					)
				),
				'options' => wp_json_encode(
					array(
						'post_type'  => 'page',
						'batch_size' => 500,
						'dry_run'    => 1,
					)
				),
			)
		);
		$saved = $out['data']['job'];
		$this->assertSame( 'ready', $saved['status'] );
		$this->assertSame( array( 'post_title' => 'title' ), $saved['mapping']['fields'] );
		$this->assertSame( 'page', $saved['options']['post_type'] );
		$this->assertSame( 100, $saved['options']['batch_size'] );
		$this->assertArrayNotHasKey( 'cache_file', $saved['settings'] );
		$this->assertSame( 'Dry run started, nothing will be saved.', end( $saved['log'] )['m'] );

		$this->assertRefused(
			$this->ajax(
				'save',
				array(
					'job'     => $job->data['id'],
					'mapping' => wp_json_encode( array( 'fields' => array( 'source_id' => 'id' ) ) ),
				)
			),
			'Map at least the Title or Content field.'
		);
	}

	public function test_save_refuses_a_post_type_the_user_cannot_edit() {
		register_post_type(
			'mp_invoice',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_posts' => 'manage_options' ),
			)
		);
		$this->give_editors_access();
		$this->login( 'editor' );
		$out = $this->ajax(
			'save',
			array(
				'job'     => $this->preview_books(),
				'mapping' => wp_json_encode( array( 'fields' => array( 'post_title' => 'title' ) ) ),
				'options' => wp_json_encode( array( 'post_type' => 'mp_invoice' ) ),
			)
		);
		$this->assertRefused( $out, 'You are not allowed to import this post type.' );
		unregister_post_type( 'mp_invoice' );
	}

	public function test_run_in_batches() {
		$this->login( 'administrator' );
		$id = $this->preview_books( 3 );
		$this->ajax(
			'save',
			array(
				'job'     => $id,
				'mapping' => wp_json_encode( array( 'fields' => array( 'post_title' => 'title' ) ) ),
				'options' => wp_json_encode( array( 'batch_size' => 2 ) ),
			)
		);

		$first = $this->ajax( 'run', array( 'job' => $id ) );
		$this->assertFalse( $first['data']['done'] );
		$this->assertSame( 2, $first['data']['job']['stats']['processed'] );
		$this->assertCount( 2, $first['data']['results'] );
		$last = $this->ajax( 'run', array( 'job' => $id ) );
		$this->assertTrue( $last['data']['done'] );
		$this->assertSame( 'complete', $last['data']['job']['status'] );
		$this->assertSame( 3, $last['data']['job']['stats']['created'] );
	}

	public function test_side_section_switches_saved_from_the_mapping_screen_are_followed() {
		$this->login( 'administrator' );
		$job   = $this->json_job(
			array(
				'migrateplus'   => array(
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => array( 'source_id' => 'id' ) ),
				),
				'items'         => array(),
				'site_settings' => array( 'options' => array( 'blogname' => 'Moved here' ) ),
			)
		);
		$saved = $this->ajax(
			'save',
			array(
				'job'     => $job->data['id'],
				'mapping' => wp_json_encode( array() ),
				'options' => wp_json_encode( array( 'import_site_settings' => 1 ) ),
			)
		);
		$this->assertTrue( $saved['success'] );
		do {
			$run = $this->ajax( 'run', array( 'job' => $job->data['id'] ) );
		} while ( ! $run['data']['done'] );
		$this->assertSame( 1, MigratePlus_Job::load( $job->data['id'] )->data['options']['import_site_settings'] );
		$this->assertSame( 'Moved here', get_option( 'blogname' ) );
	}

	/**
	 * @param string $id Job id.
	 * @return int[] The author of each imported post, in the file's order.
	 */
	protected function authors_of_books( $id ) {
		$posts = get_posts(
			array(
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_key'    => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) );
	}

	public function test_posts_go_to_the_person_importing_when_no_author_is_picked() {
		$this->login( 'administrator' );
		$me         = get_current_user_id();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$mapping    = array( 'fields' => array( 'post_title' => 'title' ) );
		// Someone who may not write posts, or no one at all, is dropped rather than refused.
		foreach ( array( array(), array( 'author' => 999999 ), array( 'author' => $subscriber ) ) as $options ) {
			$id = $this->preview_books();
			$this->save_and_run( $id, $mapping, $options );
			$this->assertSame( 0, MigratePlus_Job::load( $id )->data['options']['author'] );
			$this->assertSame( array( $me, $me, $me ), $this->authors_of_books( $id ) );
		}
	}

	public function test_the_author_picked_for_all_and_for_single_records() {
		$this->login( 'administrator' );
		// The picker lists anyone who may write posts, so a contributor is accepted for pages too.
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$id          = $this->preview_books();
		$this->save_and_run(
			$id,
			array( 'fields' => array( 'post_title' => 'title' ) ),
			array(
				'author'    => $contributor,
				'authors'   => array( '1' => $editor ),
				'post_type' => 'page',
			)
		);
		$this->assertSame( $contributor, MigratePlus_Job::load( $id )->data['options']['author'] );
		$this->assertSame( array( 1 => $editor ), MigratePlus_Job::load( $id )->data['options']['authors'] );
		$posts = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$this->assertSame( array( 'Dune', 'Emma', 'Ulysses' ), wp_list_pluck( $posts, 'post_title' ) );
		$this->assertSame( array( $contributor, $editor, $contributor ), array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) ) );
	}

	public function test_save_keeps_only_the_single_record_authors_who_may_write_posts() {
		$this->login( 'administrator' );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$id         = $this->preview_books( 3 );
		$saved      = $this->ajax(
			'save',
			array(
				'job'     => $id,
				'mapping' => wp_json_encode( array( 'fields' => array( 'post_title' => 'title' ) ) ),
				// JSON object keys: "0" and "2" are positions; "x" is not; 3 is past the last of 3 records.
				'options' => wp_json_encode(
					array(
						'authors' => array(
							'0' => $editor,
							'1' => $subscriber,
							'2' => 999999,
							'x' => $editor,
							'3' => $editor,
						),
					)
				),
			)
		);
		$this->assertTrue( $saved['success'] );
		$this->assertSame( array( 0 => $editor ), MigratePlus_Job::load( $id )->data['options']['authors'] );
	}

	protected function records( $id, array $params = array() ) {
		$out = $this->ajax( 'records', array( 'job' => $id ) + $params );
		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		return $out['data'];
	}

	public function test_records_pages_through_a_job() {
		$this->login( 'administrator' );
		$id = $this->preview_books( 7 );
		$this->assertSame(
			array(
				'records'   => array(
					array(
						'index'  => 3,
						'title'  => 'Beloved',
						'type'   => 'post',
						'author' => '',
					),
					array(
						'index'  => 4,
						'title'  => 'Middlemarch',
						'type'   => 'post',
						'author' => '',
					),
				),
				'total'     => 7,
				'matched'   => 7,
				'truncated' => false,
			),
			$this->records(
				$id,
				array(
					'offset' => 3,
					'limit'  => 2,
				)
			)
		);
		$this->assertSame( array( 'Kindred' ), array_column( $this->records( $id, array( 'offset' => 6 ) )['records'], 'title' ) );
	}

	public function test_records_searches_titles_in_any_case() {
		$this->login( 'administrator' );
		$id   = $this->preview_books( 7 );
		$page = $this->records(
			$id,
			array(
				'q'     => 'R',
				'limit' => 2,
			)
		);
		// Middlemarch, Rebecca and Kindred hold an "r".
		$this->assertSame( array( 4, 5 ), array_column( $page['records'], 'index' ) );
		$this->assertSame( 3, $page['matched'] );
		$this->assertSame( 7, $page['total'] );
		$this->assertFalse( $page['truncated'] );
		$page = $this->records(
			$id,
			array(
				'q'      => 'r',
				'offset' => 2,
			)
		);
		$this->assertSame( array( 'Kindred' ), array_column( $page['records'], 'title' ) );
		$this->assertSame( array(), $this->records( $id, array( 'q' => 'zzz' ) )['records'] );
	}

	public function test_records_follow_the_mapping_on_screen_and_name_each_records_type() {
		$this->login( 'administrator' );
		$job  = $this->json_job(
			array(
				'migrateplus' => array(
					'site'       => 'http://old.test',
					'post_types' => array( 'post', 'page' ),
					'mapping'    => array(
						'fields' => array(
							'source_id'  => 'id',
							'post_title' => 'title',
							'post_type'  => 'post_type',
						),
					),
				),
				'items'       => array(
					array(
						'id'        => 1,
						'title'     => 'A post',
						'headline'  => 'Post headline',
						'post_type' => 'post',
					),
					array(
						'id'        => 2,
						'title'     => '',
						'headline'  => 'Page headline',
						'post_type' => 'page',
					),
				),
			)
		);
		$page = $this->records( $job->data['id'] );
		$this->assertSame( array( 'A post', '(no title) #2' ), array_column( $page['records'], 'title' ) );
		$this->assertSame( array( 'post', 'page' ), array_column( $page['records'], 'type' ) );
		$mapping = array(
			'fields' => array(
				'post_title' => 'headline',
				'post_type'  => 'post_type',
			),
		);
		$page    = $this->records( $job->data['id'], array( 'mapping' => wp_json_encode( $mapping ) ) );
		$this->assertSame( array( 'Post headline', 'Page headline' ), array_column( $page['records'], 'title' ) );
	}

	public function test_records_stop_a_search_after_5000_records() {
		$this->login( 'administrator' );
		$items = array();
		for ( $i = 0; $i < 5001; $i++ ) {
			$items[] = array( 'title' => 5000 === $i ? 'Needle' : 'Hay ' . $i );
		}
		$job  = $this->json_job( $items );
		$page = $this->records( $job->data['id'], array( 'q' => 'needle' ) );
		$this->assertSame( array(), $page['records'] );
		$this->assertTrue( $page['truncated'] );
		$this->assertSame( 0, $page['matched'] );
	}

	public function test_records_refuse_another_users_job() {
		$this->give_editors_access();
		$this->login( 'editor' );
		$id = $this->preview_books();
		$this->login( 'editor' );
		$this->assertRefused( $this->ajax( 'records', array( 'job' => $id ) ), 'You are not allowed to do this.' );
	}

	public function test_users_search_answers_who_may_write_posts() {
		$this->login( 'administrator' );
		$writer = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'wanda',
				'user_email'   => 'wanda@here.test',
				'display_name' => 'Wanda Writer',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'wanda-reads',
			)
		);
		$expected = array(
			array(
				'id'    => $writer,
				'name'  => 'Wanda Writer',
				'login' => 'wanda',
			),
		);
		foreach ( array( 'wan', 'here.test', 'Writer' ) as $q ) {
			$this->assertSame( $expected, $this->ajax( 'users', array( 'q' => $q ) )['data']['authors'] );
		}
	}

	public function test_users_search_keeps_logins_and_emails_from_editors() {
		$this->give_editors_access();
		$this->login( 'editor' );
		$writer   = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'wanda',
				'user_email'    => 'wanda@here.test',
				'user_nicename' => 'wanda-w',
				'display_name'  => 'Wanda Writer',
			)
		);
		$expected = array(
			array(
				'id'    => $writer,
				'name'  => 'Wanda Writer',
				'login' => 'wanda-w',
			),
		);
		foreach ( array( 'wanda-w', 'Writer' ) as $q ) {
			$this->assertSame( $expected, $this->ajax( 'users', array( 'q' => $q ) )['data']['authors'] );
		}
		$this->assertSame( array(), $this->ajax( 'users', array( 'q' => 'here.test' ) )['data']['authors'] );
	}

	public function test_saved_mappings_leave_the_author_out() {
		$this->login( 'administrator' );
		$out = $this->ajax(
			'mapping_save',
			array(
				'job'     => $this->preview_books(),
				'name'    => 'Books',
				'mapping' => wp_json_encode( array( 'fields' => array( 'post_title' => 'title' ) ) ),
				'options' => wp_json_encode(
					array(
						'author'         => get_current_user_id(),
						'authors'        => array( 0 => get_current_user_id() ),
						'default_author' => get_current_user_id(),
						'create_authors' => 1,
						'post_type'      => 'page',
					)
				),
			)
		)['data'];
		$this->assertSame( 'page', $out['mappings'][0]['options']['post_type'] );
		$this->assertSame( array(), array_intersect_key( $out['mappings'][0]['options'], array_flip( array( 'author', 'authors', 'default_author', 'create_authors' ) ) ) );

		// Saved by an older version.
		update_option(
			MigratePlus_Mappings::OPTION,
			array(
				'Old' => array(
					'name'    => 'Old',
					'fields'  => array(),
					'mapping' => array(),
					'options' => array(
						'author'         => 3,
						'authors'        => array( 3 ),
						'default_author' => 3,
						'create_authors' => 1,
						'duplicate'      => 'update',
					),
					'updated' => 1,
				),
			),
			false
		);
		$this->assertSame( array( 'duplicate' => 'update' ), MigratePlus_Mappings::get( 'Old' )['options'] );
	}

	public function test_redirects_left_off_on_the_mapping_screen_are_not_recorded() {
		$this->login( 'administrator' );
		$fields = array(
			'source_id'  => 'id',
			'post_title' => 'title',
			'source_url' => 'link',
		);
		$job    = $this->json_job(
			array(
				'migrateplus' => array(
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => $fields ),
				),
				'items'       => array(
					array(
						'id'    => 1,
						'title' => 'Moved',
						'link'  => 'http://old.test/2019/old-address/',
					),
				),
			)
		);
		$this->save_and_run( $job->data['id'], array( 'fields' => $fields ), array( 'import_redirects' => 0 ) );
		$this->assertCount( 1, get_posts( array( 'title' => 'Moved' ) ) );
		$this->assertSame( 0, MigratePlus_Redirects::count_for_job( $job->data['id'] ) );
	}

	public function test_run_and_restart_refuse_an_import_never_started() {
		$this->login( 'administrator' );
		$id = $this->preview_books();
		foreach ( array( 'run', 'restart' ) as $action ) {
			$this->assertRefused( $this->ajax( $action, array( 'job' => $id ) ), 'This import has not been started yet. Choose its mapping and options, then start it.' );
		}
		$this->assertSame( array(), get_posts( array( 'title' => 'Dune' ) ) );
		$this->assertSame( 'draft', MigratePlus_Job::load( $id )->data['status'] );
	}

	public function test_finished_import_deletes_its_caches_and_runs_again_from_the_file() {
		$this->login( 'administrator' );
		$fields = array(
			'source_id'  => 'id',
			'post_title' => 'title',
		);
		$job    = $this->json_job(
			array(
				'migrateplus' => array(
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => $fields ),
				),
				'items'       => array(
					array(
						'id'    => 1,
						'title' => 'Hello',
					),
				),
				'menus'       => array(
					array(
						'name'  => 'Main',
						'items' => array(),
					),
				),
			)
		);
		$id     = $job->data['id'];
		$caches = array( $job->data['settings']['cache_file'], $job->data['settings']['menus_file'] );

		// "Run the real import" goes straight on from a dry run, so a dry run keeps them.
		$this->save_and_run( $id, array( 'fields' => $fields ), array( 'dry_run' => 1 ) );
		$this->assertFileExists( $caches[0] );
		$this->save_and_run( $id, array( 'fields' => $fields ), array( 'import_menus' => 1 ) );
		$this->assertFileDoesNotExist( $caches[0] );
		$this->assertFileDoesNotExist( $caches[1] );
		$this->assertArrayNotHasKey( 'cache_file', MigratePlus_Job::load( $id )->data['settings'] );

		$restart = $this->ajax( 'restart', array( 'job' => $id ) );
		$this->assertTrue( $restart['success'] );
		$this->assertSame( array( 'File read again.', 'Job restarted.' ), array_slice( array_column( $restart['data']['job']['log'], 'm' ), -2 ) );
		$this->assertFileExists( MigratePlus_Job::load( $id )->data['settings']['cache_file'] );
		do {
			$run = $this->ajax( 'run', array( 'job' => $id ) );
		} while ( ! $run['data']['done'] );
		$this->assertSame( 1, $run['data']['job']['stats']['skipped'] );
		$this->assertCount( 1, get_posts( array( 'title' => 'Hello' ) ) );
	}

	public function test_media_preview_accepts_the_signed_link_of_a_file_name_with_a_space() {
		$this->login( 'administrator' );
		$url  = 'http://old.test/files/photo one.jpg';
		$here = self::factory()->attachment->create_object( 'photo.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $here, '_migrateplus_source_url', $url );
		$job   = $this->json_job(
			array(
				'migrateplus' => array(
					'site'    => 'http://old.test',
					'mapping' => array(
						'fields' => array(
							'source_id'  => 'id',
							'post_type'  => 'post_type',
							'post_title' => 'title',
							'media_url'  => 'file',
						),
					),
				),
				'items'       => array(
					array(
						'id'        => 7,
						'post_type' => 'attachment',
						'title'     => 'Photo',
						'file'      => $url,
					),
				),
			)
		);
		$found = $this->ajax( 'duplicates', array( 'job' => $job->data['id'] ) )['data'];
		parse_str( (string) wp_parse_url( $found['images'][0]['imported_preview'], PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- the request ajax_media_preview() reads.
		// Thrown where the image would be fetched, past the signature check: ajax_media_preview() exits otherwise.
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $fetch ) {
				throw new RuntimeException( $fetch ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			},
			10,
			3
		);
		try {
			MigratePlus_Admin::ajax_media_preview();
			$this->fail( 'The image was not fetched.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'http://old.test/files/photo%20one.jpg', $e->getMessage() );
		}
	}

	public function test_duplicates_follow_the_options_sent() {
		$this->login( 'administrator' );
		$id  = $this->import_books( 2 );
		$out = $this->ajax( 'duplicates', array( 'job' => $id ) )['data'];
		$this->assertSame( 2, $out['total'] );
		$this->assertSame( 2, $out['before'] );
		$this->assertSame( array( 'Dune', 'Emma' ), array_column( $out['examples'], 'title' ) );
		$this->assertSame( array(), $out['images'] );

		// Records imported before are what "update" is for, so they stop counting.
		$update = $this->ajax(
			'duplicates',
			array(
				'job'     => $id,
				'options' => wp_json_encode( array( 'duplicate' => 'update' ) ),
			)
		);
		$this->assertSame( 0, $update['data']['count'] );
		$this->assertSame( 'skip', MigratePlus_Job::load( $id )->data['options']['duplicate'] );
	}

	public function test_duplicates_list_each_match_with_what_differs_and_where_it_is() {
		$this->login( 'administrator' );
		$id   = $this->import_books( 2 );
		$dune = get_posts( array( 'title' => 'Dune' ) )[0]->ID;
		$emma = get_posts( array( 'title' => 'Emma' ) )[0]->ID;
		wp_update_post(
			array(
				'ID'         => $dune,
				'post_title' => 'Dune (1965)',
			)
		);

		$out = $this->ajax( 'duplicates', array( 'job' => $id ) )['data'];
		$this->assertFalse( $out['items_truncated'] );
		$this->assertSame(
			array(
				array(
					'index'          => 0,
					'title'          => 'Dune',
					'existing_title' => 'Dune (1965)',
					'post_id'        => $dune,
					'type'           => 'Post',
					'kind'           => 'before',
					'label'          => '',
					'changed'        => true,
					'compared'       => true,
					'changes'        => array( 'title' ),
					'view_url'       => get_permalink( $dune ),
					'edit_url'       => get_edit_post_link( $dune, 'raw' ),
					'media'          => null,
					'author'         => '',
				),
				array(
					'index'          => 1,
					'title'          => 'Emma',
					'existing_title' => 'Emma',
					'post_id'        => $emma,
					'type'           => 'Post',
					'kind'           => 'before',
					'label'          => '',
					'changed'        => false,
					'compared'       => true,
					'changes'        => array(),
					'view_url'       => get_permalink( $emma ),
					'edit_url'       => get_edit_post_link( $emma, 'raw' ),
					'media'          => null,
					'author'         => '',
				),
			),
			array_map(
				static function ( $item ) {
					$item['changes'] = array_keys( $item['changes'] );
					return $item;
				},
				$out['items']
			)
		);
		$this->assertSame( array( 'Dune (1965)', 'Emma' ), array_column( $out['examples'], 'title' ) );
		$this->assertSame( array( $dune ), array_column( $out['changed'], 'post_id' ) );
	}

	public function test_duplicates_list_stops_at_the_cap_and_says_so() {
		$this->login( 'administrator' );
		$id = $this->import_books( 3 );
		add_filter(
			'migrateplus_duplicates_list_cap',
			static function () {
				return 2;
			}
		);
		$out = $this->ajax( 'duplicates', array( 'job' => $id ) )['data'];
		$this->assertSame( 3, $out['count'] );
		$this->assertSame( array( 0, 1 ), array_column( $out['items'], 'index' ) );
		$this->assertTrue( $out['items_truncated'] );
	}

	public function test_rollback() {
		$this->login( 'administrator' );
		$id    = $this->import_books( 3 );
		$calls = 0;
		do {
			$out = $this->ajax( 'rollback', array( 'job' => $id ) );
			if ( ++$calls > 10 ) {
				$this->fail( 'The rollback was still not done after 10 batches.' );
			}
		} while ( ! $out['data']['done'] );
		$this->assertSame( '', $out['data']['error'] );
		$this->assertSame( 0, $out['data']['remaining'] );
		$this->assertSame( 'rolled_back', $out['data']['job']['status'] );
		$this->assertSame( array(), get_posts( array( 'title' => 'Dune' ) ) );
	}

	public function test_restart_forgets_approved_changes() {
		$this->login( 'administrator' );
		$job                                    = MigratePlus_Job::load( $this->import_books( 1 ) );
		$job->data['options']['update_ids']     = array( 5 );
		$job->data['options']['skip_ids']       = array( 6 );
		$job->data['options']['duplicate_rest'] = 'copy';
		$job->save();

		$out = $this->ajax( 'restart', array( 'job' => $job->data['id'] ) )['data']['job'];
		$this->assertSame( 'ready', $out['status'] );
		$this->assertSame( 0, $out['stats']['processed'] );
		$this->assertSame( array(), $out['options']['update_ids'] );
		$this->assertSame( array(), $out['options']['skip_ids'] );
		$this->assertSame( '', $out['options']['duplicate_rest'] );
	}

	public function test_delete_keeps_the_imported_posts() {
		$this->login( 'administrator' );
		$id = $this->import_books( 1 );
		$this->assertSame( array( 'success' => true ), $this->ajax( 'delete', array( 'job' => $id ) ) );
		$this->assertNull( MigratePlus_Job::load( $id ) );
		$this->assertCount( 1, get_posts( array( 'title' => 'Dune' ) ) );
	}

	public function test_delete_waits_for_a_running_batch() {
		$this->login( 'administrator' );
		$job  = MigratePlus_Job::load( $this->preview_books() );
		$lock = MigratePlus_Runner::lock( $job );
		$this->assertRefused( $this->ajax( 'delete', array( 'job' => $job->data['id'] ) ), 'An import batch for this job is running. Try again when it finishes.' );
		MigratePlus_Runner::unlock( $job, $lock );
		$this->assertNotNull( MigratePlus_Job::load( $job->data['id'] ) );
	}

	public function test_run_while_a_batch_holds_the_lock() {
		$this->login( 'administrator' );
		$job                 = MigratePlus_Job::load( $this->preview_books() );
		$job->data['status'] = 'ready'; // Started: a draft is refused before the lock is tried.
		$job->save();
		$lock = MigratePlus_Runner::lock( $job );
		$out  = $this->ajax( 'run', array( 'job' => $job->data['id'] ) );
		MigratePlus_Runner::unlock( $job, $lock );
		$this->assertFalse( $out['success'] );
		$this->assertSame(
			array(
				'message' => 'A batch for this job is already running.',
				'code'    => 'migrateplus_locked',
			),
			$out['data']
		);
	}

	public function test_media_preview_resets_in_request_when_the_request_throws() {
		$this->login( 'administrator' );
		$url      = 'https://old.test/image.jpg';
		$_GET     = array(
			'url'   => $url,
			'sig'   => hash_hmac( 'sha256', 'migrateplus_preview|' . $url, wp_salt( 'auth' ) ),
			'nonce' => wp_create_nonce( 'migrateplus_admin' ),
		);
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- the request ajax_media_preview() reads.
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'Connection reset' );
			}
		);
		try {
			MigratePlus_Admin::ajax_media_preview();
			$this->fail( 'The request did not throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Connection reset', $e->getMessage() );
		}
		$this->assertFalse( MigratePlus_Util::$in_request );
	}

	public function test_jobs_and_job() {
		$this->give_editors_access();
		$this->login( 'administrator' );
		$admins = $this->preview_books();
		$this->login( 'editor' );
		$mine = $this->preview_books( 7 );

		$jobs = $this->ajax( 'jobs' )['data']['jobs'];
		$this->assertSame( array( $mine ), array_column( $jobs, 'id' ) );
		$this->assertSame( array(), $jobs[0]['log'] );
		$this->assertSame( 'Book feed', $jobs[0]['label'] );

		$job = $this->ajax( 'job', array( 'job' => $mine ) )['data']['job'];
		$this->assertSame( array( 'isbn', 'title', 'blurb' ), $job['fields'] );
		$this->assertCount( 5, $job['records'] );
		$this->assertNotEmpty( $job['log'] );

		$this->login( 'administrator' );
		$this->assertEqualSets( array( $admins, $mine ), array_column( $this->ajax( 'jobs' )['data']['jobs'], 'id' ) );
		$this->assertTrue( $this->ajax( 'delete', array( 'job' => $mine ) )['success'] );
	}

	public function job_actions() {
		$actions = array( 'job', 'save', 'duplicates', 'run', 'restart', 'rollback', 'delete', 'mapping_save' );
		return array_combine( $actions, array_chunk( $actions, 1 ) );
	}

	/**
	 * @dataProvider job_actions
	 *
	 * @param string $action Action.
	 */
	public function test_editors_cannot_touch_another_users_job( $action ) {
		$this->give_editors_access();
		$this->login( 'administrator' );
		$id = $this->preview_books();
		$this->login( 'editor' );
		$this->assertRefused( $this->ajax( $action, array( 'job' => $id ) ), 'You are not allowed to do this.' );
		$this->assertRefused( $this->ajax( $action, array( 'job' => 'gone' ) ), 'Import job not found.' );
		$this->assertSame( 'draft', MigratePlus_Job::load( $id )->data['status'] );
	}

	public function test_history_delete() {
		$this->give_editors_access();
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->login( 'administrator' );
		$admin_job    = $this->preview_books();
		$admin_export = $this->finished_export();
		$this->login( 'editor' );
		$done                    = $this->preview_books();
		$running                 = MigratePlus_Job::load( $this->preview_books() );
		$running->data['status'] = 'running';
		$running->save();
		$export = $this->finished_export();

		$entry = static function ( $kind, $id ) {
			return array(
				'kind' => $kind,
				'id'   => $id,
			);
		};
		$items = array(
			$entry( 'job', $done ),
			$entry( 'job', $running->data['id'] ),
			$entry( 'job', $admin_job ),
			$entry( 'job', 'gone' ),
			$entry( 'export', $export ),
			$entry( 'export', $admin_export ),
			array( 'kind' => 'job' ),
			'junk',
		);
		$out   = $this->ajax( 'history_delete', array( 'items' => wp_json_encode( $items ) ) )['data'];
		$this->assertSame( array( $done, $export ), array_column( $out['deleted'], 'id' ) );
		$this->assertSame( array( $running->data['id'], $admin_job, 'gone', $admin_export ), array_column( $out['skipped'], 'id' ) );
		$this->assertSame( array( 'still running', 'not allowed', 'no longer in history', 'not allowed' ), array_column( $out['skipped'], 'reason' ) );
		$this->assertNull( MigratePlus_Job::load( $done ) );
		$this->assertNotNull( MigratePlus_Job::load( $admin_job ) );
		$this->assertNotNull( MigratePlus_Exporter::history_entry( $admin_export ) );

		$junk = $this->ajax( 'history_delete', array( 'items' => 'not json' ) )['data'];
		$this->assertSame( array(), $junk['deleted'] );
		$this->assertSame( array(), $junk['skipped'] );
	}

	public function test_a_running_import_whose_requests_died_can_be_deleted() {
		$this->login( 'administrator' );
		$id                  = $this->preview_books();
		$job                 = MigratePlus_Job::load( $id );
		$job->data['status'] = 'running';
		$job->save();
		// Written directly: save() would stamp "updated" with the current time.
		$data            = get_option( 'migrateplus_job_' . $id );
		$data['updated'] = time() - MigratePlus_Runner::LOCK_TTL - 1;
		update_option( 'migrateplus_job_' . $id, $data, false );

		$this->assertTrue( $this->ajax( 'job', array( 'job' => $id ) )['data']['job']['stalled'] );
		$out = $this->ajax(
			'history_delete',
			array(
				'items' => wp_json_encode(
					array(
						array(
							'kind' => 'job',
							'id'   => $id,
						),
					)
				),
			)
		)['data'];
		$this->assertSame( array( $id ), array_column( $out['deleted'], 'id' ) );
		$this->assertNull( MigratePlus_Job::load( $id ) );
	}

	public function test_only_the_owner_or_an_administrator_changes_a_saved_mapping() {
		$this->give_editors_access();
		$this->login( 'administrator' );
		MigratePlus_Mappings::save( 'Admin feed', array(), array(), array() );
		$this->login( 'editor' );
		MigratePlus_Mappings::save( 'Editor feed', array(), array(), array() );
		$refusal = 'Only the person who saved this mapping, or an administrator, can change or delete it.';

		$this->assertRefused( $this->ajax( 'mapping_delete', array( 'name' => 'Admin feed' ) ), $refusal );
		$this->assertRefused(
			$this->ajax(
				'mapping_rename',
				array(
					'name'     => 'Admin feed',
					'new_name' => 'Mine now',
				)
			),
			$refusal
		);
		$this->assertRefused(
			$this->ajax(
				'mapping_save',
				array(
					'job'  => $this->preview_books(),
					'name' => 'Admin feed',
				)
			),
			$refusal
		);
		$this->assertTrue( $this->ajax( 'mapping_delete', array( 'name' => 'Editor feed' ) )['success'] );
		$this->assertSame( array( 'Admin feed' ), array_keys( MigratePlus_Mappings::all() ) );

		$this->login( 'administrator' );
		$this->assertTrue( $this->ajax( 'mapping_delete', array( 'name' => 'Admin feed' ) )['success'] );
	}

	public function test_admin_script_can_use_wp_i18n_with_the_plugins_translations() {
		$this->login( 'administrator' );
		$_GET['page'] = MigratePlus_Admin::SLUG;
		MigratePlus_Admin::assets( 'toplevel_page_migrateplus' );
		$script = wp_scripts()->registered['migrateplus'];
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'migrateplus', $script->textdomain );
	}

	public function test_saved_mapping_endpoints() {
		$this->login( 'administrator' );
		$id  = $this->preview_books();
		$out = $this->ajax(
			'mapping_save',
			array(
				'job'     => $id,
				'name'    => 'Books <b>feed</b>',
				'mapping' => wp_json_encode( array( 'fields' => array( 'post_title' => 'title' ) ) ),
				'options' => wp_json_encode(
					array(
						'post_type' => 'page',
						'dry_run'   => 1,
					)
				),
			)
		)['data'];
		$this->assertSame( 'Books feed', $out['name'] );
		$this->assertSame( array( 'isbn', 'title', 'blurb' ), $out['mappings'][0]['fields'] );
		$this->assertSame( 'page', $out['mappings'][0]['options']['post_type'] );
		$this->assertArrayNotHasKey( 'dry_run', $out['mappings'][0]['options'] );
		$this->assertRefused( $this->ajax( 'mapping_save', array( 'job' => $id ) ), 'Give the mapping a name.' );

		MigratePlus_Mappings::save( 'Recipes', array(), array(), array() );
		$this->assertRefused(
			$this->ajax(
				'mapping_rename',
				array(
					'name'     => 'Books feed',
					'new_name' => 'Recipes',
				)
			),
			'A saved mapping called "Recipes" already exists.'
		);
		$renamed = $this->ajax(
			'mapping_rename',
			array(
				'name'     => 'Books feed',
				'new_name' => 'Books',
			)
		)['data'];
		$this->assertSame( 'Books', $renamed['name'] );
		$this->assertSame( array( 'Books', 'Recipes' ), array_column( $renamed['mappings'], 'name' ) );

		$deleted = $this->ajax( 'mapping_delete', array( 'name' => 'Books' ) )['data'];
		$this->assertSame( array( 'Recipes' ), array_column( $deleted['mappings'], 'name' ) );
	}

	public function test_mapping_save_is_capped() {
		$this->login( 'administrator' );
		for ( $i = 1; $i <= MigratePlus_Mappings::MAX; $i++ ) {
			MigratePlus_Mappings::save( 'Map ' . $i, array(), array(), array() );
		}
		$id   = $this->preview_books();
		$over = $this->ajax(
			'mapping_save',
			array(
				'job'  => $id,
				'name' => 'Books',
			)
		);
		$this->assertStringStartsWith( 'There are already 50 saved mappings', $over['data']['message'] );
		$again = $this->ajax(
			'mapping_save',
			array(
				'job'  => $id,
				'name' => 'Map 1',
			)
		);
		$this->assertTrue( $again['success'] );
	}

	public function test_mapping_save_refuses_exports() {
		$this->login( 'administrator' );
		$job = $this->json_job(
			array(
				'migrateplus' => array(
					'site'      => 'http://old.test',
					'post_type' => 'post',
					'mapping'   => array( 'fields' => array( 'post_title' => 'title' ) ),
				),
				'items'       => array(
					array(
						'id'    => 1,
						'title' => 'Hello',
					),
				),
			)
		);
		$out = $this->ajax(
			'mapping_save',
			array(
				'job'  => $job->data['id'],
				'name' => 'Old site',
			)
		);
		$this->assertRefused( $out, 'Migrate Plus exports carry their own mapping, so there is nothing to save.' );
	}

	public function test_export_count() {
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		self::factory()->post->create( array( 'post_title' => 'Goodbye' ) );
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		wp_create_nav_menu( 'Main' );
		$args = array(
			'args' => wp_json_encode(
				array(
					'post_types'    => array( 'post', 'page' ),
					'include_menus' => 1,
				)
			),
		);

		$this->login( 'administrator' );
		$out = $this->ajax( 'export_count', $args )['data'];
		$this->assertSame( 3, $out['count'] );
		$this->assertSame(
			array(
				'post' => 2,
				'page' => 1,
			),
			$out['counts']
		);
		$this->assertSame( 1, $out['menus'] );

		$this->give_editors_access();
		$this->login( 'editor' );
		$this->assertSame( 0, $this->ajax( 'export_count', $args )['data']['menus'] );
	}

	public function test_export_posts_searches_by_title_and_id() {
		$post  = self::factory()->post->create( array( 'post_title' => 'Needle post' ) );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Needle page',
				'post_status' => 'draft',
			)
		);
		$plain = self::factory()->post->create( array( 'post_title' => 'Haystack' ) );

		$this->login( 'administrator' );
		$this->assertSame( array(), $this->ajax( 'export_posts', array( 'search' => '' ) )['data']['posts'] );

		$rows = $this->ajax( 'export_posts', array( 'search' => 'needle' ) )['data']['posts'];
		$this->assertEqualsCanonicalizing( array( $post, $draft ), array_column( $rows, 'id' ) );
		$page_row = $rows[ array_search( $draft, array_column( $rows, 'id' ), true ) ];
		$this->assertSame( 'Needle page', $page_row['title'] );
		$this->assertSame( 'page', $page_row['type'] );
		$this->assertSame( 'Page', $page_row['label'] );
		$this->assertSame( 'Draft', $page_row['status'] );
		$this->assertNotEmpty( $page_row['date'] );

		// A number finds that exact post first, even when its title doesn't match.
		$rows = $this->ajax( 'export_posts', array( 'search' => (string) $plain ) )['data']['posts'];
		$this->assertSame( $plain, $rows[0]['id'] );
	}

	public function test_export_posts_hides_types_the_user_cannot_edit() {
		register_post_type(
			'mp_invoice',
			array(
				'public'       => true,
				'label'        => 'Invoices',
				'capabilities' => array( 'edit_posts' => 'manage_options' ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'mp_invoice',
				'post_title' => 'Needle invoice',
			)
		);
		$post = self::factory()->post->create( array( 'post_title' => 'Needle post' ) );

		$this->give_editors_access();
		$this->login( 'editor' );
		$rows = $this->ajax( 'export_posts', array( 'search' => 'needle' ) )['data']['posts'];
		$this->assertSame( array( $post ), array_column( $rows, 'id' ) );
		unregister_post_type( 'mp_invoice' );
	}

	public function test_export_start_with_hand_picked_posts() {
		$this->login( 'administrator' );
		$pick = self::factory()->post->create(
			array(
				'post_title'  => 'Picked',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Not picked' ) );

		$start = $this->ajax( 'export_start', array( 'args' => wp_json_encode( array( 'post_ids' => array( $pick ) ) ) ) )['data'];
		$this->assertSame( 1, $start['total'] );
		do {
			$run = $this->ajax( 'export_run', array( 'export' => $start['id'] ) )['data'];
		} while ( ! $run['complete'] );

		$file = MigratePlus_Exporter::load_state( $start['id'] )['file'];
		$doc  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( array( 'Picked' ), array_column( $doc['items'], 'title' ) );
		$this->ajax( 'export_forget', array( 'export' => $start['id'] ) );
	}

	public function test_export_refuses_a_type_the_user_cannot_edit() {
		register_post_type(
			'mp_invoice',
			array(
				'public'       => true,
				'label'        => 'Invoices',
				'capabilities' => array( 'edit_posts' => 'manage_options' ),
			)
		);
		$this->give_editors_access();
		$this->login( 'editor' );
		$out = $this->ajax( 'export_start', array( 'args' => wp_json_encode( array( 'post_types' => array( 'post', 'mp_invoice' ) ) ) ) );
		$this->assertRefused( $out, 'You are not allowed to export Invoices.' );
		$this->assertSame( array(), MigratePlus_Exporter::history() );
		unregister_post_type( 'mp_invoice' );
	}

	public function test_export_refuses_types_that_are_not_content() {
		register_post_type(
			'acf-field-group',
			array(
				'show_ui' => true,
				'label'   => 'Field Groups',
			)
		);
		register_post_type(
			'mp_hidden',
			array(
				'show_ui' => false,
				'label'   => 'Hidden',
			)
		);
		$this->login( 'administrator' );
		foreach ( array(
			'acf-field-group' => 'Field Groups',
			'mp_hidden'       => 'Hidden',
		) as $type => $label ) {
			$out = $this->ajax( 'export_count', array( 'args' => wp_json_encode( array( 'post_types' => array( 'post', $type ) ) ) ) );
			$this->assertRefused( $out, $label . ' can\'t be exported.' );
		}
		$this->assertTrue( MigratePlus_Admin::exportable_type( 'post' ) );
		$this->assertTrue( MigratePlus_Admin::exportable_type( 'attachment' ) );
		$this->assertFalse( MigratePlus_Admin::exportable_type( 'shop_order' ) );
		$this->assertFalse( MigratePlus_Admin::exportable_type( 'wp_global_styles' ) );
		// Synced patterns, navigation menus and templates are posts of the block editor: they export like any type.
		$this->assertTrue( MigratePlus_Admin::exportable_type( 'wp_block' ) );
		$this->assertTrue( MigratePlus_Admin::exportable_type( 'wp_template' ) );
		unregister_post_type( 'acf-field-group' );
		unregister_post_type( 'mp_hidden' );
	}

	public function test_export_start_run_log_and_forget() {
		$this->login( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$start = $this->ajax( 'export_start', array( 'args' => wp_json_encode( array( 'post_types' => array( 'page', 'post' ) ) ) ) )['data'];
		$this->assertSame( 2, $start['total'] );
		$this->assertSame( 'Pages', $start['section'] );
		$this->assertArrayNotHasKey( 'url', $start );

		do {
			$run = $this->ajax( 'export_run', array( 'export' => $start['id'] ) )['data'];
		} while ( ! $run['complete'] );
		$this->assertSame( 2, $run['done'] );
		$this->assertStringEndsWith( '.json', $run['filename'] );
		$this->assertStringContainsString( 'action=migrateplus_export_download', $run['url'] );

		$file = MigratePlus_Exporter::load_state( $start['id'] )['file'];
		$doc  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( array( 'page', 'post' ), $doc['migrateplus']['post_types'] );
		$this->assertSame( array( 'About', 'Hello' ), array_column( $doc['items'], 'title' ) );

		$exports = $this->ajax( 'exports' )['data']['exports'];
		$this->assertSame( $run['url'], $exports[0]['url'] );
		$log = $this->ajax( 'export_log', array( 'export' => $start['id'] ) )['data']['log'];
		$this->assertSame( array( 't', 'l', 'm' ), array_keys( $log[0] ) );

		$this->assertSame( array( 'success' => true ), $this->ajax( 'export_forget', array( 'export' => $start['id'] ) ) );
		$this->assertNull( MigratePlus_Exporter::history_entry( $start['id'] ) );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_export_start_refuses_an_empty_export() {
		$this->login( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$out = $this->ajax(
			'export_start',
			array(
				'args' => wp_json_encode(
					array(
						'post_types'        => array( 'post' ),
						'statuses'          => array( 'future' ),
						'include_term_data' => 0, // With term data on, the default category alone would make a file.
					)
				),
			)
		);
		$this->assertRefused( $out, 'Nothing matches these filters. Widen the status or date range.' );
		$this->assertSame( array(), MigratePlus_Exporter::history() );
	}

	/**
	 * Menus are on by default, so the request still asks for them from users the toggle is hidden from.
	 */
	public function test_menus_only_export_needs_edit_theme_options() {
		wp_create_nav_menu( 'Main' );
		$args = array(
			'args' => wp_json_encode(
				array(
					'post_types'        => array( 'post' ),
					'statuses'          => array( 'future' ),
					'include_menus'     => 1,
					'include_term_data' => 0,
				)
			),
		);
		$this->login( 'administrator' );
		$this->assertSame( 0, $this->ajax( 'export_start', $args )['data']['total'] );

		$this->give_editors_access();
		$this->login( 'editor' );
		$this->assertRefused( $this->ajax( 'export_start', $args ), 'Nothing matches these filters. Widen the status or date range.' );
	}

	public function test_editors_cannot_touch_another_users_export() {
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->give_editors_access();
		$this->login( 'administrator' );
		$id = $this->finished_export();
		$this->login( 'editor' );

		$this->assertRefused( $this->ajax( 'export_run', array( 'export' => $id ) ), 'Export not found. It may have expired; run it again.' );
		$this->assertRefused( $this->ajax( 'export_download', array( 'export' => $id ) ), 'Export not found. It may have expired; run it again.' );
		$this->assertRefused( $this->ajax( 'export_log', array( 'export' => $id ) ), 'This export is no longer in history.' );
		$this->assertRefused( $this->ajax( 'export_forget', array( 'export' => $id ) ), 'You are not allowed to do this.' );
		$this->assertSame( array(), $this->ajax( 'exports' )['data']['exports'] );
		$this->assertNotNull( MigratePlus_Exporter::history_entry( $id ) );

		$mine = $this->finished_export();
		$this->assertSame( array( $mine ), array_column( $this->ajax( 'exports' )['data']['exports'], 'id' ) );
	}

	public function test_export_download_before_the_export_finished() {
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->login( 'administrator' );
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		$out   = $this->ajax( 'export_download', array( 'export' => $state['id'] ) );
		$this->assertSame( array( 'die' => 'This export is not ready or has expired. Run it again.' ), $out );
	}

	// Pull from another site: the endpoints call the old site's Migrate Plus REST
	// routes with the application password from the request and relay the answers.

	/**
	 * Play the old site: answer every HTTP request the pull makes and record it.
	 *
	 * @param array|callable $answer A pre_http_request response, or a function of ( $args, $url ) returning one.
	 * @return ArrayObject Each request as { url, args }.
	 */
	protected function mock_old_site( $answer ) {
		// Earlier tests can leave the core/plugin/theme update checks due; admin_init would
		// then fire them into this mock, whose answers update.php cannot parse.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		$calls = new ArrayObject();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $answer, $calls ) {
				$calls[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return is_callable( $answer ) ? $answer( $args, $url ) : $answer;
			},
			10,
			3
		);
		return $calls;
	}

	/**
	 * What the old site's REST API answers with.
	 *
	 * @param array|string $body Decoded body, or raw.
	 * @param int          $code HTTP status.
	 * @return array
	 */
	protected function old_site_says( $body, $code = 200 ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		);
	}

	protected function pull_creds() {
		return array(
			'url'  => 'https://old.test',
			'user' => 'kenny',
			'pass' => 'abcd efgh ijkl',
		);
	}

	public function test_pull_check_asks_the_old_site_with_the_application_password() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site(
			$this->old_site_says(
				array(
					'name'    => "Old <b>Site</b>\n",
					'url'     => 'https://old.test',
					'version' => '0.1.0',
					'types'   => array(
						array(
							'type'  => 'post',
							'label' => 'Posts',
							'count' => '12',
						),
						'not-a-type',
					),
				)
			)
		);

		$out = $this->ajax( 'pull_check', $this->pull_creds() );

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertSame( 'Old Site', $out['data']['name'] );
		$this->assertSame(
			array(
				array(
					'type'  => 'post',
					'label' => 'Posts',
					'count' => 12,
				),
			),
			$out['data']['types']
		);

		$this->assertCount( 1, $calls );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '/migrateplus/v1/site', $query['rest_route'] );
		$this->assertStringStartsWith( 'https://old.test/?', $calls[0]['url'] );
		$headers = $calls[0]['args']['headers'];
		$this->assertSame( 'Basic ' . base64_encode( 'kenny:abcd efgh ijkl' ), $headers['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- asserting the Basic auth header.
		$this->assertGreaterThanOrEqual( 30, $calls[0]['args']['timeout'] );
	}

	public function test_pull_needs_an_address_and_an_application_password() {
		$this->login( 'administrator' );
		$creds = $this->pull_creds();
		$this->assertRefused(
			$this->ajax( 'pull_check', array_merge( $creds, array( 'url' => '' ) ) ),
			"Enter the old site's address, starting with http:// or https://."
		);
		$this->assertRefused(
			$this->ajax( 'pull_check', array_merge( $creds, array( 'pass' => '' ) ) ),
			'Enter a username and an application password from the old site, or its pull key.'
		);
	}

	public function test_pull_check_with_the_pull_key_sends_the_key_header_instead() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site(
			$this->old_site_says(
				array(
					'name'    => 'Old Site',
					'url'     => 'https://old.test',
					'version' => '0.1.0',
					'types'   => array(),
				)
			)
		);

		$out = $this->ajax(
			'pull_check',
			array(
				'url' => 'https://old.test',
				'key' => 'k3y-from-the-old-site',
			)
		);

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertCount( 1, $calls );
		$headers = $calls[0]['args']['headers'];
		$this->assertSame( 'k3y-from-the-old-site', $headers['X-MigratePlus-Key'] );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	public function test_pull_check_names_where_the_old_site_redirects_instead_of_following() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://www.old.test/?rest_route=/migrateplus/v1/site' ),
				'body'     => '',
			)
		);

		$this->assertRefused(
			$this->ajax( 'pull_check', $this->pull_creds() ),
			'The old site redirects to https://www.old.test. Enter that address instead.'
		);
		$this->assertCount( 1, $calls );
		$this->assertSame( 0, $calls[0]['args']['redirection'] );
	}

	public function test_pull_sends_an_application_password_only_over_https() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site( $this->old_site_says( array( 'types' => array() ) ) );

		$this->assertRefused(
			$this->ajax( 'pull_check', array_merge( $this->pull_creds(), array( 'url' => 'http://old.test' ) ) ),
			'An application password is only sent over https. For an old site on plain http, use its pull key.'
		);
		$this->assertCount( 0, $calls );

		$out = $this->ajax(
			'pull_check',
			array(
				'url' => 'http://old.test',
				'key' => 'k3y-from-the-old-site',
			)
		);
		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
	}

	public function test_only_administrators_pull_from_local_hosts() {
		$this->give_editors_access();
		$allowed = new ArrayObject();
		$this->mock_old_site(
			function () use ( $allowed ) {
				$allowed[] = MigratePlus_Util::$in_request;
				return $this->old_site_says( array( 'types' => array() ) );
			}
		);

		$this->login( 'administrator' );
		$this->ajax( 'pull_check', $this->pull_creds() );
		$this->login( 'editor' );
		$this->ajax( 'pull_check', $this->pull_creds() );

		$this->assertSame( array( true, false ), $allowed->getArrayCopy() );
		$this->assertFalse( MigratePlus_Util::$in_request );
	}

	public function test_pull_check_spots_a_site_without_the_plugin() {
		$this->login( 'administrator' );
		$this->mock_old_site(
			$this->old_site_says(
				array(
					'code'    => 'rest_no_route',
					'message' => 'No route was found matching the URL and request method.',
				),
				404
			)
		);
		$this->assertRefused(
			$this->ajax( 'pull_check', $this->pull_creds() ),
			'Migrate Plus is not active on the old site, or its version there is too old to pull from.'
		);
	}

	public function test_pull_check_relays_what_the_old_site_said() {
		$this->login( 'administrator' );
		$this->mock_old_site(
			$this->old_site_says(
				array(
					'code'    => 'migrateplus_rest_forbidden',
					'message' => 'This needs a user allowed to run Migrate Plus.',
				),
				403
			)
		);
		$this->assertRefused(
			$this->ajax( 'pull_check', $this->pull_creds() ),
			'The old site said: This needs a user allowed to run Migrate Plus.'
		);
	}

	public function test_pull_check_on_a_site_that_is_not_wordpress() {
		$this->login( 'administrator' );
		$this->mock_old_site( $this->old_site_says( '<!doctype html><html></html>' ) );
		$this->assertRefused(
			$this->ajax( 'pull_check', $this->pull_creds() ),
			'The old site did not answer like a WordPress site (HTTP 200). Check the address.'
		);
	}

	public function test_pull_check_when_the_old_site_is_unreachable() {
		$this->login( 'administrator' );
		$this->mock_old_site( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );
		$this->assertRefused(
			$this->ajax( 'pull_check', $this->pull_creds() ),
			'Could not reach the old site: cURL error 6: Could not resolve host'
		);
	}

	public function test_pull_start_sends_the_types_and_relays_the_export() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site(
			$this->old_site_says(
				array(
					'id'    => 'exp123',
					'total' => 40,
				)
			)
		);

		$this->assertRefused(
			$this->ajax( 'pull_start', $this->pull_creds() ),
			'Choose at least one content type to pull.'
		);
		$out = $this->ajax( 'pull_start', array_merge( $this->pull_creds(), array( 'post_types' => 'post,page' ) ) );

		$this->assertSame(
			array(
				'export' => 'exp123',
				'total'  => 40,
			),
			$out['data']
		);
		$this->assertCount( 1, $calls );
		$this->assertSame( 'POST', $calls[0]['args']['method'] );
		$this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
		$this->assertSame( array( 'args' => array( 'post_types' => array( 'post', 'page' ) ) ), json_decode( $calls[0]['args']['body'], true ) );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '/migrateplus/v1/export', $query['rest_route'] );
	}

	public function test_pull_run_relays_progress_and_the_old_sites_error() {
		$this->login( 'administrator' );
		$calls = $this->mock_old_site(
			function ( $args ) {
				$sent = json_decode( $args['body'], true );
				$this->assertSame( array( 'export' => 'exp123' ), $sent );
				return $this->old_site_says(
					array(
						'id'       => 'exp123',
						'done'     => 50,
						'total'    => 80,
						'complete' => false,
					)
				);
			}
		);

		$out = $this->ajax( 'pull_run', array_merge( $this->pull_creds(), array( 'export' => 'exp123' ) ) );

		$this->assertSame(
			array(
				'done'     => 50,
				'total'    => 80,
				'complete' => false,
				'filename' => '',
			),
			$out['data']
		);
		$this->assertCount( 1, $calls );

		$this->mock_old_site( $this->old_site_says( array( 'error' => 'Could not write the export file.' ) ) );
		$this->assertRefused(
			$this->ajax( 'pull_run', array_merge( $this->pull_creds(), array( 'export' => 'exp123' ) ) ),
			'The old site said: Could not write the export file.'
		);
	}

	public function test_pull_fetch_downloads_the_file_and_previews_it() {
		self::factory()->post->create( array( 'post_title' => 'Pulled over HTTP' ) );
		$this->login( 'administrator' );
		$export = $this->finished_export();
		$file   = MigratePlus_Exporter::load_state( $export )['file'];

		// The old site streams the finished export; the mock writes it where the download would land.
		$calls = $this->mock_old_site(
			function ( $args ) use ( $file ) {
				copy( $file, $args['filename'] );
				return $this->old_site_says( '' );
			}
		);

		$out = $this->ajax(
			'pull_fetch',
			array_merge(
				$this->pull_creds(),
				array(
					'export'   => 'exp123',
					'filename' => 'old-site.json',
				)
			)
		);

		$this->assertTrue( $out['success'], wp_json_encode( $out ) );
		$this->assertSame( 'json', $out['data']['job']['source'] );
		$this->assertSame( 'old-site.json', $out['data']['job']['settings']['file_name'] );
		$this->assertSame( array( 'Pulled over HTTP' ), array_column( $out['data']['records'], 'title' ) );

		$this->assertCount( 1, $calls );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '/migrateplus/v1/export/file', $query['rest_route'] );
		$this->assertSame( 'exp123', $query['export'] );
		$this->assertTrue( $calls[0]['args']['stream'] );
	}

	public function test_pull_fetch_needs_a_finished_export() {
		$this->login( 'administrator' );
		$this->assertRefused(
			$this->ajax( 'pull_fetch', $this->pull_creds() ),
			'Run the export on the old site first.'
		);
	}

	public function test_replace_dry_run() {
		global $wpdb;
		$this->login( 'administrator' );
		self::factory()->post->create( array( 'post_content' => 'See http://old.test/about' ) );
		$start = $this->ajax(
			'replace_start',
			array(
				'args' => wp_json_encode(
					array(
						'search'  => 'http://old.test',
						'replace' => 'https://new.example',
						'tables'  => array( $wpdb->posts ),
						'dry_run' => 1,
					)
				),
			)
		)['data'];
		$this->assertTrue( $start['dry_run'] );
		$this->assertSame( $wpdb->posts, $start['table'] );

		do {
			$run = $this->ajax( 'replace_run', array( 'run' => $start['id'] ) )['data'];
		} while ( ! $run['complete'] );
		$this->assertSame( 1, $run['tables'][0]['rows'] );
		$this->assertRefused( $this->ajax( 'replace_run', array( 'run' => $start['id'] ) ), 'This run has expired. Start it again.' );
		$this->assertSame( array(), $this->ajax( 'replace_history' )['data']['runs'] );
	}

	public function test_replace_run_rollback_and_forget() {
		global $wpdb;
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_content' => 'See http://old.test/about' ) );
		$id      = $this->ajax(
			'replace_start',
			array(
				'args' => wp_json_encode(
					array(
						'search'  => 'http://old.test',
						'replace' => 'https://new.example',
						'tables'  => array( $wpdb->posts ),
					)
				),
			)
		)['data']['id'];
		do {
			$run = $this->ajax( 'replace_run', array( 'run' => $id ) )['data'];
		} while ( ! $run['complete'] );
		wp_cache_flush();
		$this->assertSame( 'See https://new.example/about', get_post( $post_id )->post_content );

		$runs = $this->ajax( 'replace_history' )['data']['runs'];
		$this->assertSame( array( $id ), array_column( $runs, 'id' ) );
		$this->assertArrayNotHasKey( 'backup', $runs[0] );

		do {
			$undo = $this->ajax( 'replace_rollback', array( 'run' => $id ) )['data'];
		} while ( 'rolled_back' !== $undo['status'] );
		wp_cache_flush();
		$this->assertSame( 'See http://old.test/about', get_post( $post_id )->post_content );
		$this->assertRefused( $this->ajax( 'replace_rollback', array( 'run' => $id ) ), 'This replacement can no longer be rolled back.' );

		$this->assertSame( array( 'success' => true ), $this->ajax( 'replace_forget', array( 'run' => $id ) ) );
		$this->assertSame( array(), MigratePlus_Replace::history() );
	}
}
