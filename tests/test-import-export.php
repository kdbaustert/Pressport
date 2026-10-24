<?php
/**
 * Import link rewriting, export meta exclusion, and field detection for PressPort exports.
 *
 * @package PressPort
 */
class Test_Import_Export extends WP_UnitTestCase {

	public function test_rewrite_links_only_touches_links_to_the_old_site() {
		$html = '<a href="http://old.test/page/">a</a> <a href="https://old.test">b</a> '
			. '<a href="http://old.test.evil.com/x">c</a> <a href="http://old.test/wp-content/uploads/f.pdf">d</a> '
			. '<img src="http://old.test/wp-content/uploads/x.jpg">';
		$out  = PressPort_Importer::rewrite_links( $html, 'http://old.test' );
		$home = untrailingslashit( home_url() );

		$this->assertStringContainsString( 'href="' . $home . '/page/"', $out );
		$this->assertStringContainsString( 'href="' . $home . '"', $out );
		$this->assertStringContainsString( 'href="http://old.test.evil.com/x"', $out );
		$this->assertStringContainsString( 'href="http://old.test/wp-content/uploads/f.pdf"', $out );
		$this->assertStringContainsString( 'src="http://old.test/wp-content/uploads/x.jpg"', $out );
	}

	public function test_rewrite_links_does_nothing_without_a_source_site() {
		$html = '<a href="http://old.test/">a</a>';
		$this->assertSame( $html, PressPort_Importer::rewrite_links( $html, '' ) );
	}

	public function test_meta_patterns_parse_lines_commas_and_prefixes() {
		$this->assertSame( array( 'rank_math_*', 'foo', 'x' ), PressPort_Exporter::meta_patterns( " rank_math_* , foo\r\n\r\n<b>x</b>\nfoo" ) );
	}

