<?php
/**
 * Find & Replace: matching, serialized data, real runs and rollback.
 *
 * @package MigratePlus
 */
class Test_Replace extends WP_UnitTestCase {

	/**
	 * @param string $text   Text.
	 * @param string $search Search.
	 * @param bool   $whole  Whole URLs only.
	 * @return string
	 */
	private function replace( $text, $search, $whole = true ) {
		$count = 0;
		return MigratePlus_Replace::replace_value( $text, MigratePlus_Replace::matcher( $search, 'NEW', false, $whole ), $count );
	}

	/**
	 * Run a replacement over the given tables to the end.
	 *
	 * @param array $args Start args.
	 * @return array Final state.
	 */
	private function run_all( array $args ) {
		$state = MigratePlus_Replace::start( $args );
		$this->assertIsArray( $state );
		while ( ! $state['complete'] ) {
			MigratePlus_Replace::run_batch( $state );
		}
		MigratePlus_Replace::delete( $state['id'] );
		wp_cache_flush();
		return $state;
	}

	/**
	 * @dataProvider whole_url_cases
	 *
	 * @param string $search   Search.
	 * @param string $text     Text.
	 * @param string $expected Result.
	 */
	public function test_whole_urls_only_match_the_same_site( $search, $text, $expected ) {
		$this->assertSame( $expected, $this->replace( $text, $search ) );
	}

	public function whole_url_cases() {
		return array(
			'path'                   => array( 'http://old.test', 'http://old.test/a', 'NEW/a' ),
			'end of sentence'        => array( 'http://old.test', 'See http://old.test.', 'See NEW.' ),
			'port'                   => array( 'http://old.test', 'http://old.test:8080/x', 'NEW:8080/x' ),
			'quoted'                 => array( 'http://old.test', '"http://old.test"', '"NEW"' ),
			'longer host'            => array( 'http://old.test', 'http://old.test.evil.com', 'http://old.test.evil.com' ),
			'longer name'            => array( 'http://old.test', 'http://old.testing.com', 'http://old.testing.com' ),
			'other scheme'           => array( 'http://old.test', 'https://old.test', 'https://old.test' ),
			'bare domain'            => array( 'old.test', 'admin@old.test', 'admin@NEW' ),
			'bare domain, subdomain' => array( 'old.test', 'www.old.test', 'www.old.test' ),
			'bare domain, prefix'    => array( 'old.test', 'notold.test', 'notold.test' ),
			'path boundary'          => array( 'http://old.test/blog', 'http://old.test/blogging', 'http://old.test/blogging' ),
			'not a url'              => array( 'foo', 'foobar foo', 'NEWbar NEW' ),
		);
	}

	public function test_whole_urls_off_matches_plain_text() {
		$this->assertSame( 'NEW.evil.com', $this->replace( 'http://old.test.evil.com', 'http://old.test', false ) );
	}

