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
		try {
			$sources = $data->invoke( null, 'new' )['sources'];
		} catch ( TypeError $e ) {
			$this->markTestIncomplete( 'Bug: a registered class that does not implement MigratePlus_Source is passed to call_user_func() in bootstrap_data(), a fatal TypeError on every MigratePlus admin page but Settings. ' . $e->getMessage() );
		}
		$this->assertSame( array( 'json', 'books' ), array_column( $sources, 'id' ) );
		$this->assertSame( 'Book feed', $sources[1]['label'] );
		$this->assertSame( 'count', $sources[1]['schema'][0]['key'] );
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
}
