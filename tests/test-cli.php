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
			'mapping on an export' => array( array( 'mapping' => 'Books feed' ), 'This file is a MigratePlus export: it carries its own mapping' ),
			'unknown mapping'      => array( array( 'mapping' => 'Nope' ), 'No saved mapping called "Nope".' ),
			'bad duplicate mode'   => array( array( 'duplicate' => 'merge' ), '--duplicate must be skip, update or create.' ),
			'bad forced status'    => array( array( 'force-status' => 'trash' ), '--force-status must be publish, draft, pending, private or keep.' ),
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
		// An import that was started but stopped before its first batch.
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 1 );
		$job     = $preview['job'];

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
}
