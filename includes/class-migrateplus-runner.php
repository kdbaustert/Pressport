<?php
/**
 * Job orchestration shared by the admin (AJAX) and WP-CLI.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview, batch execution and rollback.
 */
class MigratePlus_Runner {

	const LOCK_TTL = 5 * MINUTE_IN_SECONDS; // A lock older than this was left by a request that died.

	/**
	 * Prepare a source, create a job and return a preview.
	 *
	 * @param string $source_id Source id.
	 * @param array  $settings  Raw settings.
	 * @param int    $sample    Number of sample records.
	 * @return array|WP_Error { job: MigratePlus_Job, records: array, fields: string[], mapping: array }
	 */
	public static function preview( $source_id, array $settings, $sample = 5 ) {
		$source = MigratePlus_Sources::create( $source_id, $settings );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$prepared = $source->prepare();
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		// Re-instantiate with prepared settings (detected headers, totals, cache files).
		$source = MigratePlus_Sources::create( $source_id, $prepared );
		$batch  = $source->fetch( null, $sample );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		// An export of menus (or terms, users...) alone has no items: the import goes straight to its side sections.
		$sides = 0;
		foreach ( self::side_stages() as $side ) {
			$sides += empty( $prepared[ $side['count'] ] ) ? 0 : (int) $prepared[ $side['count'] ];
		}
		if ( empty( $batch['records'] ) && ! $sides ) {
			return new WP_Error( 'migrateplus_empty', __( 'The source returned no records.', 'migrateplus' ) );
		}

		// Fields the source found while preparing (it may look past the sample), plus those in the sample.
		$fields = array_fill_keys( isset( $prepared['fields'] ) ? (array) $prepared['fields'] : array(), true );
		foreach ( $batch['records'] as $record ) {
			foreach ( array_keys( $record ) as $key ) {
				$fields[ $key ] = true;
			}
		}
		// Numeric keys come back as integers; field names are strings everywhere else.
		$names = array_map( 'strval', array_keys( $fields ) );

		$job = MigratePlus_Job::create( $source_id, $prepared );
		if ( null === $job->data['total'] && isset( $batch['total'] ) ) {
			$job->data['total'] = $batch['total'];
		}
		$job->data['mapping']['fields'] = array_intersect( $source->suggested_mapping(), $names );

		// Files exported by MigratePlus carry their own mapping: apply it.
		$embedded = isset( $prepared['migrateplus_mapping'] ) ? $prepared['migrateplus_mapping'] : null;
		if ( is_array( $embedded ) ) {
			// Cleaned like a mapping sent from the mapping screen: a file is no more trusted than a request.
			$job->data['mapping'] = self::apply_embedded_mapping( $job->data['mapping'], MigratePlus_Mappings::clean_mapping( $embedded ), $names );
			$job->log( 'info', __( 'This file was exported by Migrate Plus; its field mapping was applied.', 'migrateplus' ) );
		}
		// ...and the type it was exported from: import into that type when this site has it.
		$exported_type = isset( $prepared['migrateplus_post_type'] ) ? $prepared['migrateplus_post_type'] : '';
		if ( '' !== $exported_type && MigratePlus_Importer::importable_post_type( $exported_type ) ) {
			$job->data['options']['post_type'] = $exported_type;
		}
		// With several types each record names its own, so "Import as" is only the fallback
		// for a type this site lacks: the first content type of the file that it has.
		$exported_types = isset( $prepared['migrateplus_post_types'] ) ? array_filter( explode( ',', (string) $prepared['migrateplus_post_types'] ) ) : array();
		if ( count( $exported_types ) > 1 ) {
			foreach ( array_diff( $exported_types, array( 'attachment' ) ) as $type ) {
				if ( MigratePlus_Importer::importable_post_type( $type ) ) {
					$job->data['options']['post_type'] = $type;
					break;
				}
			}
		}
		$job->data['sections'] = self::sections( $prepared );
		$job->log( 'info', sprintf( /* translators: %s: source label */ __( 'Connected to source: %s', 'migrateplus' ), call_user_func( array( get_class( $source ), 'label' ) ) ) );
		$job->save();

		return array(
			'job'     => $job,
			'records' => $batch['records'],
			'fields'  => $names,
			'mapping' => $job->data['mapping'],
		);
	}

