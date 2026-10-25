<?php
/**
 * Menus imported into a menu that is already here, and imported again after
 * items were deleted on the old site.
 *
 * @package MigratePlus
 */
class Test_Menus extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function import_menus( array $menus ) {
		$file = wp_tempnam( 'migrateplus-menus.json' );
		file_put_contents( $file, wp_json_encode( $menus ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$job = MigratePlus_Job::create(
			'json',
			array(
				'migrateplus_site' => 'http://old.test',
				'menus_file'       => $file,
			)
		);
		MigratePlus_Menus::import( $job );
		wp_delete_file( $file );
		$job->delete();
	}

	protected function menu( array $items ) {
		return array(
			array(
				'id'    => 1,
				'name'  => 'Main',
				'slug'  => 'main',
				'items' => $items,
			),
		);
	}

	protected function titles( $menu_id ) {
		return wp_list_pluck( wp_get_nav_menu_items( $menu_id ), 'title' );
	}

	public function test_items_take_over_hand_made_ones_and_go_when_the_file_drops_them() {
		$menu = wp_create_nav_menu( 'Main' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-type'   => 'custom',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);
		$home = array(
			'id'    => 10,
			'title' => 'Home',
			'type'  => 'custom',
			'url'   => home_url( '/' ),
			'order' => 1,
		);
		$blog = array(
			'id'    => 11,
			'title' => 'Blog',
			'type'  => 'custom',
			'url'   => home_url( '/blog/' ),
			'order' => 2,
		);

		$this->import_menus( $this->menu( array( $home, $blog ) ) );
		$this->assertSame( array( 'Home', 'Blog' ), $this->titles( $menu ) );

		// Blog was deleted on the old site; Home was made here by hand, so it stays either way.
		$this->import_menus( $this->menu( array() ) );
		$this->assertSame( array( 'Home' ), $this->titles( $menu ) );
	}
}
