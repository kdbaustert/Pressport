<?php
/**
 * The source registry and the migrateplus_sources filter.
 *
 * @package MigratePlus
 */

require_once __DIR__ . '/class-migrateplus-test-source.php';

class Test_Sources extends WP_UnitTestCase {

	public function add_sources( $sources ) {
		return $sources + array(
			'books' => 'MigratePlus_Test_Source',
			'ghost' => 'MigratePlus_No_Such_Source',
			'plain' => 'stdClass',
		);
	}

	public function test_json_is_the_only_built_in_source() {
		$this->assertSame( array( 'json' => 'MigratePlus_Source_JSON' ), MigratePlus_Sources::all() );
	}

	public function test_filter_adds_a_source() {
		add_filter( 'migrateplus_sources', array( $this, 'add_sources' ) );
		$this->assertSame( 'MigratePlus_Test_Source', MigratePlus_Sources::all()['books'] );

		$source = MigratePlus_Sources::create( 'books', array( 'count' => '2' ) );
		$this->assertInstanceOf( 'MigratePlus_Test_Source', $source );
		$this->assertSame( array( 'Dune', 'Emma' ), array_column( $source->fetch( null, 10 )['records'], 'title' ) );
	}

	public function test_create_refuses_unknown_ids_and_invalid_classes() {
		add_filter( 'migrateplus_sources', array( $this, 'add_sources' ) );
		$this->assertSame( 'Unknown source "nope".', MigratePlus_Sources::create( 'nope', array() )->get_error_message() );
		$this->assertSame( 'Unknown source "ghost".', MigratePlus_Sources::create( 'ghost', array() )->get_error_message() );
		$this->assertSame( 'Source class must implement MigratePlus_Source.', MigratePlus_Sources::create( 'plain', array() )->get_error_message() );
	}

	public function test_a_job_with_a_removed_source_reports_it() {
		$job = MigratePlus_Job::create( 'books', array() );
		$this->assertWPError( $job->source() );
		$this->assertWPError( MigratePlus_Runner::find_duplicates( $job ) );
		$job->delete();
	}

