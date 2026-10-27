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

	public function test_customizer_settings_keep_the_case_of_their_names() {
		$file = $this->settings_file(
			array(
				'sections' => array( 'theme' ),
				'theme'    => array(
					'stylesheet' => get_stylesheet(),
					'mods'       => array( 'MyTheme_Color' => 'teal' ),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'teal', get_theme_mod( 'MyTheme_Color' ) );
		$this->assertFalse( get_theme_mod( 'mytheme_color', false ) );
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

	protected function settings_file( array $site ) {
		return $this->json_file(
			array(
				'migrateplus'   => array(
					'version' => '1',
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => array() ),
				),
				'items'         => array(),
				'site_settings' => $site + array(
					'sections' => array(),
					'options'  => array(),
					'widgets'  => array(),
					'theme'    => array(),
					'plugins'  => array(),
				),
			)
		);
	}

	protected function log_of( MigratePlus_Job $job ) {
		return implode( "\n", array_column( $job->data['log'], 'm' ) );
	}

	public function test_no_section_picked_exports_no_site_settings() {
		$refused = MigratePlus_Exporter::sanitize_args(
			array(
				'post_types'            => array(),
				'include_site_settings' => 1,
				'site_sections'         => array(),
			)
		);
		$this->assertWPError( $refused );
		// Called directly with nothing picked, the section still exports nothing rather than everything.
		$args = array( 'site_sections' => array() );
		$this->assertSame( array(), MigratePlus_Site::picked_sections( $args ) );
		$this->assertSame( 0, MigratePlus_Site::count( $args ) );
		$this->assertSame( array(), MigratePlus_Site::export( $args ) );
	}

	public function test_permalink_settings_reach_the_rules_flushed_in_the_same_request() {
		global $wp_rewrite;
		$top = $wp_rewrite->extra_rules_top;
		$wp_rewrite->set_permalink_structure( '' );
		// Registered while the site has plain links, so it has no permalink shape until the import gives it one.
		register_post_type(
			'mp_book',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'books' ),
			)
		);
		$file = $this->settings_file(
			array(
				'options' => array(
					'permalink_structure' => '/%year%/%postname%/',
					'category_base'       => 'topics',
					'tag_base'            => 'labels',
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( '/%year%/%postname%/', $wp_rewrite->permalink_structure );
		$this->assertSame( 'topics/%category%', $wp_rewrite->get_category_permastruct() );
		$this->assertSame( 'labels/%post_tag%', $wp_rewrite->get_tag_permastruct() );
		$rules = array_keys( (array) get_option( 'rewrite_rules' ) );
		$this->assertContains( 'topics/(.+?)/?$', $rules );
		$this->assertContains( 'labels/([^/]+)/?$', $rules );
		$this->assertContains( 'books/([^/]+)(?:/([0-9]+))?/?$', $rules );
		$job->delete();
		unregister_post_type( 'mp_book' );
		$wp_rewrite->set_category_base( '' );
		$wp_rewrite->set_tag_base( '' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->extra_rules_top = $top; // See the rollback test below.
	}

	public function test_rolling_back_a_permalink_change_flushes_rules_for_the_restored_structure() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$file = $this->settings_file(
			array(
				'options' => array(
					'permalink_structure' => '/%year%/%postname%/',
					'category_base'       => 'topics',
				),
			)
		);
		$top  = $wp_rewrite->extra_rules_top;
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertContains( 'topics/(.+?)/?$', array_keys( (array) get_option( 'rewrite_rules' ) ) );
		// WP_Rewrite::rewrite_rules() copies the permastructs' rules into extra_rules_top as it builds them. A
		// rollback runs in a request of its own, which starts without the import's.
		$wp_rewrite->extra_rules_top = $top;
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( '/%postname%/', $wp_rewrite->permalink_structure );
		$this->assertSame( '/category/%category%', $wp_rewrite->get_category_permastruct() );
		$rules = array_keys( (array) get_option( 'rewrite_rules' ) );
		$this->assertContains( 'category/(.+?)/?$', $rules );
		$this->assertNotContains( 'topics/(.+?)/?$', $rules );
		$this->assertContains( '([^/]+)(?:/([0-9]+))?/?$', $rules );
		$this->assertNotContains( '([0-9]{4})/([^/]+)(?:/([0-9]+))?/?$', $rules );
		$job->delete();
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->extra_rules_top = $top;
	}

	public function test_widgets_join_this_sites_and_point_at_imported_media_and_menus() {
		register_sidebar(
			array(
				'id'   => 'mp-side',
				'name' => 'Side',
			)
		);
		update_option(
			'sidebars_widgets',
			array(
				'wp_inactive_widgets' => array( 'text-5' ),
				'mp-side'             => array( 'block-2' ),
				'array_version'       => 3,
			)
		);
		update_option(
			'widget_block',
			array(
				2              => array( 'content' => '<p>Ours</p>' ),
				'_multiwidget' => 1,
			)
		);
		update_option(
			'widget_text',
			array(
				5              => array( 'text' => 'Kept aside' ),
				'_multiwidget' => 1,
			)
		);
		$menu    = wp_create_nav_menu( 'Main' );
		$file    = $this->settings_file(
			array(
				'widgets' => array(
					'sidebars'  => array( 'mp-side' => array( 'block-2', 'media_image-3', 'nav_menu-4' ) ),
					'instances' => array(
						'widget_block'       => array( 2 => array( 'content' => '<p>Theirs</p>' ) ),
						'widget_media_image' => array(
							3 => array(
								'attachment_id' => 77,
								'url'           => 'http://old.test/wp-content/uploads/tree.jpg',
							),
						),
						'widget_nav_menu'    => array( 4 => array( 'nav_menu' => 12 ) ),
					),
					'menus'     => array( '12' => 'main' ),
				),
			)
		);
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		$job     = $preview['job'];
		// The image the file's media section brought, as the records stage leaves it.
		$tree = self::factory()->attachment->create_object( 'tree.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $tree, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, '77' ) );
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			$this->assertIsArray( $result );
		} while ( ! $result['done'] );
		wp_delete_file( $file );

		$sidebars = get_option( 'sidebars_widgets' );
		$this->assertSame( array( 'block-3', 'media_image-3', 'nav_menu-4' ), $sidebars['mp-side'] );
		$this->assertSame( array( 'text-5', 'block-2' ), $sidebars['wp_inactive_widgets'] );
		$this->assertSame( 3, $sidebars['array_version'] );
		$blocks = get_option( 'widget_block' );
		$this->assertSame( '<p>Ours</p>', $blocks[2]['content'] );
		$this->assertSame( '<p>Theirs</p>', $blocks[3]['content'] );
		$this->assertSame( 'Kept aside', get_option( 'widget_text' )[5]['text'] );
		$image = get_option( 'widget_media_image' )[3];
		$this->assertSame( $tree, $image['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $tree ), $image['url'] );
		$this->assertSame( $menu, get_option( 'widget_nav_menu' )[4]['nav_menu'] );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( array( 'mp-side' => array( 'block-2' ) ), array_intersect_key( get_option( 'sidebars_widgets' ), array( 'mp-side' => 1 ) ) );
		$this->assertArrayNotHasKey( 3, (array) get_option( 'widget_media_image', array() ) );
		$job->delete();
		unregister_sidebar( 'mp-side' );
	}

	public function test_a_widget_menu_imported_after_the_settings_is_set_then() {
		register_sidebar( array( 'id' => 'mp-side' ) );
		$file = $this->settings_file(
			array(
				'widgets' => array(
					'sidebars'  => array( 'mp-side' => array( 'nav_menu-4' ) ),
					'instances' => array( 'widget_nav_menu' => array( 4 => array( 'nav_menu' => 12 ) ) ),
					'menus'     => array( '12' => 'footer' ),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 0, get_option( 'widget_nav_menu' )[4]['nav_menu'] );
		$this->assertStringContainsString( 'A Navigation Menu widget shows the "footer" menu, which is not on this site yet', $this->log_of( $job ) );
		$footer = wp_create_nav_menu( 'Footer' );
		$this->assertSame( 1, MigratePlus_Site::resolve_widget_menus( $job ) );
		$this->assertSame( $footer, get_option( 'widget_nav_menu' )[4]['nav_menu'] );
		$this->assertArrayNotHasKey( 'widget_menus', $job->data );
		$job->delete();
		unregister_sidebar( 'mp-side' );
	}

	public function test_logo_and_site_icon_follow_the_featured_images_option() {
		$file = $this->settings_file(
			array(
				'options' => array( 'site_icon' => 'http://old.test/wp-content/uploads/icon.png' ),
				'theme'   => array(
					'stylesheet' => get_stylesheet(),
					'mods'       => array( 'custom_logo' => 'http://old.test/wp-content/uploads/logo.png' ),
				),
			)
		);
		$job  = $this->import_file( $file, array( 'import_featured_image' => 0 ) );
		wp_delete_file( $file );
		$log = $this->log_of( $job );
		$this->assertStringContainsString( 'The site icon was not downloaded: images are downloaded only when "Featured images" is on.', $log );
		$this->assertStringContainsString( 'The logo was not downloaded: images are downloaded only when "Featured images" is on.', $log );
		$this->assertSame( 0, (int) get_option( 'site_icon' ) );
		$this->assertFalse( get_theme_mod( 'custom_logo', false ) );
		$job->delete();
	}

	public function test_css_and_html_widgets_need_unfiltered_html() {
		register_sidebar( array( 'id' => 'mp-side' ) );
		add_role(
			'mp_options',
			'Options',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'mp_options' ) ) );
		$file = $this->settings_file(
			array(
				'widgets' => array(
					'sidebars'  => array( 'mp-side' => array( 'custom_html-2', 'archives-3' ) ),
					'instances' => array(
						'widget_custom_html' => array( 2 => array( 'content' => '<script>steal()</script>' ) ),
						'widget_archives'    => array( 3 => array( 'title' => 'Old posts' ) ),
					),
				),
				'theme'   => array(
					'stylesheet' => get_stylesheet(),
					'custom_css' => 'body { background: url(javascript:steal()) }',
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( array( 'archives-3' ), get_option( 'sidebars_widgets' )['mp-side'] );
		$this->assertArrayNotHasKey( 2, (array) get_option( 'widget_custom_html', array() ) );
		$this->assertSame( '', wp_get_custom_css() );
		$log = $this->log_of( $job );
		$this->assertStringContainsString( 'You may not add unfiltered HTML on this site, so 1 text, HTML or block widget was left out.', $log );
		$this->assertStringContainsString( 'You may not edit CSS on this site, so the Additional CSS was not applied.', $log );
		$job->delete();
		remove_role( 'mp_options' );
		unregister_sidebar( 'mp-side' );
	}

	public function test_a_change_to_search_engine_visibility_is_reported() {
		update_option( 'blog_public', '1' );
		$file = $this->settings_file( array( 'options' => array( 'blog_public' => '0' ) ) );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 0, (int) get_option( 'blog_public' ) );
		$this->assertStringContainsString( 'Search engine visibility changes: search engines are now asked not to index this site.', $this->log_of( $job ) );
		$job->delete();
	}
}