	public function test_serialized_data_stays_valid() {
		$value = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			array(
				'url'    => 'x http://old.test/page',
				'n'      => 5,
				'nested' => serialize( array( 'u' => 'http://old.test' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'obj'    => (object) array( 'u' => 'http://old.test/o' ),
				'other'  => 'http://old.test.evil.com',
			)
		);
		$count = 0;
		$out   = MigratePlus_Replace::replace_value( $value, MigratePlus_Replace::matcher( 'http://old.test', 'https://a-much-longer.example', false ), $count );
		$data  = unserialize( $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		$this->assertSame( 3, $count );
		$this->assertSame( 'x https://a-much-longer.example/page', $data['url'] );
		$this->assertSame( 'https://a-much-longer.example', unserialize( $data['nested'] )['u'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( 'https://a-much-longer.example/o', $data['obj']->u );
		$this->assertSame( 'http://old.test.evil.com', $data['other'] );
	}

	public function test_objects_of_real_classes_are_kept_byte_for_byte() {
		$value = 'a:2:{i:0;O:8:"Some_Cls":1:{s:1:"u";s:15:"http://old.test";}i:1;s:15:"http://old.test";}';
		$count = 0;
		$out   = MigratePlus_Replace::replace_value( $value, MigratePlus_Replace::matcher( 'http://old.test', 'https://x', false ), $count );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( 'O:8:"Some_Cls":1:{s:1:"u";s:15:"http://old.test";}', $out );
		$this->assertStringContainsString( 's:9:"https://x"', $out );
	}

	public function test_json_escaped_urls_are_replaced_with_their_escaping_kept() {
		$count = 0;
		$out   = MigratePlus_Replace::replace_value( '[{"url":"https:\/\/old.test\/a","link":"https://old.test/b","other":"https:\/\/old.testing.com"}]', MigratePlus_Replace::matcher( 'https://old.test', 'https://new.test', false ), $count );
		$this->assertSame( '[{"url":"https:\/\/new.test\/a","link":"https://new.test/b","other":"https:\/\/old.testing.com"}]', $out );
		$this->assertSame( 2, $count );
	}

	public function test_matches_in_keys_unloaded_objects_and_enums_count_as_unsafe() {
		$match  = MigratePlus_Replace::matcher( 'http://old.test', 'https://x', false );
		$count  = 0;
		$unsafe = 0;
		$keyed  = 'a:2:{s:19:"http://old.test/key";s:1:"x";i:0;O:8:"Some_Cls":1:{s:1:"u";s:15:"http://old.test";}}';
		$enum   = 'a:2:{i:0;E:11:"Suit:Hearts";i:1;s:15:"http://old.test";}';
		$this->assertSame( $keyed, MigratePlus_Replace::replace_value( $keyed, $match, $count, $unsafe ) );
		$this->assertSame( $enum, MigratePlus_Replace::replace_value( $enum, $match, $count, $unsafe ) );
		$this->assertSame( 0, $count );
		$this->assertSame( 3, $unsafe );
	}

	public function test_corrupt_serialized_data_is_left_alone() {
		$value = 'a:1:{s:99:"http://old.test";}';
		$this->assertSame( $value, $this->replace( $value, 'http://old.test' ) );
	}

	public function test_dry_run_changes_nothing() {
		$post_id = self::factory()->post->create( array( 'post_content' => 'Go to http://old.test/a' ) );
		$state   = $this->run_all(
			array(
				'search'  => 'http://old.test',
				'replace' => 'https://new.example',
				'tables'  => array( $GLOBALS['wpdb']->posts ),
				'dry_run' => 1,
			)
		);

		$this->assertSame( 1, $state['results'][ $GLOBALS['wpdb']->posts ]['rows'] );
		$this->assertSame( 'Go to http://old.test/a', get_post( $post_id )->post_content );
		$this->assertSame( array(), MigratePlus_Replace::history() );
	}

	public function test_real_run_then_rollback_restores_and_keeps_later_edits() {
		global $wpdb;
		update_option( 'pp_test_option', array( 'link' => 'http://old.test/page' ) );
		$option_before = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'pp_test_option'" );
		$post_id       = self::factory()->post->create( array( 'post_content' => 'Link http://old.test/a and http://old.test.evil.com' ) );
		update_post_meta( $post_id, 'plain', 'http://old.test' );
		update_post_meta( $post_id, '_migrateplus_source_url', 'http://old.test/img.jpg' );
		update_option( 'migrateplus_test_mark', 'http://old.test' );

		$this->run_all(
			array(
				'search'  => 'http://old.test',
				'replace' => 'https://new.example',
				'tables'  => array( $wpdb->options, $wpdb->posts, $wpdb->postmeta ),
				'dry_run' => 0,
			)
		);

		$this->assertSame( 'Link https://new.example/a and http://old.test.evil.com', get_post( $post_id )->post_content );
		$this->assertSame( 'https://new.example/page', get_option( 'pp_test_option' )['link'] );
		$this->assertSame( 'https://new.example', get_post_meta( $post_id, 'plain', true ) );
		$this->assertSame( 'http://old.test/img.jpg', get_post_meta( $post_id, '_migrateplus_source_url', true ), 'MigratePlus tracking meta is skipped.' );
		$this->assertSame( 'http://old.test', get_option( 'migrateplus_test_mark' ), 'MigratePlus options are skipped.' );

		// Changed after the replacement: rollback must not overwrite it.
		update_post_meta( $post_id, 'plain', 'edited by hand' );

		$history = MigratePlus_Replace::history();
		$this->assertCount( 1, $history );
		$this->assertSame( 'complete', $history[0]['status'] );
		$this->assertTrue( $history[0]['can_undo'] );

		do {
			$entry = MigratePlus_Replace::rollback_batch( $history[0]['id'] );
			$this->assertIsArray( $entry );
		} while ( 'rolled_back' !== $entry['status'] );
		wp_cache_flush();

		$this->assertSame( 2, $entry['restored'] );
		$this->assertSame( 1, $entry['kept'] );
		$this->assertSame( 'Link http://old.test/a and http://old.test.evil.com', get_post( $post_id )->post_content );
		$this->assertSame( $option_before, $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'pp_test_option'" ) );
		$this->assertSame( 'edited by hand', get_post_meta( $post_id, 'plain', true ) );

		$this->assertWPError( MigratePlus_Replace::rollback_batch( $history[0]['id'] ), 'A run cannot be rolled back twice.' );
		MigratePlus_Replace::forget( $history[0]['id'] );
	}

	public function test_a_batch_waits_while_another_request_runs_the_same_run() {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_content' => 'Go to http://old.test/a' ) );
		$state   = MigratePlus_Replace::start(
			array(
				'search'  => 'http://old.test',
				'replace' => 'https://new.example',
				'tables'  => array( $wpdb->posts ),
			)
		);
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => MigratePlus_Replace::LOCK_PREFIX . $state['id'],
				'option_value' => ( time() + 60 ) . ':other',
				'autoload'     => 'no',
			)
		);

		MigratePlus_Replace::run_batch( $state );
		$this->assertTrue( $state['busy'] );
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( 'Go to http://old.test/a', get_post( $post_id )->post_content );

		$wpdb->delete( $wpdb->options, array( 'option_name' => MigratePlus_Replace::LOCK_PREFIX . $state['id'] ) );
		while ( ! $state['complete'] ) {
			MigratePlus_Replace::run_batch( $state );
		}
		clean_post_cache( $post_id );
		$this->assertArrayNotHasKey( 'busy', $state );
		$this->assertSame( 'Go to https://new.example/a', get_post( $post_id )->post_content );
		MigratePlus_Replace::forget( $state['id'] );
	}

	public function test_a_run_resumed_after_its_request_died_never_replaces_twice() {
		global $wpdb;
		for ( $i = 0; $i < 520; $i++ ) {
			add_option( 'mp_resume_' . $i, 'http://old.test', '', false );
		}
		// The replacement holds the search text, so a row replaced twice would end in /blog/blog.
		$state = MigratePlus_Replace::start(
			array(
				'search'  => 'http://old.test',
				'replace' => 'http://old.test/blog',
				'tables'  => array( $wpdb->options ),
			)
		);
		$died  = false;
		$die   = static function ( $query ) use ( &$died, $wpdb ) {
			if ( ! $died && false !== strpos( $query, "FROM `{$wpdb->options}` WHERE (`option_id`) > (" ) ) {
				$died = true;
				throw new RuntimeException( 'Request died' );
			}
			return $query;
		};
		add_filter( 'query', $die );
		try {
			MigratePlus_Replace::run_batch( $state );
			$this->fail( 'The second page was never read.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Request died', $e->getMessage() );
		}
		remove_filter( 'query', $die );

		$state = MigratePlus_Replace::load_state( $state['id'] );
		while ( ! $state['complete'] ) {
			MigratePlus_Replace::run_batch( $state );
		}
		wp_cache_flush();
		$values = array();
		for ( $i = 0; $i < 520; $i++ ) {
			$values[ get_option( 'mp_resume_' . $i ) ] = true;
		}
		$this->assertSame( array( 'http://old.test/blog' ), array_keys( $values ) );
		MigratePlus_Replace::forget( $state['id'] );
	}

	public function test_interrupted_run_can_be_rolled_back_once_idle() {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_content' => 'Go to http://old.test/a' ) );
		$state   = MigratePlus_Replace::start(
			array(
				'search'  => 'http://old.test',
				'replace' => 'https://new.example',
				'tables'  => array( $wpdb->posts ),
				'dry_run' => 0,
			)
		);
		MigratePlus_Replace::run_batch( $state );
		// The request died before the browser asked for the next batch: state and a "running" entry are left.
		$entry           = MigratePlus_Replace::history_entry( $state['id'] );
		$entry['status'] = 'running';
		update_option( MigratePlus_Replace::LOG_PREFIX . $state['id'], $entry, false );
		$state['complete'] = false;
		update_option( MigratePlus_Replace::STATE_PREFIX . $state['id'], $state, false );

		$this->assertWPError( MigratePlus_Replace::rollback_batch( $state['id'] ), 'A run that just made progress is still running.' );
		$this->assertSame( 'running', MigratePlus_Replace::history()[0]['status'] );
		$this->assertFalse( MigratePlus_Replace::history()[0]['can_undo'] );

		$state['created'] = time() - 600;
		$state['updated'] = time() - 600;
		update_option( MigratePlus_Replace::STATE_PREFIX . $state['id'], $state, false );
		$history = MigratePlus_Replace::history();
		$this->assertSame( 'stopped', $history[0]['status'] );
		$this->assertTrue( $history[0]['can_resume'] );
		$this->assertTrue( $history[0]['can_undo'] );

		do {
			$entry = MigratePlus_Replace::rollback_batch( $state['id'] );
			$this->assertIsArray( $entry );
		} while ( 'rolled_back' !== $entry['status'] );
		clean_post_cache( $post_id );
		$this->assertSame( 'Go to http://old.test/a', get_post( $post_id )->post_content );
		$this->assertNull( MigratePlus_Replace::load_state( $state['id'] ), 'A run being rolled back cannot be resumed.' );
		MigratePlus_Replace::forget( $state['id'] );
	}

	public function test_failed_restore_keeps_the_rollback_file_for_a_retry() {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_content' => 'Go to http://old.test/a' ) );
		$this->run_all(
			array(
				'search'  => 'http://old.test',
				'replace' => 'https://new.example',
				'tables'  => array( $wpdb->posts ),
				'dry_run' => 0,
			)
		);
		$id = MigratePlus_Replace::history()[0]['id'];

		// The database refuses the restore.
		$break = static function ( $query ) use ( $wpdb ) {
			return 0 === strpos( $query, "UPDATE `{$wpdb->posts}`" ) ? 'UPDATE migrateplus_no_such_table SET a = 1' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		$result   = MigratePlus_Replace::rollback_batch( $id );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$entry = MigratePlus_Replace::history()[0];
		$this->assertSame( 'rollback_failed', $entry['status'] );
		$this->assertTrue( $entry['can_undo'], 'The rollback file is kept.' );

		do {
			$entry = MigratePlus_Replace::rollback_batch( $id );
			$this->assertIsArray( $entry );
		} while ( 'rolled_back' !== $entry['status'] );
		clean_post_cache( $post_id );
		$this->assertSame( 'Go to http://old.test/a', get_post( $post_id )->post_content );
		$this->assertSame( 0, $entry['kept'] );
		$this->assertSame( 0, $entry['failed'] );
		MigratePlus_Replace::forget( $id );
	}

	public function test_tables_are_only_this_sites() {
		global $wpdb;
		// Real tables, not the suite's temporary ones: SHOW TABLE STATUS doesn't list temporary tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$extra = array( 'staging_options', 'staging_posts', '2_posts', 'mp_plugin_log', '2024_stats' );
		foreach ( $extra as $name ) {
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}{$name}` (id INT PRIMARY KEY, v TEXT)" ); // phpcs:ignore WordPress.DB
		}
		$names = wp_list_pluck( MigratePlus_Replace::tables(), 'name' );
		$start = MigratePlus_Replace::start(
			array(
				'search'  => 'a',
				'replace' => 'b',
				'tables'  => array( $wpdb->prefix . 'staging_options', $wpdb->prefix . '2_posts' ),
				'dry_run' => 1,
			)
		);
		foreach ( $extra as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$name}`" ); // phpcs:ignore WordPress.DB
		}

		$this->assertContains( $wpdb->posts, $names );
		$this->assertContains( $wpdb->users, $names, 'A single site owns its users.' );
		$this->assertContains( $wpdb->prefix . 'mp_plugin_log', $names );
		$this->assertContains( $wpdb->prefix . '2024_stats', $names, 'A plugin table whose name starts with a number.' );
		$this->assertNotContains( $wpdb->prefix . 'staging_options', $names, 'Another install under wp_staging_.' );
		$this->assertNotContains( $wpdb->prefix . 'staging_posts', $names );
		$this->assertNotContains( $wpdb->prefix . '2_posts', $names, 'Another site of a network.' );
		$this->assertSame( 'migrateplus_replace_tables', $start->get_error_code() );
	}

	public function test_cleanup_deletes_rollback_files_after_30_days() {
		global $wpdb;
		self::factory()->post->create( array( 'post_content' => 'Go to http://old.test/a' ) );
		$args             = array(
			'search'  => 'http://old.test',
			'replace' => 'https://new.example',
			'tables'  => array( $wpdb->posts ),
			'dry_run' => 0,
		);
		$old              = $this->run_all( $args );
		$recent           = $this->run_all(
			array_merge(
				$args,
				array(
					'search'  => 'https://new.example',
					'replace' => 'https://newer.example',
				)
			)
		);
		$entry            = MigratePlus_Replace::history_entry( $old['id'] );
		$entry['created'] = time() - 31 * DAY_IN_SECONDS;
		update_option( MigratePlus_Replace::LOG_PREFIX . $old['id'], $entry, false );

		MigratePlus_Replace::cleanup();

		$this->assertFileDoesNotExist( $entry['backup'] );
		$runs = array_column( MigratePlus_Replace::history(), null, 'id' );
		$this->assertCount( 2, $runs, 'The run stays listed.' );
		$this->assertFalse( $runs[ $old['id'] ]['can_undo'] );
		$this->assertSame( 'Can no longer be rolled back: its rollback file is gone. Rollback files are deleted after 30 days.', $runs[ $old['id'] ]['undo_note'] );
		$this->assertWPError( MigratePlus_Replace::rollback_batch( $old['id'] ) );
		$this->assertTrue( $runs[ $recent['id'] ]['can_undo'] );
		$this->assertSame( '', $runs[ $recent['id'] ]['undo_note'] );
		MigratePlus_Replace::forget( $old['id'] );
		MigratePlus_Replace::forget( $recent['id'] );
	}

	public function test_start_validates_input() {
		$this->assertWPError(
			MigratePlus_Replace::start(
				array(
					'search' => '',
					'tables' => array( $GLOBALS['wpdb']->posts ),
				)
			)
		);
		$this->assertWPError(
			MigratePlus_Replace::start(
				array(
					'search'  => 'a',
					'replace' => 'a',
					'tables'  => array( $GLOBALS['wpdb']->posts ),
				)
			)
		);
		$this->assertWPError(
			MigratePlus_Replace::start(
				array(
					'search'  => 'a',
					'replace' => 'b',
					'tables'  => array( 'not_a_table' ),
				)
			)
		);
	}
}
