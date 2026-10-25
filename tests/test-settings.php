<?php
/**
 * Settings: sanitizing, the cached get(), who may change them, and history cleanup.
 *
 * @package MigratePlus
 */
class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( MigratePlus_Job::all() as $job ) {
			$job->delete();
		}
		foreach ( MigratePlus_Exporter::history() as $entry ) {
			MigratePlus_Exporter::forget( $entry['id'] );
		}
		foreach ( MigratePlus_Replace::history() as $entry ) {
			MigratePlus_Replace::delete( $entry['id'] );
			MigratePlus_Replace::forget( $entry['id'] );
		}
		delete_option( MigratePlus_Settings::OPTION );
		MigratePlus_Settings::flush_cache();
		parent::tear_down();
	}

	public function test_sanitize_keeps_valid_values() {
		$out = MigratePlus_Settings::sanitize(
			array(
				'post_type'           => 'page',
				'default_status'      => 'draft',
				'force_status'        => 'private',
				'duplicate'           => 'update',
				'capability'          => 'edit_others_posts',
				'batch_size'          => '25',
				'max_media_size'      => '8',
				'allow_local_sources' => '1',
				'export_statuses'     => array( 'publish', 'future' ),
			)
		);
		$this->assertSame( 'page', $out['post_type'] );
		$this->assertSame( 'draft', $out['default_status'] );
		$this->assertSame( 'private', $out['force_status'] );
		$this->assertSame( 'update', $out['duplicate'] );
		$this->assertSame( 'edit_others_posts', $out['capability'] );
		$this->assertSame( 25, $out['batch_size'] );
		$this->assertSame( 8, $out['max_media_size'] );
		$this->assertSame( 1, $out['allow_local_sources'] );
		$this->assertSame( array( 'publish', 'future' ), $out['export_statuses'] );
	}

	public function test_sanitize_drops_unknown_keys() {
		$out = MigratePlus_Settings::sanitize( array( 'debug_mode' => 1 ) );
		$this->assertEqualSets( array_keys( MigratePlus_Settings::defaults() ), array_keys( $out ) );
		$this->assertSame( 'manage_options', MigratePlus_Settings::sanitize( 'broken' )['capability'] );
	}

	/**
	 * @dataProvider out_of_range
	 *
	 * @param string $key      Setting.
	 * @param mixed  $value    Submitted.
	 * @param mixed  $expected Stored.
	 */
	public function test_sanitize_falls_back_or_clamps( $key, $value, $expected ) {
		$this->assertSame( $expected, MigratePlus_Settings::sanitize( array( $key => $value ) )[ $key ] );
	}

	public function out_of_range() {
		return array(
			'batch size 0'          => array( 'batch_size', '0', 1 ),
			'batch size 1000'       => array( 'batch_size', '1000', 100 ),
			'empty batch size'      => array( 'batch_size', '', 10 ),
			'timeout 1'             => array( 'request_timeout', '1', 60 ),
			'timeout 59'            => array( 'request_timeout', '59', 60 ),
			'timeout 9999'          => array( 'request_timeout', '9999', 300 ),
			'remote size 0'         => array( 'max_remote_size', '0', 1 ),
			'remote size 5000'      => array( 'max_remote_size', '5000', 2048 ),
			'media size -5'         => array( 'max_media_size', '-5', 0 ),
			'media size 4096'       => array( 'max_media_size', '4096', 2048 ),
			'retention -1'          => array( 'job_retention_days', '-1', 0 ),
			'retention 99999'       => array( 'job_retention_days', '99999', 3650 ),
			'status trash'          => array( 'default_status', 'trash', 'publish' ),
			'force status future'   => array( 'force_status', 'future', '' ),
			'duplicate merge'       => array( 'duplicate', 'merge', 'skip' ),
			'capability edit_posts' => array( 'capability', 'edit_posts', 'manage_options' ),
			'capability as a list'  => array( 'capability', array( 'edit_others_posts' ), 'manage_options' ),
			'unknown post type'     => array( 'post_type', 'recipe', 'post' ),
			'post type in caps'     => array( 'post_type', 'Page', 'page' ),
			'checkbox on'           => array( 'import_menus', 'on', 1 ),
			'checkbox 0'            => array( 'create_terms', '0', 0 ),
			'unticked checkbox'     => array( 'import_comments', null, 0 ),
			'statuses filtered'     => array( 'export_statuses', array( 'draft', 'trash', 'FUTURE' ), array( 'draft', 'future' ) ),
			'one status as text'    => array( 'export_statuses', 'pending', array( 'pending' ) ),
			'no valid status'       => array( 'export_statuses', array( 'trash' ), array( 'publish' ) ),
			'exclude meta'          => array( 'export_exclude_meta', " rank_math_* , foo\r\n\r\n<b>bar</b>\nfoo\n", "rank_math_*\nfoo\nbar" ),
		);
	}

	public function test_get_merges_defaults() {
		update_option( MigratePlus_Settings::OPTION, array( 'batch_size' => 42 ) );
		MigratePlus_Settings::flush_cache();
		$this->assertSame( 42, MigratePlus_Settings::get( 'batch_size' ) );
		$this->assertSame( 'post', MigratePlus_Settings::get( 'post_type' ) );
		$this->assertNull( MigratePlus_Settings::get( 'debug_mode' ) );

		update_option( MigratePlus_Settings::OPTION, 'broken' );
		MigratePlus_Settings::flush_cache();
		$this->assertSame( 10, MigratePlus_Settings::get( 'batch_size' ) );
	}

	public function test_cache_flushes_on_update() {
		update_option( MigratePlus_Settings::OPTION, array( 'batch_size' => 5 ) );
		MigratePlus_Settings::flush_cache();
		MigratePlus_Settings::get();

		// init() is only hooked in wp-admin, so without it the cached copy goes stale.
		update_option( MigratePlus_Settings::OPTION, array( 'batch_size' => 6 ) );
		$this->assertSame( 5, MigratePlus_Settings::get( 'batch_size' ) );

		MigratePlus_Settings::init();
		update_option( MigratePlus_Settings::OPTION, array( 'batch_size' => 7 ) );
		$this->assertSame( 7, MigratePlus_Settings::get( 'batch_size' ) );
	}

	public function test_settings_api_save_is_sanitized() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		MigratePlus_Settings::init();
		MigratePlus_Settings::register();
		update_option(
			MigratePlus_Settings::OPTION,
			array(
				'batch_size' => 999,
				'capability' => 'read',
			)
		);
		$stored = get_option( MigratePlus_Settings::OPTION );
		$this->assertSame( 100, $stored['batch_size'] );
		$this->assertSame( 'manage_options', $stored['capability'] );
		unregister_setting( MigratePlus_Settings::GROUP, MigratePlus_Settings::OPTION );
	}

	/**
	 * @group ms-required
	 */
	public function test_only_a_super_admin_changes_local_sources_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 0, MigratePlus_Settings::sanitize( array( 'allow_local_sources' => '1' ) )['allow_local_sources'] );
		grant_super_admin( get_current_user_id() );
		$this->assertSame( 1, MigratePlus_Settings::sanitize( array( 'allow_local_sources' => '1' ) )['allow_local_sources'] );
	}

	public function test_settings_capability_is_always_manage_options() {
		update_option( MigratePlus_Settings::OPTION, array( 'capability' => 'edit_others_posts' ) );
		MigratePlus_Settings::flush_cache();
		MigratePlus_Settings::init();
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_' . MigratePlus_Settings::GROUP, 'edit_others_posts' ) );
	}

	public function test_cleanup_with_retention_off_keeps_everything() {
		$job                 = MigratePlus_Job::create( 'json', array() );
		$job->data['status'] = 'complete'; // A draft is purged after a day whatever the retention: see below.
		$job->save();
		$this->backdate( 'migrateplus_job_' . $job->data['id'], 'updated', 400 );
		MigratePlus_Settings::cleanup();
		$this->assertNotNull( MigratePlus_Job::load( $job->data['id'] ) );
	}

	public function test_cleanup_purges_imports_never_started_after_a_day() {
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, '[{"title":"Hello"}]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$old   = MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'];
		$fresh = MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'];
		wp_delete_file( $file );
		$this->backdate( 'migrateplus_job_' . $old->data['id'], 'updated', 2 );

		MigratePlus_Settings::cleanup();

		$this->assertNull( MigratePlus_Job::load( $old->data['id'] ) );
		$this->assertFileDoesNotExist( $old->data['settings']['cache_file'] );
		$this->assertNotNull( MigratePlus_Job::load( $fresh->data['id'] ) );
	}

	public function test_cleanup_skips_live_running_jobs_and_rollbacks() {
		global $wpdb;
		update_option( MigratePlus_Settings::OPTION, array( 'job_retention_days' => 10 ) );
		MigratePlus_Settings::flush_cache();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_content' => 'See http://old.test/about' ) );

		$old     = MigratePlus_Job::create( 'json', array() );
		$running = MigratePlus_Job::create( 'json', array() );
		$stalled = MigratePlus_Job::create( 'json', array() );
		$recent  = MigratePlus_Job::create( 'json', array() );
		foreach ( array( $old, $recent ) as $job ) {
			$job->data['status'] = 'complete';
			$job->save();
		}
		// Both last saved 11 days ago; a batch still holds the lock of one, the other's requests died.
		foreach ( array( $running, $stalled ) as $job ) {
			$job->data['status'] = 'running';
			$job->save();
		}
		$lock = MigratePlus_Runner::lock( $running );
		$this->backdate( 'migrateplus_job_' . $old->data['id'], 'updated', 11 );
		$this->backdate( 'migrateplus_job_' . $running->data['id'], 'updated', 11 );
		$this->backdate( 'migrateplus_job_' . $stalled->data['id'], 'updated', 11 );
		$this->backdate( 'migrateplus_job_' . $recent->data['id'], 'updated', 9 );

		$old_export    = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		$recent_export = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		$this->backdate( MigratePlus_Exporter::LOG_PREFIX . $old_export['id'], 'created', 11 );

		$runs = array();
		foreach ( array( 'complete', 'rolling_back' ) as $status ) {
			$state  = MigratePlus_Replace::start(
				array(
					'search'  => 'http://old.test',
					'replace' => 'https://new.example',
					'tables'  => array( $wpdb->posts ),
				)
			);
			$option = MigratePlus_Replace::LOG_PREFIX . $state['id'];
			update_option( $option, array_merge( get_option( $option ), array( 'status' => $status ) ), false );
			$this->backdate( $option, 'created', 11 );
			$runs[ $status ] = $state['id'];
		}

		MigratePlus_Settings::cleanup();
		MigratePlus_Runner::unlock( $running, $lock );

		$this->assertNull( MigratePlus_Job::load( $old->data['id'] ) );
		$this->assertNotNull( MigratePlus_Job::load( $running->data['id'] ) );
		$this->assertNull( MigratePlus_Job::load( $stalled->data['id'] ) );
		$this->assertNotNull( MigratePlus_Job::load( $recent->data['id'] ) );
		$this->assertNull( MigratePlus_Exporter::history_entry( $old_export['id'] ) );
		$this->assertFileDoesNotExist( $old_export['work'] );
		$this->assertNotNull( MigratePlus_Exporter::history_entry( $recent_export['id'] ) );
		$this->assertNull( MigratePlus_Replace::history_entry( $runs['complete'] ) );
		$this->assertNotNull( MigratePlus_Replace::history_entry( $runs['rolling_back'] ) );
	}

	/**
	 * @dataProvider storage_answers
	 *
	 * @param string    $answer   What the web server does with a file in the folder.
	 * @param bool|null $expected storage_public().
	 */
	public function test_storage_probe_reports_whether_files_are_served( $answer, $expected ) {
		$dir      = MigratePlus_Util::ensure_storage_dir();
		$requests = array();
		$serve    = static function ( $pre, $args, $url ) use ( $answer, $dir, &$requests ) {
			$requests[] = $url;
			if ( 'unreachable' === $answer ) {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
			// nginx without a deny rule hands over the file itself; Apache reads .htaccess and refuses.
			$body = 'served' === $answer ? (string) file_get_contents( $dir . wp_basename( $url ) ) : 'Forbidden'; // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 'served' === $answer ? 200 : 403,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $serve, 10, 3 );
		$first  = MigratePlus_Settings::storage_public();
		$second = MigratePlus_Settings::storage_public();
		remove_filter( 'pre_http_request', $serve );

		$this->assertSame( $expected, $first );
		$this->assertSame( $expected, $second );
		$this->assertCount( 1, $requests, 'The answer is cached for an hour.' );
		$uploads = wp_upload_dir( null, false );
		$this->assertStringStartsWith( trailingslashit( $uploads['baseurl'] ) . 'migrateplus/probe-', $requests[0] );
		$this->assertSame( array(), glob( $dir . 'probe-*' ), 'The probe file is deleted.' );
	}

	public function storage_answers() {
		return array(
			'served'      => array( 'served', true ),
			'denied'      => array( 'denied', false ),
			'unreachable' => array( 'unreachable', null ),
		);
	}

	/**
	 * Writes the option directly, because save() would stamp "updated" with the current time.
	 */
	protected function backdate( $option, $key, $days ) {
		$value         = get_option( $option );
		$value[ $key ] = time() - $days * DAY_IN_SECONDS;
		update_option( $option, $value, false );
	}
}
