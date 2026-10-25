<?php
/**
 * The site settings section of MigratePlus exports: the chosen sections travel,
 * references point at imported content, and rollback puts the old values back.
 *
 * @package MigratePlus
 */
class Test_Site extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

	public function test_sections_are_chosen_and_carry_references_not_ids() {
		$about = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
				'post_name'  => 'about',
			)
		);
		$news  = self::factory()->category->create( array( 'name' => 'News' ) );
		update_option( 'blogname', 'Old Site' );
		update_option( 'posts_per_page', 7 );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $about );
		update_option( 'default_category', $news );
		update_option( 'permalink_structure', '/%postname%/' );
		set_theme_mod( 'mp_color', 'teal' );
		update_option(
			'sidebars_widgets',
			array(
				'sidebar-1'     => array( 'block-2' ),
				'array_version' => 3,
			)
		);
		update_option(
			'widget_block',
			array(
				2              => array( 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
				'_multiwidget' => 1,
			)
		);

		$doc = $this->export_doc(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
				'site_sections'         => array( 'general', 'reading', 'writing' ),
			)
		);
		$this->assertSame( array(), $doc['items'] );
		$site = $doc['site_settings'];
		$this->assertSame( array( 'general', 'writing', 'reading' ), $site['sections'] );
		$this->assertSame( 'Old Site', $site['options']['blogname'] );
		$this->assertSame( 7, (int) $site['options']['posts_per_page'] );
		$this->assertSame( 'about', $site['options']['page_on_front']['slug'] );
		$this->assertSame( 'News', $site['options']['default_category'] );
		$this->assertArrayNotHasKey( 'permalink_structure', $site['options'] );
		$this->assertSame( array(), $site['widgets'] );
		$this->assertSame( array(), $site['theme'] );

		$doc  = $this->export_doc(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
			)
		);
		$site = $doc['site_settings'];
		$this->assertSame( '/%postname%/', $site['options']['permalink_structure'] );
		$this->assertSame( 'teal', $site['theme']['mods']['mp_color'] );
		$this->assertSame( get_stylesheet(), $site['theme']['stylesheet'] );
		$this->assertSame( array( 'block-2' ), $site['widgets']['sidebars']['sidebar-1'] );
		$this->assertSame( 'Hi', wp_strip_all_tags( $site['widgets']['instances']['widget_block'][2]['content'] ) );
		$this->assertArrayNotHasKey( 'siteurl', $site['options'] );
	}

	public function test_import_applies_the_settings_points_at_imported_pages_and_rolls_back() {
		$about = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
				'post_name'  => 'about',
			)
		);
		update_option( 'blogname', 'Old Site' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $about );
		set_theme_mod( 'mp_color', 'teal' );
		$doc                        = $this->export_doc(
			array(
				'post_types'            => array( 'page' ),
				'include_site_settings' => 1,
			)
		);
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );

		// The new site, with its own values.
		wp_delete_post( $about, true );
		update_option( 'blogname', 'New Site' );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		set_theme_mod( 'mp_color', 'red' );

		$dry = $this->import_file( $file, array( 'dry_run' => 1 ) );
		$this->assertSame( 'New Site', get_option( 'blogname' ) );
		$this->assertStringContainsString( 'Would apply', implode( "\n", array_column( $dry->data['log'], 'm' ) ) );
		$dry->delete();

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$last = end( $job->data['sections'] );
		$this->assertSame( 'site_settings', $last['type'] );
		$this->assertSame( 'Old Site', get_option( 'blogname' ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$imported = get_posts(
			array(
				'post_type'  => 'page',
				'name'       => 'about',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertSame( $imported[0]->ID, (int) get_option( 'page_on_front' ) );
		$this->assertSame( 'teal', get_theme_mod( 'mp_color' ) );
		$this->assertSame( array( 'value' => 'New Site' ), $job->data['side_prev']['options']['blogname'] );
		$this->assertSame( array( 'value' => 'red' ), $job->data['side_prev']['theme_mods']['mp_color'] );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'New Site', get_option( 'blogname' ) );
		$this->assertSame( 'posts', get_option( 'show_on_front' ) );
		$this->assertSame( 0, (int) get_option( 'page_on_front' ) );
		$this->assertSame( 'red', get_theme_mod( 'mp_color' ) );
		$this->assertSame( array(), $job->data['side_prev'] );
		$job->delete();
	}

	public function test_customizer_settings_of_another_theme_are_not_applied() {
		$doc  = array(
			'migrateplus'   => array(
				'version' => '1',
				'site'    => 'http://old.test',
				'mapping' => array( 'fields' => array() ),
			),
			'items'         => array(),
			'site_settings' => array(
				'sections' => array( 'theme' ),
				'options'  => array(),
				'widgets'  => array(),
				'theme'    => array(
					'stylesheet' => 'some-other-theme',
					'name'       => 'Some Other Theme',
					'mods'       => array( 'mp_color' => 'teal' ),
					'custom_css' => '.site { color: teal; }',
				),
				'plugins'  => array(),
			),
		);
		$file = $this->json_file( $doc );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertFalse( get_theme_mod( 'mp_color', false ) );
		$this->assertSame( '.site { color: teal; }', wp_get_custom_css() );
		$this->assertStringContainsString( 'are for the "Some Other Theme" theme', implode( "\n", array_column( $job->data['log'], 'm' ) ) );
		$job->delete();
	}

	public function test_site_settings_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$args = MigratePlus_Exporter::sanitize_args(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
			)
		);
		$this->assertSame( 0, MigratePlus_Site::count( $args ) );
		$this->assertSame( array(), MigratePlus_Site::export( $args ) );
	}
}
