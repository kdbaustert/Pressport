<?php
/**
 * The REST routes another site pulls an export through: who may use them, what
 * /site offers, and the export round trip. export_file exit()s once it streams
 * the file, so only its refusals are covered.
 *
 * @package MigratePlus
 */

class Test_Rest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	protected $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		foreach ( MigratePlus_Exporter::history() as $entry ) {
			MigratePlus_Exporter::forget( $entry['id'] );
		}
		delete_option( MigratePlus_Settings::OPTION );
		MigratePlus_Settings::flush_cache();
		parent::tear_down();
	}

	/**
	 * Dispatch a request to a route under the plugin's namespace.
	 *
	 * @param string $method  Method.
	 * @param string $route   Route without the namespace.
	 * @param array  $params  Request params.
	 * @param array  $headers Request headers.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, array $params = array(), array $headers = array() ) {
		$request = new WP_REST_Request( $method, '/' . MigratePlus_Rest::ROUTE_NAMESPACE . '/' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Store a pull key as if the given user had generated it on Settings → Access.
	 *
	 * @param int $user The generating user's id.
	 * @return string The key.
	 */
	protected function store_pull_key( $user ) {
		$saved             = (array) get_option( MigratePlus_Settings::OPTION, array() );
		$saved['pull_key'] = 'a-stored-pull-key';
		$saved['pull_key_user'] = $user;
		update_option( MigratePlus_Settings::OPTION, $saved );
		MigratePlus_Settings::flush_cache();
		return 'a-stored-pull-key';
	}

	protected function login( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	protected function give_editors_access() {
		update_option( MigratePlus_Settings::OPTION, array( 'capability' => 'edit_others_posts' ) );
		MigratePlus_Settings::flush_cache();
	}

	/**
	 * Export args that carry no side sections, so only the posts count.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	protected function bare_args( array $over = array() ) {
		return array_merge(
			array(
				'post_types'              => array( 'post' ),
				'include_term_data'       => 0,
				'include_acf_definitions' => 0,
				'include_acf_options'     => 0,
				'include_site_settings'   => 0,
				'include_menus'           => 0,
				'include_orders'          => 0,
			),
			$over
		);
	}

	public function test_refuses_without_a_login() {
		wp_set_current_user( 0 );
		$response = $this->request( 'GET', 'site' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'migrateplus_rest_forbidden', $response->as_error()->get_error_code() );
	}

	public function test_refuses_a_user_without_the_capability() {
		$this->login( 'subscriber' );
		foreach ( array( array( 'GET', 'site' ), array( 'POST', 'export' ), array( 'POST', 'export/run' ), array( 'GET', 'export/file' ) ) as $call ) {
			$response = $this->request( $call[0], $call[1] );
			$this->assertSame( 403, $response->get_status(), $call[1] );
			$this->assertSame( 'migrateplus_rest_forbidden', $response->as_error()->get_error_code(), $call[1] );
		}
	}

	public function test_capability_filter_opens_the_routes() {
		$this->give_editors_access();
		$this->login( 'editor' );
		$this->assertSame( 200, $this->request( 'GET', 'site' )->get_status() );
	}

	public function test_the_pull_key_connects_as_the_user_who_generated_it() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$key   = $this->store_pull_key( $admin );
		wp_set_current_user( 0 );
		self::factory()->post->create();

		$response = $this->request( 'GET', 'site', array(), array( 'X-MigratePlus-Key' => $key ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $admin, get_current_user_id() );
		$types = wp_list_pluck( $response->get_data()['types'], 'count', 'type' );
		$this->assertSame( 1, $types['post'] );
	}

	public function test_a_wrong_or_missing_pull_key_is_refused() {
		$this->store_pull_key( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', 'site', array(), array( 'X-MigratePlus-Key' => 'not-the-key' ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'migrateplus_rest_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( 0, get_current_user_id() );

		$this->assertSame( 401, $this->request( 'GET', 'site' )->get_status() );
	}

	public function test_a_pull_key_of_a_user_without_the_capability_is_refused() {
		$key = $this->store_pull_key( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', 'site', array(), array( 'X-MigratePlus-Key' => $key ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'migrateplus_rest_forbidden', $response->as_error()->get_error_code() );
	}

	public function test_a_pull_key_of_a_deleted_user_is_refused() {
		$gone = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$key  = $this->store_pull_key( $gone );
		wp_delete_user( $gone );
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', 'site', array(), array( 'X-MigratePlus-Key' => $key ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_site_names_the_site_and_counts_each_type() {
		$this->login( 'administrator' );
		self::factory()->post->create_many( 2 );
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		$site = $this->request( 'GET', 'site' )->get_data();

		$this->assertSame( get_bloginfo( 'name' ), $site['name'] );
		$this->assertSame( home_url(), $site['url'] );
		$this->assertSame( MIGRATEPLUS_VERSION, $site['version'] );
		$types = wp_list_pluck( $site['types'], 'count', 'type' );
		$this->assertSame( 2, $types['post'] );
		$this->assertSame( 1, $types['page'] );
		foreach ( $site['types'] as $type ) {
			$this->assertSame( array( 'type', 'label', 'count' ), array_keys( $type ) );
		}
	}

	public function test_export_runs_to_a_file() {
		$this->login( 'administrator' );
		self::factory()->post->create_many( 3 );

		$started = $this->request( 'POST', 'export', array( 'args' => $this->bare_args() ) );
		$this->assertSame( 200, $started->get_status() );
		$start = $started->get_data();
		$this->assertSame( 3, $start['total'] );
		$this->assertNotSame( '', $start['id'] );

		do {
			$run = $this->request( 'POST', 'export/run', array( 'export' => $start['id'] ) )->get_data();
			$this->assertArrayNotHasKey( 'error', $run );
		} while ( ! $run['complete'] );

		$this->assertSame( 3, $run['done'] );
		$this->assertStringEndsWith( '.json', $run['filename'] );
		$this->assertGreaterThan( 0, $run['size'] );
		$state = MigratePlus_Exporter::load_state( $start['id'] );
		$this->assertFileExists( $state['file'] );
	}

	public function test_export_refuses_a_type_that_is_not_exportable() {
		$this->login( 'administrator' );
		$response = $this->request( 'POST', 'export', array( 'args' => array( 'post_types' => array( 'wp_global_styles' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'migrateplus_export_type', $response->as_error()->get_error_code() );
	}

	public function test_export_refuses_when_nothing_matches() {
		$this->login( 'administrator' );
		$response = $this->request( 'POST', 'export', array( 'args' => $this->bare_args( array( 'after' => '2999-01-01' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'migrateplus_export_empty', $response->as_error()->get_error_code() );
		$this->assertSame( array(), MigratePlus_Exporter::history() );
	}

	public function test_file_of_an_unfinished_export_is_not_ready() {
		$this->login( 'administrator' );
		self::factory()->post->create();
		$start = $this->request( 'POST', 'export', array( 'args' => $this->bare_args() ) )->get_data();

		$response = $this->request( 'GET', 'export/file', array( 'export' => $start['id'] ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'migrateplus_export_unready', $response->as_error()->get_error_code() );
	}

	public function test_an_export_belongs_to_who_started_it() {
		$this->give_editors_access();
		$this->login( 'editor' );
		self::factory()->post->create();
		$start = $this->request( 'POST', 'export', array( 'args' => $this->bare_args() ) )->get_data();

		// Another editor may not run it; an administrator may.
		$this->login( 'editor' );
		$response = $this->request( 'POST', 'export/run', array( 'export' => $start['id'] ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'migrateplus_export_missing', $response->as_error()->get_error_code() );

		$this->login( 'administrator' );
		$this->assertSame( 200, $this->request( 'POST', 'export/run', array( 'export' => $start['id'] ) )->get_status() );
	}

	public function test_a_missing_export_is_not_found() {
		$this->login( 'administrator' );
		$response = $this->request( 'POST', 'export/run', array( 'export' => 'nosuchexport' ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'migrateplus_export_missing', $response->as_error()->get_error_code() );
	}
}
