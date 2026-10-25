<?php
/**
 * Import job persistence.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * An import job: source config, field mapping, options, progress and log.
 * Stored as a non-autoloaded option so it survives across AJAX/CLI batches.
 */
class MigratePlus_Job {

	const INDEX_OPTION = 'migrateplus_job_ids';
	const MAX_LOG      = 500;

	public $data;

	/**
	 * @return array
	 */
	public static function default_options() {
		$s = MigratePlus_Settings::get();
		return array(
			'post_type'             => post_type_exists( $s['post_type'] ) ? $s['post_type'] : 'post',
			'default_status'        => $s['default_status'],
			'force_status'          => $s['force_status'], // '' keeps each record's status; otherwise every post gets this one.
			'default_author'        => $s['default_author'] ? (int) $s['default_author'] : get_current_user_id(),
			'duplicate'             => $s['duplicate'],
			'skip_existing'         => 0, // Also skip records matching content already here by slug, SKU or file name.
			'update_ids'            => array(), // Existing posts whose changes the user reviewed and approved: these are updated.
			'replace_ids'           => array(), // Existing media whose file the user chose to replace with the imported one.
			'copy_ids'              => array(), // Existing media the user chose to keep, adding the imported file as a new item.
			'batch_size'            => (int) $s['batch_size'],
			'import_featured_image' => (int) $s['import_featured_image'],
			'import_content_images' => (int) $s['import_content_images'],
			'create_terms'          => (int) $s['create_terms'],
			'create_authors'        => (int) $s['create_authors'],
			'rewrite_links'         => (int) $s['rewrite_links'],
			'import_comments'       => (int) $s['import_comments'],
			'import_menus'          => (int) $s['import_menus'],
			'import_term_data'      => (int) $s['import_term_data'],
			'import_users'          => (int) $s['import_users'],
			'import_acf_definitions' => (int) $s['import_acf_definitions'],
			'import_acf_options'    => (int) $s['import_acf_options'],
			'import_site_settings'  => (int) $s['import_site_settings'],
			'import_orders'         => (int) $s['import_orders'],
			'import_redirects'      => (int) $s['import_redirects'],
			'dry_run'               => 0,
		);
	}

	/**
	 * @param array $data Data.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge(
			array(
				'id'       => '',
				'created'  => time(),
				'updated'  => time(),
				'user'     => get_current_user_id(),
				'source'   => '',
				'settings' => array(),
				'mapping'  => array(
					'fields'     => array(),
					'meta'       => array(),
					'taxonomies' => array(),
					'acf'        => array(),
				),
				'options'  => self::default_options(),
				'status'   => 'draft',
				'cursor'   => null,
				'total'    => null,
				'stats'    => array(
					'processed' => 0,
					'created'   => 0,
					'updated'   => 0,
					'skipped'   => 0,
					'failed'    => 0,
				),
				'log'      => array(),
			),
			$data
		);
		// Posts created across every run: "Run again" resets stats, but the earlier posts can still be rolled back.
		// Comments added to posts the import only updated count too: rollback removes them.
		// sections: the parts of a MigratePlus export (terms, users, post types, menus...), with how many of each are done.
		// stage: the part being imported; '' before the first batch resolves it (see MigratePlus_Runner::next_stage()).
		// stage_pos: items of the current side stage already imported, so a batch resumes where the last one stopped.
		// menu_locations: theme location => [ menu before, menu the import set ], for rollback.
		// side_prev: option and ACF values a side section overwrote, restored on rollback.
		// cursor_skip: records of the batch at "cursor" already imported, by a batch that was cut short.
		$this->data += array(
			'created_total'  => 0,
			'comments_total' => 0,
			'sections'       => array(),
			'stage'          => '',
			'stage_pos'      => 0,
			'menu_locations' => array(),
			'side_prev'      => array(),
			'cursor_skip'    => 0,
		);
	}

	/**
	 * Start the sections, and a batch that was cut short, over, for a run from the first record.
	 */
	public function reset_sections() {
		$this->data['stage']       = '';
		$this->data['stage_pos']   = 0;
		$this->data['cursor_skip'] = 0;
		foreach ( $this->data['sections'] as $i => $section ) {
			$this->data['sections'][ $i ]['done'] = 0;
		}
	}

	/**
	 * Create and persist a new job.
	 *
	 * @param string $source   Source id.
	 * @param array  $settings Prepared settings.
	 * @return MigratePlus_Job
	 */
	public static function create( $source, array $settings ) {
		$job = new self(
			array(
				'id'       => strtolower( wp_generate_password( 10, false ) ),
				'source'   => $source,
				'settings' => $settings,
				'total'    => isset( $settings['total'] ) ? $settings['total'] : null,
			)
		);
		$job->save();
		$ids   = (array) get_option( self::INDEX_OPTION, array() );
		$ids[] = $job->data['id'];
		update_option( self::INDEX_OPTION, array_values( array_unique( $ids ) ), false );
		return $job;
	}

	/**
	 * @param string $id Job id.
	 * @return MigratePlus_Job|null
	 */
	public static function load( $id ) {
		$id   = sanitize_key( $id );
		$data = $id ? get_option( 'migrateplus_job_' . $id ) : false;
		return is_array( $data ) ? new self( $data ) : null;
	}

