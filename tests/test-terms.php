<?php
/**
 * The terms section of MigratePlus exports: descriptions, meta, hierarchy and
 * empty terms travel, re-imports update, and rollback removes created terms.
 *
 * @package MigratePlus
 */
class Test_Terms extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Write a JSON file for an import test.
	 *
	 * @param array $doc Document.
	 * @return string Path.
	 */
	protected function json_file( array $doc ) {
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	/**
	 * Preview and run a JSON import to the end.
	 *
	 * @param string $file    Path.
	 * @param array  $options Import options to change.
	 * @return MigratePlus_Job
	 */
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

	/**
	 * Run an export to the end and decode its file.
	 *
	 * @param array $args Export args.
	 * @return array
	 */
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

	public function test_terms_round_trip_keeps_description_meta_hierarchy_and_empty_terms() {
		$parent = self::factory()->category->create( array( 'name' => 'Gear' ) );
		$child  = self::factory()->category->create(
			array(
				'name'        => 'Tents',
				'slug'        => 'tents-shelters',
				'parent'      => $parent,
				'description' => 'Sleep outside.',
			)
		);
		add_term_meta( $child, 'color', 'green' );
		add_term_meta( $child, 'order', '2' );
		$empty = self::factory()->category->create( array( 'name' => 'Nobody Uses Me' ) );
		self::factory()->post->create(
			array(
				'post_title'    => 'A post',
				'post_category' => array( $child ),
			)
		);

		$doc = $this->export_doc( array( 'include_term_data' => 1 ) );
		$this->assertArrayHasKey( 'terms', $doc );
		$paths = array_column( $doc['terms'], 'path' );
		$this->assertContains( 'Gear', $paths );
		$this->assertContains( 'Gear > Tents', $paths );
		$this->assertContains( 'Nobody Uses Me', $paths );
		// Parents come before their children, so one pass can link them up.
		$this->assertLessThan( array_search( 'Gear > Tents', $paths, true ), array_search( 'Gear', $paths, true ) );

		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		foreach ( array( $child, $parent, $empty ) as $id ) {
			wp_delete_term( $id, 'category' );
		}

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame( 'terms', $job->data['sections'][0]['type'] );
		$this->assertSame( $job->data['sections'][0]['total'], $job->data['sections'][0]['done'] );

		$tents = get_term_by( 'slug', 'tents-shelters', 'category' );
		$this->assertInstanceOf( 'WP_Term', $tents );
		$this->assertSame( 'Sleep outside.', $tents->description );
		$this->assertSame( 'green', get_term_meta( $tents->term_id, 'color', true ) );
		$this->assertSame( '2', get_term_meta( $tents->term_id, 'order', true ) );
		$this->assertSame( 'Gear', get_term( $tents->parent, 'category' )->name );
		$this->assertSame( $job->data['id'], get_term_meta( $tents->term_id, '_migrateplus_job_id', true ) );
		$this->assertNotEmpty( get_term_meta( $tents->term_id, '_migrateplus_source_uid', true ) );
		$this->assertInstanceOf( 'WP_Term', get_term_by( 'name', 'Nobody Uses Me', 'category' ) );
		// The post the import created (the original is still here) landed in the imported term, which already had its data.
		$post = get_posts(
			array(
				'post_status' => 'any',
				'numberposts' => 1,
				'title'       => 'A post',
				'meta_key'    => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertTrue( has_term( $tents->term_id, 'category', $post[0] ) );
		$job->delete();
	}

	public function test_reimport_updates_terms_without_duplicates() {
		$term = self::factory()->category->create(
			array(
				'name'        => 'News',
				'description' => 'Old words.',
			)
		);
		self::factory()->post->create( array( 'post_category' => array( $term ) ) );
		$doc = $this->export_doc( array( 'include_term_data' => 1 ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );

		$before = (int) wp_count_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		$job    = $this->import_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame(
			$before,
			(int) wp_count_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				)
			)
		);
		// A term that was already here is updated, so it is never deleted by a rollback.
		$this->assertSame( '', (string) get_term_meta( $term, '_migrateplus_job_id', true ) );
		$job->delete();
		wp_delete_file( $file );
	}

	public function test_file_term_changes_reach_the_existing_term() {
		$term = self::factory()->category->create(
			array(
				'name'        => 'Guides',
				'description' => 'Before.',
			)
		);
		$doc  = $this->export_doc( array( 'include_term_data' => 1 ) );
		foreach ( $doc['terms'] as $i => $record ) {
			if ( 'Guides' === $record['name'] ) {
				$doc['terms'][ $i ]['description']   = 'After.';
				$doc['terms'][ $i ]['meta']['badge'] = 'star';
			}
		}
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );

		$job = $this->import_file( $file );
		$this->assertSame( 'After.', get_term( $term, 'category' )->description );
		$this->assertSame( 'star', get_term_meta( $term, 'badge', true ) );
		$job->delete();
		wp_delete_file( $file );
	}

	public function test_rollback_removes_created_terms_and_keeps_existing_ones() {
		$kept = self::factory()->category->create( array( 'name' => 'Stays' ) );
		self::factory()->category->create( array( 'name' => 'Goes' ) );
		$doc                        = $this->export_doc( array( 'include_term_data' => 1 ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$goes                       = get_term_by( 'name', 'Goes', 'category' );
		wp_delete_term( $goes->term_id, 'category' );

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertInstanceOf( 'WP_Term', get_term_by( 'name', 'Goes', 'category' ) );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( get_term_by( 'name', 'Goes', 'category' ) );
		$this->assertInstanceOf( 'WP_Term', get_term( $kept, 'category' ) );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$job->delete();
	}

	public function test_dry_run_saves_no_terms() {
		self::factory()->category->create( array( 'name' => 'Ghost' ) );
		$doc                        = $this->export_doc( array( 'include_term_data' => 1 ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$ghost                      = get_term_by( 'name', 'Ghost', 'category' );
		wp_delete_term( $ghost->term_id, 'category' );

		$job = $this->import_file( $file, array( 'dry_run' => 1 ) );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertFalse( get_term_by( 'name', 'Ghost', 'category' ) );
		$this->assertStringContainsString( 'Would create term "Ghost"', implode( "\n", wp_list_pluck( $job->data['log'], 'm' ) ) );
		$job->delete();
	}

	public function test_terms_of_an_unregistered_taxonomy_are_skipped_with_a_warning() {
		self::factory()->post->create( array( 'post_title' => 'Keeps the import going' ) );
		$doc                        = $this->export_doc( array( 'include_term_data' => 1 ) );
		$doc['terms'][]             = array(
			'id'       => 999,
			'taxonomy' => 'not_registered_here',
			'name'     => 'Lost',
			'slug'     => 'lost',
			'path'     => 'Lost',
		);
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertStringContainsString( 'taxonomy "not_registered_here" is not registered', implode( "\n", wp_list_pluck( $job->data['log'], 'm' ) ) );
		$job->delete();
	}

	/**
	 * Terms created while importing posts (create_terms) are tagged with the job,
	 * so a rollback removes them; that used to leave them behind.
	 */
	public function test_terms_created_by_a_post_import_are_rolled_back() {
		$file = $this->json_file(
			array(
				array(
					'id'    => 1,
					'title' => 'Tagged post',
					'cats'  => array( 'Fresh Cat' ),
				),
			)
		);

		$preview              = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		$job                  = $preview['job'];
		$job->data['mapping'] = array(
			'fields'     => array(
				'source_id'  => 'id',
				'post_title' => 'title',
			),
			'meta'       => array(),
			'taxonomies' => array(
				array(
					'taxonomy' => 'category',
					'source'   => 'cats',
				),
			),
			'acf'        => array(),
		);
		$job->data['options']['create_terms'] = 1;
		$job->save();
		do {
			$result = MigratePlus_Runner::run_batch( $job );
		} while ( ! $result['done'] );
		wp_delete_file( $file );

		$term = get_term_by( 'name', 'Fresh Cat', 'category' );
		$this->assertInstanceOf( 'WP_Term', $term );
		$this->assertSame( $job->data['id'], get_term_meta( $term->term_id, '_migrateplus_job_id', true ) );

		do {
			$rolled = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $rolled['done'] );
		$this->assertSame( '', $rolled['error'] );
		$this->assertFalse( get_term_by( 'name', 'Fresh Cat', 'category' ) );
		$job->delete();
	}
}
