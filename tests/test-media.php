<?php
/**
 * Media sideloading, offline: pre_http_request answers every download.
 *
 * @package MigratePlus
 */
class Test_Media extends WP_UnitTestCase {

	/**
	 * Requests made, as url => args.
	 *
	 * @var array[]
	 */
	protected $requests = array();

	/**
	 * What the fake server answers: body, content type, status.
	 *
	 * @var array
	 */
	protected $serve;

	public function set_up() {
		parent::set_up();
		$this->serve = array( $this->png(), 'image/png', 200 );
		add_filter( 'pre_http_request', array( $this, 'fake_download' ), 10, 3 );
	}

	public function tear_down() {
		// The uploads folder outlives the DB rollback.
		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		delete_option( MigratePlus_Settings::OPTION );
		MigratePlus_Settings::flush_cache();
		parent::tear_down();
	}

	/**
	 * A short-circuited request never writes the temp file download_url() streams to, so this does.
	 */
	public function fake_download( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$this->requests[ $url ]     = $args;
		list( $body, $type, $code ) = $this->serve;
		if ( ! empty( $args['stream'] ) ) {
			file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return array(
			'headers'  => array( 'content-type' => $type ),
			'body'     => empty( $args['stream'] ) ? $body : '',
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
		);
	}

	protected function png() {
		$image = imagecreatetruecolor( 4, 4 );
		ob_start();
		imagepng( $image );
		return ob_get_clean();
	}

	public function test_sideload_downloads_each_url_once() {
		$id = MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0, 'A blue mug', '', 'abc123' );
		$this->assertIsInt( $id );
		$this->assertSame( 'http://old.test/uploads/mug.png', get_post_meta( $id, '_migrateplus_source_url', true ) );
		$this->assertSame( 'abc123', get_post_meta( $id, '_migrateplus_job_id', true ) );
		$this->assertSame( 'A blue mug', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( 60, $this->requests['http://old.test/uploads/mug.png']['timeout'], 'The 30 second default is raised to 60.' );

		$this->assertSame( $id, MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0 ) );
		$this->assertCount( 1, $this->requests );

		$copy = MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0, '', '', '', false );
		$this->assertNotSame( $id, $copy );
	}

	public function test_sideload_resolves_relative_urls_and_names_files_by_type() {
		$id = MigratePlus_Media::sideload( '/media/cover', 0, '', 'http://old.test/blog' );
		$this->assertArrayHasKey( 'http://old.test/media/cover', $this->requests );
		$this->assertSame( 'cover.png', wp_basename( get_attached_file( $id ) ) );
	}

	public function test_sideload_refuses_urls_it_should_not_fetch() {
		$this->assertSame( 'migrateplus_media_url', MigratePlus_Media::sideload( 'javascript:alert(1)', 0, '', 'http://old.test' )->get_error_code() );
		$this->assertSame( 'migrateplus_media_url', MigratePlus_Media::sideload( 'mug.png', 0 )->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_max_media_size() {
		update_option( MigratePlus_Settings::OPTION, array( 'max_media_size' => 1 ) );
		MigratePlus_Settings::flush_cache();
		$this->serve[0] = $this->png() . str_repeat( "\0", MB_IN_BYTES );

		$error = MigratePlus_Media::sideload( 'http://old.test/uploads/poster.png', 0 );
		$this->assertSame( 'Skipped: larger than the 1 MB limit in Settings.', $error->get_error_message() );
		$this->assertSame( MB_IN_BYTES + 1, $this->requests['http://old.test/uploads/poster.png']['limit_response_size'] );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'attachment' ) ) );

		$this->serve[0] = $this->png();
		$this->assertIsInt( MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0 ) );
	}

	public function test_sideload_refuses_what_is_not_an_image() {
		$this->serve = array( '<html><body>Hello</body></html>', 'text/html', 200 );
		$this->assertWPError( MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0 ) );

		$this->serve = array( 'Not Found', 'text/plain', 404 );
		$this->assertWPError( MigratePlus_Media::sideload( 'http://old.test/uploads/gone.png', 0 ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'attachment' ) ) );
	}

	public function test_content_images_point_at_the_local_copy() {
		$html   = '<!-- wp:image {"id":12} --><figure><img src="http://old.test/uploads/mug.png" alt="Mug" class="wp-image-12" srcset="http://old.test/uploads/mug-300.png 300w" sizes="100vw"></figure><!-- /wp:image -->'
			. '<img src="' . home_url( '/wp-content/uploads/logo.png' ) . '">'
			. '<img src="http://old.test/uploads/broken.png">';
		$errors = array();
		$post   = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return false !== strpos( $url, 'broken' ) ? new WP_Error( 'http_request_failed', 'Connection refused' ) : $pre;
			},
			9,
			3
		);

		$out = MigratePlus_Media::import_content_images( $html, $post, 'http://old.test', 'abc123', $errors );
		$id  = MigratePlus_Media::find_existing( 'http://old.test/uploads/mug.png' );

		$this->assertStringContainsString( 'src="' . wp_get_attachment_url( $id ) . '"', $out );
		$this->assertStringContainsString( 'class="wp-image-' . $id . '"', $out );
		$this->assertStringContainsString( '<!-- wp:image {"id":' . $id . '} -->', $out );
		$this->assertStringNotContainsString( 'srcset', $out );
		$this->assertStringContainsString( home_url( '/wp-content/uploads/logo.png' ), $out );
		$this->assertStringContainsString( 'src="http://old.test/uploads/broken.png"', $out );
		$this->assertSame( array( 'http://old.test/uploads/broken.png: Connection refused' ), $errors );
		$this->assertSame( $post, wp_get_post_parent_id( $id ) );
	}

	public function test_content_images_reuse_the_original_of_a_size_variant() {
		$id   = MigratePlus_Media::sideload( 'http://old.test/uploads/photo.png', 0 );
		$full = wp_get_attachment_url( $id );
		$html = '<!-- wp:image {"id":12,"linkDestination":"media"} --><figure><a href="http://old.test/uploads/photo.png"><img src="http://old.test/uploads/photo-1024x683.png" class="wp-image-12"></a></figure><!-- /wp:image -->'
			. '<img src="http://old.test/uploads/photo-scaled.png">';

		$out = MigratePlus_Media::import_content_images( $html, 0, 'http://old.test', '' );

		$this->assertSame(
			'<!-- wp:image {"id":' . $id . ',"linkDestination":"media"} --><figure><a href="' . $full . '"><img src="' . $full . '" class="wp-image-' . $id . '"></a></figure><!-- /wp:image -->'
				. '<img src="' . $full . '">',
			$out
		);
		$this->assertSame( array( 'http://old.test/uploads/photo.png' ), array_keys( $this->requests ) );
	}

	public function test_content_images_find_the_old_attachment_by_its_id_and_fix_cover_and_media_text_blocks() {
		$job = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$id  = MigratePlus_Media::sideload( 'http://old.test/uploads/hero.png', 0 );
		update_post_meta( $id, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, 12 ) );
		$url  = wp_get_attachment_url( $id );
		$html = '<!-- wp:cover {"url":"http://old.test/uploads/hero-edited.png","id":12,"dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-12" src="http://old.test/uploads/hero-edited.png"/></div><!-- /wp:cover -->'
			. '<!-- wp:media-text {"mediaId":12,"mediaLink":"http://old.test/?attachment_id=12","mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="http://old.test/uploads/hero-edited.png" class="wp-image-12 size-full"/></figure></div><!-- /wp:media-text -->';

		$out = MigratePlus_Media::import_content_images( $html, 0, 'http://old.test', $job->data['id'] );
		$job->delete();

		$this->assertSame(
			'<!-- wp:cover {"url":"' . $url . '","id":' . $id . ',"dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-' . $id . '" src="' . $url . '"/></div><!-- /wp:cover -->'
				. '<!-- wp:media-text {"mediaId":' . $id . ',"mediaLink":"' . get_attachment_link( $id ) . '","mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="' . $url . '" class="wp-image-' . $id . ' size-full"/></figure></div><!-- /wp:media-text -->',
			$out
		);
		$this->assertCount( 1, $this->requests );
	}

	public function test_block_ids_content_images_mapped_are_not_mapped_again_when_blocks_relink() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job  = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$hero = MigratePlus_Media::sideload( 'http://old.test/uploads/hero.png', 0 );
		update_post_meta( $hero, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, '12' ) );
		// The old site's attachment numbered like the hero's copy here: mapping that number again would land on it.
		$decoy = MigratePlus_Media::sideload( 'http://old.test/uploads/decoy.png', 0 );
		update_post_meta( $decoy, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, (string) $hero ) );
		$post = self::factory()->post->create();
		$html = '<!-- wp:image {"id":12} --><figure class="wp-block-image"><img src="http://old.test/uploads/hero.png" class="wp-image-12"/></figure><!-- /wp:image -->'
			. '<!-- wp:block {"ref":99} /-->';

		$out = MigratePlus_Media::import_content_images( $html, $post, 'http://old.test', $job->data['id'] );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post,
					'post_content' => $out,
				)
			)
		);
		MigratePlus_Blocks::relink( $post, $job );
		$image = '<!-- wp:image {"id":' . $hero . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $hero ) . '" class="wp-image-' . $hero . '"/></figure><!-- /wp:image -->';
		$this->assertSame( $image . '<!-- wp:block {"ref":99} /-->', get_post( $post )->post_content );

		// The pattern arrives: the post is relinked from what the import saved, and the image keeps its id.
		$pattern = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		update_post_meta( $pattern, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, '99' ) );
		MigratePlus_Blocks::link_pending( $pattern, '99', $job );
		$this->assertSame( $image . '<!-- wp:block {"ref":' . $pattern . '} /-->', get_post( $post )->post_content );
		$this->assertSame( array(), get_post_meta( $post, MigratePlus_Blocks::PENDING_META, false ) );
		$this->assertSame( '', get_post_meta( $post, MigratePlus_Blocks::SOURCE_META, true ) );
		$job->delete();
	}

	public function test_content_images_come_only_from_the_old_site_and_uploads_folders() {
		$html = '<img src="http://old.test/uploads/mug.png">'
			. '<img src="http://cdn.test/wp-content/uploads/2024/05/cup.png">'
			. '<img src="http://tracker.test/pixel.png">';

		$out = MigratePlus_Media::import_content_images( $html, 0, 'http://old.test', '' );
		$this->assertSame( array( 'http://old.test/uploads/mug.png', 'http://cdn.test/wp-content/uploads/2024/05/cup.png' ), array_keys( $this->requests ) );
		$this->assertStringContainsString( '<img src="http://tracker.test/pixel.png">', $out );

		add_filter(
			'migrateplus_content_image_hosts',
			static function ( $hosts ) {
				$hosts[] = 'tracker.test';
				return $hosts;
			}
		);
		MigratePlus_Media::import_content_images( $html, 0, 'http://old.test', '' );
		$this->assertArrayHasKey( 'http://tracker.test/pixel.png', $this->requests );
	}

	public function test_cover_backgrounds_videos_and_files_point_at_the_local_copies() {
		$html = '<!-- wp:cover {"url":"http://old.test/uploads/bg.png","id":21,"hasParallax":true} --><div class="wp-block-cover has-parallax" style="background-image:url(http://old.test/uploads/bg.png)"></div><!-- /wp:cover -->'
			. '<!-- wp:video {"id":22} --><figure class="wp-block-video"><video controls src="http://old.test/uploads/clip.png"></video></figure><!-- /wp:video -->'
			. '<!-- wp:file {"id":23,"href":"http://old.test/uploads/doc.png"} --><div class="wp-block-file"><a href="http://old.test/uploads/doc.png">doc</a></div><!-- /wp:file -->';

		$out  = MigratePlus_Media::import_content_images( $html, 0, 'http://old.test', '' );
		$bg   = MigratePlus_Media::find_existing( 'http://old.test/uploads/bg.png' );
		$clip = MigratePlus_Media::find_existing( 'http://old.test/uploads/clip.png' );
		$doc  = MigratePlus_Media::find_existing( 'http://old.test/uploads/doc.png' );
		$this->assertSame(
			'<!-- wp:cover {"url":"' . wp_get_attachment_url( $bg ) . '","id":' . $bg . ',"hasParallax":true} --><div class="wp-block-cover has-parallax" style="background-image:url(' . wp_get_attachment_url( $bg ) . ')"></div><!-- /wp:cover -->'
				. '<!-- wp:video {"id":' . $clip . '} --><figure class="wp-block-video"><video controls src="' . wp_get_attachment_url( $clip ) . '"></video></figure><!-- /wp:video -->'
				. '<!-- wp:file {"id":' . $doc . ',"href":"' . wp_get_attachment_url( $doc ) . '"} --><div class="wp-block-file"><a href="' . wp_get_attachment_url( $doc ) . '">doc</a></div><!-- /wp:file -->',
			$out
		);
	}

	public function test_a_crash_while_resizing_leaves_an_attachment_rollback_can_find() {
		add_filter(
			'wp_generate_attachment_metadata',
			static function () {
				throw new RuntimeException( 'Out of memory' );
			}
		);
		try {
			MigratePlus_Media::sideload( 'http://old.test/uploads/huge.png', 0, '', '', 'abc123' );
			$this->fail( 'The resize did not throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Out of memory', $e->getMessage() );
		}
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'meta_key'    => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => 'abc123', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	public function test_replacing_from_a_url_without_an_extension_names_the_file_by_its_type() {
		$id = MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0 );
		$this->assertTrue( MigratePlus_Media::replace_file( $id, 'http://old.test/download?id=5' ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
	}

	public function test_replacing_a_scaled_image_keeps_its_scaled_url() {
		$big = imagecreatetruecolor( 2600, 20 );
		ob_start();
		imagepng( $big );
		$this->serve[0] = ob_get_clean();
		$id             = MigratePlus_Media::sideload( 'http://old.test/uploads/panorama.png', 0 );
		$url            = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/panorama-scaled.png', $url );

		$this->serve[0] = $this->png();
		$this->assertTrue( MigratePlus_Media::replace_file( $id, 'http://old.test/uploads/panorama-small.png' ) );

		$file = get_attached_file( $id );
		$this->assertSame( $url, wp_get_attachment_url( $id ) );
		$this->assertSame( array( 4, 4 ), array_slice( getimagesize( $file ), 0, 2 ) );
		$this->assertSame( 'panorama.png', wp_get_attachment_metadata( $id )['original_image'] );
		$original = wp_get_original_image_path( $id );
		wp_delete_attachment( $id, true );
		$this->assertFileDoesNotExist( $file );
		$this->assertFileDoesNotExist( $original );
	}

	public function test_alt_text_keeps_backslashes() {
		$id = MigratePlus_Media::sideload( 'http://old.test/uploads/mug.png', 0, 'Saved in C:\Photos\mugs' );
		$this->assertSame( 'Saved in C:\Photos\mugs', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_local_host_allowance_ends_when_a_hook_throws() {
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'Hook failed' );
			},
			9
		);
		foreach ( array( 'download', 'download_to_storage' ) as $method ) {
			try {
				'download' === $method ? MigratePlus_Media::download( 'http://old.test/a.png' ) : MigratePlus_Util::download_to_storage( 'http://old.test/a.json', array( 'json' ) );
				$this->fail( 'The hook did not throw.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'Hook failed', $e->getMessage() );
			}
			$this->assertFalse( MigratePlus_Util::$in_request, $method );
		}
	}

	public function test_storage_folder_htaccess_turns_listings_off() {
		$dir = MigratePlus_Util::storage_dir();
		wp_mkdir_p( $dir );
		// As versions before Options -Indexes wrote it.
		file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Util::ensure_storage_dir();
		$this->assertSame( "Options -Indexes\nRequire all denied\nDeny from all\n", file_get_contents( $dir . '.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_nested_values_read_back_as_they_were() {
		$record = MigratePlus_Util::flatten(
			array(
				'meta' => array(
					'order' => array(
						'5' => 10,
						'4' => 3,
					),
					'flags' => array(
						'on'   => true,
						'off'  => false,
						'none' => null,
					),
					'deep'  => array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => 'x' ) ) ) ) ) ),
				),
			)
		);
		// The mapping screen's field list is unchanged: it stops at the same depth.
		$this->assertSame( array( 'meta.order.5', 'meta.order.4', 'meta.flags.on', 'meta.flags.off', 'meta.flags.none', 'meta.deep.a.b.c.d.e' ), array_keys( $record ) );

		$this->assertSame(
			array(
				5 => 10,
				4 => 3,
			),
			MigratePlus_ACF::read( $record, 'meta.order' )
		);
		$this->assertSame(
			array(
				'on'   => true,
				'off'  => false,
				'none' => null,
			),
			MigratePlus_ACF::read( $record, 'meta.flags' )
		);
		$this->assertSame( array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => 'x' ) ) ) ) ) ), MigratePlus_ACF::read( $record, 'meta.deep' ) );
		// A single field is stored as before.
		$this->assertSame( '0', MigratePlus_ACF::read( $record, 'meta.flags.off' ) );
		$this->assertSame( '', MigratePlus_ACF::read( $record, 'meta.flags.none' ) );
	}

	public function test_acf_empty_repeater_has_no_rows() {
		$ctx   = array(
			'post_id'  => 0,
			'warnings' => array(),
		);
		$items = array(
			'key'        => 'field_items',
			'name'       => 'items',
			'label'      => 'Items',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'   => 'field_item',
					'name'  => 'item',
					'label' => 'Item',
					'type'  => 'text',
				),
			),
		);
		$rows  = array(
			'key'        => 'field_rows',
			'name'       => 'rows',
			'label'      => 'Rows',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'   => 'field_title',
					'name'  => 'title',
					'label' => 'Title',
					'type'  => 'text',
				),
				$items,
			),
		);

		$this->assertSame( array(), MigratePlus_ACF::convert( $items, array(), $ctx ) );
		$this->assertSame(
			array(
				array(
					'field_title' => 'Kitchen',
					'field_items' => array(),
				),
			),
			MigratePlus_ACF::convert(
				$rows,
				array(
					array(
						'title' => 'Kitchen',
						'items' => array(),
					),
				),
				$ctx
			)
		);
		$this->assertSame( array(), MigratePlus_ACF::convert( array( 'type' => 'gallery' ), array(), $ctx ) );
	}

	public function test_acf_taxonomy_names_win_over_term_ids() {
		$other         = self::factory()->category->create( array( 'name' => 'Archive' ) );
		$named         = self::factory()->category->create( array( 'name' => (string) $other ) );
		$lone          = self::factory()->category->create( array( 'name' => 'Lone' ) );
		$field         = array(
			'type'       => 'taxonomy',
			'taxonomy'   => 'category',
			'field_type' => 'checkbox',
			'add_term'   => 1,
		);
		$plain         = array(
			'post_id'      => 0,
			'job'          => new MigratePlus_Job( array( 'source' => 'json' ) ),
			'create_terms' => true,
			'warnings'     => array(),
		);
		$export        = $plain;
		$export['job'] = new MigratePlus_Job(
			array(
				'source'   => 'json',
				'settings' => array( 'migrateplus_mapping' => array() ),
			)
		);

		$this->assertSame( array( $named ), MigratePlus_ACF::convert( $field, (string) $other, $plain ) );
		$this->assertSame( array( $named ), MigratePlus_ACF::convert( $field, (string) $other, $export ) );
		// No term has that name: other data means the id, an export a term to create.
		$this->assertSame( array( $lone ), MigratePlus_ACF::convert( $field, (string) $lone, $plain ) );
		$created = MigratePlus_ACF::convert( $field, (string) $lone, $export );
		$this->assertSame( (string) $lone, get_term( $created[0], 'category' )->name );
	}

	public function test_acf_exported_related_post_waits_rather_than_matching_a_local_title() {
		$local  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$field  = array(
			'type'      => 'relationship',
			'post_type' => array( 'page' ),
		);
		$ref    = array(
			'id'    => 99,
			'slug'  => 'about-us',
			'title' => 'About',
		);
		$export = array(
			'post_id'  => 0,
			'job'      => new MigratePlus_Job(
				array(
					'source'   => 'json',
					'settings' => array( 'migrateplus_mapping' => array() ),
				)
			),
			'defer'    => true,
			'pending'  => array(),
			'warnings' => array(),
		);

		$this->assertSame( array(), MigratePlus_ACF::convert( $field, array( $ref ), $export ) );
		$this->assertSame( array( MigratePlus_Importer::uid( $export['job'], 99 ) ), $export['pending'] );
		// Data from elsewhere still matches by title.
		$this->assertSame( $local, MigratePlus_ACF::resolve_post( $ref, array( 'page' ), new MigratePlus_Job( array( 'source' => 'json' ) ) ) );
	}
	public function test_acf_number_leaves_a_comma_alone() {
		$ctx   = array(
			'post_id'  => 0,
			'warnings' => array(),
		);
		$field = array(
			'type'  => 'number',
			'label' => 'Rating',
		);
		$this->assertSame( '', MigratePlus_ACF::convert( $field, '1,5', $ctx ) );
		$this->assertSame( array( 'ACF "Rating": "1,5" is not a number, so it was left empty.' ), $ctx['warnings'] );
		$this->assertSame( 1500, MigratePlus_ACF::convert( $field, '1 500', $ctx ) );
		$this->assertSame( 2.5, MigratePlus_ACF::convert( $field, '2.5', $ctx ) );
	}

	public function test_acf_links_urls_and_editor_text_point_at_this_site_when_links_are_rewritten() {
		$ctx = array(
			'post_id'  => 0,
			'base'     => 'http://old.test',
			'rewrite'  => true,
			'warnings' => array(),
		);
		$this->assertSame(
			array(
				'url'    => home_url( '/contact/' ),
				'title'  => 'Contact',
				'target' => '',
			),
			MigratePlus_ACF::convert(
				array( 'type' => 'link' ),
				array(
					'url'   => 'http://old.test/contact/',
					'title' => 'Contact',
				),
				$ctx
			)
		);
		$this->assertSame( home_url( '/shop/' ), MigratePlus_ACF::convert( array( 'type' => 'url' ), 'http://old.test/shop/', $ctx ) );
		$this->assertSame( '<a href="' . home_url( '/about/' ) . '">About</a> <img src="http://old.test/wp-content/uploads/a.png">', MigratePlus_ACF::convert( array( 'type' => 'wysiwyg' ), '<a href="http://old.test/about/">About</a> <img src="http://old.test/wp-content/uploads/a.png">', $ctx ) );

		$ctx['rewrite'] = false;
		$this->assertSame( 'http://old.test/shop/', MigratePlus_ACF::convert( array( 'type' => 'url' ), 'http://old.test/shop/', $ctx ) );
	}

	public function test_acf_references_never_resolve_to_a_menu_item_or_revision() {
		$item     = self::factory()->post->create(
			array(
				'post_type'  => 'nav_menu_item',
				'post_title' => 'Lonely',
			)
		);
		$revision = self::factory()->post->create(
			array(
				'post_type'  => 'revision',
				'post_title' => 'Draft words',
			)
		);
		$this->assertSame( 0, MigratePlus_ACF::resolve_post( 'Lonely', array() ) );
		$this->assertSame( 0, MigratePlus_ACF::resolve_post( 'Draft words', array() ) );
		$this->assertSame( 0, MigratePlus_ACF::resolve_post( (string) $item, array() ) );
		$this->assertSame( 0, MigratePlus_ACF::resolve_post( (string) $revision, array() ) );
	}
}
