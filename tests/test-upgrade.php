<?php
/**
 * Moving an install's data from before the plugin was renamed (PressPort), and
 * importing files exported under the old name.
 *
 * @package MigratePlus
 */
class Test_Upgrade extends WP_UnitTestCase {

	/**
	 * @return string The storage folder as it was named before the rename.
	 */
	protected function old_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'pressport/';
	}

	public function tear_down() {
		foreach ( array( $this->old_dir(), MigratePlus_Util::storage_dir() ) as $dir ) {
			foreach ( glob( $dir . 'upgrade-test-*' ) as $file ) {
				wp_delete_file( $file );
			}
		}
		foreach ( array( 'index.php', '.htaccess' ) as $name ) {
			if ( file_exists( $this->old_dir() . $name ) ) {
				wp_delete_file( $this->old_dir() . $name );
			}
		}
		if ( is_dir( $this->old_dir() ) ) {
			rmdir( $this->old_dir() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		parent::tear_down();
	}

	public function test_run_moves_options_meta_files_and_saved_paths() {
		$old = $this->old_dir();
		wp_mkdir_p( $old );
		file_put_contents( $old . 'index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $old . 'upgrade-test-cache.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $old . 'upgrade-test-export.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $old . 'upgrade-test-backup.jsonl', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Util::ensure_storage_dir(); // Activating the renamed plugin creates the new folder first.

		update_option( 'pressport_settings', array( 'batch_size' => 7 ) );
		update_option( 'pressport_mappings', array( 'Feed' => array( 'name' => 'Feed' ) ), false );
		update_option( 'pressport_job_ids', array( 'abc' ), false );
		update_option(
			'pressport_job_abc',
			array(
				'id'       => 'abc',
				'source'   => 'json',
				'settings' => array(
					'pressport_site'      => 'http://old.test',
					'pressport_post_type' => 'post',
					'cache_file'          => $old . 'upgrade-test-cache.json',
					'file_name'           => 'site.json',
				),
			),
			false
		);
		update_option( 'pressport_export_e1', array( 'file' => $old . 'upgrade-test-export.json' ), false );
		update_option( 'pressport_replacelog_r1', array( 'backup' => $old . 'upgrade-test-backup.jsonl' ), false );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pressport_cleanup' );

		$post = self::factory()->post->create();
		add_post_meta( $post, '_pressport_source_uid', 'uid-1' );
		add_post_meta( $post, '_pressport_job_id', 'abc' );
		add_post_meta( $post, '_pressport_pending_' . md5( 'sku:X' ), 'upsell' );
		add_post_meta( $post, 'unrelated', 'kept' );
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
		add_comment_meta( $comment, '_pressport_source_comment_uid', 'c-1' );
		$menu = wp_create_nav_menu( 'Upgrade test' );
		add_term_meta( $menu, '_pressport_job_id', 'abc' );

		MigratePlus_Upgrade::run();

		$new = MigratePlus_Util::storage_dir();
		foreach ( array( 'pressport_settings', 'pressport_mappings', 'pressport_job_ids', 'pressport_job_abc', 'pressport_export_e1', 'pressport_replacelog_r1' ) as $name ) {
			$this->assertFalse( get_option( $name ), $name );
		}
		$this->assertSame( 7, MigratePlus_Settings::get( 'batch_size' ) );
		$this->assertSame( array( 'Feed' ), array_keys( MigratePlus_Mappings::all() ) );
		$this->assertSame( array( 'abc' ), get_option( 'migrateplus_job_ids' ) );

		$job = MigratePlus_Job::load( 'abc' );
		$this->assertSame( 'http://old.test', $job->data['settings']['migrateplus_site'] );
		$this->assertSame( 'post', $job->data['settings']['migrateplus_post_type'] );
		$this->assertArrayNotHasKey( 'pressport_site', $job->data['settings'] );
		$this->assertSame( $new . 'upgrade-test-cache.json', $job->data['settings']['cache_file'] );
		$this->assertFileExists( $job->data['settings']['cache_file'] );
		$this->assertSame( $new . 'upgrade-test-export.json', MigratePlus_Exporter::load_state( 'e1' )['file'] );
		$this->assertSame( $new . 'upgrade-test-backup.jsonl', MigratePlus_Replace::history_entry( 'r1' )['backup'] );
		$this->assertDirectoryDoesNotExist( $old );
		// "Imported before" still matches, since the uid is the same, only under the new key.
		$this->assertSame( $post, MigratePlus_Importer::find_by_uid( 'uid-1', 'post' ) );

		$this->assertSame( 'uid-1', get_post_meta( $post, '_migrateplus_source_uid', true ) );
		$this->assertSame( 'abc', get_post_meta( $post, '_migrateplus_job_id', true ) );
		$this->assertSame( 'upsell', get_post_meta( $post, '_migrateplus_pending_' . md5( 'sku:X' ), true ) );
		$this->assertSame( 'kept', get_post_meta( $post, 'unrelated', true ) );
		$this->assertSame( '', get_post_meta( $post, '_pressport_source_uid', true ) );
		$this->assertSame( 'c-1', get_comment_meta( $comment, '_migrateplus_source_comment_uid', true ) );
		$this->assertSame( '', get_comment_meta( $comment, '_pressport_source_comment_uid', true ) );
		$this->assertSame( array( $menu ), $job->imported_menus() );
		$this->assertFalse( wp_next_scheduled( 'pressport_cleanup' ) );
	}

	public function test_maybe_run_only_runs_once() {
		delete_option( MigratePlus_Upgrade::FLAG );
		update_option( 'pressport_settings', array( 'batch_size' => 3 ) );
		MigratePlus_Upgrade::maybe_run();
		$this->assertFalse( get_option( 'pressport_settings' ) );
		$this->assertSame( array( 'batch_size' => 3 ), get_option( 'migrateplus_settings' ) );

		update_option( 'pressport_settings', array( 'batch_size' => 9 ) );
		MigratePlus_Upgrade::maybe_run();
		$this->assertSame( array( 'batch_size' => 9 ), get_option( 'pressport_settings' ) );
		$this->assertSame( array( 'batch_size' => 3 ), get_option( 'migrateplus_settings' ) );
		MigratePlus_Settings::flush_cache();
	}

	/**
	 * Two first page loads running the move at once: the other one has already moved an
	 * option and deleted it, while this one's cache still says the new name is free.
	 */
	public function test_run_never_writes_over_an_option_moved_by_another_request() {
		global $wpdb;
		update_option( 'migrateplus_raced', 'moved', false );
		update_option( 'migrateplus_stale', 'current', false );
		// Old rows this request listed, and its cache: one already deleted, both new names missing.
		foreach ( array( 'pressport_raced', 'pressport_stale' ) as $name ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => 'old',
					'autoload'     => 'no',
				)
			);
		}
		wp_cache_delete( 'migrateplus_raced', 'options' );
		wp_cache_delete( 'migrateplus_stale', 'options' );
		wp_cache_set(
			'notoptions',
			array(
				'migrateplus_raced' => true,
				'migrateplus_stale' => true,
				'pressport_raced'   => true,
			),
			'options'
		);

		MigratePlus_Upgrade::run();

		$this->assertSame( 'moved', get_option( 'migrateplus_raced' ) );
		$this->assertSame( 'current', get_option( 'migrateplus_stale' ) );
		$this->assertFalse( get_option( 'pressport_stale' ) );
		MigratePlus_Settings::flush_cache();
	}

	/**
	 * A file exported before the rename has its header under "pressport". It still
	 * imports as an export, and matches what it imported before once the upgrade
	 * has renamed that content's tracking meta.
	 */
	public function test_old_export_imports_and_matches_posts_imported_before_the_rename() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Imported before the rename: the old meta keys, and the uid the old code wrote (same formula).
		$before = self::factory()->post->create( array( 'post_title' => 'Old title' ) );
		add_post_meta( $before, '_pressport_source_uid', md5( 'json|http://old.test|5' ) );
		add_post_meta( $before, '_pressport_source_id', '5' );
		add_post_meta( $before, '_pressport_job_id', 'oldjob' );
		MigratePlus_Upgrade::run();

		$file = wp_tempnam( 'upgrade-test.json' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$file,
			wp_json_encode(
				array(
					'pressport' => array(
						'site'      => 'http://old.test',
						'post_type' => 'post',
						'mapping'   => array(
							'fields'      => array(
								'source_id'  => 'id',
								'post_title' => 'title',
							),
							'meta'        => array(
								array(
									'key'    => 'tag',
									'source' => 'meta.tag',
								),
							),
							'meta_native' => 1,
						),
					),
					'items'     => array(
						array(
							'id'    => 5,
							'title' => 'New title',
							'meta'  => array( 'tag' => array( '_pressport_rows' => array( 'a', 'b' ) ) ),
						),
						array(
							'id'    => 6,
							'title' => 'Another',
						),
					),
				)
			)
		);
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		wp_delete_file( $file );
		$this->assertIsArray( $preview );
		$job = $preview['job'];
		$this->assertSame( 'http://old.test', $job->data['settings']['migrateplus_site'] );
		$this->assertArrayHasKey( 'migrateplus_mapping', $job->data['settings'] );

		$found = MigratePlus_Runner::find_duplicates( $job );
		$this->assertSame( 1, $found['before'] );
		$this->assertSame( $before, $found['examples'][0]['post_id'] );

		$job->data['options']['duplicate'] = 'update';
		$job->save();
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			$this->assertIsArray( $result );
		} while ( ! $result['done'] );

		$this->assertSame( 1, $job->data['stats']['updated'] );
		$this->assertSame( 1, $job->data['stats']['created'] );
		$this->assertSame( 'New title', get_the_title( $before ) );
		$this->assertSame( array( 'a', 'b' ), get_post_meta( $before, 'tag', false ) );
		$job->delete();
	}

	public function failed_writes() {
		return array(
			'option copy' => array( 'INSERT INTO', 'migrateplus_kept' ),
			'meta rename' => array( 'UPDATE', 'CONCAT' ),
		);
	}

	/**
	 * A write that fails keeps the old copy and leaves the move to be tried again on the next load.
	 *
	 * @dataProvider failed_writes
	 *
	 * @param string $verb   Start of the query that fails.
	 * @param string $needle Text in it.
	 */
	public function test_maybe_run_tries_again_when_a_write_failed( $verb, $needle ) {
		global $wpdb;
		delete_option( MigratePlus_Upgrade::FLAG );
		update_option( 'pressport_kept', 'value', false );
		$post = self::factory()->post->create();
		add_post_meta( $post, '_pressport_job_id', 'abc' );
		$fail = static function ( $query ) use ( $verb, $needle ) {
			return 0 === strpos( ltrim( $query ), $verb ) && false !== strpos( $query, $needle ) ? 'SELECT broken FROM' : $query;
		};
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		MigratePlus_Upgrade::maybe_run();
		$wpdb->suppress_errors( $errors );
		remove_filter( 'query', $fail );
		$this->assertFalse( get_option( MigratePlus_Upgrade::FLAG ) );
		if ( 'INSERT INTO' === $verb ) {
			$this->assertSame( 'value', get_option( 'pressport_kept' ) );
		}

		MigratePlus_Upgrade::maybe_run();
		$this->assertSame( '1', (string) get_option( MigratePlus_Upgrade::FLAG ) );
		$this->assertSame( 'value', get_option( 'migrateplus_kept' ) );
		$this->assertSame( 'abc', get_post_meta( $post, '_migrateplus_job_id', true ) );
	}

	public function test_a_new_install_keeps_the_object_cache() {
		wp_cache_set( 'probe', 'kept', 'migrateplus_test' );
		MigratePlus_Upgrade::run();
		$this->assertSame( 'kept', wp_cache_get( 'probe', 'migrateplus_test' ) );
	}

	/**
	 * A move that keeps failing runs again on every load, and must not empty the object cache each time.
	 */
	public function test_a_run_that_moved_nothing_keeps_the_object_cache() {
		global $wpdb;
		delete_option( MigratePlus_Upgrade::FLAG );
		update_option( 'pressport_kept', 'value', false );
		add_post_meta( self::factory()->post->create(), '_pressport_job_id', 'abc' );
		$fail = static function ( $query ) {
			$query = ltrim( $query );
			$copy  = 0 === strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, 'migrateplus_kept' );
			$meta  = 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, 'CONCAT' );
			return $copy || $meta ? 'SELECT broken FROM' : $query;
		};
		add_filter( 'query', $fail );
		$errors = $wpdb->suppress_errors( true );
		wp_cache_set( 'probe', 'kept', 'migrateplus_test' );
		MigratePlus_Upgrade::maybe_run();
		$wpdb->suppress_errors( $errors );
		remove_filter( 'query', $fail );

		$this->assertFalse( get_option( MigratePlus_Upgrade::FLAG ) );
		$this->assertSame( 'kept', wp_cache_get( 'probe', 'migrateplus_test' ) );
	}

	/**
	 * The old folder's index.php and .htaccess keep guarding a file that could not be moved.
	 */
	public function test_guard_files_stay_while_a_file_is_left_in_the_old_folder() {
		$old = $this->old_dir();
		$new = MigratePlus_Util::ensure_storage_dir();
		wp_mkdir_p( $old );
		foreach ( array( 'index.php', '.htaccess' ) as $guard ) {
			file_put_contents( $old . $guard, 'guard' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		file_put_contents( $old . 'upgrade-test-moved.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// The same name in both folders: this one can't be moved.
		file_put_contents( $old . 'upgrade-test-stuck.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $new . 'upgrade-test-stuck.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		MigratePlus_Upgrade::run();

		$this->assertFileExists( $new . 'upgrade-test-moved.json' );
		$this->assertFileExists( $old . 'upgrade-test-stuck.json' );
		$this->assertFileExists( $old . 'index.php' );
		$this->assertFileExists( $old . '.htaccess' );
	}
}