	/**
	 * A MigratePlus export's parts, in import order, for progress: one per post type, then its menus.
	 *
	 * @param array $prepared Prepared source settings.
	 * @return array[] { type, label, total, done }
	 */
	protected static function sections( array $prepared ) {
		$sections = array();
		foreach ( self::side_stages() as $side ) {
			if ( 'before' === $side['when'] && ! empty( $prepared[ $side['count'] ] ) ) {
				$sections[] = array(
					'type'  => $side['type'],
					'label' => $side['label'],
					'total' => (int) $prepared[ $side['count'] ],
					'done'  => 0,
				);
			}
		}
		foreach ( isset( $prepared['migrateplus_counts'] ) ? (array) $prepared['migrateplus_counts'] : array() as $type => $total ) {
			$sections[] = array(
				'type'  => (string) $type,
				'label' => '' === (string) $type ? __( 'Items', 'migrateplus' ) : MigratePlus_Exporter::type_label( (string) $type ),
				'total' => (int) $total,
				'done'  => 0,
			);
		}
		foreach ( self::side_stages() as $side ) {
			if ( 'after' === $side['when'] && ! empty( $prepared[ $side['count'] ] ) ) {
				$sections[] = array(
					'type'  => $side['type'],
					'label' => $side['label'],
					'total' => (int) $prepared[ $side['count'] ],
					'done'  => 0,
				);
			}
		}
		return $sections;
	}

	/**
	 * The side sections an export can carry next to its records, in import order.
	 * Terms and users come first, so records find them; everything that points at
	 * records comes after them. Each one's class implements pending( $job ) and
	 * import_batch( $job ) => { done: bool, results: array[] }.
	 *
	 * @return array[] stage => { class, type, label, count, option, when }
	 */
	public static function side_stages() {
		return array(
			'terms' => array(
				'class'  => 'MigratePlus_Terms',
				'type'   => 'terms',
				'label'  => __( 'Terms', 'migrateplus' ),
				'count'  => 'migrateplus_terms',
				'option' => 'import_term_data',
				'when'   => 'before',
			),
			'users' => array(
				'class'  => 'MigratePlus_Users',
				'type'   => 'users',
				'label'  => __( 'Users', 'migrateplus' ),
				'count'  => 'migrateplus_users',
				'option' => 'import_users',
				'when'   => 'before',
			),
			'acf_definitions' => array(
				'class'  => 'MigratePlus_ACF_Definitions',
				'type'   => 'acf_definitions',
				'label'  => __( 'ACF definitions', 'migrateplus' ),
				'count'  => 'migrateplus_acf_definitions',
				'option' => 'import_acf_definitions',
				'when'   => 'before',
			),
			'acf_options' => array(
				'class'  => 'MigratePlus_ACF_Options',
				'type'   => 'acf_options',
				'label'  => __( 'ACF options', 'migrateplus' ),
				'count'  => 'migrateplus_acf_options',
				'option' => 'import_acf_options',
				'when'   => 'after',
			),
			'site_settings' => array(
				'class'  => 'MigratePlus_Site',
				'type'   => 'site_settings',
				'label'  => __( 'Site settings', 'migrateplus' ),
				'count'  => 'migrateplus_site_settings',
				'option' => 'import_site_settings',
				'when'   => 'after',
			),
			'menus' => array(
				'class'  => 'MigratePlus_Menus',
				'type'   => 'nav_menu',
				'label'  => __( 'Menus', 'migrateplus' ),
				'count'  => 'migrateplus_menus',
				'option' => 'import_menus',
				'when'   => 'after',
			),
			'orders' => array(
				'class'  => 'MigratePlus_Orders',
				'type'   => 'orders',
				'label'  => __( 'Orders', 'migrateplus' ),
				'count'  => 'migrateplus_orders',
				'option' => 'import_orders',
				'when'   => 'after',
			),
		);
	}

