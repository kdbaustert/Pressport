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
}
