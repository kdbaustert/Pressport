<?php
/**
 * Yoast SEO data through an export and an import. Yoast itself is not installed
 * here: its presence is its version constant, which this file defines, and its
 * data is plain meta and options.
 *
 * @package MigratePlus
 */
class Test_Yoast extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '99.0-test' );
		}
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

	public function test_post_and_term_seo_round_trip_with_the_primary_category_as_a_path() {
		$gear  = self::factory()->category->create( array( 'name' => 'Gear' ) );
		$tents = self::factory()->category->create(
			array(
				'name'   => 'Tents',
				'parent' => $gear,
			)
		);
		$post  = self::factory()->post->create(
			array(
				'post_title'    => 'Best tents',
				'post_category' => array( $gear, $tents ),
			)
		);
		update_post_meta( $post, '_yoast_wpseo_title', 'Best tents of %%currentyear%%' );
		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Our picks.' );
		update_post_meta( $post, '_yoast_wpseo_focuskw', 'tents' );
		update_post_meta( $post, '_yoast_wpseo_primary_category', $tents );
		update_post_meta( $post, '_yoast_wpseo_linkdex', '71' ); // Computed: stays behind.
		update_post_meta( $post, '_yoast_wpseo_opengraph-image-id', '5' ); // An id: the URL travels, not this.
		update_option(
			'wpseo_taxonomy_meta',
			array(
				'category' => array(
					$tents => array(
						'wpseo_title' => 'Tents we like',
						'wpseo_desc'  => 'All tents.',
					),
				),
			)
		);

		$doc  = $this->export_doc(
			array(
				'post_types'        => array( 'post' ),
				'include_term_data' => 1,
				'include_yoast'     => 1,
			)
		);
		$item = $doc['items'][0];
		$this->assertSame( 'Best tents of %%currentyear%%', $item['yoast']['title'] );
		$this->assertSame( 'tents', $item['yoast']['focuskw'] );
		$this->assertSame( array( 'category' => 'Gear > Tents' ), $item['yoast']['primary'] );
		$this->assertArrayNotHasKey( 'linkdex', $item['yoast'] );
		$this->assertArrayNotHasKey( 'opengraph-image-id', $item['yoast'] );
		$this->assertSame( 'yoast', $doc['migrateplus']['mapping']['fields']['yoast'] );
		$term_records = array_column( $doc['terms'], null, 'path' );
		$this->assertSame(
			array(
				'wpseo_title' => 'Tents we like',
				'wpseo_desc'  => 'All tents.',
			),
			$term_records['Gear > Tents']['yoast']
		);

		// Left out when asked.
		$this->assertArrayNotHasKey(
			'yoast',
			$this->export_doc(
				array(
					'post_types'    => array( 'post' ),
					'include_yoast' => 0,
				)
			)['items'][0]
		);

		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		wp_delete_post( $post, true );
		wp_delete_term( $tents, 'category' );
		wp_delete_term( $gear, 'category' );
		delete_option( 'wpseo_taxonomy_meta' );

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new       = get_posts(
			array(
				'title'      => 'Best tents',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		)[0];
		$new_tents = get_term_by( 'name', 'Tents', 'category' );
		$this->assertSame( 'Best tents of %%currentyear%%', get_post_meta( $new->ID, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Our picks.', get_post_meta( $new->ID, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( (string) $new_tents->term_id, get_post_meta( $new->ID, '_yoast_wpseo_primary_category', true ) );
		$this->assertSame( '', get_post_meta( $new->ID, '_yoast_wpseo_linkdex', true ) );
		$tax_meta = get_option( 'wpseo_taxonomy_meta' );
		$this->assertSame( 'Tents we like', $tax_meta['category'][ $new_tents->term_id ]['wpseo_title'] );
		$job->delete();
	}

	public function test_settings_travel_without_tokens_and_merge_over_this_sites() {
		update_option(
			'wpseo',
			array(
				'keyword_analysis_active' => true,
				'semrush_tokens'          => array( 'access_token' => 'secret' ),
				'zapier_api_key'          => 'secret',
				'version'                 => '22.0',
			)
		);
		update_option(
			'wpseo_titles',
			array(
				'separator'    => 'sc-dash',
				'company_name' => 'Old Co',
			)
		);
		$settings = MigratePlus_Yoast::export_settings();
		$this->assertSame( array( 'keyword_analysis_active' => true ), $settings['wpseo'] );
		$this->assertSame( 'Old Co', $settings['wpseo_titles']['company_name'] );

		update_option(
			'wpseo',
			array(
				'keyword_analysis_active' => false,
				'zapier_api_key'          => 'mine',
			)
		);
		update_option(
			'wpseo_titles',
			array(
				'separator'        => 'sc-pipe',
				'company_name'     => 'New Co',
				'title-home-wpseo' => 'Home',
			)
		);
		$prev = array();
		$this->assertSame( 2, MigratePlus_Yoast::import_settings( $settings, $prev ) );
		$this->assertSame(
			array(
				'keyword_analysis_active' => true,
				'zapier_api_key'          => 'mine',
			),
			get_option( 'wpseo' )
		);
		$this->assertSame(
			array(
				'separator'        => 'sc-dash',
				'company_name'     => 'Old Co',
				'title-home-wpseo' => 'Home',
			),
			get_option( 'wpseo_titles' )
		);
		$this->assertSame(
			array(
				'keyword_analysis_active' => false,
				'zapier_api_key'          => 'mine',
			),
			$prev['wpseo']
		);

		// The site settings section carries them, and rollback puts the old values back.
		$doc = $this->export_doc(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
				'site_sections'         => array( 'yoast' ),
			)
		);
		$this->assertSame( array( 'yoast' ), $doc['site_settings']['sections'] );
		$this->assertSame( 'Old Co', $doc['site_settings']['plugins']['yoast']['wpseo_titles']['company_name'] );
		update_option( 'wpseo_titles', array( 'company_name' => 'Before import' ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$job                        = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'Old Co', get_option( 'wpseo_titles' )['company_name'] );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( array( 'company_name' => 'Before import' ), get_option( 'wpseo_titles' ) );
		$job->delete();
	}
}
