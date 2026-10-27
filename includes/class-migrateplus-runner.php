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
			// No job will be made to delete the caches prepare() wrote, which copy the whole file.
			MigratePlus_Job::delete_cache_files( $prepared );
			return $batch;
		}
		// An export of menus (or terms, settings...) alone has no items: the import goes straight to its side sections.
		$sides = 0;
		foreach ( self::side_stages() as $side ) {
			$sides += empty( $prepared[ $side['count'] ] ) ? 0 : (int) $prepared[ $side['count'] ];
		}
		if ( empty( $batch['records'] ) && ! $sides ) {
			MigratePlus_Job::delete_cache_files( $prepared );
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
	 * ACF definitions come first, so the taxonomies and term field groups they
	 * register exist when terms arrive; terms come next, so records find them;
	 * everything that points at records comes after them. Each one's class
	 * implements pending( $job ) and
	 * import_batch( $job ) => { done: bool, results: array[] }.
	 *
	 * @return array[] stage => { class, type, label, count, option, when }
	 */
	public static function side_stages() {
		return array(
			'acf_definitions' => array(
				'class'  => 'MigratePlus_ACF_Definitions',
				'type'   => 'acf_definitions',
				'label'  => __( 'ACF definitions', 'migrateplus' ),
				'count'  => 'migrateplus_acf_definitions',
				'option' => 'import_acf_definitions',
				'when'   => 'before',
			),
			'terms'           => array(
				'class'  => 'MigratePlus_Terms',
				'type'   => 'terms',
				'label'  => __( 'Terms', 'migrateplus' ),
				'count'  => 'migrateplus_terms',
				'option' => 'import_term_data',
				'when'   => 'before',
			),
			'acf_options'     => array(
				'class'  => 'MigratePlus_ACF_Options',
				'type'   => 'acf_options',
				'label'  => __( 'ACF options', 'migrateplus' ),
				'count'  => 'migrateplus_acf_options',
				'option' => 'import_acf_options',
				'when'   => 'after',
			),
			'site_settings'   => array(
				'class'  => 'MigratePlus_Site',
				'type'   => 'site_settings',
				'label'  => __( 'Site settings', 'migrateplus' ),
				'count'  => 'migrateplus_site_settings',
				'option' => 'import_site_settings',
				'when'   => 'after',
			),
			'menus'           => array(
				'class'  => 'MigratePlus_Menus',
				'type'   => 'nav_menu',
				'label'  => __( 'Menus', 'migrateplus' ),
				'count'  => 'migrateplus_menus',
				'option' => 'import_menus',
				'when'   => 'after',
			),
			'orders'          => array(
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
			'terms'           => __( 'The file\'s terms were not imported: "Term data" is off.', 'migrateplus' ),
			'acf_definitions' => __( 'The file\'s ACF definitions were not imported: "ACF definitions" is off.', 'migrateplus' ),
			'acf_options'     => __( 'The file\'s ACF options were not imported: "ACF options" is off.', 'migrateplus' ),
			'site_settings'   => __( 'The file\'s site settings were not imported: "Site settings" is off.', 'migrateplus' ),
			'menus'           => __( 'The file\'s menus were not imported: "Import menus" is off.', 'migrateplus' ),
			'orders'          => __( 'The file\'s orders were not imported: "Orders" is off.', 'migrateplus' ),
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
	 *                        images: [{ post_id, title, kind, label, current, current_full, imported, is_image, changes }],
	 *                        items: [{ index, title, existing_title, post_id, type, kind, label, changed, changes,
	 *                        view_url, edit_url, media: null|{ current, current_full, imported, is_image } }],
	 *                        items_truncated: bool }
	 *                        items lists every duplicate (up to the migrateplus_duplicates_list_cap filter, 1000),
	 *                        in the file's order, for the screen that chooses what to do with each.
	 */
	public static function find_duplicates( MigratePlus_Job $job, $examples = 5 ) {
		$source = $job->source();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( function_exists( 'set_time_limit' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$out                    = array(
			'total'    => 0,
			'count'    => 0,
			'before'   => 0,
			'existing' => 0,
			'examples' => array(),
			'changed'  => array(), // Duplicates whose content differs: the user reviews these before updating.
			'images'   => array(), // Media duplicates, shown side by side.
			'items'    => array(),
		);
		$cap                    = max( 0, (int) apply_filters( 'migrateplus_duplicates_list_cap', 1000 ) );
		$out['items_truncated'] = false;
		$cursor                 = null;
		do {
			$batch = $source->fetch( $cursor, 200 );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			foreach ( $batch['records'] as $record ) {
				// The record's position in the file, numbered as run_batch() numbers it.
				$index = $out['total'];
				$match = MigratePlus_Importer::find_duplicate( $record, $job, $index );
				++$out['total'];
				if ( ! $match ) {
					continue;
				}
				++$out['count'];
				++$out[ $match['kind'] ];
				$title = html_entity_decode( get_the_title( $match['post_id'] ), ENT_QUOTES, 'UTF-8' );
				if ( count( $out['items'] ) < $cap ) {
					$out['items'][] = self::duplicate_item( $record, $job, $index, $match, $title );
				} else {
					$out['items_truncated'] = true;
				}
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
	 * One duplicate for the list find_duplicates() returns.
	 *
	 * @param array           $record Flat record.
	 * @param MigratePlus_Job $job    Job.
	 * @param int             $index  The record's position in the file.
	 * @param array           $match  What MigratePlus_Importer::find_duplicate() found.
	 * @param string          $title  The title of the item on this site.
	 * @return array
	 */
	protected static function duplicate_item( array $record, MigratePlus_Job $job, $index, array $match, $title ) {
		$type    = get_post_type_object( get_post_type( $match['post_id'] ) );
		$changes = empty( $match['image'] ) ? (array) $match['changes'] : (array) $match['image']['changes'];
		// Products carry more than changes_for() reads, so their rows show no Differs or Unchanged.
		$compared = ! empty( $match['image'] ) || ! MigratePlus_Woo::is_product_type( get_post_type( $match['post_id'] ) );
		$media    = empty( $match['image'] ) ? null : array_intersect_key( $match['image'], array_flip( array( 'current', 'current_full', 'imported', 'is_image' ) ) );
		$view     = get_permalink( $match['post_id'] );
		$edit     = current_user_can( 'edit_post', $match['post_id'] ) ? get_edit_post_link( $match['post_id'], 'raw' ) : '';
		return array(
			'index'          => $index,
			'title'          => self::record_title( $record, (array) $job->data['mapping'], $index ),
			'existing_title' => $title,
			'post_id'        => $match['post_id'],
			'type'           => $type ? $type->labels->singular_name : '',
			'kind'           => $match['kind'],
			'label'          => $match['label'],
			'changed'        => (bool) $changes,
			'compared'       => $compared,
			'changes'        => $changes,
			'view_url'       => $view ? $view : '',
			'edit_url'       => $edit ? $edit : '',
			'media'          => $media,
			'author'         => MigratePlus_Importer::source_author( $record, (array) $job->data['mapping'] )['key'],
		);
	}

	/**
	 * The most records a title search reads, so a search of a very large file answers in time.
	 */
	const RECORDS_SEARCH_LIMIT = 5000;

	/**
	 * A page of a job's records, each with its position in the file (as run_batch() numbers
	 * them), its title and the post type it imports as. With a search, only records whose
	 * title holds it (in any case), read from the start up to RECORDS_SEARCH_LIMIT records.
	 *
	 * @param MigratePlus_Job $job     Job.
	 * @param array           $mapping Mapping the titles and types follow.
	 * @param int             $offset  Records (or matches) to pass over.
	 * @param int             $limit   At most this many.
	 * @param string          $search  Part of a title, or '' for every record.
	 * @return array|WP_Error { records: array[] { index, title, type }, total, matched, truncated }
	 */
	public static function list_records( MigratePlus_Job $job, array $mapping, $offset, $limit, $search = '' ) {
		$source = $job->source();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$view                  = clone $job;
		$view->data['mapping'] = $mapping;
		$total                 = null === $job->data['total'] ? null : (int) $job->data['total'];
		$out                   = array(
			'records'   => array(),
			'total'     => $total,
			'matched'   => 0,
			'truncated' => false,
		);
		$row                   = static function ( $record, $index ) use ( $view, $mapping ) {
			return array(
				'index'  => $index,
				'title'  => self::record_title( $record, $mapping, $index ),
				'type'   => MigratePlus_Importer::type_for( $record, $view ),
				'author' => MigratePlus_Importer::source_author( $record, $mapping )['key'],
			);
		};
		if ( '' === $search ) {
			$batch = self::fetch_from( $source, $offset, $limit );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			foreach ( array_values( $batch['records'] ) as $i => $record ) {
				$out['records'][] = $row( $record, $offset + $i );
			}
			$out['total']   = null !== $total ? $total : ( isset( $batch['total'] ) ? (int) $batch['total'] : null );
			$out['matched'] = (int) $out['total'];
			return $out;
		}
		$index  = 0;
		$cursor = null;
		do {
			$batch = $source->fetch( $cursor, 100 );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			foreach ( $batch['records'] as $record ) {
				if ( $index >= self::RECORDS_SEARCH_LIMIT ) {
					$out['truncated'] = true;
					break 2;
				}
				$title = self::record_title( $record, $mapping, $index );
				// mbstring is optional in PHP.
				if ( false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( $title, $search ) : stripos( $title, $search ) ) ) {
					if ( $out['matched'] >= $offset && count( $out['records'] ) < $limit ) {
						$out['records'][] = $row( $record, $index );
					}
					++$out['matched'];
				}
				++$index;
			}
			$cursor = $batch['next'];
		} while ( null !== $cursor && ! empty( $batch['records'] ) );
		return $out;
	}

	/**
	 * A batch of records from a position in the file. A JSON file's cursor is that position,
	 * so its batch is read straight away; another source's cursor is its own, so its batches
	 * are read from the start.
	 *
	 * @param MigratePlus_Source $source Source.
	 * @param int                $offset Position of the first record.
	 * @param int                $limit  At most this many.
	 * @return array|WP_Error As MigratePlus_Source::fetch().
	 */
	protected static function fetch_from( MigratePlus_Source $source, $offset, $limit ) {
		if ( $source instanceof MigratePlus_Source_JSON || 0 === $offset ) {
			return $source->fetch( $offset ? $offset : null, $limit );
		}
		$seen   = 0;
		$cursor = null;
		$out    = array(
			'records' => array(),
			'next'    => null,
			'total'   => null,
		);
		do {
			$batch = $source->fetch( $cursor, 100 );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
			$from           = max( 0, $offset - $seen );
			$out['records'] = array_merge( $out['records'], array_slice( $batch['records'], $from, $limit - count( $out['records'] ) ) );
			$out['total']   = $batch['total'];
			$seen          += count( $batch['records'] );
			$cursor         = $batch['next'];
			$short          = count( $out['records'] ) < $limit;
		} while ( null !== $cursor && ! empty( $batch['records'] ) && $short );
		return $out;
	}

	/**
	 * A record's title as the mapping reads it (the "title" field without one), as plain
	 * text, or "(no title)" with its position.
	 *
	 * @param array $record  Flat record.
	 * @param array $mapping Mapping.
	 * @param int   $index   The record's position in the file.
	 * @return string
	 */
	public static function record_title( array $record, array $mapping, $index ) {
		$field = empty( $mapping['fields']['post_title'] ) ? 'title' : (string) $mapping['fields']['post_title'];
		$title = isset( $record[ $field ] ) ? MigratePlus_Util::stringify( $record[ $field ] ) : '';
		$title = trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) );
		/* translators: %d: the record's number in the file */
		return '' !== $title ? $title : sprintf( __( '(no title) #%d', 'migrateplus' ), $index + 1 );
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
		try {
			return self::run_stage( $job, $source, $lock );
		} finally {
			// Also when the batch threw: counting would stay deferred, and the job locked for LOCK_TTL.
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			if ( $lock ) {
				self::unlock( $job, $lock );
			}
		}
	}

	/**
	 * One batch of the stage a locked job is on.
	 *
	 * @param MigratePlus_Job    $job    Job.
	 * @param MigratePlus_Source $source The job's source.
	 * @param string|false       $lock   Lock token, renewed in place as records finish; false once lost.
	 * @return array|WP_Error { done: bool, results: array[] }
	 */
	protected static function run_stage( MigratePlus_Job $job, MigratePlus_Source $source, &$lock ) {
		$stage = (string) $job->data['stage'];
		if ( '' !== $stage && 'records' !== $stage && ! isset( self::side_stages()[ $stage ] ) ) {
			// Left by an older version on a section it no longer has (users). The sections run again from the
			// start; what they imported before is found by its source id and skipped.
			$job->log( 'info', __( 'This import was paused on a section this version no longer imports. It continues with the next one.', 'migrateplus' ) );
			$job->data['stage']     = '';
			$job->data['stage_pos'] = 0;
		}
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
				// Records processed before this one give its position in the file: the job runs
				// through the file in order, and a cut-short batch resumes past those it counted.
				$result = MigratePlus_Importer::import( $record, $job, (int) $job->data['stats']['processed'] );
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
			$level = 'failed' === $result['action'] ? 'error' : ( ! empty( $result['warning'] ) ? 'warning' : ( 'skipped' === $result['action'] ? 'skipped' : 'success' ) );
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
			// Field groups shown on given pages can only point at them now the pages are here.
			MigratePlus_ACF_Definitions::relink_locations( $job );
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
	 * One batch of a side stage (terms, ACF options, site settings, menus or orders).
	 *
	 * @param MigratePlus_Job $job   Job.
	 * @param string          $stage Side stage, a key of side_stages().
	 * @param string          $lock  Lock token.
	 * @return array|WP_Error { done: bool, results: array[] }
	 */
	protected static function run_side( MigratePlus_Job $job, $stage, $lock ) {
		$side  = self::side_stages()[ $stage ];
		$threw = false;
		try {
			$out                       = call_user_func( array( $side['class'], 'import_batch' ), $job );
			$job->data['side_failure'] = array();
		} catch ( Throwable $e ) {
			$threw = true;
			$out   = self::side_failure( $job, $stage, $e );
		}
		foreach ( $out['results'] as $result ) {
			if ( 'failed' === $result['action'] ) {
				// Counted with the records', so the finished line and WP-CLI's exit status report them.
				++$job->data['stats']['failed'];
			}
			$job->log( 'failed' === $result['action'] ? 'error' : ( ! empty( $result['warning'] ) ? 'warning' : ( 'skipped' === $result['action'] ? 'skipped' : 'success' ) ), $result['message'] );
		}
		foreach ( $job->data['sections'] as $i => $section ) {
			if ( $side['type'] === $section['type'] ) {
				$job->data['sections'][ $i ]['done'] = $out['done'] ? $section['total'] : min( $section['total'], $section['done'] + ( $threw ? 0 : count( $out['results'] ) ) );
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
	 * What a side section's batch that threw comes to. The section stays the stage, so
	 * the next batch tries again from where its class last saved its place (past the
	 * items it finished). Only when it throws again at that same place is the rest of
	 * the section given up on, so a fault that keeps recurring can't loop forever.
	 *
	 * @param MigratePlus_Job $job   Job (side_failure is updated in place).
	 * @param string          $stage Side stage.
	 * @param Throwable       $e     What was thrown.
	 * @return array { done: bool, results: array[] }
	 */
	protected static function side_failure( MigratePlus_Job $job, $stage, Throwable $e ) {
		$side  = self::side_stages()[ $stage ];
		$pos   = (int) $job->data['stage_pos'];
		$last  = (array) $job->data['side_failure'];
		$again = isset( $last['stage'], $last['pos'] ) && $stage === $last['stage'] && $pos === (int) $last['pos'];

		$job->data['side_failure'] = $again ? array() : array(
			'stage' => $stage,
			'pos'   => $pos,
		);
		return array(
			'done'    => $again,
			'results' => array(
				array(
					'action'  => 'failed',
					'post_id' => 0,
					'message' => sprintf(
						$again
							/* translators: 1: section (Terms, Orders...), 2: error */
							? __( '%1$s stopped at the same place again, so the rest of them were left out: %2$s', 'migrateplus' )
							/* translators: 1: section (Terms, Orders...), 2: error */
							: __( '%1$s stopped: %2$s The next batch tries again from where it stopped.', 'migrateplus' ),
						$side['label'],
						$e->getMessage()
					),
					'warning' => false,
					'section' => $side['type'],
				),
			),
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
		// The redirects the batch's posts added are written once per batch, not held until shutdown.
		MigratePlus_Redirects::flush();
		$lock = self::checkpoint( $job, $lock );

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( ! $lock ) {
			return false;
		}
		if ( $done && empty( $job->data['options']['dry_run'] ) ) {
			// Only once the job is saved as complete. A dry run keeps them: "Run the real import" goes on from it.
			$job->delete_caches();
			$job->save();
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
		// Once every post and comment is gone, the settings and values the import replaced go
		// back to what they were, before its terms go: the site settings may have made one of
		// them the default category, which WordPress refuses to delete.
		if ( 0 === $deleted && ! $job->imported_ids( 1 ) && ! $job->imported_comments( 0, 0, true ) ) {
			MigratePlus_Site::restore( $job );
			MigratePlus_ACF_Options::restore( $job );
			MigratePlus_ACF_Definitions::restore( $job );
		}
		// Terms it created, once the posts that used them are gone. Children come first,
		// so a parent the user may not delete never strands its child.
		if ( 0 === $deleted ) {
			foreach ( $job->imported_terms() as $id => $taxonomy ) {
				$ok = self::delete_imported_term( $id, $taxonomy, $cli );
				if ( null === $ok ) {
					++$denied;
				} elseif ( $ok ) {
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
		$remaining = $job->imported_count();
		$error     = '';
		if ( 0 === $remaining ) {
			MigratePlus_Menus::restore_locations( $job );
			// Settings and values the import replaced, when this batch deleted the last posts
			// and they were not put back before the terms (it does nothing when they were).
			MigratePlus_Site::restore( $job );
			MigratePlus_ACF_Options::restore( $job );
			MigratePlus_ACF_Definitions::restore( $job );
			MigratePlus_Redirects::forget_job( $job->data['id'] );
			$job->delete_caches();
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
	 * Delete a term a job created, if the user may. A taxonomy no longer registered (an
	 * ACF taxonomy whose definition the rollback deleted) is registered for the moment
	 * it takes, since WordPress deletes terms of registered taxonomies only; deleting
	 * its terms then is a matter of other people's posts, as for a type no longer there.
	 *
	 * @param int    $id       Term id.
	 * @param string $taxonomy Its taxonomy, as stored.
	 * @param bool   $cli      WP-CLI, which runs with the shell account's access.
	 * @return bool|null Whether it was deleted; null when the user may not delete it.
	 */
	protected static function delete_imported_term( $id, $taxonomy, $cli ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			if ( ! $cli && ! current_user_can( get_taxonomy( $taxonomy )->cap->delete_terms ) ) {
				return null;
			}
			return true === wp_delete_term( $id, $taxonomy );
		}
		if ( ! $cli && ! current_user_can( 'delete_others_posts' ) ) {
			return null;
		}
		register_taxonomy( $taxonomy, array(), array( 'public' => false ) );
		$deleted = true === wp_delete_term( $id, $taxonomy );
		unregister_taxonomy( $taxonomy );
		return $deleted;
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