	/**
	 * All jobs, newest first.
	 *
	 * @return MigratePlus_Job[]
	 */
	public static function all() {
		$jobs = array();
		foreach ( (array) get_option( self::INDEX_OPTION, array() ) as $id ) {
			$job = self::load( $id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		usort(
			$jobs,
			static function ( $a, $b ) {
				return $b->data['created'] - $a->data['created'];
			}
		);
		return $jobs;
	}

	public function save() {
		$this->data['updated'] = time();
		update_option( 'migrateplus_job_' . $this->data['id'], $this->data, false );
	}

	/**
	 * Delete the job record and any cached source files.
	 */
	public function delete() {
		foreach ( array_merge( array( 'file', 'cache_file' ), self::side_file_keys() ) as $key ) {
			$path = isset( $this->data['settings'][ $key ] ) ? $this->data['settings'][ $key ] : '';
			if ( $path && 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( MigratePlus_Util::storage_dir() ) ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_option( 'migrateplus_job_' . $this->data['id'] );
		$ids = array_diff( (array) get_option( self::INDEX_OPTION, array() ), array( $this->data['id'] ) );
		update_option( self::INDEX_OPTION, array_values( $ids ), false );
	}

	/**
	 * Whether the job's file carries side sections (terms, users, ACF options,
	 * site settings or menus) next to its records.
	 *
	 * @return bool
	 */
	public function has_side_sections() {
		foreach ( self::side_file_keys() as $key ) {
			if ( ! empty( $this->data['settings'][ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The settings keys that hold a side section's cache file ("terms_file"...).
	 *
	 * @return string[]
	 */
	public static function side_file_keys() {
		return array_map(
			static function ( $key ) {
				return $key . '_file';
			},
			MigratePlus_Runner::side_keys()
		);
	}

	/**
	 * Instantiate the job's source.
	 *
	 * @return MigratePlus_Source|WP_Error
	 */
	public function source() {
		return MigratePlus_Sources::create( $this->data['source'], (array) $this->data['settings'] );
	}

	/**
	 * Append a log line.
	 *
	 * @param string $level   info|success|warning|error.
	 * @param string $message Message.
	 */
	public function log( $level, $message ) {
		$this->data['log'][] = array(
			't' => time(),
			'l' => $level,
			'm' => $message,
		);
		if ( count( $this->data['log'] ) > self::MAX_LOG ) {
			$this->data['log'] = array_slice( $this->data['log'], -self::MAX_LOG );
		}
	}

	/**
	 * Number of posts (menu items included), comments, menus, terms, orders and
	 * users currently tagged with this job.
	 *
	 * @return int
	 */
	public function imported_count() {
		global $wpdb;
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT m.post_id ) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_migrateplus_job_id' AND m.meta_value = %s", $this->data['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $posts + (int) $this->imported_comments( 0, 0, true ) + count( $this->imported_menus() ) + count( $this->imported_terms() ) + count( $this->imported_orders() ) + count( $this->imported_users() );
	}

	/**
	 * IDs of the WooCommerce orders this job created.
	 *
	 * @return int[]
	 */
	public function imported_orders() {
		return MigratePlus_Orders::imported( $this->data['id'] );
	}

	/**
	 * IDs of the users this job created.
	 *
	 * @return int[]
	 */
	public function imported_users() {
		return MigratePlus_Users::imported( $this->data['id'] );
	}

	/**
	 * IDs of the nav menus this job created.
	 *
	 * @return int[]
	 */
	public function imported_menus() {
		$ids = get_terms(
			array(
				'taxonomy'   => 'nav_menu',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $this->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * IDs of the terms this job created, children before parents so each can be
	 * deleted on its own (menus are counted apart, by imported_menus()).
	 *
	 * @return int[]
	 */
	public function imported_terms() {
		$ids = get_terms(
			array(
				'taxonomy'   => array_values( array_diff( get_taxonomies(), array( 'nav_menu' ) ) ),
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'id',
				'order'      => 'DESC',
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $this->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * IDs of comments tagged with this job, of every type and status.
	 *
	 * @param int  $limit  Comments per page (0 = all).
	 * @param int  $offset Comments to skip.
	 * @param bool $count  Return the number of comments instead.
	 * @return int[]|int
	 */
	public function imported_comments( $limit, $offset = 0, $count = false ) {
		return get_comments(
			array(
				'meta_key'                  => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'                => $this->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'status'                    => 'any',
				'type'                      => 'all',
				'fields'                    => 'ids',
				'number'                    => $limit,
				'offset'                    => $offset,
				'orderby'                   => 'comment_ID',
				'order'                     => 'ASC',
				'count'                     => $count,
				'update_comment_meta_cache' => false,
			)
		);
	}

	/**
	 * IDs of posts tagged with this job, newest first, for rollback. Read from the meta
	 * table, as imported_count() counts them: a query by post type would leave out a type
	 * no longer registered (products with WooCommerce off), and rollback would report
	 * success with them still there. No filters either, so a language plugin can't hide any.
	 *
	 * @param int $limit  Posts per page.
	 * @param int $offset Posts to skip.
	 * @return int[]
	 */
	public function imported_ids( $limit, $offset = 0 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_migrateplus_job_id' AND m.meta_value = %s ORDER BY m.post_id DESC LIMIT %d OFFSET %d", $this->data['id'], $limit, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $ids );
	}

	/**
	 * Safe representation for the browser (secrets removed, server paths hidden).
	 *
	 * @return array
	 */
	public function to_public() {
		$data     = $this->data;
		$settings = array();
		foreach ( (array) $data['settings'] as $key => $value ) {
			if ( in_array( $key, array_merge( array( 'password', 'app_password', 'api_key', 'headers', 'file', 'cache_file', 'api_base' ), self::side_file_keys() ), true ) ) {
				continue;
			}
			$settings[ $key ] = is_scalar( $value ) ? $value : '';
		}
		$data['settings'] = $settings;
		$data['log']      = array_slice( $data['log'], -100 );
		unset( $data['cursor'] );
		return $data;
	}
}
