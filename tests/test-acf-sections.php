<?php
/**
 * ACF field groups and options page values through an export and an import.
 *
 * @package MigratePlus
 */
class Test_ACF_Sections extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! MigratePlus_ACF_Definitions::active() ) {
			$this->markTestSkipped( 'Advanced Custom Fields is not installed; composer install fetches it.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		foreach ( get_posts(
			array(
				'post_type'      => 'acf-field-group',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		) as $group ) {
			acf_delete_field_group( $group->ID );
		}
		parent::tear_down();
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

	protected function group( $title = 'Book details' ) {
		return acf_import_field_group(
			array(
				'key'      => 'group_mp_book',
				'title'    => $title,
				'fields'   => array(
					array(
						'key'   => 'field_mp_isbn',
						'label' => 'ISBN',
						'name'  => 'isbn',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
	}

	public function test_field_groups_round_trip_by_key_and_roll_back() {
		$this->group();
		// Definitions ride with the content they describe: the file needs a post type.
		$doc = $this->export_doc(
			array(
				'post_types'              => array( 'post' ),
				'include_acf_definitions' => 1,
			)
		);
		$this->assertSame( array( 'acf-field-group' ), array_unique( array_column( $doc['acf_definitions'], 'kind' ) ) );
		$this->assertSame( 'group_mp_book', $doc['acf_definitions'][0]['key'] );
		$this->assertSame( 'isbn', $doc['acf_definitions'][0]['data']['fields'][0]['name'] );
		$this->assertArrayNotHasKey( 'ID', $doc['acf_definitions'][0]['data'] );

		// Gone from this site: the import creates it, and rollback removes it with its field.
		acf_delete_field_group( acf_get_field_group( 'group_mp_book' )['ID'] );
		$this->assertFalse( acf_get_field_group( 'group_mp_book' ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$job                        = $this->import_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertContains( 'acf_definitions', array_column( $job->data['sections'], 'type' ) );
		$group = acf_get_field_group( 'group_mp_book' );
		$this->assertSame( 'Book details', $group['title'] );
		$this->assertSame( 'isbn', acf_get_fields( $group )[0]['name'] );
		$this->assertSame( $job->data['id'], get_post_meta( $group['ID'], '_migrateplus_job_id', true ) );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( acf_get_field_group( 'group_mp_book' ) );
		$this->assertFalse( acf_get_field( 'field_mp_isbn' ) );
		$job->delete();

		// Already here under another title: updated by key, and rollback puts the old shape back.
		$this->group( 'Book facts' );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'Book details', acf_get_field_group( 'group_mp_book' )['title'] );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'      => 'acf-field-group',
					'post_status'    => 'any',
					'posts_per_page' => -1,
				)
			)
		);
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( 'Book facts', acf_get_field_group( 'group_mp_book' )['title'] );
		$job->delete();
	}

	public function test_a_group_this_site_defines_in_code_is_left_alone() {
		acf_add_local_field_group(
			array(
				'key'      => 'group_mp_local',
				'title'    => 'From code',
				'fields'   => array(),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
		$doc  = array(
			'migrateplus'     => array(
				'version' => '1',
				'site'    => 'http://old.test',
				'mapping' => array( 'fields' => array() ),
			),
			'items'           => array(),
			'acf_definitions' => array(
				array(
					'kind'  => 'acf-field-group',
					'key'   => 'group_mp_local',
					'title' => 'From the file',
					'data'  => array(
						'key'      => 'group_mp_local',
						'title'    => 'From the file',
						'fields'   => array(),
						'location' => array(),
					),
				),
			),
		);
		$file = $this->json_file( $doc );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertStringContainsString( 'is defined in code or acf-json on this site', implode( "\n", array_column( $job->data['log'], 'm' ) ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'acf-field-group',
					'post_status' => 'any',
					'name'        => 'group_mp_local',
				)
			)
		);
		acf_remove_local_field_group( 'group_mp_local' );
		$job->delete();
	}

	public function test_options_page_values_round_trip_and_roll_back() {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			$this->markTestSkipped( 'Options pages need ACF PRO.' );
		}
		acf_add_options_page(
			array(
				'page_title' => 'Site options',
				'menu_slug'  => 'mp-site-options',
			)
		);
		acf_import_field_group(
			array(
				'key'      => 'group_mp_options',
				'title'    => 'Footer',
				'fields'   => array(
					array(
						'key'   => 'field_mp_footer',
						'label' => 'Footer text',
						'name'  => 'footer_text',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'mp-site-options',
						),
					),
				),
			)
		);
		update_field( 'field_mp_footer', 'Old footer', 'option' );
		$doc = $this->export_doc(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
				'site_sections'         => array( 'general' ),
				'include_acf_options'   => 1,
			)
		);
		$this->assertSame( 'mp-site-options', $doc['acf_options'][0]['page'] );
		$this->assertSame( 'Old footer', $doc['acf_options'][0]['fields']['footer_text'] );

		update_field( 'field_mp_footer', 'New footer', 'option' );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$job                        = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'Old footer', get_field( 'field_mp_footer', 'option' ) );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( 'New footer', get_field( 'field_mp_footer', 'option' ) );
		$job->delete();
	}
	public function test_options_rollback_removes_a_value_never_saved_and_mixed_case_pages_match() {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			$this->markTestSkipped( 'Options pages need ACF PRO.' );
		}
		acf_add_options_page(
			array(
				'page_title' => 'Brand',
				'menu_slug'  => 'Brand-Options',
				'post_id'    => 'brand',
			)
		);
		acf_import_field_group(
			array(
				'key'      => 'group_mp_brand',
				'title'    => 'Brand',
				'fields'   => array(
					array(
						'key'           => 'field_mp_tagline',
						'label'         => 'Tagline',
						'name'          => 'tagline',
						'type'          => 'text',
						'default_value' => 'Default tagline',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'Brand-Options',
						),
					),
				),
			)
		);
		// Nothing saved: the default is not a value to export.
		$this->assertSame( array(), MigratePlus_ACF_Options::export( array() ) );

		$file = $this->json_file(
			array(
				'migrateplus' => array(
					'version' => '1',
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => array() ),
				),
				'items'       => array(),
				'acf_options' => array(
					array(
						'page'    => 'Brand-Options',
						'title'   => 'Brand',
						'post_id' => 'brand',
						'fields'  => array( 'tagline' => 'Imported' ),
					),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'Imported', get_field( 'field_mp_tagline', 'brand' ) );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertNull( acf_get_metadata_by_field( 'brand', acf_get_field( 'field_mp_tagline' ) ) );
		$job->delete();
	}

	/**
	 * Fields that point at posts, on posts.
	 */
	protected function reference_group() {
		acf_import_field_group(
			array(
				'key'      => 'group_mp_refs',
				'title'    => 'References',
				'fields'   => array(
					array(
						'key'       => 'field_mp_link',
						'label'     => 'Link',
						'name'      => 'link',
						'type'      => 'page_link',
						'post_type' => array( 'page' ),
					),
					array(
						'key'       => 'field_mp_related',
						'label'     => 'Related',
						'name'      => 'related',
						'type'      => 'relationship',
						'post_type' => array( 'page' ),
					),
					array(
						'key'        => 'field_mp_feature',
						'label'      => 'Feature',
						'name'       => 'feature',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'       => 'field_mp_feature_posts',
								'label'     => 'Posts',
								'name'      => 'posts',
								'type'      => 'relationship',
								'post_type' => array( 'page' ),
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
	}

	/**
	 * A page imported from the old site's post with this id.
	 */
	protected function arrive( MigratePlus_Job $job, $source_id ) {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, $source_id ) );
		MigratePlus_ACF::link_pending( $page, MigratePlus_Importer::uid( $job, $source_id ) );
		return $page;
	}

	protected function import_references( array $record, MigratePlus_Job $job ) {
		$this->reference_group();
		$post     = self::factory()->post->create();
		$warnings = array();
		MigratePlus_ACF::import(
			$post,
			$record,
			array_combine( array_map( 'strval', array_keys( $record ) ), array_keys( $record ) ),
			$job,
			array(),
			'http://old.test',
			$warnings
		);
		$this->assertSame( array(), $warnings );
		return $post;
	}

	protected function export_job() {
		return MigratePlus_Job::create(
			'json',
			array(
				'migrateplus_site'    => 'http://old.test',
				'migrateplus_mapping' => array(),
			)
		);
	}

	public function test_a_page_link_to_a_post_later_in_the_file_is_linked_when_it_arrives() {
		$job  = $this->export_job();
		$post = $this->import_references(
			array(
				'field_mp_link' => array(
					'id'    => 50,
					'slug'  => 'later',
					'title' => 'Later',
				),
			),
			$job
		);
		$this->assertSame( array( 'field_mp_link|' . MigratePlus_Importer::uid( $job, 50 ) ), get_post_meta( $post, MigratePlus_ACF::PENDING_META, false ) );

		$page = $this->arrive( $job, 50 );
		$this->assertSame( $page, (int) get_field( 'field_mp_link', $post, false ) );
		$this->assertSame( array(), get_post_meta( $post, MigratePlus_ACF::PENDING_META, false ) );
		$job->delete();
	}

	public function test_relationship_posts_that_arrive_late_take_their_place_in_the_files_order() {
		$job   = $this->export_job();
		$there = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $there, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, 62 ) );
		$post = $this->import_references(
			array(
				'field_mp_related' => array(
					array( 'id' => 61 ),
					array( 'id' => 62 ),
					array( 'id' => 63 ),
				),
			),
			$job
		);
		$this->assertSame( array( $there ), array_map( 'intval', get_field( 'field_mp_related', $post, false ) ) );

		// The last one first, then the first.
		$last  = $this->arrive( $job, 63 );
		$first = $this->arrive( $job, 61 );
		$this->assertSame( array( $first, $there, $last ), array_map( 'intval', get_field( 'field_mp_related', $post, false ) ) );
		$job->delete();
	}

	public function test_a_reference_inside_a_group_waits_for_its_post() {
		$job  = $this->export_job();
		$post = $this->import_references(
			array(
				'field_mp_feature' => array(
					'posts' => array( array( 'id' => 71 ) ),
				),
			),
			$job
		);
		$this->assertSame( array( 'field_mp_feature/field_mp_feature_posts#0|' . MigratePlus_Importer::uid( $job, 71 ) ), get_post_meta( $post, MigratePlus_ACF::PENDING_META, false ) );

		$page = $this->arrive( $job, 71 );
		$this->assertSame( array( $page ), array_map( 'intval', get_field( 'field_mp_feature', $post, false )['field_mp_feature_posts'] ) );
		$this->assertSame( array(), get_post_meta( $post, MigratePlus_ACF::PENDING_META, false ) );
		$job->delete();
	}
}
