<?php
/**
 * The wp migrateplus commands, called directly against a WP-CLI stub.
 *
 * @package MigratePlus
 */

require_once __DIR__ . '/wp-cli-stub.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-cli.php';

class Test_CLI extends WP_UnitTestCase {

	/**
	 * Temp files to delete.
	 *
	 * @var string[]
	 */
	protected $files = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		WP_CLI::$output = array();
		WP_CLI::$items  = array();
		WP_CLI::$asked  = array();
	}

	public function tear_down() {
		array_map( 'wp_delete_file', $this->files );
		parent::tear_down();
	}

	/**
	 * @param string $command Method.
	 * @param array  $args    Positional args.
	 * @param array  $assoc   Assoc args.
	 * @return string Everything the command printed.
	 */
	protected function wp( $command, array $args = array(), array $assoc = array() ) {
		( new MigratePlus_CLI() )->$command( $args, $assoc );
		return implode( "\n", WP_CLI::$output );
	}

	/**
	 * @return string A temp file path that tear_down() deletes.
	 */
	protected function temp_file() {
		$file          = wp_tempnam( 'migrateplus-cli' );
		$this->files[] = $file;
		return $file;
	}

	/**
	 * @param mixed $data Data to write as JSON.
	 * @return string A temp file holding it.
	 */
	protected function json_file( $data ) {
		$file = $this->temp_file();
		file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	/**
	 * @return string A feed of one book, which is not a Migrate Plus export.
	 */
	protected function books_feed() {
		return $this->json_file(
			array(
				array(
					'isbn' => '9780141439518',
					'name' => 'Pride and Prejudice',
				),
			)
		);
	}

	/**
	 * Export with the command, as another site (http://old.test) would have.
	 *
	 * @param string $types Post types.
	 * @param array  $assoc Export flags.
	 * @return string Path.
	 */
	protected function export_from_old_site( $types = 'post', array $assoc = array() ) {
		$file = $this->temp_file();
		$this->wp( 'export', array( $types ), $assoc + array( 'output' => $file ) );
		$doc  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$home = untrailingslashit( home_url() );
		array_walk_recursive(
			$doc,
			static function ( &$value ) use ( $home ) {
				$value = is_string( $value ) ? str_replace( $home, 'http://old.test', $value ) : $value;
			}
		);
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		WP_CLI::$output = array();
		return $file;
	}

	/**
	 * @param string $command Method.
	 * @param array  $args    Positional args.
	 * @param array  $assoc   Assoc args.
	 * @return string Error message.
	 */
	protected function wp_error( $command, array $args, array $assoc ) {
		try {
			$this->wp( $command, $args, $assoc );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The command did not fail.' );
	}

	public function export_cases() {
		return array(
			'one type'      => array( 'post', array(), array( 'Hello world' ) ),
			'several types' => array( 'post,page', array(), array( 'Hello world', 'About' ) ),
			'status'        => array( 'post', array( 'status' => 'publish,draft' ), array( 'Hello world', 'Packing list' ) ),
			'comments'      => array( 'post', array( 'comments' => true ), array( 'Hello world' ) ),
			'menus'         => array( 'post', array( 'menus' => true ), array( 'Hello world' ) ),
			'gzip'          => array( 'post', array( 'gzip' => true ), array( 'Hello world' ) ),
		);
	}

	/**
	 * @dataProvider export_cases
	 *
	 * @param string   $types  Post types argument.
	 * @param array    $assoc  Flags.
	 * @param string[] $titles Titles expected in the file.
	 */
	public function test_export( $types, array $assoc, array $titles ) {
		$hello = self::factory()->post->create( array( 'post_title' => 'Hello world' ) );
		self::factory()->post->create(
			array(
				'post_title'  => 'Packing list',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_title' => 'About',
				'post_type'  => 'page',
			)
		);
		self::factory()->comment->create( array( 'comment_post_ID' => $hello ) );
		$menu = wp_create_nav_menu( 'Main' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);

		$file   = $this->temp_file();
		$output = $this->wp( 'export', array( $types ), $assoc + array( 'output' => $file ) );
		$this->assertStringContainsString( 'Success: Exported ' . count( $titles ) . ' items', $output );
		$this->assertStringEndsWith( ' to ' . $file, $output );

		$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$doc = json_decode( isset( $assoc['gzip'] ) ? gzdecode( $raw ) : $raw, true );
		$this->assertSame( $titles, array_column( $doc['items'], 'title' ) );
		$this->assertSame( isset( $assoc['comments'] ), isset( $doc['items'][0]['comments'][0] ) );
		$this->assertSame( isset( $assoc['menus'] ), isset( $doc['menus'] ) );
	}

	public function test_import_dry_run_counts_duplicates_and_saves_nothing() {
		self::factory()->post->create( array( 'post_title' => 'Hello world' ) );
		$second = self::factory()->post->create( array( 'post_title' => 'Second post' ) );
		$file   = $this->export_from_old_site();
		wp_delete_post( $second, true );

		$output = $this->wp(
			'import',
			array( 'json' ),
			array(
				'file'    => $file,
				'dry-run' => true,
			)
		);
		$this->assertStringContainsString( '1 of 2 records are already on this site: 0 imported before, 1 with the same slug', $output );
		$this->assertStringContainsString( 'Success: [dry run] 1 created, 0 updated, 1 skipped, 0 failed.', $output );
		$this->assertSame( array( 'Hello world' ), wp_list_pluck( get_posts(), 'post_title' ) );
	}

	public function test_import_force_status_then_update() {
		$ids  = array(
			self::factory()->post->create( array( 'post_title' => 'Hello world' ) ),
			self::factory()->post->create( array( 'post_title' => 'Second post' ) ),
		);
		$file = $this->export_from_old_site();
		array_map( 'wp_delete_post', $ids, array( true, true ) );

		$this->wp(
			'import',
			array( 'json' ),
			array(
				'file'         => $file,
				'force-status' => 'draft',
			)
		);
		$posts = get_posts( array( 'post_status' => 'any' ) );
		$this->assertSame( array( 'draft', 'draft' ), wp_list_pluck( $posts, 'post_status' ) );
		wp_update_post(
			array(
				'ID'         => $posts[0]->ID,
				'post_title' => 'Edited here',
			)
		);

		WP_CLI::$output = array();
		$output         = $this->wp(
			'import',
			array( 'json' ),
			array(
				'file'         => $file,
				'duplicate'    => 'update',
				'force-status' => 'private',
			)
		);
		$this->assertStringContainsString( '0 created, 2 updated', $output );
		$after = get_posts( array( 'post_status' => 'any' ) );
		$this->assertSame( wp_list_pluck( $posts, 'ID' ), wp_list_pluck( $after, 'ID' ) );
		$this->assertEqualSets( array( 'Hello world', 'Second post' ), wp_list_pluck( $after, 'post_title' ) );
		$this->assertSame( array( 'private', 'private' ), wp_list_pluck( $after, 'post_status' ) );
	}

	public function mapping_sources() {
		return array(
			'saved mapping' => array( 'mapping' ),
			'config file'   => array( 'config' ),
		);
	}

	/**
	 * @dataProvider mapping_sources
	 *
	 * @param string $how mapping or config.
	 */
	public function test_import_feed_with( $how ) {
		$feed = $this->temp_file();
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$feed,
			wp_json_encode(
				array(
					array(
						'isbn'  => '9780141439518',
						'name'  => 'Pride and Prejudice',
						'blurb' => 'A novel of manners.',
					),
				)
			)
		);
		$mapping = array(
			'fields' => array(
				'source_id'    => 'isbn',
				'post_title'   => 'name',
				'post_content' => 'blurb',
			),
		);
		$options = array( 'post_type' => 'page' );
		MigratePlus_Mappings::save( 'Books feed', $mapping, $options, array( 'isbn', 'name', 'blurb' ) );
		$config = $this->temp_file();
		file_put_contents( $config, wp_json_encode( compact( 'mapping', 'options' ) + array( 'source' => 'json' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( 'mapping' === $how ) {
			$this->wp(
				'import',
				array( 'json' ),
				array(
					'file'    => $feed,
					'mapping' => 'Books feed',
				)
			);
		} else {
			$this->wp(
				'import',
				array(),
				array(
					'file'   => $feed,
					'config' => $config,
				)
			);
		}
		$pages = get_posts( array( 'post_type' => 'page' ) );
		$this->assertSame( array( 'Pride and Prejudice' ), wp_list_pluck( $pages, 'post_title' ) );
		$this->assertSame( 'A novel of manners.', $pages[0]->post_content );
	}

	public function import_errors() {
		return array(
			'mapping on an export' => array( array( 'mapping' => 'Books feed' ), 'This file is a Migrate Plus export: it carries its own mapping' ),
			'unknown mapping'      => array( array( 'mapping' => 'Nope' ), 'No saved mapping called "Nope".' ),
			'bad duplicate mode'   => array( array( 'duplicate' => 'merge' ), '--duplicate must be skip, update or create.' ),
			'bad forced status'    => array( array( 'force-status' => 'trash' ), '--force-status must be publish, draft, pending, private or keep.' ),
			'bad switch value'     => array( array( 'comments' => 'maybe' ), '--comments takes yes, no, true, false, 1 or 0.' ),
			'unknown author'       => array( array( 'author' => 'nobody@example.test' ), 'Default author not found.' ),
			'unknown post type'    => array( array( 'post_type' => 'mp_nope' ), 'Post type "mp_nope" does not exist.' ),
		);
	}

	/**
	 * @dataProvider import_errors
	 *
	 * @param array  $assoc Flags.
	 * @param string $error Error the command stops with.
	 */
	public function test_import_refuses( array $assoc, $error ) {
		self::factory()->post->create( array( 'post_title' => 'Hello world' ) );
		MigratePlus_Mappings::save( 'Books feed', array( 'fields' => array( 'post_title' => 'name' ) ), array(), array( 'name' ) );
		$file = $this->export_from_old_site();

		$this->assertStringContainsString( $error, $this->wp_error( 'import', array( 'json' ), $assoc + array( 'file' => $file ) ) );
		// No draft job is left behind for wp migrateplus jobs to list.
		$this->assertSame( array(), MigratePlus_Job::all() );
	}

	public function test_jobs_resume_and_rollback() {
		$ids  = array(
			self::factory()->post->create( array( 'post_title' => 'Hello world' ) ),
			self::factory()->post->create( array( 'post_title' => 'Second post' ) ),
		);
		$file = $this->export_from_old_site();
		array_map( 'wp_delete_post', $ids, array( true, true ) );
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 1 );
		$job     = $preview['job'];
		$this->assertStringStartsWith( 'Job ' . $job->data['id'] . ' was never started', $this->wp_error( 'resume', array( $job->data['id'] ), array() ) );
		// An import that was started but stopped before its first batch.
		$job->data['status'] = 'ready';
		$job->save();

		$this->assertStringContainsString( '2 created, 0 updated', $this->wp( 'resume', array( $job->data['id'] ) ) );
		$this->wp( 'jobs' );
		$this->assertSame( $job->data['id'], WP_CLI::$items[0]['id'] );
		$this->assertSame( 'complete', WP_CLI::$items[0]['status'] );
		$this->assertSame( 2, WP_CLI::$items[0]['created_n'] );

		$this->assertStringContainsString( 'Success: Deleted 2 items.', $this->wp( 'rollback', array( $job->data['id'] ), array( 'yes' => true ) ) );
		$this->assertSame( array(), get_posts( array( 'post_status' => 'any' ) ) );
		$this->assertSame( 'Job not found.', $this->wp_error( 'rollback', array( 'nope' ), array( 'yes' => true ) ) );
	}

	public function test_mapping_list_export_delete_import() {
		MigratePlus_Mappings::save(
			'Books feed',
			array( 'fields' => array( 'post_title' => 'name' ) ),
			array( 'post_type' => 'page' ),
			array( 'isbn', 'name' )
		);

		$this->wp( 'mapping', array( 'list' ) );
		$this->assertSame( array( 'Books feed', 'page', 1, 2 ), array_values( array_slice( WP_CLI::$items[0], 0, 4 ) ) );

		$file = $this->temp_file();
		$this->wp( 'mapping', array( 'export', 'Books feed' ), array( 'output' => $file ) );
		$config = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( array( 'post_title' => 'name' ), $config['mapping']['fields'] );

		$this->wp( 'mapping', array( 'delete', 'Books feed' ) );
		$this->assertSame( array(), MigratePlus_Mappings::all() );
		$this->assertSame( 'No saved mapping called "Books feed".', $this->wp_error( 'mapping', array( 'delete', 'Books feed' ), array() ) );

		$this->wp( 'mapping', array( 'import', $file ) );
		$this->assertSame( 'page', MigratePlus_Mappings::get( 'Books feed' )['options']['post_type'] );
	}

	public function test_import_exits_with_an_error_when_records_fail() {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$error = $this->wp_error(
			'import',
			array( 'json' ),
			array(
				'file' => $this->books_feed(),
				'map'  => $this->json_file( array( 'fields' => array( 'post_title' => 'name' ) ) ),
			)
		);
		$this->assertStringStartsWith( '0 created, 0 updated, 0 skipped, 1 failed. Job id: ', $error );
		$this->assertStringContainsString( 'Warning: Failed "Pride and Prejudice"', implode( "\n", WP_CLI::$output ) );
	}

	public function duplicate_defaults() {
		return array(
			'no --duplicate'     => array( array(), 'Skipping them. Pass --duplicates=import', 'Success: 0 created, 0 updated, 1 skipped', 1 ),
			'--duplicate=create' => array( array( 'duplicate' => 'create' ), 'Importing them as copies (--duplicate=create).', 'Success: 1 created, 0 updated, 0 skipped', 2 ),
		);
	}

	/**
	 * @dataProvider duplicate_defaults
	 *
	 * @param array  $assoc  Flags.
	 * @param string $said   What the command says it does with the duplicate.
	 * @param string $result Summary.
	 * @param int    $posts  Posts called Hello world afterwards.
	 */
	public function test_import_with_yes_does_not_ask_about_duplicates( array $assoc, $said, $result, $posts ) {
		self::factory()->post->create( array( 'post_title' => 'Hello world' ) );
		$file   = $this->export_from_old_site();
		$output = $this->wp(
			'import',
			array( 'json' ),
			$assoc + array(
				'file' => $file,
				'yes'  => true,
			)
		);
		$this->assertSame( array(), WP_CLI::$asked );
		$this->assertStringContainsString( $said, $output );
		$this->assertStringContainsString( $result, $output );
		$this->assertCount( $posts, get_posts( array( 'title' => 'Hello world' ) ) );
	}

	public function bad_map_files() {
		return array(
			'a config file'       => array( array( 'source' => 'json' ), 'has no mapping: expected an object with "fields", and optionally "meta", "taxonomies" and "acf".' ),
			'fields as text'      => array( array( 'fields' => 'name' ), '"fields" must be a JSON object or list.' ),
			'no title or content' => array( array( 'fields' => array( 'source_id' => 'isbn' ) ), 'Map at least the Title or Content field (post_title or post_content under "fields").' ),
		);
	}

	/**
	 * @dataProvider bad_map_files
	 *
	 * @param array  $map   The --map file's contents.
	 * @param string $error Error the command stops with.
	 */
	public function test_import_refuses_a_bad_map_file( array $map, $error ) {
		$out = $this->wp_error(
			'import',
			array( 'json' ),
			array(
				'file' => $this->books_feed(),
				'map'  => $this->json_file( $map ),
			)
		);
		$this->assertStringEndsWith( $error, $out );
		$this->assertSame( array(), MigratePlus_Job::all() );
	}

	public function test_import_cleans_the_map_file() {
		$this->wp(
			'import',
			array( 'json' ),
			array(
				'file' => $this->books_feed(),
				'map'  => $this->json_file(
					array(
						'fields' => array(
							'post_title' => 'name',
							'bogus'      => 'isbn',
						),
						'meta'   => array(
							array(
								'key'    => 'isbn',
								'source' => 'isbn',
							),
							array( 'key' => 'no_source' ),
						),
					)
				),
			)
		);
		$mapping = MigratePlus_Job::all()[0]->data['mapping'];
		$this->assertSame( array( 'post_title' => 'name' ), $mapping['fields'] );
		$this->assertSame(
			array(
				array(
					'key'    => 'isbn',
					'source' => 'isbn',
				),
			),
			$mapping['meta']
		);
		$this->assertSame( '9780141439518', get_post_meta( get_posts()[0]->ID, 'isbn', true ) );
	}

	public function test_import_switches_take_yes_and_no_and_stay_out_of_the_source_settings() {
		$this->wp(
			'import',
			array( 'json' ),
			array(
				'file'           => $this->books_feed(),
				'map'            => $this->json_file( array( 'fields' => array( 'post_title' => 'name' ) ) ),
				'comments'       => 'no',
				'content-images' => 'yes',
				'create-terms'   => 'false',
				'duplicates'     => 'skip',
				'yes'            => true,
			)
		);
		$job = MigratePlus_Job::all()[0]->data;
		$this->assertSame( 0, $job['options']['import_comments'] );
		$this->assertSame( 1, $job['options']['import_content_images'] );
		$this->assertSame( 0, $job['options']['create_terms'] );
		$this->assertArrayNotHasKey( 'duplicates', $job['settings'] );
		$this->assertArrayNotHasKey( 'yes', $job['settings'] );
	}

	public function test_config_options_add_to_the_saved_mappings() {
		MigratePlus_Mappings::save( 'Books feed', array( 'fields' => array( 'post_title' => 'name' ) ), array( 'post_type' => 'page' ), array( 'isbn', 'name' ) );
		$this->wp(
			'import',
			array( 'json' ),
			array(
				'file'    => $this->books_feed(),
				'mapping' => 'Books feed',
				'config'  => $this->json_file( array( 'options' => array( 'default_status' => 'draft' ) ) ),
			)
		);
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
			)
		);
		$this->assertSame( array( 'Pride and Prejudice' ), wp_list_pluck( $pages, 'post_title' ) );
		$this->assertSame( 'draft', $pages[0]->post_status );
	}

	public function test_rollback_of_a_dry_run_deletes_nothing() {
		$this->wp(
			'import',
			array( 'json' ),
			array(
				'file'    => $this->books_feed(),
				'map'     => $this->json_file( array( 'fields' => array( 'post_title' => 'name' ) ) ),
				'dry-run' => true,
			)
		);
		$job            = MigratePlus_Job::all()[0];
		WP_CLI::$output = array();
		$this->assertSame( 'Warning: Nothing to roll back: job ' . $job->data['id'] . ' was a dry run.', $this->wp( 'rollback', array( $job->data['id'] ), array( 'yes' => true ) ) );
		$this->assertSame( 'complete', MigratePlus_Job::load( $job->data['id'] )->data['status'] );
	}

	public function test_export_refuses_types_that_are_not_content() {
		register_post_type( 'acf-field-group', array( 'show_ui' => true ) );
		register_post_type( 'mp_hidden', array( 'show_ui' => false ) );
		$this->assertStringStartsWith( '"acf-field-group" can\'t be exported', $this->wp_error( 'export', array( 'post,acf-field-group' ), array( 'output' => $this->temp_file() ) ) );
		$this->assertStringStartsWith( '"mp_hidden" can\'t be exported', $this->wp_error( 'export', array( 'mp_hidden' ), array( 'output' => $this->temp_file() ) ) );
		unregister_post_type( 'acf-field-group' );
		unregister_post_type( 'mp_hidden' );
	}
}