	public function test_admin_lists_only_usable_sources() {
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		add_filter(
			'migrateplus_sources',
			static function ( $sources ) {
				return $sources + array(
					'books' => 'MigratePlus_Test_Source',
					'ghost' => 'MigratePlus_No_Such_Source',
					'plain' => 'stdClass',
				);
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = new ReflectionMethod( 'MigratePlus_Admin', 'bootstrap_data' );
		if ( PHP_VERSION_ID < 80100 ) {
			$data->setAccessible( true ); // Needed before PHP 8.1, deprecated in 8.5.
		}
		$sources = $data->invoke( null, 'new' )['sources'];
		$this->assertSame( array( 'json', 'books' ), array_column( $sources, 'id' ) );
		$this->assertSame( 'Book feed', $sources[1]['label'] );
		$this->assertSame( 'count', $sources[1]['schema'][0]['key'] );
	}

	/**
	 * "Run again" prepares the refreshed file over the last run's settings.
	 */
	public function test_json_refreshed_without_menus_drops_the_old_menus() {
		$export = array(
			'migrateplus' => array(
				'site'    => 'http://old.test',
				'mapping' => array( 'fields' => array( 'source_id' => 'id' ) ),
			),
			'items'       => array( array( 'id' => 1 ) ),
			'menus'       => array(
				array(
					'name'  => 'Main',
					'items' => array(),
				),
			),
		);
		$file   = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, wp_json_encode( $export ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$first = ( new MigratePlus_Source_JSON( array( 'file' => $file ) ) )->prepare();
		$this->assertIsArray( $first );
		$this->assertFileExists( $first['menus_file'] );
		$this->assertSame( 1, $first['migrateplus_menus'] );

		unset( $export['menus'] );
		file_put_contents( $file, wp_json_encode( $export ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$again = ( new MigratePlus_Source_JSON( $first ) )->prepare();
		wp_delete_file( $file );
		wp_delete_file( $first['cache_file'] );
		$this->assertIsArray( $again );
		wp_delete_file( $again['cache_file'] );
		$this->assertArrayNotHasKey( 'menus_file', $again );
		$this->assertArrayNotHasKey( 'migrateplus_menus', $again );
		$this->assertFileDoesNotExist( $first['menus_file'] );
	}

	public function test_guess_mapping_takes_each_source_field_once() {
		$this->assertSame(
			array(
				'source_id'      => 'ID',
				'post_title'     => 'headline',
				'post_content'   => 'body',
				'post_date'      => 'published_at',
				'featured_image' => 'image',
			),
			MigratePlus_Sources::guess_mapping( array( 'ID', 'headline', 'body', 'published_at', 'image', 'unrelated' ) )
		);
	}

	public function test_json_cache_reads_one_batch_at_a_time() {
		$records = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$records[] = array(
				'id'    => $i,
				'title' => 'Line ' . $i . "\nwith a break",
			);
		}
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, wp_json_encode( $records ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$prepared = ( new MigratePlus_Source_JSON( array( 'file' => $file ) ) )->prepare();
		wp_delete_file( $file );
		$source = new MigratePlus_Source_JSON( $prepared );

		$middle = $source->fetch( 2, 2 );
		$last   = $source->fetch( $middle['next'], 10 );
		wp_delete_file( $prepared['cache_file'] );
		$this->assertSame( array( 3, 4 ), array_column( $middle['records'], 'id' ) );
		$this->assertSame( "Line 3\nwith a break", $middle['records'][0]['title'] );
		$this->assertSame( 4, $middle['next'] );
		$this->assertSame( 5, $middle['total'] );
		$this->assertSame( array( 5 ), array_column( $last['records'], 'id' ) );
		$this->assertNull( $last['next'] );
	}

	/**
	 * A job started before the cache had an index, resumed after the update.
	 */
	public function test_json_cache_written_whole_by_an_older_version_still_reads() {
		$cache = MigratePlus_Util::ensure_storage_dir() . 'json-oldformat.json';
		file_put_contents( $cache, wp_json_encode( array( array( 'id' => 1 ), array( 'id' => 2 ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$batch = ( new MigratePlus_Source_JSON( array( 'cache_file' => $cache ) ) )->fetch( 1, 5 );
		wp_delete_file( $cache );
		$this->assertSame( array( 2 ), array_column( $batch['records'], 'id' ) );
		$this->assertNull( $batch['next'] );
	}

	public function test_plain_json_over_the_size_limit_is_refused() {
		$file  = wp_tempnam( 'migrateplus-test.json' );
		$limit = static function () {
			return 10;
		};
		file_put_contents( $file, '[{"title":"More than ten bytes"}]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		add_filter( 'migrateplus_max_json_size', $limit );
		$out = ( new MigratePlus_Source_JSON( array( 'file' => $file ) ) )->prepare();
		remove_filter( 'migrateplus_max_json_size', $limit );
		wp_delete_file( $file );
		$this->assertSame( 'This file holds more than 10 B of JSON, more than this server can import. Use a smaller export, or raise PHP\'s memory limit.', $out->get_error_message() );
	}

	public function test_a_batch_that_throws_unlocks_the_job_and_turns_counting_back_on() {
		add_filter( 'migrateplus_sources', array( $this, 'add_sources' ) );
		$job                           = MigratePlus_Job::create( 'books', array( 'count' => '2' ) );
		MigratePlus_Test_Source::$down = true;
		try {
			MigratePlus_Runner::run_batch( $job );
			$this->fail( 'The batch should have thrown.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'The feed is down.', $e->getMessage() );
		} finally {
			MigratePlus_Test_Source::$down = false;
		}
		wp_cache_delete( 'migrateplus_lock_' . $job->data['id'], 'options' );
		$this->assertFalse( get_option( 'migrateplus_lock_' . $job->data['id'] ) );
		$this->assertFalse( wp_defer_term_counting() );
		$this->assertFalse( wp_defer_comment_counting() );

		$result = MigratePlus_Runner::run_batch( $job );
		remove_filter( 'migrateplus_sources', array( $this, 'add_sources' ) );
		$this->assertIsArray( $result );
		$job->delete();
	}
}