	/**
	 * The keys of every side section, in import order: what the JSON source
	 * splits off and the job caches as "<key>_file".
	 *
	 * @return string[]
	 */
	public static function side_keys() {
		return array_keys( self::side_stages() );
	}

	/**
	 * What a side section's "left out" log line says when the file carries it but
	 * its import option is off.
	 *
	 * @param string $stage Side stage.
	 * @return string
	 */
	protected static function off_message( $stage ) {
		$messages = array(
			'terms'       => __( 'The file\'s terms were not imported: "Term data" is off.', 'migrateplus' ),
			'users'       => __( 'The file\'s users were not imported: "Users" is off.', 'migrateplus' ),
			'acf_definitions' => __( 'The file\'s ACF definitions were not imported: "ACF definitions" is off.', 'migrateplus' ),
			'acf_options' => __( 'The file\'s ACF options were not imported: "ACF options" is off.', 'migrateplus' ),
			'site_settings' => __( 'The file\'s site settings were not imported: "Site settings" is off.', 'migrateplus' ),
			'menus'       => __( 'The file\'s menus were not imported: "Import menus" is off.', 'migrateplus' ),
			'orders'      => __( 'The file\'s orders were not imported: "Orders" is off.', 'migrateplus' ),
		);
		return isset( $messages[ $stage ] ) ? $messages[ $stage ] : '';
	}

	/**
	 * Every stage of an import, in order.
	 *
	 * @return string[]
	 */
	protected static function stage_order() {
		$sides = self::side_stages();
		return array_merge(
			array_keys(
				array_filter(
					$sides,
					static function ( $s ) {
						return 'before' === $s['when'];
					}
				)
			),
			array( 'records' ),
			array_keys(
				array_filter(
					$sides,
					static function ( $s ) {
						return 'after' === $s['when'];
					}
				)
			)
		);
	}

	/**
	 * The stage after $current with something to do, in import order, or '' when
	 * the import is finished. '' as $current means the first stage of the job.
	 *
	 * @param MigratePlus_Job $job     Job.
	 * @param string          $current Stage just finished ('' at the start).
	 * @return string
	 */
	public static function next_stage( MigratePlus_Job $job, $current = '' ) {
		$sides = self::side_stages();
		$order = self::stage_order();
		$at    = '' === $current ? 0 : (int) array_search( $current, $order, true ) + 1;
		foreach ( array_slice( $order, $at ) as $stage ) {
			if ( 'records' === $stage ) {
				return $stage;
			}
			if ( class_exists( $sides[ $stage ]['class'] ) && call_user_func( array( $sides[ $stage ]['class'], 'pending' ), $job ) ) {
				return $stage;
			}
		}
		return '';
	}

	/**
	 * Count a processed record towards its section.
	 *
	 * @param MigratePlus_Job $job    Job (updated in place).
	 * @param array           $record Flat record.
	 */
	protected static function tally( MigratePlus_Job $job, array $record ) {
		$field = isset( $job->data['mapping']['fields']['post_type'] ) ? $job->data['mapping']['fields']['post_type'] : '';
		$type  = '' !== $field && isset( $record[ $field ] ) ? sanitize_key( MigratePlus_Util::stringify( $record[ $field ] ) ) : '';
		foreach ( $job->data['sections'] as $i => $section ) {
			if ( $section['type'] === $type ) {
				++$job->data['sections'][ $i ]['done'];
				return;
			}
		}
	}

