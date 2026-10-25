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
}
