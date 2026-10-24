<?php
/**
 * Import job persistence.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * An import job: source config, field mapping, options, progress and log.
 * Stored as a non-autoloaded option so it survives across AJAX/CLI batches.
 */
class PressPort_Job {

	const INDEX_OPTION = 'pressport_job_ids';
	const MAX_LOG      = 500;

	public $data;

	/**
	 * @return array
	 */
	public static function default_options() {
		$s = PressPort_Settings::get();
		return array(
			'post_type'             => post_type_exists( $s['post_type'] ) ? $s['post_type'] : 'post',
			'default_status'        => $s['default_status'],
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
		$this->data += array( 'created_total' => 0 );
	}

	/**
	 * Create and persist a new job.
	 *
	 * @param string $source   Source id.
	 * @param array  $settings Prepared settings.
	 * @return PressPort_Job
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
	 * @return PressPort_Job|null
	 */
	public static function load( $id ) {
		$id   = sanitize_key( $id );
		$data = $id ? get_option( 'pressport_job_' . $id ) : false;
		return is_array( $data ) ? new self( $data ) : null;
	}

	/**
	 * All jobs, newest first.
	 *
	 * @return PressPort_Job[]
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
		update_option( 'pressport_job_' . $this->data['id'], $this->data, false );
	}

	/**
	 * Delete the job record and any cached source files.
	 */
	public function delete() {
		foreach ( array( 'file', 'cache_file' ) as $key ) {
			$path = isset( $this->data['settings'][ $key ] ) ? $this->data['settings'][ $key ] : '';
			if ( $path && 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( PressPort_Util::storage_dir() ) ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_option( 'pressport_job_' . $this->data['id'] );
		$ids = array_diff( (array) get_option( self::INDEX_OPTION, array() ), array( $this->data['id'] ) );
		update_option( self::INDEX_OPTION, array_values( $ids ), false );
	}

	/**
	 * Instantiate the job's source.
	 *
	 * @return PressPort_Source|WP_Error
	 */
	public function source() {
		return PressPort_Sources::create( $this->data['source'], (array) $this->data['settings'] );
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
	 * Number of posts currently tagged with this job.
	 *
	 * @return int
	 */
	public function imported_count() {
		return (int) $this->imported_query( 1 )->found_posts;
	}

	/**
	 * IDs of posts tagged with this job, shared by the count and rollback so both see the same posts.
	 *
	 * @param int $limit  Posts per page.
	 * @param int $offset Posts to skip.
	 * @return WP_Query
	 */
	public function imported_query( $limit, $offset = 0 ) {
		// 'any' leaves out types and statuses excluded from search (coupons, hidden CPTs, trash): list them all.
		// Filters are suppressed so a language plugin can't hide posts in other languages.
		return new WP_Query(
			array(
				'post_type'        => array_keys( get_post_types() ),
				'post_status'      => array_keys( get_post_stati() ),
				'meta_key'         => '_pressport_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $this->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => $limit,
				'offset'           => $offset,
				'no_found_rows'    => false,
				'suppress_filters' => true,
			)
		);
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
			if ( in_array( $key, array( 'password', 'app_password', 'api_key', 'headers', 'file', 'cache_file', 'api_base' ), true ) ) {
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
