<?php
/**
 * Import link rewriting, export meta exclusion, and field detection for MigratePlus exports.
 *
 * @package MigratePlus
 */
class Test_Import_Export extends WP_UnitTestCase {

	public function test_rewrite_links_only_touches_links_to_the_old_site() {
		$html = '<a href="http://old.test/page/">a</a> <a href="https://old.test">b</a> '
			. '<a href="http://old.test.evil.com/x">c</a> <a href="http://old.test/wp-content/uploads/f.pdf">d</a> '
			. '<img src="http://old.test/wp-content/uploads/x.jpg">';
		$out  = MigratePlus_Importer::rewrite_links( $html, 'http://old.test' );
		$home = untrailingslashit( home_url() );

		$this->assertStringContainsString( 'href="' . $home . '/page/"', $out );
		$this->assertStringContainsString( 'href="' . $home . '"', $out );
		$this->assertStringContainsString( 'href="http://old.test.evil.com/x"', $out );
		$this->assertStringContainsString( 'href="http://old.test/wp-content/uploads/f.pdf"', $out );
		$this->assertStringContainsString( 'src="http://old.test/wp-content/uploads/x.jpg"', $out );
	}

	public function test_rewrite_links_does_nothing_without_a_source_site() {
		$html = '<a href="http://old.test/">a</a>';
		$this->assertSame( $html, MigratePlus_Importer::rewrite_links( $html, '' ) );
	}

	public function test_meta_patterns_parse_lines_commas_and_prefixes() {
		$this->assertSame( array( 'rank_math_*', 'foo', 'x' ), MigratePlus_Exporter::meta_patterns( " rank_math_* , foo\r\n\r\n<b>x</b>\nfoo" ) );
	}