	public function test_export_leaves_out_excluded_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'rank_math_title', 'seo' );
		update_post_meta( $post_id, 'keepme', 'yes' );

		$args = PressPort_Exporter::sanitize_args( array( 'exclude_meta' => 'rank_math_*' ) );
		$item = PressPort_Exporter::export_post( get_post( $post_id ), $args );

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
		$file = wp_tempnam( 'pressport-test.json' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$file,
			wp_json_encode(
				array(
					'pressport' => array(
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
					'items'     => $items,
				)
			)
		);

		$preview = PressPort_Runner::preview( 'json', array( 'file' => $file ), 1 );
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
		$file = wp_tempnam( 'pressport-test.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	/**
	 * Preview and run a JSON import to the end.
	 *
	 * @param string $file Path.
	 * @return PressPort_Job
	 */
	protected function import_file( $file ) {
		$preview = PressPort_Runner::preview( 'json', array( 'file' => $file ), 5 );
		$this->assertIsArray( $preview );
		$job = $preview['job'];
		do {
			$result = PressPort_Runner::run_batch( $job );
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
				'meta_key'    => '_pressport_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
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

		$args  = PressPort_Exporter::sanitize_args(
			array(
				'post_type'     => 'page',
				'include_meta'  => 1,
				'include_terms' => 0,
				'include_image' => 0,
				'include_acf'   => 0,
			)
		);
		$items = array( PressPort_Exporter::export_post( get_post( $child ), $args ), PressPort_Exporter::export_post( get_post( $parent ), $args ) );
		$this->assertSame( array( 'red', 'blue' ), $items[0]['meta']['colors'] );
		$this->assertSame( array( '_pressport_rows' => array( 'a', 'b' ) ), $items[0]['meta']['tag'] );
		$state = array(
			'args'      => $args,
			'taxes'     => array(),
			'meta_keys' => array_keys( $items[0]['meta'] ),
			'acf_names' => array(),
		);
		$file  = $this->json_file(
			array(
				'pressport' => array(
					'site'      => 'http://old.test',
					'post_type' => 'page',
					'mapping'   => PressPort_Exporter::mapping_for( $state ),
				),
				'items'     => $items,
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
		$this->assertSame( '', get_post_meta( $post->ID, '_pressport_parent_uid', true ) );
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
				'pressport' => array(
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
				'items'     => array(
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

	public function test_author_cache_retries_a_miss_when_creating() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 0, PressPort_Importer::resolve_author( 'Cache Miss Person', false ) );
		$this->assertGreaterThan( 0, PressPort_Importer::resolve_author( 'Cache Miss Person', true ) );
	}

	public function test_authors_are_only_created_by_users_who_may() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 0, PressPort_Importer::resolve_author( 'Editor Made Person', true ) );
	}

	public function test_importable_post_types_need_edit_access_and_content_types() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( PressPort_Importer::importable_post_type( 'post' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( PressPort_Importer::importable_post_type( 'post' ) );
		$this->assertFalse( PressPort_Importer::importable_post_type( 'wp_template' ) );
		$this->assertFalse( PressPort_Importer::importable_post_type( 'nav_menu_item' ) );
	}

	public function test_missing_terms_are_reported() {
		$warnings = array();
		$this->assertSame( array(), PressPort_Importer::resolve_terms( 'category', array( 'Nowhere' ), false, $warnings ) );
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
			PressPort_ACF::resolve_user(
				array(
					'email' => 'designer@target.test',
					'login' => 'someone-else',
				)
			)
		);
		$this->assertSame(
			$id,
			PressPort_ACF::resolve_user(
				array(
					'email' => 'designer@source.test',
					'login' => 'designer',
				)
			)
		);
		$this->assertSame( $id, PressPort_ACF::resolve_user( 'designer@target.test' ) ); // Older exports.
		$this->assertSame(
			0,
			PressPort_ACF::resolve_user(
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
		$args  = PressPort_Exporter::sanitize_args(
			array(
				'post_type' => 'post',
				'statuses'  => 'publish',
			)
		);
		$state = PressPort_Exporter::start( $args );
		$this->assertIsArray( $state );
		PressPort_Exporter::run_batch( $state, 1 );
		wp_delete_post( $ids[0], true );
		while ( empty( $state['complete'] ) ) {
			PressPort_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		PressPort_Exporter::forget( $state['id'] );

		$this->assertSame( $ids, array_column( $doc['items'], 'id' ) );
	}

	/**
	 * @param string $site Site the records come from.
	 * @return PressPort_Job
	 */
	protected function job_from( $site ) {
		return new PressPort_Job(
			array(
				'source'   => 'json',
				'settings' => array( 'pressport_site' => $site ),
			)
		);
	}

	public function test_uid_is_scoped_to_the_source() {
		$a = $this->job_from( 'http://a.test' );
		$this->assertSame( '', PressPort_Importer::uid( $a, '' ) );
		$this->assertSame( PressPort_Importer::uid( $a, 42 ), PressPort_Importer::uid( $this->job_from( 'http://a.test' ), '42' ) );
		$this->assertNotSame( PressPort_Importer::uid( $a, 42 ), PressPort_Importer::uid( $this->job_from( 'http://b.test' ), 42 ) );
	}

	public function test_slug_from_uses_the_last_path_segment() {
		$this->assertSame( 'my-post', PressPort_Importer::slug_from( '/blog/my-post/' ) );
		$this->assertSame( 'my-post', PressPort_Importer::slug_from( 'https://old.test/blog/my-post?x=1' ) );
		$this->assertSame( 'hello-world', PressPort_Importer::slug_from( 'Hello World' ) );
		$this->assertSame( '', PressPort_Importer::slug_from( '/' ) );
	}

	/**
	 * WP-CLI passes the file path; the admin keeps the sanitized upload name. Both must give the same key.
	 */
	public function test_cli_and_admin_file_keys_match() {
		$cli   = new PressPort_Job( array( 'settings' => array( 'file' => '/tmp/My Export (1).json' ) ) );
		$admin = new PressPort_Job( array( 'settings' => array( 'file_name' => sanitize_file_name( 'My Export (1).json' ) ) ) );
		$this->assertSame( PressPort_Importer::source_key( $admin ), PressPort_Importer::source_key( $cli ) );
	}

	public function test_absolutize_only_returns_http_urls() {
		$this->assertSame( 'http://old.test/a.jpg', PressPort_Media::absolutize( '/a.jpg', 'http://old.test' ) );
		$this->assertSame( 'http://old.test/dir/a.jpg', PressPort_Media::absolutize( 'a.jpg', 'http://old.test/dir/' ) );
		$this->assertSame( '', PressPort_Media::absolutize( '/a.jpg', 'javascript:alert(1)//' ) );
		$this->assertSame( '', PressPort_Media::absolutize( 'javascript:alert(1)', 'http://old.test' ) );
		$this->assertSame( '', PressPort_Media::absolutize( 'data:image/png;base64,AAAA', 'http://old.test' ) );
	}

	public function test_json_source_keeps_only_an_http_site() {
		foreach ( array(
			'javascript:alert(1)' => '',
			'http://old.test'     => 'http://old.test',
		) as $site => $expected ) {
			$file   = $this->json_file(
				array(
					'pressport' => array(
						'site'    => $site,
						'mapping' => array(),
					),
					'items'     => array( array( 'id' => 1 ) ),
				)
			);
			$source = new PressPort_Source_JSON( array( 'file' => $file ) );
			$out    = $source->prepare();
			wp_delete_file( $file );
			$this->assertIsArray( $out );
			wp_delete_file( $out['cache_file'] );
			$this->assertSame( $expected, $out['pressport_site'] );
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
				'pressport' => array(
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
				'items'     => array(
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
	 * The changes shown before an update are what the import would write, pressport_post_data included.
	 */
	public function test_changes_for_applies_the_post_data_filter() {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Same',
				'post_name'  => 'old-slug',
			)
		);
		$job     = new PressPort_Job(
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
		add_filter( 'pressport_post_data', $filter );
		$changes = PressPort_Importer::changes_for(
			array(
				'title' => 'Same',
				'slug'  => '/blog/new-slug/',
			),
			$job,
			$post_id,
			'post'
		);
		remove_filter( 'pressport_post_data', $filter );

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
		update_post_meta( $from_a, '_pressport_source_uid', PressPort_Importer::uid( $a, 42 ) );
		update_post_meta( $from_a, '_pressport_source_id', '42' );

		$this->assertSame( $from_a, PressPort_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $a ) );
		$this->assertSame( 0, PressPort_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $b ) );
		// A plain id is not a PressPort reference: any earlier import of it still counts.
		$this->assertSame( $from_a, PressPort_ACF::resolve_post( '42', array( 'post' ), $b ) );

		$from_b = self::factory()->post->create();
		update_post_meta( $from_b, '_pressport_source_uid', PressPort_Importer::uid( $b, 42 ) );
		update_post_meta( $from_b, '_pressport_source_id', '42' );
		$this->assertSame( $from_b, PressPort_ACF::resolve_post( '42', array( 'post' ), $b ) );
		$this->assertSame( $from_b, PressPort_ACF::resolve_post( array( 'id' => 42 ), array( 'post' ), $b ) );
	}
}