	/**
	 * Merge an embedded export mapping, keeping only entries whose source exists
	 * (nested sources such as "acf.items" match flattened keys like "acf.items.0.title").
	 *
	 * @param array    $mapping  Current mapping.
	 * @param array    $embedded Embedded mapping.
	 * @param string[] $fields   Source fields seen in the preview.
	 * @return array
	 */
	protected static function apply_embedded_mapping( array $mapping, array $embedded, array $fields ) {
		$has = static function ( $source ) use ( $fields ) {
			foreach ( $fields as $f ) {
				if ( $f === $source || 0 === strpos( $f, $source . '.' ) ) {
					return true;
				}
			}
			return false;
		};
		foreach ( isset( $embedded['fields'] ) ? (array) $embedded['fields'] : array() as $target => $source ) {
			if ( $has( $source ) ) {
				$mapping['fields'][ $target ] = $source;
			}
		}
		foreach ( array( 'meta', 'taxonomies' ) as $list ) {
			foreach ( isset( $embedded[ $list ] ) ? (array) $embedded[ $list ] : array() as $row ) {
				if ( isset( $row['source'] ) && $has( $row['source'] ) && ( 'meta' !== $list || ! empty( $row['key'] ) ) && ( 'taxonomies' !== $list || taxonomy_exists( $row['taxonomy'] ) ) ) {
					$mapping[ $list ][] = $row;
				}
			}
		}
		foreach ( isset( $embedded['acf'] ) ? (array) $embedded['acf'] : array() as $field => $source ) {
			if ( $has( $source ) ) {
				$mapping['acf'][ $field ] = $source;
			}
		}
		return $mapping;
	}