	public function test_export_leaves_out_excluded_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'rank_math_title', 'seo' );
		update_post_meta( $post_id, 'keepme', 'yes' );

		$args = MigratePlus_Exporter::sanitize_args( array( 'exclude_meta' => 'rank_math_*' ) );
		$item = MigratePlus_Exporter::export_post( get_post( $post_id ), $args );

		$this->assertSame( array( 'keepme' => 'yes' ), $item['meta'] );
	}

	/**
	 * A custom field that only the last item has must still be mapped
	 * (the preview used to look at the first record only).
	 */
	public function test_preview_maps_fields_that_only_later_items_have() {
		$items = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$items[] = array(
				'id'      => $i,
				'title'   => 'Post ' . $i,
				'content' => 'x',
				'meta'    => 30 === $i ? array( 'keepme' => 'yes' ) : array(),
			);
		}
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$file,
			wp_json_encode(
				array(
					'migrateplus' => array(
						'site'      => 'http://old.test',
						'post_type' => 'post',
						'mapping'   => array(
							'fields' => array(
								'source_id'    => 'id',
								'post_title'   => 'title',
								'post_content' => 'content',
							),
							'meta'   => array(
								array(
									'key'    => 'keepme',
									'source' => 'meta.keepme',
								),
							),
						),
					),
					'items'       => $items,
				)
			)
		);

		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 1 );
		wp_delete_file( $file );

		$this->assertIsArray( $preview );
		$this->assertContains( 'meta.keepme', $preview['fields'] );
		$this->assertSame(
			array(
				array(
					'key'    => 'keepme',
					'source' => 'meta.keepme',
				),
			),
			$preview['mapping']['meta']
		);
		$preview['job']->delete();
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
	 * @param string $source_id Source ID.
	 * @param string $type      Post type.
	 * @return int[]
	 */
	protected function imported( $source_id, $type = 'any' ) {
		return get_posts(
			array(
				'post_type'   => $type,
				'post_status' => 'any',
				'meta_key'    => '_migrateplus_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
			)
		);
	}

	/**
	 * Parent, password, template, format, array meta and multi-row meta survive
	 * export and import, with the child listed before its parent.
	 */
	public function test_round_trip_keeps_parent_password_template_format_and_meta() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_post_type_support( 'page', 'post-formats' );
		// WordPress resets a template the theme does not have to "default" on the next update.
		$templates = static function ( $list ) {
			return $list + array( 'templates/wide.php' => 'Wide' );
		};
		add_filter( 'theme_page_templates', $templates );
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Parent',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Child',
				'post_parent'   => $parent,
				'post_password' => 'secret',
			)
		);
		update_post_meta( $child, '_wp_page_template', 'templates/wide.php' );
		set_post_format( $child, 'aside' );
		update_post_meta( $child, 'colors', array( 'red', 'blue' ) );
		$sizes = array(
			's' => 1,
			'm' => array( 2, 3 ),
		);
		update_post_meta( $child, 'sizes', $sizes );
		add_post_meta( $child, 'tag', 'a' );
		add_post_meta( $child, 'tag', 'b' );

		$args  = MigratePlus_Exporter::sanitize_args(
			array(
				'post_type'     => 'page',
				'include_meta'  => 1,
				'include_terms' => 0,
				'include_image' => 0,
				'include_acf'   => 0,
			)
		);
		$items = array( MigratePlus_Exporter::export_post( get_post( $child ), $args ), MigratePlus_Exporter::export_post( get_post( $parent ), $args ) );
		$this->assertSame( array( 'red', 'blue' ), $items[0]['meta']['colors'] );
		$this->assertSame( array( '_migrateplus_rows' => array( 'a', 'b' ) ), $items[0]['meta']['tag'] );
		$state = array(
			'args'      => $args,
			'taxes'     => array(),
			'meta_keys' => array_keys( $items[0]['meta'] ),
			'acf_names' => array(),
		);
		$file  = $this->json_file(
			array(
				'migrateplus' => array(
					'site'      => 'http://old.test',
					'post_type' => 'page',
					'mapping'   => MigratePlus_Exporter::mapping_for( $state ),
				),
				'items'       => $items,
			)
		);
		wp_delete_post( $child, true );
		wp_delete_post( $parent, true );

		$job = $this->import_file( $file );
		wp_delete_file( $file );

		$new_parent = $this->imported( $parent, 'page' );
		$new_child  = $this->imported( $child, 'page' );
		$this->assertCount( 1, $new_parent );
		$this->assertCount( 1, $new_child );
		$post = get_post( $new_child[0] );
		$this->assertSame( $new_parent[0], (int) $post->post_parent );
		$this->assertSame( '', get_post_meta( $post->ID, '_migrateplus_parent_uid', true ) );
		$this->assertSame( 'secret', $post->post_password );
		$this->assertSame( 'templates/wide.php', get_post_meta( $post->ID, '_wp_page_template', true ) );
		$this->assertSame( 'aside', get_post_format( $post ) );
		$this->assertSame( array( 'red', 'blue' ), get_post_meta( $post->ID, 'colors', true ) );
		$this->assertEquals( $sizes, get_post_meta( $post->ID, 'sizes', true ) );
		$this->assertSame( array( 'a', 'b' ), get_post_meta( $post->ID, 'tag', false ) );

		remove_post_type_support( 'page', 'post-formats' );
		remove_filter( 'theme_page_templates', $templates );
		$job->delete();
	}

	/**
	 * Exports from before arrays were written natively carried them as JSON text.
	 */
	public function test_old_export_array_meta_is_decoded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->json_file(
			array(
				'migrateplus' => array(
					'site'      => 'http://legacy.test',
					'post_type' => 'post',
					'mapping'   => array(
						'fields' => array(
							'source_id'  => 'id',
							'post_title' => 'title',
						),
						'meta'   => array(
							array(
								'key'    => 'colors',
								'source' => 'meta.colors',
							),
							array(
								'key'    => 'plain',
								'source' => 'meta.plain',
							),
						),
					),
				),
				'items'       => array(
					array(
						'id'    => 7,
						'title' => 'Legacy',
						'meta'  => array(
							'colors' => '["red","blue"]',
							'plain'  => 'text',
						),
					),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );

		$ids = $this->imported( 7, 'post' );
		$this->assertCount( 1, $ids );
		$this->assertSame( array( 'red', 'blue' ), get_post_meta( $ids[0], 'colors', true ) );
		$this->assertSame( 'text', get_post_meta( $ids[0], 'plain', true ) );
		$job->delete();
	}

	/**
	 * Two unrelated JSON files with the same IDs are different records; the same
	 * file imported again is the same records.
	 */
	public function test_unrelated_json_files_do_not_collide() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$doc = array(
			array(
				'id'      => 1,
				'title'   => 'One',
				'content' => 'x',
			),
		);
		$a   = $this->json_file( $doc );
		$b   = $this->json_file( $doc );
		$job = $this->import_file( $a );
		$job->delete();
		$job = $this->import_file( $b );
		$job->delete();
		$this->assertCount( 2, $this->imported( 1, 'post' ) );

		$job = $this->import_file( $a );
		$this->assertSame( 1, $job->data['stats']['skipped'] );
		$job->delete();
		wp_delete_file( $a );
		wp_delete_file( $b );
	}

	public function test_importable_post_types_need_edit_access_and_content_types() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( MigratePlus_Importer::importable_post_type( 'post' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( MigratePlus_Importer::importable_post_type( 'post' ) );
		// Templates have no admin screen but are content of the block editor; menu items travel with their menu.
		$this->assertTrue( MigratePlus_Importer::importable_post_type( 'wp_template' ) );
		$this->assertFalse( MigratePlus_Importer::importable_post_type( 'wp_global_styles' ) );
		$this->assertFalse( MigratePlus_Importer::importable_post_type( 'nav_menu_item' ) );
	}

	public function test_missing_terms_are_reported() {
		$warnings = array();
		$this->assertSame( array(), MigratePlus_Importer::resolve_terms( 'category', array( 'Nowhere' ), false, $warnings ) );
		$this->assertCount( 1, $warnings );
	}

	/**
	 * The same person often has a different email on each site, so ACF user fields fall back to the login.
	 */
	public function test_acf_users_match_by_email_then_login() {
		$id = self::factory()->user->create(
			array(
				'user_login' => 'designer',
				'user_email' => 'designer@target.test',
			)
		);
		$this->assertSame(
			$id,
			MigratePlus_ACF::resolve_user(
				array(
					'email' => 'designer@target.test',
					'login' => 'someone-else',
				)
			)
		);
		$this->assertSame(
			$id,
			MigratePlus_ACF::resolve_user(
				array(
					'email' => 'designer@source.test',
					'login' => 'designer',
				)
			)
		);
		$this->assertSame( $id, MigratePlus_ACF::resolve_user( 'designer@target.test' ) ); // Older exports.
		$this->assertSame(
			0,
			MigratePlus_ACF::resolve_user(
				array(
					'email' => 'nobody@source.test',
					'login' => 'nobody',
				)
			)
		);
	}

	/**
	 * Posts deleted between batches must not shift later posts out of the export.
	 */
	public function test_export_pages_by_id() {
		$ids   = self::factory()->post->create_many( 3 );
		$args  = MigratePlus_Exporter::sanitize_args(
			array(
				'post_type' => 'post',
				'statuses'  => 'publish',
			)
		);
		$state = MigratePlus_Exporter::start( $args );
		$this->assertIsArray( $state );
		MigratePlus_Exporter::run_batch( $state, 1 );
		wp_delete_post( $ids[0], true );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );

		$this->assertSame( $ids, array_column( $doc['items'], 'id' ) );
	}

	/**
	 * @param string $site Site the records come from.
	 * @return MigratePlus_Job
	 */
	protected function job_from( $site ) {
		return new MigratePlus_Job(
			array(
				'source'   => 'json',
				'settings' => array( 'migrateplus_site' => $site ),
			)
		);
	}

	public function test_uid_is_scoped_to_the_source() {
		$a = $this->job_from( 'http://a.test' );
		$this->assertSame( '', MigratePlus_Importer::uid( $a, '' ) );
		$this->assertSame( MigratePlus_Importer::uid( $a, 42 ), MigratePlus_Importer::uid( $this->job_from( 'http://a.test' ), '42' ) );
		$this->assertNotSame( MigratePlus_Importer::uid( $a, 42 ), MigratePlus_Importer::uid( $this->job_from( 'http://b.test' ), 42 ) );
	}

	public function test_slug_from_uses_the_last_path_segment() {
		$this->assertSame( 'my-post', MigratePlus_Importer::slug_from( '/blog/my-post/' ) );
		$this->assertSame( 'my-post', MigratePlus_Importer::slug_from( 'https://old.test/blog/my-post?x=1' ) );
		$this->assertSame( 'hello-world', MigratePlus_Importer::slug_from( 'Hello World' ) );
		$this->assertSame( '', MigratePlus_Importer::slug_from( '/' ) );
	}

	/**
	 * WP-CLI passes the file path; the admin keeps the sanitized upload name. Both must give the same key.
	 */
	public function test_cli_and_admin_file_keys_match() {
		$cli   = new MigratePlus_Job( array( 'settings' => array( 'file' => '/tmp/My Export (1).json' ) ) );
		$admin = new MigratePlus_Job( array( 'settings' => array( 'file_name' => sanitize_file_name( 'My Export (1).json' ) ) ) );
		$this->assertSame( MigratePlus_Importer::source_key( $admin ), MigratePlus_Importer::source_key( $cli ) );
	}

	public function test_absolutize_only_returns_http_urls() {
		$this->assertSame( 'http://old.test/a.jpg', MigratePlus_Media::absolutize( '/a.jpg', 'http://old.test' ) );
		$this->assertSame( 'http://old.test/dir/a.jpg', MigratePlus_Media::absolutize( 'a.jpg', 'http://old.test/dir/' ) );
		$this->assertSame( '', MigratePlus_Media::absolutize( '/a.jpg', 'javascript:alert(1)//' ) );
		$this->assertSame( '', MigratePlus_Media::absolutize( 'javascript:alert(1)', 'http://old.test' ) );
		$this->assertSame( '', MigratePlus_Media::absolutize( 'data:image/png;base64,AAAA', 'http://old.test' ) );
	}

	public function test_json_source_keeps_only_an_http_site() {
		foreach ( array(
			'javascript:alert(1)' => '',
			'http://old.test'     => 'http://old.test',
		) as $site => $expected ) {
			$file   = $this->json_file(
				array(
					'migrateplus' => array(
						'site'    => $site,
						'mapping' => array(),
					),
					'items'       => array( array( 'id' => 1 ) ),
				)
			);
			$source = new MigratePlus_Source_JSON( array( 'file' => $file ) );
			$out    = $source->prepare();
			wp_delete_file( $file );
			$this->assertIsArray( $out );
			wp_delete_file( $out['cache_file'] );
			$this->assertSame( $expected, $out['migrateplus_site'] );
		}
	}

	/**
	 * A child imported before its parent waits for it, also in a post type hidden from search.
	 */
	public function test_waiting_children_in_hidden_post_types_find_their_parent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_post_type(
			'pp_hidden',
			array(
				'show_ui'             => true,
				'exclude_from_search' => true,
				'hierarchical'        => true,
			)
		);
		$file = $this->json_file(
			array(
				'migrateplus' => array(
					'site'    => 'http://hidden.test',
					'mapping' => array(
						'fields' => array(
							'source_id'   => 'id',
							'post_type'   => 'post_type',
							'post_title'  => 'title',
							'post_parent' => 'parent.id',
						),
					),
				),
				'items'       => array(
					array(
						'id'        => 2,
						'post_type' => 'pp_hidden',
						'title'     => 'Child',
						'parent'    => array( 'id' => 1 ),
					),
					array(
						'id'        => 1,
						'post_type' => 'pp_hidden',
						'title'     => 'Parent',
					),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );

		$parent = $this->imported( 1, 'pp_hidden' );
		$child  = $this->imported( 2, 'pp_hidden' );
		$this->assertCount( 1, $parent );
		$this->assertCount( 1, $child );
		$this->assertSame( $parent[0], (int) get_post( $child[0] )->post_parent );
		$job->delete();
		unregister_post_type( 'pp_hidden' );
	}

	/**
	 * The changes shown before an update are what the import would write, migrateplus_post_data included.
	 */
	public function test_changes_for_applies_the_post_data_filter() {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Same',
				'post_name'  => 'old-slug',
			)
		);
		$job     = new MigratePlus_Job(
			array(
				'source'  => 'json',
				'mapping' => array(
					'fields' => array(
						'post_title' => 'title',
						'post_name'  => 'slug',
					),
				),
			)
		);
		$filter  = static function ( $postarr ) {
			$postarr['post_title'] .= ' (filtered)';
			return $postarr;
		};
		add_filter( 'migrateplus_post_data', $filter );
		$changes = MigratePlus_Importer::changes_for(
			array(
				'title' => 'Same',
				'slug'  => '/blog/new-slug/',
			),
			$job,
			$post_id,
			'post'
		);
		remove_filter( 'migrateplus_post_data', $filter );

		$this->assertSame( 'Same (filtered)', $changes['title']['new'] );
		$this->assertSame( 'new-slug', $changes['slug']['new'] );
		$this->assertArrayNotHasKey( 'excerpt', $changes );
	}

	/**
	 * An exported reference names a post on its own site: { id: 42 } from one site
	 * must not link to post 42 imported from another.
	 */
	public function test_related_posts_match_the_source_they_came_from() {
		$a      = $this->job_from( 'http://a.test' );
		$b      = $this->job_from( 'http://b.test' );
		$from_a = self::factory()->post->create();
		update_post_meta( $from_a, '_migrateplus_source_uid', MigratePlus_Importer::uid( $a, 42 ) );
		update_post_meta( $from_a, '_migrateplus_source_id', '42' );

		$this->assertSame( $from_a, MigratePlus_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $a ) );
		$this->assertSame( 0, MigratePlus_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $b ) );
		// A plain id is not a MigratePlus reference: any earlier import of it still counts.
		$this->assertSame( $from_a, MigratePlus_ACF::resolve_post( '42', array( 'post' ), $b ) );

		$from_b = self::factory()->post->create();
		update_post_meta( $from_b, '_migrateplus_source_uid', MigratePlus_Importer::uid( $b, 42 ) );
		update_post_meta( $from_b, '_migrateplus_source_id', '42' );
		$this->assertSame( $from_b, MigratePlus_ACF::resolve_post( '42', array( 'post' ), $b ) );
		$this->assertSame( $from_b, MigratePlus_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $b ) );
	}

	/**
	 * A MigratePlus export with the given items, its mapping as the exporter writes it.
	 *
	 * @param array[] $items Items.
	 * @param array   $args  Export args.
	 * @return string Path.
	 */
	protected function export_file( array $items, array $args = array() ) {
		$args  = MigratePlus_Exporter::sanitize_args( $args );
		$state = array(
			'args'      => $args,
			'taxes'     => array(),
			'meta_keys' => array(),
			'acf_names' => array(),
		);
		return $this->json_file(
			array(
				'migrateplus' => array(
					'site'      => 'http://old.test',
					'post_type' => 'post',
					'mapping'   => MigratePlus_Exporter::mapping_for( $state ),
				),
				'items'       => $items,
			)
		);
	}

	/**
	 * @param int    $id     Source ID.
	 * @param string $status Status.
	 * @param string $date   GMT date.
	 * @return array
	 */
	protected function item( $id, $status = 'publish', $date = '2020-01-01T10:00:00+00:00' ) {
		return array(
			'id'       => $id,
			'title'    => 'Item ' . $id,
			'content'  => 'x',
			'status'   => $status,
			'date_gmt' => $date,
		);
	}

	public function test_force_status_overrides_every_record_also_on_update() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$future = gmdate( 'c', time() + WEEK_IN_SECONDS );
		$file   = $this->export_file( array( $this->item( 1 ), $this->item( 2, 'future', $future ), $this->item( 3, 'private' ) ) );

		$this->import_file( $file, array( 'force_status' => 'draft' ) )->delete();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->assertSame( 'draft', get_post_status( $this->imported( $id, 'post' )[0] ) );
		}

		// Updating: the forced status replaces the one here; a published post dated in the future stays scheduled.
		$this->import_file(
			$file,
			array(
				'force_status' => 'publish',
				'duplicate'    => 'update',
			)
		)->delete();
		wp_delete_file( $file );
		$this->assertSame( 'publish', get_post_status( $this->imported( 1, 'post' )[0] ) );
		$this->assertSame( 'future', get_post_status( $this->imported( 2, 'post' )[0] ) );
		$this->assertSame( 'publish', get_post_status( $this->imported( 3, 'post' )[0] ) );
	}

	public function test_without_force_status_records_keep_their_status() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->export_file( array( $this->item( 1, 'private' ), $this->item( 2, 'nonsense' ) ) );
		$this->import_file(
			$file,
			array(
				'force_status'   => '',
				'default_status' => 'pending',
			)
		)->delete();
		wp_delete_file( $file );
		$this->assertSame( 'private', get_post_status( $this->imported( 1, 'post' )[0] ) );
		$this->assertSame( 'pending', get_post_status( $this->imported( 2, 'post' )[0] ) );
	}

	public function test_a_false_status_imports_as_a_draft() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// JSON false, not the string: flatten() keeps the boolean and stringify() must read it as 0.
		$file = $this->export_file( array( $this->item( 1, false ), $this->item( 2, true ) ) );
		$this->import_file(
			$file,
			array(
				'force_status'   => '',
				'default_status' => 'pending',
			)
		)->delete();
		wp_delete_file( $file );
		$this->assertSame( 'draft', get_post_status( $this->imported( 1, 'post' )[0] ) );
		$this->assertSame( 'publish', get_post_status( $this->imported( 2, 'post' )[0] ) );
	}

	public function test_finished_import_flushes_rewrite_rules_once_and_dry_runs_do_not() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_permalink_structure( '/%postname%/' );
		$flushes = 0;
		$count   = static function () use ( &$flushes ) {
			++$flushes;
		};
		add_action( 'generate_rewrite_rules', $count );
		$file = $this->export_file( array( $this->item( 1 ), $this->item( 2 ) ) );

		$this->import_file(
			$file,
			array(
				'dry_run'    => 1,
				'batch_size' => 1,
			)
		)->delete();
		$this->assertSame( 0, $flushes );
		$this->import_file( $file, array( 'batch_size' => 1 ) )->delete();
		$this->assertSame( 1, $flushes );

		remove_action( 'generate_rewrite_rules', $count );
		wp_delete_file( $file );
		$this->set_permalink_structure( '' );
	}

	public function test_export_carries_the_author_login_and_name_but_no_email() {
		$user = self::factory()->user->create(
			array(
				'user_login'   => 'writer',
				'user_email'   => 'writer@source.test',
				'display_name' => 'Wanda Writer',
			)
		);
		$item = MigratePlus_Exporter::export_post( get_post( self::factory()->post->create( array( 'post_author' => $user ) ) ), MigratePlus_Exporter::sanitize_args( array() ) );
		$this->assertSame( 'writer', $item['author'] );
		$this->assertSame( 'writer', $item['author_login'] );
		$this->assertSame( 'Wanda Writer', $item['author_name'] );
		$this->assertStringNotContainsString( 'writer@source.test', wp_json_encode( $item ) );
		$fields = MigratePlus_Exporter::mapping_for(
			array(
				'args'      => MigratePlus_Exporter::sanitize_args( array() ),
				'taxes'     => array(),
				'meta_keys' => array(),
				'acf_names' => array(),
			)
		)['fields'];
		$this->assertSame( 'author_login', $fields['post_author_login'] );
		$this->assertSame( 'author_name', $fields['post_author_name'] );
	}

	/**
	 * User accounts differ from site to site: every post gets the author picked for the
	 * import, even when a user here has the old author's email or login, and the old
	 * author is kept with the post. An email in an older file is left out.
	 */
	public function test_records_get_the_picked_author_and_keep_the_old_one_as_a_note() {
		$picked = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->user->create(
			array(
				'user_login' => 'loginmatch',
				'user_email' => 'same@both.test',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$items = array(
			$this->item( 1 ) + array(
				'author'       => 'loginmatch',
				'author_login' => 'loginmatch',
				'author_name'  => 'Someone',
			),
			// From before exports stopped carrying emails.
			$this->item( 2 ) + array(
				'author'      => 'same@both.test',
				'author_name' => 'Old Style',
			),
		);
		$file = $this->export_file( $items );
		$job  = $this->import_file( $file, array( 'author' => $picked ) );

		$first  = $this->imported( 1, 'post' )[0];
		$second = $this->imported( 2, 'post' )[0];
		$this->assertSame( array( $picked, $picked ), array( (int) get_post( $first )->post_author, (int) get_post( $second )->post_author ) );

		// Updating the posts hands them to whoever is picked this time.
		$again   = self::factory()->user->create( array( 'role' => 'author' ) );
		$updated = $this->import_file(
			$file,
			array(
				'author'    => $again,
				'duplicate' => 'update',
			)
		);
		wp_delete_file( $file );
		$this->assertSame( array( $first, $second ), array( $this->imported( 1, 'post' )[0], $this->imported( 2, 'post' )[0] ) );
		$this->assertSame( array( $again, $again ), array( (int) get_post( $first )->post_author, (int) get_post( $second )->post_author ) );
		$updated->delete();
		$this->assertSame( 'loginmatch', get_post_meta( $first, MigratePlus_Importer::SOURCE_AUTHOR_META, true ) );
		$this->assertSame( 'Someone', get_post_meta( $first, MigratePlus_Importer::SOURCE_AUTHOR_NAME_META, true ) );
		$this->assertSame( '', get_post_meta( $second, MigratePlus_Importer::SOURCE_AUTHOR_META, true ) );
		$this->assertSame( 'Old Style', get_post_meta( $second, MigratePlus_Importer::SOURCE_AUTHOR_NAME_META, true ) );
		$this->assertSame( array(), wp_list_filter( $job->data['log'], array( 'l' => 'warning' ) ) );
		$job->delete();
	}

	public function test_records_given_their_own_author_get_it_on_create_and_update() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$all  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$own  = self::factory()->user->create( array( 'role' => 'author' ) );
		$file = $this->export_file( array( $this->item( 1 ), $this->item( 2 ), $this->item( 3 ) ) );
		$job  = $this->import_file(
			$file,
			array(
				'author'     => $all,
				'authors'    => array( 1 => $own ),
				'batch_size' => 2, // The third record comes in a batch of its own.
			)
		);
		$job->delete();
		$authors = function () {
			return array_map(
				function ( $id ) {
					return (int) get_post( $this->imported( $id, 'post' )[0] )->post_author;
				},
				array( 1, 2, 3 )
			);
		};
		$this->assertSame( array( $all, $own, $all ), $authors() );

		// Updated, the posts follow this run's picks: the third gets one of its own, the rest the author for all.
		$job = $this->import_file(
			$file,
			array(
				'author'     => $own,
				'authors'    => array( 2 => $all ),
				'duplicate'  => 'update',
				'batch_size' => 2,
			)
		);
		$job->delete();
		wp_delete_file( $file );
		$this->assertSame( array( $own, $own, $all ), $authors() );
	}

	public function test_an_author_picked_for_an_old_author_covers_their_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$all   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$maya  = self::factory()->user->create( array( 'role' => 'author' ) );
		$own   = self::factory()->user->create( array( 'role' => 'author' ) );
		$items = array(
			$this->item( 1 ),
			$this->item( 2 ),
			$this->item( 3 ),
			$this->item( 4 ),
		);
		// 1, 2 and 3 are maya's on the old site; 2 also has an author of its own, which wins.
		$items[0]['author_login'] = 'maya';
		$items[1]['author_login'] = 'maya';
		$items[2]['author_login'] = 'maya';
		$items[3]['author_name']  = 'Guest Writer';
		$file = $this->export_file( $items );
		$job  = $this->import_file(
			$file,
			array(
				'author'     => $all,
				'authors'    => array( 1 => $own ),
				'author_map' => array(
					'maya'         => $maya,
					'Guest Writer' => $all,
				),
			)
		);
		$job->delete();
		wp_delete_file( $file );
		$authors = array();
		foreach ( array( 1, 2, 3, 4 ) as $source_id ) {
			$authors[] = (int) get_post( $this->imported( $source_id, 'post' )[0] )->post_author;
		}
		$this->assertSame( array( $maya, $own, $maya, $all ), $authors );
	}

	public function test_clean_author_map_keeps_only_valid_picks() {
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame(
			array(
				'maya'         => $editor,
				'Guest Writer' => $editor,
			),
			MigratePlus_Mappings::clean_author_map(
				array(
					'maya'                    => $editor,
					'Guest Writer'            => (string) $editor,
					'guest'                   => $subscriber,
					'gone'                    => 999999,
					''                        => $editor,
					str_repeat( 'a', 201 )    => $editor,
				)
			)
		);
		$this->assertSame( array(), MigratePlus_Mappings::clean_author_map( 'maya' ) );
	}

	public function test_an_author_who_may_not_write_posts_falls_back_to_the_next_pick() {
		$me         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $me );
		$opts = array(
			'author'  => $editor,
			'authors' => array(
				0 => $subscriber,
				1 => 999999,
				2 => $editor,
			),
		);
		$this->assertSame( array( $editor, $editor, $editor, $editor ), array_map( array( 'MigratePlus_Importer', 'author_for' ), array_fill( 0, 4, $opts ), array( 0, 1, 2, null ) ) );
		$this->assertSame( $me, MigratePlus_Importer::author_for( array( 'author' => $subscriber ), 0 ) );
		$this->assertSame( $me, MigratePlus_Importer::author_for( array( 'author' => 0 ), null ) );
		// WP-CLI without --user: no one, so WordPress picks the author.
		wp_set_current_user( 0 );
		$this->assertSame( 0, MigratePlus_Importer::author_for( array( 'author' => 0 ), 0 ) );
	}

	public function test_clean_options_keeps_only_valid_single_record_authors() {
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$subscriber  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$authors     = array(
			0     => $editor,
			1     => $subscriber,
			2     => 999999,
			'two' => $editor,
			3     => (string) $contributor,
			4     => $editor,
			-1    => $editor,
		);
		$this->assertSame(
			array(
				0 => $editor,
				3 => $contributor,
			),
			MigratePlus_Mappings::clean_options( array( 'authors' => $authors ), 4 )['authors']
		);
		$this->assertSame(
			array(
				0 => $editor,
				3 => $contributor,
				4 => $editor,
			),
			MigratePlus_Mappings::clean_options( array( 'authors' => $authors ) )['authors']
		);
		$this->assertSame( array(), MigratePlus_Mappings::clean_options( array( 'authors' => 'everyone' ) )['authors'] );
	}

	/**
	 * Export a post with a thread of comments, delete it, and import the file.
	 *
	 * @return array { file: string, member: int, source: int }
	 */
	protected function export_commented_post() {
		$member = self::factory()->user->create( array( 'user_email' => 'member@site.test' ) );
		$post   = self::factory()->post->create( array( 'post_title' => 'Discussed' ) );
		$top    = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post,
				'comment_author'       => 'Guest',
				'comment_author_email' => 'guest@example.test',
				'comment_author_url'   => 'http://guest.test',
				'comment_content'      => 'First!',
				'comment_date'         => '2021-03-04 05:06:07',
				'comment_date_gmt'     => '2021-03-04 05:06:07',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post,
				'comment_parent'  => $top,
				'user_id'         => $member,
				'comment_author'  => 'Member',
				'comment_content' => 'A reply',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_content'  => 'Waiting',
				'comment_approved' => '0',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_content'  => 'Buy now',
				'comment_approved' => 'spam',
			)
		);
		add_comment_meta( $top, 'rating', '5' );
		add_comment_meta( $top, '_private', 'no' );

		$args  = MigratePlus_Exporter::sanitize_args( array( 'include_comments' => 1 ) );
		$state = MigratePlus_Exporter::start( $args );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state );
		}
		$file = wp_tempnam( 'migrateplus-test.json' );
		copy( $state['file'], $file );
		MigratePlus_Exporter::forget( $state['id'] );
		wp_delete_post( $post, true );
		return array(
			'file'   => $file,
			'member' => $member,
			'source' => $post,
		);
	}

	public function test_comments_round_trip_with_threading_users_and_meta() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x   = $this->export_commented_post();
		$doc = json_decode( (string) file_get_contents( $x['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 'comments', $doc['migrateplus']['mapping']['fields']['comments'] );
		$this->assertCount( 2, $doc['items'][0]['comments'] ); // Approved only.
		$this->assertSame( array( 'rating' => '5' ), $doc['items'][0]['comments'][0]['meta'] );

		$job = $this->import_file( $x['file'] );
		$this->assertSame( 2, $job->data['comments_total'] );
		$job->delete();
		$post     = $this->imported( $x['source'], 'post' )[0];
		$comments = get_comments(
			array(
				'post_id' => $post,
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		);
		$this->assertCount( 2, $comments );
		list( $top, $reply ) = $comments;
		$this->assertSame( 'Guest', $top->comment_author );
		$this->assertSame( 'guest@example.test', $top->comment_author_email );
		$this->assertSame( 'http://guest.test', $top->comment_author_url );
		$this->assertSame( '2021-03-04 05:06:07', $top->comment_date_gmt );
		$this->assertSame( '1', $top->comment_approved );
		$this->assertSame( '5', get_comment_meta( $top->comment_ID, 'rating', true ) );
		$this->assertSame( '', get_comment_meta( $top->comment_ID, '_private', true ) );
		$this->assertSame( (int) $top->comment_ID, (int) $reply->comment_parent );
		$this->assertSame( $x['member'], (int) $reply->user_id );
		$this->assertSame( '2', get_post( $post )->comment_count );

		// The same file again, updating or skipping: no comment is added twice.
		foreach ( array( 'update', 'skip' ) as $mode ) {
			$job = $this->import_file( $x['file'], array( 'duplicate' => $mode ) );
			$this->assertSame( 0, $job->data['comments_total'] );
			$job->delete();
			$this->assertSame(
				2,
				get_comments(
					array(
						'post_id' => $post,
						'count'   => true,
					)
				)
			);
		}
		wp_delete_file( $x['file'] );
	}

	public function test_comments_can_be_left_out_of_an_import() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x = $this->export_commented_post();
		$this->import_file( $x['file'], array( 'import_comments' => 0 ) )->delete();
		wp_delete_file( $x['file'] );
		$this->assertSame( '0', get_post( $this->imported( $x['source'], 'post' )[0] )->comment_count );
	}

	/**
	 * Rollback deletes the posts an import created (their comments go with them) and
	 * the comments it added to posts it only updated, which stay.
	 */
	public function test_rollback_removes_comments_added_to_updated_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x = $this->export_commented_post();
		$this->import_file( $x['file'], array( 'import_comments' => 0 ) )->delete();
		$post  = $this->imported( $x['source'], 'post' )[0];
		$local = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$job = $this->import_file( $x['file'], array( 'duplicate' => 'update' ) );
		wp_delete_file( $x['file'] );
		$this->assertSame( 3, (int) get_post( $post )->comment_count );
		$this->assertSame( 2, $job->imported_count() );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job, 1 );
		} while ( ! $result['done'] );

		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$this->assertNotNull( get_post( $post ) );
		$this->assertSame(
			array( $local ),
			array_map(
				'intval',
				get_comments(
					array(
						'post_id' => $post,
						'fields'  => 'ids',
					)
				)
			)
		);
		$this->assertSame( '1', get_post( $post )->comment_count );
		$job->delete();
	}

	/**
	 * Run an export to the end and return the decoded file.
	 *
	 * @param array $args Export args.
	 * @return array
	 */
	protected function export_doc( array $args ) {
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( $args ) );
		$this->assertIsArray( $state );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );
		return $doc;
	}

	/**
	 * Several post types go into one file, media first, with one mapping that covers them all.
	 */
	public function test_export_of_several_types_writes_media_first() {
		$post = self::factory()->post->create( array( 'post_title' => 'A post' ) );
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'A page',
			)
		);
		self::factory()->attachment->create_object(
			'pic.jpg',
			$post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Pic',
			)
		);

		$args = MigratePlus_Exporter::sanitize_args( array( 'post_types' => 'page,attachment,post' ) );
		$this->assertSame( array( 'attachment', 'page', 'post' ), $args['post_types'] );
		$this->assertSame(
			array(
				'attachment' => 1,
				'page'       => 1,
				'post'       => 1,
			),
			MigratePlus_Exporter::counts( $args )
		);

		$doc = $this->export_doc(
			array(
				'post_types'    => 'page,attachment,post',
				'include_image' => 1,
			)
		);
		$this->assertArrayNotHasKey( 'post_type', $doc['migrateplus'] );
		$this->assertSame( array( 'attachment', 'page', 'post' ), $doc['migrateplus']['post_types'] );
		$this->assertSame( 3, $doc['migrateplus']['count'] );
		$this->assertSame( array( 'attachment', 'page', 'post' ), array_column( $doc['items'], 'post_type' ) );
		$this->assertSame( 'file', $doc['migrateplus']['mapping']['fields']['media_url'] );
		$this->assertSame( 'featured_image', $doc['migrateplus']['mapping']['fields']['featured_image'] );
		$this->assertArrayNotHasKey( 'menus', $doc );

		// One type: the header still names it, as older versions expect.
		$single = $this->export_doc( array( 'post_type' => 'page' ) );
		$this->assertSame( 'page', $single['migrateplus']['post_type'] );
		$this->assertSame( array( 'page' ), $single['migrateplus']['post_types'] );
		$this->assertArrayNotHasKey( 'media_url', $single['migrateplus']['mapping']['fields'] );

		$this->assertWPError( MigratePlus_Exporter::sanitize_args( array( 'post_types' => 'page,nope' ) ) );
		$this->assertWPError( MigratePlus_Exporter::sanitize_args( array( 'post_types' => array() ) ) );
	}

	/**
	 * Hand-picked posts name their own types and ignore the status and date filters.
	 */
	public function test_export_of_hand_picked_posts() {
		$draft = self::factory()->post->create(
			array(
				'post_title'  => 'Picked draft',
				'post_status' => 'draft',
			)
		);
		$page  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Picked page',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Not picked' ) );

		// The type list and the status and date filters are dropped: the picks decide.
		$args = MigratePlus_Exporter::sanitize_args(
			array(
				'post_types' => 'post',
				'post_ids'   => "$draft,$page",
				'statuses'   => 'publish',
				'after'      => '2999-01-01',
			)
		);
		$this->assertSame( array( $draft, $page ), $args['post_ids'] );
		$this->assertSame( array( 'post', 'page' ), $args['post_types'] );
		$this->assertEqualsCanonicalizing( array( 'publish', 'draft', 'pending', 'private', 'future' ), $args['statuses'] );
		$this->assertSame( '', $args['after'] );
		$this->assertSame(
			array(
				'post' => 1,
				'page' => 1,
			),
			MigratePlus_Exporter::counts( $args )
		);

		$doc = $this->export_doc( array( 'post_ids' => array( $page, $draft ) ) );
		$this->assertSame( 2, $doc['migrateplus']['count'] );
		$this->assertEqualsCanonicalizing( array( 'Picked draft', 'Picked page' ), array_column( $doc['items'], 'title' ) );

		$missing = MigratePlus_Exporter::sanitize_args( array( 'post_ids' => array( 999999 ) ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'migrateplus_export_post', $missing->get_error_code() );
		wp_trash_post( $draft );
		$trashed = MigratePlus_Exporter::sanitize_args( array( 'post_ids' => array( $draft ) ) );
		$this->assertWPError( $trashed );
		$this->assertSame( 'migrateplus_export_post', $trashed->get_error_code() );
	}

	/**
	 * The JSON source puts media first and counts each post type, whatever order the file has.
	 */
	public function test_json_source_orders_media_first_and_counts_types() {
		$file   = $this->json_file(
			array(
				'migrateplus' => array(
					'site'       => 'http://old.test',
					'post_types' => array( 'attachment', 'post' ),
					'mapping'    => array( 'fields' => array( 'source_id' => 'id' ) ),
				),
				'items'       => array(
					array(
						'id'        => 1,
						'post_type' => 'post',
					),
					array(
						'id'        => 2,
						'post_type' => 'attachment',
					),
					array(
						'id'        => 3,
						'post_type' => 'post',
					),
				),
			)
		);
		$source = new MigratePlus_Source_JSON( array( 'file' => $file ) );
		$out    = $source->prepare();
		wp_delete_file( $file );
		$this->assertIsArray( $out );
		$this->assertSame( 'attachment,post', $out['migrateplus_post_types'] );
		$this->assertSame(
			array(
				'attachment' => 1,
				'post'       => 2,
			),
			$out['migrateplus_counts']
		);
		$batch = ( new MigratePlus_Source_JSON( $out ) )->fetch( null, 10 );
		wp_delete_file( $out['cache_file'] );
		$this->assertSame( array( 2, 1, 3 ), array_column( $batch['records'], 'id' ) );
		$this->assertArrayNotHasKey( 'menus_file', $out );
	}

	/**
	 * A file with several types imports each record as its own type, links a parent of
	 * another post type, and counts progress per type.
	 */
	public function test_import_of_several_types_links_parents_across_types() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_post_type(
			'pp_doc',
			array(
				'show_ui'      => true,
				'hierarchical' => true,
			)
		);
		$state = array(
			'args'      => MigratePlus_Exporter::sanitize_args( array( 'post_types' => 'page,pp_doc' ) ),
			'taxes'     => array(),
			'meta_keys' => array(),
			'acf_names' => array(),
		);
		$file  = $this->json_file(
			array(
				'migrateplus' => array(
					'site'       => 'http://multi.test',
					'post_types' => array( 'page', 'pp_doc' ),
					'mapping'    => MigratePlus_Exporter::mapping_for( $state ),
				),
				'items'       => array(
					array(
						'id'        => 1,
						'post_type' => 'page',
						'title'     => 'Handbook',
					),
					array(
						'id'        => 2,
						'post_type' => 'page',
						'title'     => 'Chapter',
						'parent'    => array( 'id' => 1 ),
					),
					array(
						'id'        => 3,
						'post_type' => 'pp_doc',
						'title'     => 'Doc under a page',
						'parent'    => array( 'id' => 1 ),
					),
				),
			)
		);
		$job   = $this->import_file( $file, array( 'batch_size' => 1 ) );
		wp_delete_file( $file );

		$page = $this->imported( 1, 'page' );
		$this->assertCount( 1, $page );
		$this->assertSame( $page[0], (int) get_post( $this->imported( 2, 'page' )[0] )->post_parent );
		$doc = $this->imported( 3, 'pp_doc' );
		$this->assertCount( 1, $doc );
		$this->assertSame( $page[0], (int) get_post( $doc[0] )->post_parent );
		$this->assertSame( 'page', $job->data['options']['post_type'] );
		$this->assertSame(
			array(
				array( 'page', 2, 2 ),
				array( 'pp_doc', 1, 1 ),
			),
			array_map(
				static function ( $s ) {
					return array( $s['type'], $s['total'], $s['done'] );
				},
				$job->data['sections']
			)
		);
		$this->assertSame( 'complete', $job->data['status'] );

		// One job, so one rollback removes every type it created.
		do {
			$result = MigratePlus_Runner::rollback_batch( $job, 1 );
		} while ( ! $result['done'] );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$this->assertSame( array(), $this->imported( 1, 'page' ) );
		$this->assertSame( array(), $this->imported( 3, 'pp_doc' ) );
		$job->delete();
		unregister_post_type( 'pp_doc' );
	}

	public function test_custom_menu_links_to_the_old_site_are_rewritten() {
		$home = untrailingslashit( home_url() );
		$this->assertSame( $home . '/contact/?x=1', MigratePlus_Menus::rewrite_url( 'http://old.test/contact/?x=1', 'https://old.test' ) );
		$this->assertSame( $home, MigratePlus_Menus::rewrite_url( 'https://old.test', 'http://old.test' ) );
		$this->assertSame( 'http://old.test/wp-content/uploads/a.pdf', MigratePlus_Menus::rewrite_url( 'http://old.test/wp-content/uploads/a.pdf', 'http://old.test' ) );
		$this->assertSame( 'http://old.test.example.com/', MigratePlus_Menus::rewrite_url( 'http://old.test.example.com/', 'http://old.test' ) );
		$this->assertSame( 'http://old.test/x', MigratePlus_Menus::rewrite_url( 'http://old.test/x', '' ) );
	}

	/**
	 * A menu with a page, a child item, a category, custom links, an item whose page is
	 * not exported and a theme location this site lacks, exported with its pages; the
	 * pages and menu are then deleted, as if the file went to another site.
	 *
	 * @return array { file, about, team, cat, old_primary }
	 */
	protected function export_menu_site() {
		register_nav_menus( array( 'primary' => 'Primary' ) );
		$about = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
				'post_name'  => 'about',
			)
		);
		$team  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Team',
			)
		);
		$gone  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Gone',
				'post_status' => 'draft', // Left out of an export of published pages.
			)
		);
		$cat   = self::factory()->category->create( array( 'name' => 'News' ) );
		$menu  = wp_create_nav_menu( 'Main' );
		$item  = static function ( array $args ) use ( $menu ) {
			return wp_update_nav_menu_item( $menu, 0, $args + array( 'menu-item-status' => 'publish' ) );
		};
		$top   = $item(
			array(
				'menu-item-type'       => 'post_type',
				'menu-item-object'     => 'page',
				'menu-item-object-id'  => $about,
				'menu-item-classes'    => 'hi there',
				'menu-item-attr-title' => 'About us',
				'menu-item-target'     => '_blank',
				'menu-item-xfn'        => 'friend',
				'menu-item-position'   => 1,
			)
		);
		$item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $team,
				'menu-item-title'     => 'Our team',
				'menu-item-parent-id' => $top,
				'menu-item-position'  => 2,
			)
		);
		$item(
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $cat,
				'menu-item-position'  => 3,
			)
		);
		$item(
			array(
				'menu-item-type'        => 'custom',
				'menu-item-title'       => 'Contact',
				'menu-item-url'         => 'http://old.test/contact/?x=1',
				'menu-item-description' => 'Write to us',
				'menu-item-position'    => 4,
			)
		);
		$item(
			array(
				'menu-item-type'     => 'custom',
				'menu-item-title'    => 'Brochure',
				'menu-item-url'      => 'http://old.test/wp-content/uploads/a.pdf',
				'menu-item-position' => 5,
			)
		);
		$item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $gone,
				'menu-item-position'  => 6,
			)
		);
		set_theme_mod(
			'nav_menu_locations',
			array(
				'primary' => $menu,
				'retired' => $menu,
			)
		);

		$doc = $this->export_doc(
			array(
				'post_type'     => 'page',
				'statuses'      => 'publish',
				'include_menus' => 1,
			)
		);
		$this->assertCount( 1, $doc['menus'] );
		$this->assertCount( 6, $doc['menus'][0]['items'] );
		$this->assertSame( array( 'primary', 'retired' ), $doc['menus'][0]['locations'] );
		$this->assertSame( 'News', $doc['menus'][0]['items'][2]['ref']['path'] );
		$doc['migrateplus']['site'] = 'http://old.test'; // As if exported on the old site.
		$file                       = $this->json_file( $doc );

		wp_delete_nav_menu( $menu );
		foreach ( array( $about, $team, $gone ) as $id ) {
			wp_delete_post( $id, true );
		}
		$old_primary = wp_create_nav_menu( 'Old primary' );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $old_primary ) );
		return array(
			'file'        => $file,
			'about'       => $about,
			'team'        => $team,
			'cat'         => $cat,
			'old_primary' => $old_primary,
		);
	}

	/**
	 * @param int $menu_id Menu.
	 * @return array title => item
	 */
	protected function menu_items( $menu_id ) {
		$out = array();
		foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
			$out[ $item->title ] = $item;
		}
		return $out;
	}

	public function test_menus_import_after_posts_without_duplicates_and_roll_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x = $this->export_menu_site();

		$job  = $this->import_file( $x['file'] );
		$menu = get_term_by( 'slug', 'main', 'nav_menu' );
		$this->assertInstanceOf( 'WP_Term', $menu );
		$this->assertSame( 'Main', $menu->name );
		$items = $this->menu_items( $menu->term_id );
		$this->assertSame( array( 'About', 'Our team', 'News', 'Contact', 'Brochure' ), array_keys( $items ) );

		$about = $this->imported( $x['about'], 'page' )[0];
		$this->assertSame( $about, (int) $items['About']->object_id );
		$this->assertSame( array( 'hi', 'there' ), array_values( array_filter( $items['About']->classes ) ) );
		$this->assertSame( '_blank', $items['About']->target );
		$this->assertSame( 'friend', $items['About']->xfn );
		$this->assertSame( 'About us', $items['About']->attr_title );
		$this->assertSame( $this->imported( $x['team'], 'page' )[0], (int) $items['Our team']->object_id );
		$this->assertSame( $items['About']->ID, (int) $items['Our team']->menu_item_parent );
		$this->assertSame( $x['cat'], (int) $items['News']->object_id );
		$this->assertSame( untrailingslashit( home_url() ) . '/contact/?x=1', $items['Contact']->url );
		$this->assertSame( 'Write to us', $items['Contact']->description );
		$this->assertSame( 'http://old.test/wp-content/uploads/a.pdf', $items['Brochure']->url );
		$this->assertSame( $menu->term_id, get_nav_menu_locations()['primary'] );

		$log = implode( "\n", wp_list_pluck( $job->data['log'], 'm' ) );
		$this->assertStringContainsString( '"retired" does not exist', $log );
		$this->assertStringContainsString( 'Menu item "Gone" was skipped', $log );
		$this->assertSame( array( 'page', 'nav_menu' ), wp_list_pluck( $job->data['sections'], 'type' ) );
		$this->assertSame( 1, end( $job->data['sections'] )['done'] );

		// The same file again, updating: same menu, same items.
		$again = $this->import_file( $x['file'], array( 'duplicate' => 'update' ) );
		wp_delete_file( $x['file'] );
		$this->assertCount( 2, wp_get_nav_menus() ); // Main and Old primary.
		$this->assertCount( 5, wp_get_nav_menu_items( $menu->term_id ) );
		$this->assertSame( 0, $again->imported_count() );
		$again->delete();

		// Rollback removes the pages, items and menu, and gives the location its old menu back.
		$this->assertSame( 2 + 5 + 1, $job->imported_count() );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job, 2 );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( get_term_by( 'slug', 'main', 'nav_menu' ) );
		$this->assertSame( array(), $this->imported( $x['about'], 'page' ) );
		$this->assertSame( $x['old_primary'], get_nav_menu_locations()['primary'] );
		$job->delete();
	}

	public function test_menus_can_be_left_out_and_dry_runs_save_none() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x = $this->export_menu_site();

		$dry = $this->import_file( $x['file'], array( 'dry_run' => 1 ) );
		$this->assertFalse( get_term_by( 'slug', 'main', 'nav_menu' ) );
		$this->assertStringContainsString( 'Would create menu "Main": 5 items added', implode( "\n", wp_list_pluck( $dry->data['log'], 'm' ) ) );
		$dry->delete();

		$job = $this->import_file( $x['file'], array( 'import_menus' => 0 ) );
		wp_delete_file( $x['file'] );
		$this->assertFalse( get_term_by( 'slug', 'main', 'nav_menu' ) );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertStringContainsString( '"Import menus" is off', implode( "\n", wp_list_pluck( $job->data['log'], 'm' ) ) );
		$job->delete();
	}

	/**
	 * A compressed export is gzip written in the final step, and imports like the plain file,
	 * also when its name has lost the .gz: the first bytes decide.
	 */
	public function test_gzip_export_imports_like_the_plain_file() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post  = self::factory()->post->create( array( 'post_title' => 'Packed' ) );
		$state = MigratePlus_Exporter::start(
			MigratePlus_Exporter::sanitize_args(
				array(
					'post_type' => 'post',
					'gzip'      => 1,
				)
			)
		);
		$this->assertIsArray( $state );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$this->assertArrayNotHasKey( 'error', $state );
		$this->assertStringEndsWith( '.json.gz', $state['filename'] );
		$this->assertTrue( MigratePlus_Source_JSON::is_gzip( $state['file'] ) );
		$doc = json_decode( (string) gzdecode( (string) file_get_contents( $state['file'] ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( array( 'Packed' ), array_column( $doc['items'], 'title' ) );

		$renamed = wp_tempnam( 'migrateplus-test.json' );
		copy( $state['file'], $renamed );
		MigratePlus_Exporter::forget( $state['id'] );
		wp_delete_post( $post, true );

		$job = $this->import_file( $renamed );
		wp_delete_file( $renamed );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame( 1, $job->data['stats']['created'] );
		$this->assertSame( 'Packed', get_the_title( $this->imported( $post, 'post' )[0] ) );
		$this->assertSame( array(), glob( MigratePlus_Util::storage_dir() . 'gunzip-*' ) );
		$job->delete();
	}

	/**
	 * A .json.gz that expands past the limit is refused, and its partial copy removed.
	 */
	public function test_gzip_import_stops_at_the_size_limit() {
		$file = wp_tempnam( 'migrateplus-test.json.gz' );
		file_put_contents( $file, gzencode( wp_json_encode( array( array( 'title' => str_repeat( 'a', 5000 ) ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$limit = static function () {
			return 1000;
		};
		add_filter( 'migrateplus_max_json_size', $limit );
		$out = ( new MigratePlus_Source_JSON( array( 'file' => $file ) ) )->prepare();
		remove_filter( 'migrateplus_max_json_size', $limit );
		wp_delete_file( $file );
		$this->assertWPError( $out );
		$this->assertSame( 'migrateplus_gzip_size', $out->get_error_code() );
		$this->assertSame( array(), glob( MigratePlus_Util::storage_dir() . 'gunzip-*' ) );
	}

	/**
	 * Menus can be exported with no content, and such a file imports straight to its menus.
	 */
	public function test_menus_only_export_imports_its_menus() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu = wp_create_nav_menu( 'Footer' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'   => 'custom',
				'menu-item-title'  => 'Help',
				'menu-item-url'    => 'https://help.example/',
				'menu-item-status' => 'publish',
			)
		);
		$args = MigratePlus_Exporter::sanitize_args(
			array(
				'post_type'         => 'post',
				'after'             => '2099-01-01', // Nothing matches.
				'include_menus'     => 1,
				'include_term_data' => 0, // Menus alone: the terms section has its own tests.
			)
		);
		$this->assertSame( array( 'post' => 0 ), MigratePlus_Exporter::counts( $args ) );
		$doc = $this->export_doc( $args );
		$this->assertSame( array(), $doc['items'] );
		$this->assertSame( 0, $doc['migrateplus']['count'] );
		$this->assertSame( array( 'Help' ), array_column( $doc['menus'][0]['items'], 'title' ) );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		wp_delete_nav_menu( $menu );

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame( 0, (int) $job->data['total'] );
		$this->assertSame( array( 'nav_menu' ), wp_list_pluck( $job->data['sections'], 'type' ) );
		$imported = get_term_by( 'slug', 'footer', 'nav_menu' );
		$this->assertInstanceOf( 'WP_Term', $imported );
		$this->assertSame( array( 'Help' ), array_keys( $this->menu_items( $imported->term_id ) ) );
		$job->delete();
	}

	/**
	 * Saved mappings are cleaned like an import's, keep no per-run choices, are offered for
	 * files with the same top-level fields, and can be renamed and deleted.
	 */
	public function test_saved_mappings_are_cleaned_matched_renamed_and_deleted() {
		$saved = MigratePlus_Mappings::save(
			'Books <b>feed</b>',
			array(
				'fields' => array(
					'post_title'  => "title\x07",
					'not_a_field' => 'id',
				),
				'meta'   => array(
					array(
						'key'    => 'isbn',
						'source' => 'isbn',
					),
				),
			),
			array(
				'post_type'      => 'page',
				'duplicate'      => 'update',
				'dry_run'        => 1,
				'update_ids'     => array( 5 ),
				'skip_ids'       => array( 6 ),
				'duplicate_rest' => 'copy',
			),
			array( 'id', 'title', 'isbn', 'authors.0.name' )
		);
		$this->assertIsArray( $saved );
		$this->assertSame( 'Books feed', $saved['name'] );
		$this->assertSame( array( 'post_title' => 'title' ), $saved['mapping']['fields'] );
		$this->assertSame( 'page', $saved['options']['post_type'] );
		$this->assertSame( 'update', $saved['options']['duplicate'] );
		$this->assertArrayNotHasKey( 'dry_run', $saved['options'] );
		$this->assertArrayNotHasKey( 'update_ids', $saved['options'] );
		$this->assertArrayNotHasKey( 'skip_ids', $saved['options'] );
		$this->assertArrayNotHasKey( 'duplicate_rest', $saved['options'] );
		$this->assertFalse( wp_load_alloptions()[ MigratePlus_Mappings::OPTION ] ?? false );

		// Same top-level fields in another order, with more authors: offered. Different fields: not.
		$this->assertSame( 'Books feed', MigratePlus_Mappings::match( array( 'isbn', 'authors.0.name', 'authors.1.name', 'title', 'id' ) ) );
		$this->assertSame( '', MigratePlus_Mappings::match( array( 'id', 'title' ) ) );

		$config = MigratePlus_Mappings::to_config( $saved );
		$this->assertSame( 'json', $config['source'] );
		$this->assertSame( $saved['mapping'], $config['mapping'] );

		MigratePlus_Mappings::save( 'Other', array(), array(), array() );
		$this->assertWPError( MigratePlus_Mappings::rename( 'Books feed', 'Other' ) );
		$this->assertTrue( MigratePlus_Mappings::rename( 'Books feed', 'Books' ) );
		$this->assertNull( MigratePlus_Mappings::get( 'Books feed' ) );
		$this->assertSame( 'Books', MigratePlus_Mappings::get( 'Books' )['name'] );
		$this->assertTrue( MigratePlus_Mappings::delete( 'Books' ) );
		$this->assertFalse( MigratePlus_Mappings::delete( 'Books' ) );
		$this->assertSame( array( 'Other' ), array_keys( MigratePlus_Mappings::all() ) );
	}

	/**
	 * A saved mapping keeps only the options it was given, so one left out takes the
	 * MigratePlus setting when the mapping is used: --mapping and --config both merge
	 * their options over the job's defaults and now come to the same thing.
	 */
	public function test_saved_mapping_options_left_out_follow_the_settings() {
		$config = array(
			'post_type'     => 'page',
			'rewrite_links' => 0,
		);
		$saved  = MigratePlus_Mappings::save( 'Feed', array( 'fields' => array( 'post_title' => 'title' ) ), $config, array( 'title' ) );
		$this->assertIsArray( $saved );
		$this->assertSame(
			array(
				'post_type'     => 'page',
				'rewrite_links' => 0,
			),
			$saved['options']
		);
		$this->assertSame( $saved['options'], MigratePlus_Mappings::to_config( $saved )['options'] );

		foreach ( array( 1, 0 ) as $setting ) {
			update_option( MigratePlus_Settings::OPTION, array( 'create_terms' => $setting ) );
			MigratePlus_Settings::flush_cache();
			$via_mapping = array_merge( MigratePlus_Job::default_options(), MigratePlus_Mappings::get( 'Feed' )['options'] );
			$via_config  = array_merge( MigratePlus_Job::default_options(), $config );
			$this->assertSame( $setting, $via_mapping['create_terms'] );
			$this->assertSame( $via_config['create_terms'], $via_mapping['create_terms'] );
			$this->assertSame( 0, $via_mapping['rewrite_links'] ); // Given, so kept.
			$this->assertSame( 'page', $via_mapping['post_type'] );
		}
		delete_option( MigratePlus_Settings::OPTION );
		MigratePlus_Settings::flush_cache();
	}

	/**
	 * Saved mappings stop at the cap; an existing one can still be saved over.
	 */
	public function test_saved_mappings_are_capped() {
		for ( $i = 1; $i <= MigratePlus_Mappings::MAX; $i++ ) {
			$this->assertIsArray( MigratePlus_Mappings::save( 'Map ' . $i, array(), array(), array() ) );
		}
		$over = MigratePlus_Mappings::save( 'One too many', array(), array(), array() );
		$this->assertWPError( $over );
		$this->assertSame( 'migrateplus_mapping_limit', $over->get_error_code() );
		$this->assertIsArray( MigratePlus_Mappings::save( 'Map 1', array(), array( 'post_type' => 'page' ), array() ) );
		$this->assertCount( MigratePlus_Mappings::MAX, MigratePlus_Mappings::all() );
	}

	/**
	 * Posts added while an export runs must not end it before its last post type.
	 */
	public function test_content_added_mid_export_keeps_every_type() {
		self::factory()->post->create( array( 'post_title' => 'First post' ) );
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'The page',
			)
		);
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_types' => 'post,page' ) ) );
		$this->assertSame( 2, $state['total'] );
		MigratePlus_Exporter::run_batch( $state, 1 );
		self::factory()->post->create( array( 'post_title' => 'Added meanwhile' ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );
		$this->assertSame( array( 'First post', 'Added meanwhile', 'The page' ), array_column( $doc['items'], 'title' ) );
	}

	/**
	 * An item that cannot be written as JSON fails the export instead of going missing from it.
	 */
	public function test_export_fails_on_an_item_it_cannot_encode() {
		self::factory()->post->create( array( 'post_title' => 'Broken' ) );
		$nan = static function ( $item ) {
			$item['meta']['ratio'] = NAN;
			return $item;
		};
		add_filter( 'migrateplus_export_item', $nan );
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 10 );
		}
		remove_filter( 'migrateplus_export_item', $nan );
		$this->assertStringContainsString( '"Broken"', $state['error'] );
		$this->assertSame( '', $state['file'] );
		$entry = MigratePlus_Exporter::history_entry( $state['id'] );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'error', end( $entry['log'] )['l'] );
		MigratePlus_Exporter::forget( $state['id'] );
	}

	/**
	 * The decompressed-size cap leaves room to decode the file in memory.
	 */
	public function test_gzip_size_limit_is_an_eighth_of_memory() {
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$this->assertSame( $memory > 0 ? intdiv( $memory, 8 ) : GB_IN_BYTES, MigratePlus_Source_JSON::max_json_size() );
	}

	/**
	 * Users who may not edit menus can still export when menus are on by default.
	 */
	public function test_export_leaves_menus_out_for_users_who_may_not_edit_them() {
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		$args = new ReflectionMethod( 'MigratePlus_Admin', 'export_args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$args->setAccessible( true ); // Needed before PHP 8.1, deprecated in 8.5.
		}
		$_POST['args'] = wp_slash(
			wp_json_encode(
				array(
					'post_type'     => 'post',
					'include_menus' => 1,
				)
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = $args->invoke( null );
		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['include_menus'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 1, $args->invoke( null )['include_menus'] );
		unset( $_POST['args'] );
	}

	/**
	 * Deleting several History entries removes imports and exports, but refuses running ones.
	 */
	public function test_bulk_history_delete_skips_running_entries() {
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		$delete = new ReflectionMethod( 'MigratePlus_Admin', 'delete_entries' );
		if ( PHP_VERSION_ID < 80100 ) {
			$delete->setAccessible( true ); // Needed before PHP 8.1, deprecated in 8.5.
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_title' => 'Exported' ) );

		$done    = MigratePlus_Job::create( 'json', array() );
		$failed  = MigratePlus_Job::create( 'json', array() );
		$running = MigratePlus_Job::create( 'json', array() );

		$failed->data['status']  = 'failed';
		$running->data['status'] = 'running';
		$failed->save();
		$running->save();
		$finished = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );
		while ( empty( $finished['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $finished, 10 );
		}
		$unfinished = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( array( 'post_type' => 'post' ) ) );

		$out = $delete->invoke(
			null,
			array(
				array(
					'kind' => 'job',
					'id'   => $done->data['id'],
				),
				array(
					'kind' => 'job',
					'id'   => $failed->data['id'],
				),
				array(
					'kind' => 'job',
					'id'   => $running->data['id'],
				),
				array(
					'kind' => 'export',
					'id'   => $finished['id'],
				),
				array(
					'kind' => 'export',
					'id'   => $unfinished['id'],
				),
				array(
					'kind' => 'nope',
					'id'   => 'x',
				),
			)
		);

		$this->assertSame( array( $done->data['id'], $failed->data['id'], $finished['id'] ), array_column( $out['deleted'], 'id' ) );
		$this->assertSame( array( $running->data['id'], $unfinished['id'] ), array_column( $out['skipped'], 'id' ) );
		$this->assertSame( array( 'still running', 'still running' ), array_column( $out['skipped'], 'reason' ) );
		$this->assertNull( MigratePlus_Job::load( $done->data['id'] ) );
		$this->assertNull( MigratePlus_Job::load( $failed->data['id'] ) );
		$this->assertNotNull( MigratePlus_Job::load( $running->data['id'] ) );
		$this->assertNull( MigratePlus_Exporter::history_entry( $finished['id'] ) );
		$this->assertFileDoesNotExist( $finished['file'] );
		$this->assertNotNull( MigratePlus_Exporter::history_entry( $unfinished['id'] ) );

		$running->delete();
		MigratePlus_Exporter::forget( $unfinished['id'] );
	}

	/**
	 * A job whose batch lock is held (an import or rollback batch in flight) is not deleted.
	 */
	public function test_bulk_history_delete_skips_a_locked_job() {
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		$delete = new ReflectionMethod( 'MigratePlus_Admin', 'delete_entries' );
		if ( PHP_VERSION_ID < 80100 ) {
			$delete->setAccessible( true ); // Needed before PHP 8.1, deprecated in 8.5.
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job                 = MigratePlus_Job::create( 'json', array() );
		$job->data['status'] = 'complete';
		$job->save();
		$lock = MigratePlus_Runner::lock( $job );

		$out = $delete->invoke(
			null,
			array(
				array(
					'kind' => 'job',
					'id'   => $job->data['id'],
				),
			)
		);

		$this->assertSame( array(), $out['deleted'] );
		$this->assertSame( 'an import or rollback batch is running', $out['skipped'][0]['reason'] );
		$this->assertNotNull( MigratePlus_Job::load( $job->data['id'] ) );
		MigratePlus_Runner::unlock( $job, $lock );
		$job->delete();
	}

	/**
	 * A dry run into a menu matched by slug names the menu on this site it would fill.
	 */
	public function test_dry_run_names_the_existing_menu_it_would_fill() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$x    = $this->export_menu_site();
		$here = wp_create_nav_menu( 'Header' );
		wp_update_term( $here, 'nav_menu', array( 'slug' => 'main' ) );

		$dry = $this->import_file( $x['file'], array( 'dry_run' => 1 ) );
		wp_delete_file( $x['file'] );
		$this->assertStringContainsString( 'Would update menu "Main": 5 items added, 0 updated, 1 skipped, in the existing menu "Header".', implode( "\n", wp_list_pluck( $dry->data['log'], 'm' ) ) );
		$dry->delete();
	}

	/**
	 * A MigratePlus export from the given site with the given field mapping.
	 *
	 * @param string  $site   Site.
	 * @param array   $fields Mapping fields.
	 * @param array[] $items  Items.
	 * @param array   $header More header keys.
	 * @return string Path.
	 */
	protected function mapped_file( $site, array $fields, array $items, array $header = array() ) {
		return $this->json_file(
			array(
				'migrateplus' => $header + array(
					'site'    => $site,
					'mapping' => array( 'fields' => $fields ),
				),
				'items'       => $items,
			)
		);
	}

	public function test_update_leaves_fields_the_record_lacks_alone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$full = $this->mapped_file(
			'http://partial.test',
			array(
				'source_id'    => 'id',
				'post_title'   => 'title',
				'post_content' => 'content',
			),
			array(
				array(
					'id'      => 1,
					'title'   => 'First',
					'content' => 'Body',
				),
			)
		);
		$this->import_file( $full )->delete();
		wp_delete_file( $full );

		$titles = $this->mapped_file(
			'http://partial.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
			),
			array(
				array(
					'id'    => 1,
					'title' => 'Renamed',
				),
			)
		);
		$job    = $this->import_file( $titles, array( 'duplicate' => 'update' ) );
		wp_delete_file( $titles );

		$post = get_post( $this->imported( 1, 'post' )[0] );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( 'Body', $post->post_content );
		$changes = MigratePlus_Importer::changes_for(
			array(
				'id'    => 1,
				'title' => 'Renamed again',
			),
			$job,
			$post->ID,
			'post'
		);
		$this->assertSame( array( 'title' ), array_keys( $changes ) );
		$job->delete();
	}

	public function test_updating_media_keeps_its_description_and_backslashes_in_alt_text() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$attachment = self::factory()->attachment->create_object(
			'pic.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Pic',
				'post_content'   => 'A description',
			)
		);
		update_post_meta( $attachment, '_migrateplus_source_uid', md5( 'json|http://media.test|9' ) );
		$file = $this->mapped_file(
			'http://media.test',
			array(
				'source_id'  => 'id',
				'post_type'  => 'post_type',
				'post_title' => 'title',
				'media_url'  => 'file',
				'media_alt'  => 'alt',
			),
			array(
				array(
					'id'        => 9,
					'post_type' => 'attachment',
					'title'     => 'New pic',
					'file'      => 'http://media.test/pic.jpg',
					'alt'       => 'C:\\pics\\a',
				),
			),
			array( 'post_type' => 'attachment' )
		);
		$job  = $this->import_file( $file, array( 'duplicate' => 'update' ) );
		wp_delete_file( $file );

		$this->assertSame( 1, $job->data['stats']['updated'] );
		$this->assertSame( 'New pic', get_post( $attachment )->post_title );
		$this->assertSame( 'A description', get_post( $attachment )->post_content );
		$this->assertSame( 'C:\\pics\\a', get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
		$job->delete();
	}

	public function test_source_ids_keep_their_backslashes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->mapped_file(
			'http://slashes.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
			),
			array(
				array(
					'id'    => 'DOMAIN\\42',
					'title' => 'Slashed',
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );

		$ids = $this->imported( 'DOMAIN\\42', 'post' );
		$this->assertCount( 1, $ids );
		$this->assertSame( 'DOMAIN\\42', get_post_meta( $ids[0], '_migrateplus_source_id', true ) );
		$job->delete();
	}

	/**
	 * A parent skipped because a page with its slug is already here never got a uid, so
	 * its child is linked to that page by the slug the record carries.
	 */
	public function test_child_links_to_a_parent_skipped_as_already_on_this_site() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$here = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Handbook',
				'post_name'  => 'handbook',
			)
		);
		$file = $this->mapped_file(
			'http://skip.test',
			array(
				'source_id'   => 'id',
				'post_type'   => 'post_type',
				'post_title'  => 'title',
				'post_name'   => 'slug',
				'post_parent' => 'parent.id',
			),
			array(
				array(
					'id'        => 1,
					'post_type' => 'page',
					'title'     => 'Handbook',
					'slug'      => 'handbook',
				),
				array(
					'id'        => 2,
					'post_type' => 'page',
					'title'     => 'Chapter',
					'slug'      => 'chapter',
					'parent'    => array(
						'id'   => 1,
						'slug' => 'handbook',
						'type' => 'page',
					),
				),
			),
			array( 'post_type' => 'page' )
		);
		$job  = $this->import_file( $file, array( 'skip_existing' => 1 ) );
		wp_delete_file( $file );

		$this->assertSame( 1, $job->data['stats']['skipped'] );
		$this->assertSame( 1, $job->data['stats']['created'] );
		$child = $this->imported( 2, 'page' )[0];
		$this->assertSame( $here, (int) get_post( $child )->post_parent );
		$this->assertSame( '', get_post_meta( $child, '_migrateplus_parent_uid', true ) );
		$job->delete();
	}

	/**
	 * Four posts imported before and one already here by slug, as a file of them again with new titles.
	 *
	 * @param string $title_prefix Put before each title.
	 * @return string Path.
	 */
	protected function rest_file( $title_prefix ) {
		$items = array();
		foreach ( array( 'one', 'two', 'three', 'four', 'here' ) as $i => $slug ) {
			$items[] = array(
				'id'    => $i + 1,
				'title' => $title_prefix . $slug,
				'slug'  => $slug,
			);
		}
		return $this->mapped_file(
			'http://rest.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
				'post_name'  => 'slug',
			),
			$items
		);
	}

	/**
	 * The duplicates screen lists only so many duplicates: it names the ones to update and the ones to
	 * skip, and duplicate_rest decides the rest, whether imported before or matched by slug.
	 */
	public function test_skip_ids_and_duplicate_rest_decide_the_duplicates_past_the_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$here  = self::factory()->post->create(
			array(
				'post_title' => 'Here',
				'post_name'  => 'here',
			)
		);
		$first = $this->rest_file( 'First ' );
		$this->import_file( $first, array( 'skip_existing' => 1 ) )->delete();
		wp_delete_file( $first );
		$ids    = array_map(
			function ( $source_id ) {
				return $this->imported( $source_id )[0];
			},
			array( 1, 2, 3, 4 )
		);
		$titles = static function ( array $posts ) {
			return array_map( 'get_the_title', $posts );
		};

		$again = $this->rest_file( 'Again ' );
		$job   = $this->import_file(
			$again,
			array(
				'skip_existing'  => 1,
				'skip_ids'       => array( $ids[0] ),
				'duplicate_rest' => 'update',
			)
		);
		wp_delete_file( $again );
		$this->assertSame( 4, $job->data['stats']['updated'] );
		$this->assertSame( 1, $job->data['stats']['skipped'] );
		$this->assertSame( array( 'First one', 'Again two', 'Again three', 'Again four', 'Again here' ), $titles( array_merge( $ids, array( $here ) ) ) );
		$job->delete();

		$third = $this->rest_file( 'Third ' );
		$job   = $this->import_file(
			$third,
			array(
				'skip_existing'  => 1,
				'update_ids'     => array( $ids[0] ),
				'skip_ids'       => array( $ids[1], $here ),
				'duplicate_rest' => 'copy',
			)
		);
		wp_delete_file( $third );
		$this->assertSame( 1, $job->data['stats']['updated'] );
		$this->assertSame( 2, $job->data['stats']['skipped'] );
		$this->assertSame( 2, $job->data['stats']['created'] );
		$this->assertSame( array( 'Third one', 'Again two', 'Again three', 'Again four', 'Again here' ), $titles( array_merge( $ids, array( $here ) ) ) );
		$this->assertCount( 1, get_posts( array( 'title' => 'Third three' ) ) );
		$this->assertCount( 1, get_posts( array( 'title' => 'Third four' ) ) );
		$job->delete();
	}

	/**
	 * The duplicates screen offers Update only on rows that differ, so "update the rest" treats
	 * the duplicates past its list the same way: a post the file would not change stays skipped,
	 * whether it was imported before or matched by slug.
	 */
	public function test_update_the_rest_skips_posts_the_file_would_not_change() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$here  = self::factory()->post->create(
			array(
				'post_title' => 'First here',
				'post_name'  => 'here',
			)
		);
		$first = $this->rest_file( 'First ' );
		$this->import_file( $first, array( 'skip_existing' => 1 ) )->delete();
		$two = $this->imported( 2 )[0];
		wp_update_post(
			array(
				'ID'         => $two,
				'post_title' => 'Edited two',
			)
		);

		$job = $this->import_file( $first, array( 'duplicate_rest' => 'update' ) );
		wp_delete_file( $first );
		$this->assertSame( 1, $job->data['stats']['updated'] );
		$this->assertSame( 4, $job->data['stats']['skipped'] );
		$this->assertSame( 'First two', get_the_title( $two ) );
		$this->assertSame( 'First here', get_the_title( $here ) );
		$log = implode( "\n", wp_list_pluck( $job->data['log'], 'm' ) );
		$this->assertStringContainsString( sprintf( 'Not updated: "First one" (#%d) is unchanged.', $this->imported( 1 )[0] ), $log );
		$this->assertStringContainsString( sprintf( 'Not updated: "First here" (#%d) is unchanged.', $here ), $log );
		$job->delete();
	}

	public function test_clean_options_keeps_only_known_duplicate_choices() {
		$clean = MigratePlus_Mappings::clean_options(
			array(
				'skip_ids'       => array( '7', 'x', 9 ),
				'duplicate_rest' => 'copy',
			)
		);
		$this->assertSame( array( 7, 9 ), $clean['skip_ids'] );
		$this->assertSame( 'copy', $clean['duplicate_rest'] );
		$this->assertSame( '', MigratePlus_Mappings::clean_options( array( 'duplicate_rest' => 'delete' ) )['duplicate_rest'] );
	}

	/**
	 * "Import as copies" puts each new child under the new copy of its parent, not the one an earlier import made.
	 */
	public function test_copies_link_to_the_parents_copied_with_them() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->mapped_file(
			'http://copies.test',
			array(
				'source_id'   => 'id',
				'post_type'   => 'post_type',
				'post_title'  => 'title',
				'post_parent' => 'parent.id',
			),
			array(
				array(
					'id'        => 1,
					'post_type' => 'page',
					'title'     => 'Parent',
				),
				array(
					'id'        => 2,
					'post_type' => 'page',
					'title'     => 'Child',
					'parent'    => array( 'id' => 1 ),
				),
			),
			array( 'post_type' => 'page' )
		);
		$this->import_file( $file )->delete();
		$job = $this->import_file( $file, array( 'duplicate' => 'create' ) );
		wp_delete_file( $file );

		$copies = array();
		foreach ( get_posts(
			array(
				'post_type'  => 'page',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		) as $post ) {
			$copies[ $post->post_title ] = $post;
		}
		$this->assertCount( 2, $copies );
		$this->assertSame( $copies['Parent']->ID, (int) $copies['Child']->post_parent );
		$job->delete();
	}

	public function test_titles_keep_less_than_signs_and_excerpts_keep_their_html() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->mapped_file(
			'http://html.test',
			array(
				'source_id'    => 'id',
				'post_title'   => 'title',
				'post_excerpt' => 'excerpt',
			),
			array(
				array(
					'id'      => 1,
					'title'   => 'I &lt;3 PHP and a<b <em>really</em>',
					'excerpt' => '<p>Short <strong>intro</strong></p><script>alert(1)</script>',
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );

		$post = get_post( $this->imported( 1, 'post' )[0] );
		$this->assertSame( 'I <3 PHP and a<b really', $post->post_title );
		$this->assertSame( '<p>Short <strong>intro</strong></p>alert(1)', $post->post_excerpt );
		$job->delete();
	}

	/**
	 * Fields WordPress, WooCommerce and Migrate Plus keep for themselves are not written from a
	 * file, each reported once per job; other underscore fields (SEO plugins) still are.
	 */
	public function test_protected_custom_fields_are_skipped_and_reported_once() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$items = array();
		foreach ( array( 1, 2 ) as $id ) {
			$items[] = array(
				'id'       => $id,
				'title'    => 'Item ' . $id,
				'template' => 'templates/missing.php',
				'meta'     => array(
					'_thumbnail_id'       => '999',
					'_migrateplus_job_id' => 'forged',
					'_yoast_wpseo_title'  => 'SEO title',
				),
			);
		}
		$meta = array();
		foreach ( array( '_thumbnail_id', '_migrateplus_job_id', '_yoast_wpseo_title' ) as $key ) {
			$meta[] = array(
				'key'    => $key,
				'source' => 'meta.' . $key,
			);
		}
		$file = $this->json_file(
			array(
				'migrateplus' => array(
					'site'      => 'http://protected.test',
					'post_type' => 'page',
					'mapping'   => array(
						'fields' => array(
							'source_id'     => 'id',
							'post_title'    => 'title',
							'page_template' => 'template',
							'bogus_target'  => 'id',
						),
						'meta'   => $meta,
					),
				),
				'items'       => $items,
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );

		$this->assertArrayNotHasKey( 'bogus_target', $job->data['mapping']['fields'] );
		foreach ( array( 1, 2 ) as $id ) {
			$post = $this->imported( $id, 'page' )[0];
			$this->assertSame( 'SEO title', get_post_meta( $post, '_yoast_wpseo_title', true ) );
			$this->assertSame( '', get_post_meta( $post, '_thumbnail_id', true ) );
			$this->assertSame( $job->data['id'], get_post_meta( $post, '_migrateplus_job_id', true ) );
			$this->assertSame( '', get_post_meta( $post, '_wp_page_template', true ) );
		}
		$this->assertSame(
			array(
				'Page template "templates/missing.php" is not in the active theme, so it was not set.',
				'Custom field "_thumbnail_id" is kept by WordPress, WooCommerce or Migrate Plus, so it was not imported.',
				'Custom field "_migrateplus_job_id" is kept by WordPress, WooCommerce or Migrate Plus, so it was not imported.',
			),
			array_values( wp_list_pluck( wp_list_filter( $job->data['log'], array( 'l' => 'warning' ) ), 'm' ) )
		);
		$job->delete();
	}

	/**
	 * Without the type's publish or edit-others capability, a new post waits for review under
	 * the user's own name; a post the user may not edit is not updated.
	 */
	public function test_imports_respect_the_users_capabilities() {
		$other  = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'other@site.test',
			)
		);
		$theirs = self::factory()->post->create(
			array(
				'post_title'  => 'Theirs',
				'post_author' => $other,
			)
		);
		update_post_meta( $theirs, '_migrateplus_source_uid', md5( 'json|http://caps.test|2' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );
		$file = $this->mapped_file(
			'http://caps.test',
			array(
				'source_id'    => 'id',
				'post_title'   => 'title',
				'post_content' => 'content',
				'post_status'  => 'status',
				'post_author'  => 'author',
			),
			array(
				array(
					'id'      => 1,
					'title'   => 'Mine',
					'content' => 'x',
					'status'  => 'publish',
					'author'  => 'other@site.test',
				),
				array(
					'id'      => 2,
					'title'   => 'Taken over',
					'content' => 'x',
				),
			)
		);
		$job  = $this->import_file(
			$file,
			array(
				'duplicate' => 'update',
				'author'    => $other, // Picked by a contributor, who may only write as themselves.
			)
		);
		wp_delete_file( $file );

		$mine = get_post( $this->imported( 1, 'post' )[0] );
		$this->assertSame( 'pending', $mine->post_status );
		$this->assertSame( $contributor, (int) $mine->post_author );
		$this->assertSame( 1, $job->data['stats']['created'] );
		$this->assertSame( 1, $job->data['stats']['failed'] );
		$this->assertSame( 'Theirs', get_the_title( $theirs ) );
		$this->assertSame(
			array(
				'Created "Mine" (#' . $mine->ID . '). Warnings: You are not allowed to publish, so it was saved as pending review. You are not allowed to give it another author, so you are its author.',
				'Not updated: you are not allowed to edit "Taken over" (#' . $theirs . ').',
			),
			array_values(
				wp_list_pluck(
					array_filter(
						$job->data['log'],
						static function ( $line ) {
							return in_array( $line['l'], array( 'warning', 'error' ), true );
						}
					),
					'm'
				)
			)
		);
		$job->delete();
	}

	public function test_imported_reviews_count_towards_the_products_rating() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file    = $this->mapped_file(
			'http://shop.test',
			array(
				'source_id'  => 'id',
				'post_type'  => 'post_type',
				'post_title' => 'title',
				'comments'   => 'comments',
			),
			array(
				array(
					'id'        => 1,
					'post_type' => 'product',
					'title'     => 'Mug',
					'comments'  => array(
						array(
							'id'           => 5,
							'author'       => 'Ann',
							'author_email' => 'ann@shop.test',
							'content'      => 'Great mug',
							'type'         => 'review',
							'approved'     => '1',
							'meta'         => array( 'rating' => '4' ),
						),
					),
				),
			),
			array( 'post_type' => 'product' )
		);
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		wp_delete_file( $file );
		$job                                     = $preview['job'];
		$job->data['options']['import_comments'] = 1;
		// Straight to the importer: run_batch() defers comment counting, which recounts after the batch and hides this.
		$result = MigratePlus_Importer::import( $preview['records'][0], $job );

		$product = wc_get_product( $result['post_id'] );
		$this->assertSame( array( 4 => 1 ), $product->get_rating_counts() );
		$this->assertSame( '4.00', $product->get_average_rating() );
		$this->assertSame( 1, $product->get_review_count() );
		$job->delete();
	}

	/**
	 * A small export for the batch tests: three posts.
	 *
	 * @return MigratePlus_Job Previewed, not started.
	 */
	protected function three_post_job() {
		$file    = $this->export_file( array( $this->item( 1 ), $this->item( 2 ), $this->item( 3 ) ) );
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		wp_delete_file( $file );
		$job                                = $preview['job'];
		$job->data['options']['batch_size'] = 3;
		$job->save();
		return $job;
	}

	public function test_batches_save_progress_after_each_record() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job   = $this->three_post_job();
		$saved = array();
		$spy   = static function ( $post_id, $record, $running ) use ( &$saved ) {
			$saved[] = MigratePlus_Job::load( $running->data['id'] )->data['stats']['processed'];
		};
		add_action( 'migrateplus_after_import_record', $spy, 10, 3 );
		MigratePlus_Runner::run_batch( $job );
		remove_action( 'migrateplus_after_import_record', $spy, 10 );

		$this->assertSame( array( 0, 1, 2 ), $saved );
		$this->assertSame( 0, MigratePlus_Job::load( $job->data['id'] )->data['cursor_skip'] );
		$job->delete();
	}

	public function test_a_batch_cut_short_resumes_after_its_last_saved_record() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job                             = $this->three_post_job();
		$job->data['cursor_skip']        = 2; // As saved by a request that died after its second record.
		$job->data['stats']['processed'] = 2;
		$job->save();

		$result = MigratePlus_Runner::run_batch( $job );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 3, $job->data['stats']['processed'] );
		$this->assertSame( array(), $this->imported( 1, 'post' ) );
		$this->assertCount( 1, $this->imported( 3, 'post' ) );
		$job->delete();
	}

	/**
	 * A batch that outlived its lock, taken over by a retry, must not save over the retry's progress.
	 */
	public function test_a_batch_that_lost_its_lock_stops_without_saving() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job      = $this->three_post_job();
		$name     = 'migrateplus_lock_' . $job->data['id'];
		$retry    = ( time() + 300 ) . ':retry';
		$takeover = static function () use ( $wpdb, $name, $retry ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $retry ), array( 'option_name' => $name ) );
		};
		add_action( 'migrateplus_after_import_record', $takeover );
		$result = MigratePlus_Runner::run_batch( $job );
		remove_action( 'migrateplus_after_import_record', $takeover );

		$this->assertWPError( $result );
		$this->assertSame( 'migrateplus_locked', $result->get_error_code() );
		$saved = MigratePlus_Job::load( $job->data['id'] );
		$this->assertSame( 0, $saved->data['stats']['processed'] );
		$this->assertSame( 0, $saved->data['cursor_skip'] );
		$this->assertSame( $retry, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		$job->delete();
	}

	public function test_run_batch_and_rollback_refuse_a_locked_job() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job  = $this->three_post_job();
		$lock = MigratePlus_Runner::lock( $job );

		$batch = MigratePlus_Runner::run_batch( $job );
		$this->assertWPError( $batch );
		$this->assertSame( 'migrateplus_locked', $batch->get_error_code() );
		$this->assertSame(
			array(
				'deleted'   => 0,
				'remaining' => 0,
				'done'      => true,
				'error'     => 'An import batch for this job is running. Try again when it finishes.',
			),
			MigratePlus_Runner::rollback_batch( $job )
		);
		$this->assertSame( array(), $this->imported( 1, 'post' ) );
		MigratePlus_Runner::unlock( $job, $lock );
		$job->delete();
	}

	public function test_a_lock_left_by_a_request_that_died_expires() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job  = $this->three_post_job();
		$name = 'migrateplus_lock_' . $job->data['id'];
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => ( time() - 1 ) . ':dead',
				'autoload'     => 'no',
			)
		);

		$result = MigratePlus_Runner::run_batch( $job );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 3, $job->data['stats']['created'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
		$job->delete();
	}

	/**
	 * Products imported while WooCommerce was on are still rolled back once it is off.
	 */
	public function test_rollback_deletes_posts_of_a_type_no_longer_registered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_post_type( 'pp_gone', array( 'show_ui' => true ) );
		$file = $this->mapped_file(
			'http://gone.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
			),
			array(
				array(
					'id'    => 1,
					'title' => 'Soon unregistered',
				),
			),
			array( 'post_type' => 'pp_gone' )
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$id = $this->imported( 1, 'pp_gone' )[0];
		unregister_post_type( 'pp_gone' );

		$this->assertSame( 1, $job->imported_count() );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$this->assertNull( get_post( $id ) );
		$job->delete();
	}

	/**
	 * @param array $doc More top-level keys for a Migrate Plus export with no items.
	 * @return string Path.
	 */
	protected function sides_file( array $doc ) {
		return $this->json_file(
			$doc + array(
				'migrateplus' => array(
					'site'    => 'http://old.test',
					'mapping' => array( 'fields' => array( 'source_id' => 'id' ) ),
				),
				'items'       => array(),
			)
		);
	}

	/**
	 * @param MigratePlus_Job $job Job, previewed.
	 * @return MigratePlus_Job The job, run to the end (at most 10 batches).
	 */
	protected function run_to_the_end( MigratePlus_Job $job ) {
		$batches = 0;
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			$this->assertIsArray( $result );
			if ( ++$batches > 10 ) {
				$this->fail( 'The import was still not done after 10 batches.' );
			}
		} while ( ! $result['done'] );
		return $job;
	}

	public function test_a_side_section_that_throws_is_tried_again_once_then_left_out() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file   = $this->sides_file( array( 'site_settings' => array( 'options' => array( 'blogname' => 'New name' ) ) ) );
		$throws = 1;
		$fault  = static function ( $value ) use ( &$throws ) {
			if ( $throws-- > 0 ) {
				throw new RuntimeException( 'Deadlock found.' );
			}
			return $value;
		};
		add_filter( 'pre_update_option_blogname', $fault );
		$once   = $this->run_to_the_end( MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'] );
		$throws = 99;
		$always = $this->run_to_the_end( MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'] );
		remove_filter( 'pre_update_option_blogname', $fault );
		wp_delete_file( $file );

		$this->assertSame( 'New name', get_option( 'blogname' ) );
		$this->assertSame( 1, $once->data['stats']['failed'] );
		$this->assertContains( 'Site settings stopped: Deadlock found. The next batch tries again from where it stopped.', wp_list_pluck( $once->data['log'], 'm' ) );
		$this->assertSame( 'complete', $always->data['status'] );
		$this->assertSame( 2, $always->data['stats']['failed'] );
		$this->assertContains( 'Site settings stopped at the same place again, so the rest of them were left out: Deadlock found.', wp_list_pluck( $always->data['log'], 'm' ) );
		$once->delete();
		$always->delete();
	}

	public function test_an_import_paused_on_the_old_users_section_finishes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->sides_file( array( 'site_settings' => array( 'options' => array( 'blogname' => 'Resumed' ) ) ) );
		$job  = MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'];
		// What an older version left in a job it was importing users for.
		$job->data['status']    = 'running';
		$job->data['stage']     = 'users';
		$job->data['stage_pos'] = 3;
		$job->save();
		$job = $this->run_to_the_end( $job );
		wp_delete_file( $file );

		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertSame( 'Resumed', get_option( 'blogname' ) );
		$this->assertContains( 'This import was paused on a section this version no longer imports. It continues with the next one.', wp_list_pluck( $job->data['log'], 'm' ) );
		$job->delete();
	}

	/**
	 * ACF definitions register the taxonomies and term field groups that terms need.
	 */
	public function test_acf_definitions_import_before_terms() {
		$file = $this->sides_file(
			array(
				'terms'           => array(
					array(
						'taxonomy' => 'category',
						'name'     => 'News',
					),
				),
				'acf_definitions' => array( array( 'key' => 'group_1' ) ),
			)
		);
		$job  = MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'];
		wp_delete_file( $file );

		$this->assertSame( array( 'acf_definitions', 'terms' ), array_column( $job->data['sections'], 'type' ) );
		$order = array();
		for ( $stage = MigratePlus_Runner::next_stage( $job ); 'records' !== $stage; $stage = MigratePlus_Runner::next_stage( $job, $stage ) ) {
			$order[] = $stage;
		}
		$this->assertSame( array( 'acf_definitions', 'terms' ), $order );
		$job->delete();
	}

	/**
	 * Site settings can make a term the import created the default category, which WordPress
	 * refuses to delete: rollback puts the setting back first.
	 */
	public function test_rollback_deletes_a_term_the_import_made_the_default_category() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$before = (int) get_option( 'default_category' );
		$file   = $this->sides_file( array( 'site_settings' => array( 'options' => array( 'default_category' => 'Imported news' ) ) ) );
		$job    = $this->run_to_the_end( MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )['job'] );
		wp_delete_file( $file );
		$term = get_term_by( 'name', 'Imported news', 'category' );
		$this->assertSame( $term->term_id, (int) get_option( 'default_category' ) );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );

		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$this->assertFalse( get_term_by( 'name', 'Imported news', 'category' ) );
		$this->assertSame( $before, (int) get_option( 'default_category' ) );
		$job->delete();
	}

	/**
	 * Terms of an ACF taxonomy whose definition went first are still rolled back.
	 */
	public function test_rollback_deletes_terms_of_a_taxonomy_no_longer_registered() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		register_taxonomy( 'mp_gone', 'post', array( 'show_ui' => true ) );
		$file = $this->json_file(
			array(
				'migrateplus' => array(
					'site'    => 'http://gone.test',
					'mapping' => array(
						'fields'     => array(
							'source_id'  => 'id',
							'post_title' => 'title',
						),
						'taxonomies' => array(
							array(
								'taxonomy' => 'mp_gone',
								'source'   => 'terms.mp_gone',
							),
						),
					),
				),
				'items'       => array(
					array(
						'id'    => 1,
						'title' => 'Tagged',
						'terms' => array( 'mp_gone' => array( 'Old genre' ) ),
					),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		unregister_taxonomy( 'mp_gone' );

		$this->assertSame( 2, $job->imported_count() );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'rolled_back', $job->data['status'] );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'mp_gone'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertFalse( taxonomy_exists( 'mp_gone' ) );
		$job->delete();
	}

	/**
	 * A request that dies after saving a post but before its terms, fields and images
	 * leaves it half done: the resumed batch finishes it instead of skipping it.
	 */
	public function test_a_post_left_half_imported_is_finished_on_resume() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->category->create( array( 'name' => 'News' ) );
		$file    = $this->mapped_file(
			'http://crash.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
				'categories' => 'categories',
			),
			array(
				array(
					'id'         => 1,
					'title'      => 'Half done',
					'categories' => array( 'News' ),
				),
			)
		);
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ) );
		wp_delete_file( $file );
		$job = $preview['job'];
		$this->assertSame( 'skip', $job->data['options']['duplicate'] );
		$die = static function ( $meta_id, $post_id, $key ) {
			if ( '_migrateplus_source_id' === $key ) {
				throw new RuntimeException( 'Maximum execution time exceeded' );
			}
		};
		add_action( 'added_post_meta', $die, 10, 3 );
		try {
			MigratePlus_Importer::import( $preview['records'][0], $job );
			$this->fail( 'The import did not stop.' );
		} catch ( RuntimeException $e ) {
			unset( $e ); // The request died here: nothing of the batch was saved.
		}
		remove_action( 'added_post_meta', $die, 10 );
		$half = $this->imported( 1, 'post' );
		$this->assertCount( 1, $half );
		$this->assertFalse( has_category( 'News', $half[0] ) );

		$this->run_to_the_end( $job );

		$this->assertSame( $half, $this->imported( 1, 'post' ) );
		$this->assertSame( array( 'News' ), wp_list_pluck( get_the_category( $half[0] ), 'name' ) );
		$this->assertSame( '', get_post_meta( $half[0], MigratePlus_Importer::INCOMPLETE_META, true ) );
		$this->assertSame( 1, $job->data['stats']['created'] );
		$this->assertSame( 1, $job->data['created_total'] );
		$job->delete();
	}

	public function test_a_failed_preview_deletes_the_caches_it_wrote() {
		$before = glob( MigratePlus_Util::ensure_storage_dir() . 'json-*' );
		$file   = $this->sides_file( array() );
		$this->assertSame( 'The source returned no records.', MigratePlus_Runner::preview( 'json', array( 'file' => $file ) )->get_error_message() );
		wp_delete_file( $file );
		$this->assertSame( $before, glob( MigratePlus_Util::storage_dir() . 'json-*' ) );
	}

	/**
	 * Side sections are built before the file is opened, so one that dies leaves no file behind.
	 */
	public function test_an_export_that_dies_building_a_side_section_leaves_no_file() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$state = MigratePlus_Exporter::start(
			MigratePlus_Exporter::sanitize_args(
				array(
					'post_types'            => array(),
					'include_site_settings' => 1,
				)
			)
		);
		$die   = static function () {
			throw new RuntimeException( 'Allowed memory size exhausted' );
		};
		add_filter( 'option_blogname', $die );
		try {
			MigratePlus_Exporter::run_batch( $state, 10 );
			$this->fail( 'The export did not stop.' );
		} catch ( RuntimeException $e ) {
			unset( $e ); // The request died here.
		}
		remove_filter( 'option_blogname', $die );
		$this->assertSame( array(), glob( MigratePlus_Util::storage_dir() . 'export-' . $state['id'] . '-*-migrateplus-*' ) );
		MigratePlus_Exporter::forget( $state['id'] );
	}

	public function test_a_side_section_holding_a_value_json_cannot_write_is_refused() {
		$before = glob( MigratePlus_Util::ensure_storage_dir() . 'json-*' );
		$file   = wp_tempnam( 'migrateplus-test.json' );
		// 1e400 decodes to INF, which JSON can't hold: the menus could not be cached.
		file_put_contents( $file, '{"migrateplus":{"site":"http://old.test","mapping":{"fields":{"source_id":"id"}}},"items":[{"id":1}],"menus":[{"name":"Main","width":1e400}]}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = ( new MigratePlus_Source_JSON( array( 'file' => $file ) ) )->prepare();
		wp_delete_file( $file );
		$this->assertWPError( $out );
		$this->assertSame( 'migrateplus_json', $out->get_error_code() );
		$this->assertSame( $before, glob( MigratePlus_Util::storage_dir() . 'json-*' ) );
	}

	/**
	 * "C", "C#" and "C++" share the slug "c": each name gets a term of its own.
	 */
	public function test_terms_whose_names_share_a_slug_stay_apart() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = $this->mapped_file(
			'http://langs.test',
			array(
				'source_id'  => 'id',
				'post_title' => 'title',
				'tags'       => 'tags',
			),
			array(
				array(
					'id'    => 1,
					'title' => 'Languages',
					'tags'  => array( 'C', 'C#', 'C++' ),
				),
			)
		);
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$names = wp_list_pluck( wp_get_post_tags( $this->imported( 1, 'post' )[0] ), 'name' );
		sort( $names );
		$this->assertSame( array( 'C', 'C#', 'C++' ), $names );
		$job->delete();
	}
}
