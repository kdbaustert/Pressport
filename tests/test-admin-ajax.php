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

	public function actions() {
		$actions = array( 'preview', 'duplicates', 'media_preview', 'save', 'run', 'rollback', 'delete', 'history_delete', 'jobs', 'job', 'restart', 'export_count', 'export_start', 'export_run', 'export_download', 'exports', 'export_log', 'export_forget', 'replace_start', 'replace_run', 'replace_history', 'replace_rollback', 'replace_forget', 'mapping_save', 'mapping_rename', 'mapping_delete' );
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

	public function test_rollback() {
		$this->login( 'administrator' );
		$id = $this->import_books( 3 );
		do {
			$out = $this->ajax( 'rollback', array( 'job' => $id ) );
		} while ( ! $out['data']['done'] );
		$this->assertSame( '', $out['data']['error'] );
		$this->assertSame( 0, $out['data']['remaining'] );
		$this->assertSame( 'rolled_back', $out['data']['job']['status'] );
		$this->assertSame( array(), get_posts( array( 'title' => 'Dune' ) ) );
	}

	public function test_restart_forgets_approved_changes() {
		$this->login( 'administrator' );
		$job                                = MigratePlus_Job::load( $this->import_books( 1 ) );
		$job->data['options']['update_ids'] = array( 5 );
		$job->save();

		$out = $this->ajax( 'restart', array( 'job' => $job->data['id'] ) )['data']['job'];
		$this->assertSame( 'ready', $out['status'] );
		$this->assertSame( 0, $out['stats']['processed'] );
		$this->assertSame( array(), $out['options']['update_ids'] );
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
		$this->assertRefused( $out, 'MigratePlus exports carry their own mapping, so there is nothing to save.' );
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
						'post_types' => array( 'post' ),
						'statuses'   => array( 'future' ),
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
					'post_types'    => array( 'post' ),
					'statuses'      => array( 'future' ),
					'include_menus' => 1,
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
		$this->assertSame( '', $this->ajax( 'exports' )['data']['exports'][0]['url'] );
		$this->assertNotNull( MigratePlus_Exporter::history_entry( $id ) );
	}

	public function test_export_download_before_the_export_finished() {
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->login( 'administrator' );
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		$out   = $this->ajax( 'export_download', array( 'export' => $state['id'] ) );
		$this->assertSame( array( 'die' => 'This export is not ready or has expired. Run it again.' ), $out );
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