	/**
	 * Read every record of a job and count the ones already on this site, so the
	 * user can choose before importing. Nothing is saved.
	 *
	 * @param MigratePlus_Job $job      Job, with the mapping and options it will run with.
	 * @param int             $examples How many example titles to return.
	 * @return array|WP_Error { total, count, before, existing, examples: [{ title, post_id, kind, label }],
	 *                        changed: [{ post_id, title, type, kind, label, changes }],
	 *                        images: [{ post_id, title, kind, label, current, current_full, imported, is_image, changes }] }
	 */
	public static function find_duplicates( MigratePlus_Job $job, $examples = 5 ) {
		$source = $job->source();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( function_exists( 'set_time_limit' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$out    = array(
			'total'    => 0,
			'count'    => 0,
			'before'   => 0,
			'existing' => 0,
			'examples' => array(),
			'changed'  => array(), // Duplicates whose content differs: the user reviews these before updating.
			'images'   => array(), // Media duplicates, shown side by side.
		);
		$cursor = null;
		do {
			$batch = $source->fetch( $cursor, 200 );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			foreach ( $batch['records'] as $record ) {
				++$out['total'];
				$match = MigratePlus_Importer::find_duplicate( $record, $job );
				if ( ! $match ) {
					continue;
				}
				++$out['count'];
				++$out[ $match['kind'] ];
				$title = html_entity_decode( get_the_title( $match['post_id'] ), ENT_QUOTES, 'UTF-8' );
				if ( count( $out['examples'] ) < $examples ) {
					$out['examples'][] = array(
						'title'   => $title,
						'post_id' => $match['post_id'],
						'kind'    => $match['kind'],
						'label'   => $match['label'],
					);
				}
				if ( ! empty( $match['image'] ) ) {
					// Media duplicates are compared by eye: the user picks update details or replace the file.
					$out['images'][] = array_merge(
						$match['image'],
						array(
							'post_id' => $match['post_id'],
							'title'   => $title,
							'kind'    => $match['kind'],
							'label'   => $match['label'],
						)
					);
				} elseif ( $match['changes'] ) {
					$type             = get_post_type_object( get_post_type( $match['post_id'] ) );
					$out['changed'][] = array(
						'post_id' => $match['post_id'],
						'title'   => $title,
						'type'    => $type ? $type->labels->singular_name : '',
						'kind'    => $match['kind'],
						'label'   => $match['label'],
						'changes' => $match['changes'],
					);
				}
			}
			$cursor = $batch['next'];
		} while ( null !== $cursor && ! empty( $batch['records'] ) );
		return $out;
	}

	/**
	 * Process the next batch of a job.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array|WP_Error { done: bool, results: array[] }
	 */
	public static function run_batch( MigratePlus_Job $job ) {
		$lock = self::lock( $job );
		if ( ! $lock ) {
			return new WP_Error( 'migrateplus_locked', __( 'A batch for this job is already running.', 'migrateplus' ) );
		}
		if ( in_array( $job->data['status'], array( 'complete', 'rolled_back', 'cancelled' ), true ) ) {
			self::unlock( $job, $lock );
			return array(
				'done'    => true,
				'results' => array(),
			);
		}

		$source = $job->source();
		if ( is_wp_error( $source ) ) {
			self::unlock( $job, $lock );
			return $source;
		}

		if ( function_exists( 'set_time_limit' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		if ( '' === (string) $job->data['stage'] ) {
			self::advance( $job );
		}
		if ( 'records' !== $job->data['stage'] ) {
			return self::run_side( $job, $job->data['stage'], $lock );
		}
		$job->data['status'] = 'running';
		$limit               = max( 1, min( 100, (int) $job->data['options']['batch_size'] ) );
		$batch               = $source->fetch( $job->data['cursor'], $limit );

		if ( is_wp_error( $batch ) ) {
			$job->log( 'error', $batch->get_error_message() );
			$job->data['status'] = 'failed';
			$job->save();
			self::unlock( $job, $lock );
			return $batch;
		}

		if ( null === $job->data['total'] && null !== $batch['total'] ) {
			$job->data['total'] = (int) $batch['total'];
		}

		// Cursors are the source's own, so a batch cut short resumes from its first record
		// and skips the ones it already did. Those are saved after each record, below.
		$done_in_batch = (int) $job->data['cursor_skip'];
		$results       = array();
		foreach ( array_slice( $batch['records'], $done_in_batch ) as $record ) {
			self::tally( $job, $record );
			try {
				$result = MigratePlus_Importer::import( $record, $job );
			} catch ( Throwable $e ) {
				$result = array(
					'action'  => 'failed',
					'post_id' => 0,
					'message' => $e->getMessage(),
					'warning' => false,
				);
			}
			++$job->data['stats']['processed'];
			++$job->data['stats'][ $result['action'] ];
			if ( 'created' === $result['action'] && empty( $job->data['options']['dry_run'] ) ) {
				++$job->data['created_total'];
			}
			if ( ! empty( $result['comments'] ) ) {
				$job->data['comments_total'] += (int) $result['comments'];
			}
			$level = 'failed' === $result['action'] ? 'error' : ( ! empty( $result['warning'] ) ? 'warning' : ( 'skipped' === $result['action'] ? 'info' : 'success' ) );
			$job->log( $level, $result['message'] );
			$results[] = $result;

			// Saved as each record finishes, so a request that dies part way through does not do the
			// batch again. Each save also renews the lock, so a slow batch keeps it; one that lost it
			// anyway (a retry took over) stops here rather than save over the retry's progress.
			$job->data['cursor_skip'] = ++$done_in_batch;
			$lock                     = self::checkpoint( $job, $lock );
			if ( ! $lock ) {
				wp_defer_term_counting( false );
				wp_defer_comment_counting( false );
				return new WP_Error( 'migrateplus_locked', __( 'This batch took too long and another request took it over.', 'migrateplus' ) );
			}
		}

		$job->data['cursor']      = $batch['next'];
		$job->data['cursor_skip'] = 0;
		$done                     = null === $batch['next'] || empty( $batch['records'] );
		if ( $done ) {
			// Menus and the other side sections that point at the records come after them, in requests of their own.
			$done = ! self::advance( $job, 'records' );
		}
		if ( ! self::finish_batch( $job, $lock, $done ) ) {
			return new WP_Error( 'migrateplus_locked', __( 'This batch took too long and another request took it over.', 'migrateplus' ) );
		}

		return array(
			'done'    => $done,
			'results' => $results,
		);
	}

	/**
	 * One batch of a side stage (terms, users, ACF options, site settings or menus).
	 *
	 * @param MigratePlus_Job $job   Job.
	 * @param string          $stage Side stage, a key of side_stages().
	 * @param string          $lock  Lock token.
	 * @return array|WP_Error { done: bool, results: array[] }
	 */
	protected static function run_side( MigratePlus_Job $job, $stage, $lock ) {
		$side = self::side_stages()[ $stage ];
		try {
			$out = call_user_func( array( $side['class'], 'import_batch' ), $job );
		} catch ( Throwable $e ) {
			$out = array(
				'done'    => true,
				'results' => array(
					array(
						'action'  => 'failed',
						'post_id' => 0,
						'message' => $e->getMessage(),
						'warning' => false,
						'section' => $side['type'],
					),
				),
			);
		}
		foreach ( $out['results'] as $result ) {
			$job->log( 'failed' === $result['action'] ? 'error' : ( ! empty( $result['warning'] ) ? 'warning' : ( 'skipped' === $result['action'] ? 'info' : 'success' ) ), $result['message'] );
		}
		foreach ( $job->data['sections'] as $i => $section ) {
			if ( $side['type'] === $section['type'] ) {
				$job->data['sections'][ $i ]['done'] = $out['done'] ? $section['total'] : min( $section['total'], $section['done'] + count( $out['results'] ) );
			}
		}
		$done = false;
		if ( $out['done'] ) {
			$job->data['stage_pos'] = 0;
			$done                   = ! self::advance( $job, $stage );
		}
		if ( ! self::finish_batch( $job, $lock, $done ) ) {
			return new WP_Error( 'migrateplus_locked', __( 'This batch took too long and another request took it over.', 'migrateplus' ) );
		}
		return array(
			'done'    => $done,
			'results' => $out['results'],
		);
	}

	/**
	 * Move a job to the stage after $current. Side sections the file carries whose
	 * import option is off are logged as left out as the job passes them.
	 *
	 * @param MigratePlus_Job $job     Job (stage is updated in place).
	 * @param string          $current Stage that just finished.
	 * @return bool False when no stage is left: the import is done.
	 */
	protected static function advance( MigratePlus_Job $job, $current = '' ) {
		$sides = self::side_stages();
		$order = self::stage_order();
		$next  = self::next_stage( $job, $current );
		$from  = '' === $current ? 0 : (int) array_search( $current, $order, true ) + 1;
		$to    = '' === $next ? count( $order ) : (int) array_search( $next, $order, true );
		$opts  = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		foreach ( array_slice( $order, $from, max( 0, $to - $from ) ) as $stage ) {
			if ( 'records' !== $stage && ! empty( $job->data['settings'][ $stage . '_file' ] ) && empty( $opts[ $sides[ $stage ]['option'] ] ) ) {
				$job->log( 'info', self::off_message( $stage ) );
			}
		}
		$job->data['stage'] = '' === $next ? 'records' : $next;
		return '' !== $next;
	}

	/**
	 * Save a job after a batch, completing it when it is done, and release its lock.
	 *
	 * @param MigratePlus_Job $job  Job.
	 * @param string          $lock Lock token.
	 * @param bool            $done Whether the import has finished.
	 * @return bool False when the lock was lost and nothing was saved.
	 */
	protected static function finish_batch( MigratePlus_Job $job, $lock, $done ) {
		if ( $done ) {
			$job->data['status'] = 'complete';
			if ( empty( $job->data['options']['dry_run'] ) ) {
				// Saved rules can predate the post type the content went into, and its items would 404
				// until someone saves permalinks. Soft: rebuilds the rules, leaves .htaccess alone.
				flush_rewrite_rules( false );
			}
			$s = $job->data['stats'];
			$job->log(
				'info',
				sprintf(
					empty( $job->data['options']['dry_run'] )
						/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
						? __( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'migrateplus' )
						/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
						: __( 'Dry run finished, nothing was saved: %1$d would be created, %2$d updated, %3$d skipped, %4$d failed.', 'migrateplus' ),
					$s['created'],
					$s['updated'],
					$s['skipped'],
					$s['failed']
				)
			);
		}
		$lock = self::checkpoint( $job, $lock );

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( ! $lock ) {
			return false;
		}
		self::unlock( $job, $lock );
		return true;
	}

	/**
	 * Save a job part way through a batch, if its lock is still ours, and renew the lock
	 * so a batch that keeps making progress never looks abandoned (see LOCK_TTL).
	 *
	 * @param MigratePlus_Job $job   Job.
	 * @param string          $token Token from lock().
	 * @return string|false The renewed token, or false when another request holds the lock now.
	 */
	protected static function checkpoint( MigratePlus_Job $job, $token ) {
		global $wpdb;
		$renewed = ( time() + self::LOCK_TTL ) . ':' . wp_generate_password( 8, false );
		$held    = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $renewed, 'migrateplus_lock_' . $job->data['id'], $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $held ) {
			return false;
		}
		$job->save();
		return $renewed;
	}

	/**
	 * Delete a batch of posts (and their imported media) created by a job.
	 *
	 * @param MigratePlus_Job $job   Job.
	 * @param int             $limit Posts per batch.
	 * @return array { deleted: int, remaining: int, done: bool, error: string }
	 */
	public static function rollback_batch( MigratePlus_Job $job, $limit = 25 ) {
		$lock = self::lock( $job );
		if ( ! $lock ) {
			return array(
				'deleted'   => 0,
				'remaining' => $job->imported_count(),
				'done'      => true,
				'error'     => __( 'An import batch for this job is running. Try again when it finishes.', 'migrateplus' ),
			);
		}
		$cli     = defined( 'WP_CLI' ) && WP_CLI;
		$deleted = 0;
		$denied  = 0;
		$offset  = 0; // Posts left in place (not allowed, or the delete failed) stay tagged: page past them.
		do {
			$ids  = $job->imported_ids( $limit, $offset );
			$full = count( $ids ) === (int) $limit;
			foreach ( $ids as $id ) {
				// A type no longer registered has no capabilities of its own, and WordPress complains when
				// asked about one (then checks edit_others_posts): ask for deleting other people's posts.
				$may = post_type_exists( (string) get_post_type( $id ) ) ? current_user_can( 'delete_post', $id ) : current_user_can( 'delete_others_posts' );
				if ( ! $cli && ! $may ) {
					++$denied;
					++$offset;
					continue;
				}
				$type = (string) get_post_type( $id );
				if ( 'attachment' === $type ) {
					$ok = wp_delete_attachment( $id, true );
				} elseif ( 0 === strpos( $type, 'acf-' ) && MigratePlus_ACF_Definitions::delete( $id ) ) {
					$ok = true; // Through ACF, so a field group's fields go with it.
				} else {
					$ok = wp_delete_post( $id, true );
				}
				if ( $ok ) {
					++$deleted;
				} else {
					++$offset;
				}
			}
			// Page on only until something is deleted, so each batch either makes progress or proves none is possible.
		} while ( 0 === $deleted && $full );
		// Comments it added to posts it only updated. Those on deleted posts went with them.
		$offset = 0;
		do {
			$ids  = $job->imported_comments( $limit, $offset );
			$full = count( $ids ) === (int) $limit;
			foreach ( $ids as $id ) {
				if ( ! $cli && ! current_user_can( 'edit_comment', $id ) ) {
					++$denied;
					++$offset;
					continue;
				}
				if ( wp_delete_comment( $id, true ) ) {
					++$deleted;
				} else {
					++$offset;
				}
			}
		} while ( 0 === $deleted && $full );
		// Terms it created, once the posts that used them are gone. Children come first,
		// so a parent the user may not delete never strands its child.
		if ( 0 === $deleted ) {
			foreach ( $job->imported_terms() as $id ) {
				$term = get_term( $id );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$tax = get_taxonomy( $term->taxonomy );
				if ( ! $cli && ( ! $tax || ! current_user_can( $tax->cap->delete_terms ) ) ) {
					++$denied;
					continue;
				}
				if ( true === wp_delete_term( $id, $term->taxonomy ) ) {
					++$deleted;
				}
			}
		}
		// Menus it created, once their items are gone (any items added to them by hand go with them).
		if ( 0 === $deleted ) {
			foreach ( $job->imported_menus() as $id ) {
				if ( ! $cli && ! current_user_can( 'edit_theme_options' ) ) {
					++$denied;
					continue;
				}
				if ( wp_delete_nav_menu( $id ) ) {
					++$deleted;
				}
			}
		}
		// Orders it created, with their refunds and notes.
		if ( 0 === $deleted ) {
			foreach ( $job->imported_orders() as $id ) {
				if ( ! $cli && ! current_user_can( 'manage_woocommerce' ) ) {
					++$denied;
					continue;
				}
				if ( MigratePlus_Orders::delete( $id ) ) {
					++$deleted;
				}
			}
		}
		// Users it created, last: the posts and orders that were theirs are gone by now.
		if ( 0 === $deleted ) {
			foreach ( $job->imported_users() as $id ) {
				if ( ! $cli && ! current_user_can( 'delete_users' ) ) {
					++$denied;
					continue;
				}
				if ( MigratePlus_Users::delete( $id ) ) {
					++$deleted;
				}
			}
		}
		$remaining = $job->imported_count();
		$error     = '';
		if ( 0 === $remaining ) {
			MigratePlus_Menus::restore_locations( $job );
			// Settings and values the import replaced go back to what they were.
			MigratePlus_Site::restore( $job );
			MigratePlus_ACF_Options::restore( $job );
			MigratePlus_ACF_Definitions::restore( $job );
			MigratePlus_Redirects::forget_job( $job->data['id'] );
			$job->data['status']         = 'rolled_back';
			$job->data['cursor']         = null;
			$job->data['cursor_skip']    = 0;
			$job->data['created_total']  = 0;
			$job->data['comments_total'] = 0;
			$job->log( 'info', __( 'Rollback complete: imported content was deleted.', 'migrateplus' ) );
		} elseif ( 0 === $deleted ) {
			// Nothing could be deleted, so another batch would only fail again: stop without claiming success.
			/* translators: %d: number of items */
			$error = sprintf( __( 'Rollback stopped: %d imported items could not be deleted.', 'migrateplus' ), $remaining );
			$job->log( 'error', $error );
			if ( $denied ) {
				/* translators: %d: number of items */
				$job->log( 'warning', sprintf( _n( '%d of them was kept because you are not allowed to delete it.', '%d of them were kept because you are not allowed to delete them.', $denied, 'migrateplus' ), $denied ) );
			}
		}
		$job->save();
		self::unlock( $job, $lock );
		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => 0 === $remaining || '' !== $error,
			'error'     => $error,
		);
	}

	/**
	 * Take a job's batch lock, then reload the job so changes saved while waiting
	 * are not overwritten. The insert either adds the row or fails in one query,
	 * so two requests can't both get it (add_option() upserts, so it can't do this).
	 *
	 * @param MigratePlus_Job $job Job (reloaded in place).
	 * @return string|false Token for unlock(), or false while another request holds the lock.
	 */
	public static function lock( MigratePlus_Job $job ) {
		global $wpdb;
		$name  = 'migrateplus_lock_' . $job->data['id'];
		$token = ( time() + self::LOCK_TTL ) . ':' . wp_generate_password( 8, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the options API has no atomic insert.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );
		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' !== $held && (int) $held < time() ) {
				// Expired: take it over, unless another request just did.
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );
			}
		}
		// phpcs:enable
		if ( ! $taken ) {
			return false;
		}
		// This request may have cached the job before another one saved it.
		wp_cache_delete( 'migrateplus_job_' . $job->data['id'], 'options' );
		$fresh = MigratePlus_Job::load( $job->data['id'] );
		if ( $fresh ) {
			$job->data = $fresh->data;
		}
		return $token;
	}

	/**
	 * Release a lock taken with lock() (only if it is still ours).
	 *
	 * @param MigratePlus_Job $job   Job.
	 * @param string          $token Token from lock().
	 */
	public static function unlock( MigratePlus_Job $job, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'migrateplus_lock_' . $job->data['id'], $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
