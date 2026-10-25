<?php
/**
 * Block editor content through an export and an import: synced patterns,
 * navigation links and templates point at this site's content once here.
 *
 * @package MigratePlus
 */
class Test_Blocks extends WP_UnitTestCase {

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

	protected function imported( MigratePlus_Job $job, $type, $title ) {
		$posts = get_posts(
			array(
				'post_type'   => $type,
				'post_status' => 'any',
				'title'       => $title,
				'meta_key'    => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertCount( 1, $posts, $type . ' ' . $title );
		return $posts[0];
	}

	public function test_patterns_and_navigation_links_point_at_the_imported_content_whatever_the_order() {
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Promo',
				'post_content' => '<!-- wp:paragraph --><p>Buy now</p><!-- /wp:paragraph -->',
			)
		);
		$about   = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
				'post_name'  => 'about',
			)
		);
		$home    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Home',
				'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->',
			)
		);
		$nav     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Header',
				'post_content' => '<!-- wp:navigation-link {"label":"About","type":"page","id":' . $about . ',"url":"' . get_permalink( $about ) . '","kind":"post-type"} /-->',
			)
		);
		$doc     = $this->export_doc( array( 'post_types' => array( 'wp_navigation', 'page', 'wp_block' ) ) );
		// Patterns first, navigation last, whatever order was asked for.
		$this->assertSame( array( 'wp_block', 'page', 'wp_navigation' ), $doc['migrateplus']['post_types'] );
		$this->assertSame( array( 'Promo', 'About', 'Home', 'Header' ), array_column( $doc['items'], 'title' ) );

		// The file in the worst order: the page that embeds the pattern comes before the pattern.
		$doc['items']               = array( $doc['items'][2], $doc['items'][3], $doc['items'][1], $doc['items'][0] );
		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		foreach ( array( $pattern, $about, $home, $nav ) as $id ) {
			wp_delete_post( $id, true );
		}
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );

		$new_pattern = $this->imported( $job, 'wp_block', 'Promo' );
		$new_about   = $this->imported( $job, 'page', 'About' );
		$new_home    = $this->imported( $job, 'page', 'Home' );
		$new_nav     = $this->imported( $job, 'wp_navigation', 'Header' );
		$this->assertNotSame( $pattern, $new_pattern->ID );
		$this->assertSame( '<!-- wp:block {"ref":' . $new_pattern->ID . '} /-->', $new_home->post_content );
		$this->assertStringContainsString( '"id":' . $new_about->ID . ',', $new_nav->post_content );
		$this->assertStringContainsString( '"url":"' . get_permalink( $new_about ) . '"', $new_nav->post_content );
		$this->assertSame( array(), get_post_meta( $new_home->ID, MigratePlus_Blocks::PENDING_META ) );
		$job->delete();
	}

	public function test_a_pattern_not_in_the_file_leaves_the_reference_waiting() {
		$job     = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$pending = array();
		$content = MigratePlus_Blocks::relink_content( '<!-- wp:block {"ref":99} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', $job, $pending );
		$this->assertSame( '<!-- wp:block {"ref":99} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', $content );
		$this->assertSame( array( '99' ), $pending );
		$job->delete();
	}

	public function test_image_ids_follow_the_imported_media() {
		$job = MigratePlus_Job::create( 'json', array( 'migrateplus_site' => 'http://old.test' ) );
		$new = self::factory()->attachment->create_object(
			array(
				'file'           => 'photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Photo',
			)
		);
		update_post_meta( $new, '_migrateplus_source_uid', MigratePlus_Importer::uid( $job, '12' ) );
		update_post_meta( $new, '_migrateplus_job_id', $job->data['id'] );
		$pending = array();
		$content = MigratePlus_Blocks::relink_content( '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="http://old.test/photo.jpg" class="wp-image-12"/></figure><!-- /wp:image --><!-- wp:gallery {"ids":[12,13]} --><!-- /wp:gallery -->', $job, $pending );
		$this->assertStringContainsString( '<!-- wp:image {"id":' . $new . ',"sizeSlug":"large"} -->', $content );
		$this->assertStringContainsString( 'class="wp-image-' . $new . '"', $content );
		$this->assertStringContainsString( '{"ids":[' . $new . ',13]}', $content );
		$this->assertSame( array( '13' ), $pending );
		$job->delete();
	}

	public function test_templates_join_the_active_theme_and_update_the_one_with_their_slug() {
		$old = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_title'   => 'Single Posts',
				'post_name'    => 'single',
				'post_content' => '<!-- wp:post-content /-->',
			)
		);
		wp_set_object_terms( $old, 'some-old-theme', 'wp_theme' );
		$doc = $this->export_doc( array( 'post_types' => array( 'wp_template' ) ) );
		$this->assertSame( array( 'some-old-theme' ), $doc['items'][0]['terms']['wp_theme'] );
		$doc['migrateplus']['site'] = 'http://old.test';
		wp_delete_post( $old, true );

		// This theme already has a customised "single": the import updates it rather than adding single-2.
		$mine = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_title'   => 'Single Posts',
				'post_name'    => 'single',
				'post_content' => '<!-- wp:paragraph --><p>mine</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_object_terms( $mine, get_stylesheet(), 'wp_theme' );

		$file = $this->json_file( $doc );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 1, $job->data['stats']['updated'] );
		$this->assertSame( 0, $job->data['stats']['created'] );
		$this->assertSame( '<!-- wp:post-content /-->', get_post( $mine )->post_content );
		$this->assertSame( array( get_stylesheet() ), wp_get_object_terms( $mine, 'wp_theme', array( 'fields' => 'names' ) ) );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'wp_template',
					'post_status' => 'any',
					'name'        => 'single',
				)
			)
		);
		$job->delete();
	}
}
