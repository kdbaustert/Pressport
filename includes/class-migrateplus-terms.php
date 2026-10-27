<?php
/**
 * Taxonomy terms in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports the terms of the taxonomies attached to the exported post types, with
 * their descriptions, slugs, hierarchy, term meta and ACF fields, including
 * terms no post uses, and rebuilds them before the records of an import, so
 * posts land in terms that already carry their data.
 *
 * A term is matched by the uid it was imported with, then by its "Parent >
 * Child" path, then by its slug, so importing the same file again updates
 * terms rather than copying them. Terms the import creates carry
 * _migrateplus_job_id for rollback; terms that already existed are updated
 * but never deleted by a rollback.
 *
 * A product category's thumbnail travels as a URL under "image" and is
 * downloaded into the Media Library on import.
 */
class MigratePlus_Terms {

	/**
	 * Term meta holding the uid of a parent that was not imported yet.
	 *
	 * @var string
	 */
	const PARENT_META = '_migrateplus_parent_uid';

	/**
	 * Every term of the taxonomies on the exported post types, parents before
	 * children, as portable data.
	 *
	 * @param array $args Export args (post_types, exclude_meta, include_acf).
	 * @return array[] { id, taxonomy, name, slug, path, parent, description, meta, acf, image? }
	 */
	public static function export( array $args ) {
		$out = array();
		foreach ( self::taxonomies_for( $args['post_types'] ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! self::user_can( $tax->cap->manage_terms ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( self::parents_first( $terms ) as $term ) {
				$out[] = self::export_term( $term, $tax, $args );
			}
		}
		return $out;
	}

	/**
	 * One page of the terms export() returns, taxonomy by taxonomy, for an export
	 * written a page at a time. A page is ordered parents first, but a parent can
	 * sit on an earlier or a later page than its child: the import links a child
	 * to a parent that arrives after it (see adopt_waiting_children()).
	 *
	 * @param array $args   Export args (post_types, exclude_meta, include_acf).
	 * @param mixed $cursor { tax, offset } from the last batch, or null for the first.
	 * @param int   $limit  Terms per page.
	 * @return array { items: array[], next: array|null }
	 */
	public static function export_batch( array $args, $cursor, $limit ) {
		$limit  = max( 1, (int) $limit );
		$taxes  = self::taxonomies_for( $args['post_types'] );
		$index  = is_array( $cursor ) && isset( $cursor['tax'] ) ? (int) $cursor['tax'] : 0;
		$offset = is_array( $cursor ) && isset( $cursor['offset'] ) ? (int) $cursor['offset'] : 0;
		$total  = count( $taxes );
		$items  = array();
		$room   = $limit;
		while ( $index < $total && $room > 0 ) {
			$tax = get_taxonomy( $taxes[ $index ] );
			if ( ! $tax || ! self::user_can( $tax->cap->manage_terms ) ) {
				++$index;
				$offset = 0;
				continue;
			}
			$want  = $room;
			$terms = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
					'number'     => $want,
					'offset'     => $offset,
				)
			);
			$terms = is_wp_error( $terms ) ? array() : $terms;
			foreach ( self::parents_first( $terms ) as $term ) {
				$items[] = self::export_term( $term, $tax, $args );
			}
			$room   -= count( $terms );
			$offset += count( $terms );
			if ( count( $terms ) < $want ) {
				++$index;
				$offset = 0;
			}
		}
		return array(
			'items' => $items,
			'next'  => $index < $total ? array(
				'tax'    => $index,
				'offset' => $offset,
			) : null,
		);
	}

	/**
	 * How many terms an export with these args would carry.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) {
		$total = 0;
		foreach ( self::taxonomies_for( $args['post_types'] ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! self::user_can( $tax->cap->manage_terms ) ) {
				continue;
			}
			$n      = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$total += is_wp_error( $n ) ? 0 : (int) $n;
		}
		return $total;
	}

	/**
	 * The taxonomies the export's post types use, as export_post() lists terms:
	 * public or shown in the admin, post formats left out.
	 *
	 * @param string[] $post_types Post types.
	 * @return string[]
	 */
	protected static function taxonomies_for( array $post_types ) {
		$out = array();
		foreach ( $post_types as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
				if ( 'post_format' === $tax->name || ( ! $tax->public && ! $tax->show_ui ) ) {
					continue;
				}
				$out[ $tax->name ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Terms ordered so every parent comes before its children, whatever order
	 * get_terms() returned.
	 *
	 * @param WP_Term[] $terms Terms of one taxonomy.
	 * @return WP_Term[]
	 */
	protected static function parents_first( array $terms ) {
		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		$out   = array();
		$queue = isset( $by_parent[0] ) ? $by_parent[0] : array();
		while ( $queue ) {
			$term  = array_shift( $queue );
			$out[] = $term;
			if ( isset( $by_parent[ $term->term_id ] ) ) {
				$queue = array_merge( $queue, $by_parent[ $term->term_id ] );
			}
		}
		// Orphans of a parent get_terms() did not return (a filtered list) still export, at the end.
		return count( $out ) === count( $terms ) ? $out : array_merge(
			$out,
			array_udiff(
				$terms,
				$out,
				static function ( $a, $b ) {
					return $a->term_id - $b->term_id;
				}
			)
		);
	}

	/**
	 * One term as a portable record.
	 *
	 * @param WP_Term     $term Term.
	 * @param WP_Taxonomy $tax  Its taxonomy.
	 * @param array       $args Export args.
	 * @return array
	 */
	protected static function export_term( WP_Term $term, $tax, array $args ) {
		$item = array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'slug'        => $term->slug,
			'path'        => MigratePlus_Exporter::term_path( $term, $tax->hierarchical ),
			'parent'      => (int) $term->parent,
			'description' => $term->description,
			'meta'        => array(),
			'acf'         => array(),
		);

		$acf_names = array();
		if ( ! empty( $args['include_acf'] ) && MigratePlus_ACF::active() ) {
			foreach ( MigratePlus_ACF::fields_for_taxonomy( $term->taxonomy ) as $f ) {
				$field = function_exists( 'acf_get_field' ) ? acf_get_field( $f['key'] ) : null;
				if ( ! $field ) {
					continue;
				}
				$raw = get_field( $field['key'], 'term_' . $term->term_id, false );
				if ( null === $raw || '' === $raw || ( false === $raw && 'true_false' !== $field['type'] ) ) {
					continue;
				}
				$item['acf'][ $field['name'] ] = MigratePlus_Exporter::export_acf_value( $field, $raw );
				$acf_names[ $field['name'] ]   = true;
			}
		}

		foreach ( get_term_meta( $term->term_id ) as $key => $values ) {
			// Skip private keys (ACF references, MigratePlus tracking) and ACF values exported above.
			if ( '' === $key || '_' === $key[0] || isset( $acf_names[ $key ] ) || is_protected_meta( $key, 'term' ) ) {
				continue;
			}
			if ( ! empty( $args['exclude_meta'] ) && MigratePlus_Exporter::meta_excluded( $key, $args['exclude_meta'] ) ) {
				continue;
			}
			if ( 'thumbnail_id' === $key ) {
				// A WooCommerce category image: the id means nothing elsewhere, so it travels as a URL.
				$url = wp_get_attachment_url( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) );
				if ( $url ) {
					$item['image'] = $url;
				}
				continue;
			}
			if ( MigratePlus_ACF::active() && 0 === strpos( (string) get_term_meta( $term->term_id, '_' . $key, true ), 'field_' ) ) {
				continue; // Sub-field rows of an ACF repeater/group on the term.
			}
			$rows                 = array_map( 'maybe_unserialize', $values );
			$item['meta'][ $key ] = count( $rows ) > 1 ? array( '_migrateplus_rows' => $rows ) : $rows[0];
		}

		if ( ! empty( $args['include_yoast'] ) && MigratePlus_Yoast::active() ) {
			$seo = MigratePlus_Yoast::export_term( $term );
			if ( $seo ) {
				$item['yoast'] = $seo;
			}
		}

		return apply_filters( 'migrateplus_export_term', $item, $term, $args );
	}

	/**
	 * Whether a job still has terms to import. They come before its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['terms_file'] ) && ! empty( $job->data['options']['import_term_data'] );
	}

	/**
	 * The terms a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['terms_file'] ) ? (string) $job->data['settings']['terms_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_terms_cache', __( 'The cached terms are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * Import the next batch of the job's terms, resuming at stage_pos.
	 *
	 * @param MigratePlus_Job $job Job (stage_pos and created_total are updated in place).
	 * @return array { done: bool, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$terms = self::load( $job );
		if ( is_wp_error( $terms ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', 0, $terms->get_error_message() ) ),
			);
		}
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$limit   = max( 1, min( 100, (int) $opts['batch_size'] ) );
		$pos     = (int) $job->data['stage_pos'];
		$batch   = array_slice( $terms, $pos, $limit );
		$results = array();
		foreach ( $batch as $record ) {
			if ( is_array( $record ) ) {
				$results[] = self::import_term( $record, $job, $opts );
			}
			++$pos;
		}
		$job->data['stage_pos'] = $pos;
		return array(
			'done'    => $pos >= count( $terms ),
			'results' => $results,
		);
	}

	/**
	 * Create or update one term from its exported record.
	 *
	 * @param array           $record Term as exported.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import_term( array $record, MigratePlus_Job $job, array $opts ) {
		$dry      = ! empty( $opts['dry_run'] );
		$taxonomy = isset( $record['taxonomy'] ) ? sanitize_key( MigratePlus_Util::stringify( $record['taxonomy'] ) ) : '';
		$name     = isset( $record['name'] ) ? trim( MigratePlus_Util::stringify( $record['name'] ) ) : '';
		$label    = '' !== $name ? $name : __( '(unnamed term)', 'migrateplus' );
		if ( '' === $name || '' === $taxonomy ) {
			return self::result( 'failed', 0, __( 'Term without a name or taxonomy was not imported.', 'migrateplus' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			/* translators: 1: term name, 2: taxonomy */
			return self::result( 'skipped', 0, sprintf( __( 'Term "%1$s" was not imported: taxonomy "%2$s" is not registered on this site.', 'migrateplus' ), $label, $taxonomy ), true );
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( $tax->cap->manage_terms ) ) {
			/* translators: 1: term name, 2: taxonomy */
			return self::result( 'skipped', 0, sprintf( __( 'Term "%1$s" was not imported: you are not allowed to manage %2$s.', 'migrateplus' ), $label, $tax->labels->name ), true );
		}

		$uid      = self::uid( $job, $record );
		$existing = self::find_term( $record, $uid );
		if ( $existing && 'skip' === $opts['duplicate'] ) {
			/* translators: 1: term name, 2: taxonomy */
			return self::result( 'skipped', $existing, sprintf( __( 'Already here: term "%1$s" (%2$s).', 'migrateplus' ), $label, $taxonomy ) );
		}
		$parent   = $tax->hierarchical ? self::find_parent( $record, $job ) : 0;
		$warnings = array();
		// A parent not here yet may come later in the file: the term waits for it under its uid.
		$waits_for = $tax->hierarchical && ! empty( $record['parent'] ) && ! $parent ? self::uid(
			$job,
			array(
				'taxonomy' => $taxonomy,
				'id'       => $record['parent'],
			)
		) : '';
		if ( '' !== $waits_for ) {
			$warnings[] = $existing
				/* translators: %s: term name */
				? sprintf( __( 'Its parent is not on this site yet, so "%s" keeps the parent it has here until that parent is imported.', 'migrateplus' ), $label )
				/* translators: %s: term name */
				: sprintf( __( 'Its parent is not on this site yet, so "%s" sits at the top level until that parent is imported.', 'migrateplus' ), $label );
		}

		if ( $dry ) {
			/* translators: 1: Would create/Would update, 2: term name, 3: taxonomy */
			$message = sprintf( __( '%1$s term "%2$s" (%3$s).', 'migrateplus' ), $existing ? __( 'Would update', 'migrateplus' ) : __( 'Would create', 'migrateplus' ), $label, $taxonomy );
			return self::result( $existing ? 'updated' : 'created', $existing, self::with_warnings( $message, $warnings ), ! empty( $warnings ) );
		}

		$slug   = isset( $record['slug'] ) ? sanitize_title( MigratePlus_Util::stringify( $record['slug'] ) ) : '';
		$fields = array(
			'description' => isset( $record['description'] ) ? MigratePlus_Util::stringify( $record['description'] ) : '',
			'parent'      => $parent,
		);
		if ( $existing && '' !== $waits_for ) {
			unset( $fields['parent'] ); // Left out, wp_update_term() keeps the parent the term has.
		}
		if ( '' !== $slug ) {
			$fields['slug'] = $slug;
		}
		$created = false;
		if ( $existing ) {
			$updated = wp_update_term( $existing, $taxonomy, wp_slash( array_merge( $fields, array( 'name' => $name ) ) ) );
			if ( is_wp_error( $updated ) ) {
				/* translators: 1: term name, 2: error */
				return self::result( 'failed', 0, sprintf( __( 'Term "%1$s" could not be updated: %2$s', 'migrateplus' ), $label, wp_strip_all_tags( $updated->get_error_message() ) ) );
			}
			$term_id = (int) $updated['term_id'];
		} else {
			$inserted = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $fields ) );
			if ( is_wp_error( $inserted ) ) {
				$clash = $inserted->get_error_data( 'term_exists' );
				if ( ! $clash ) {
					/* translators: 1: term name, 2: error */
					return self::result( 'failed', 0, sprintf( __( 'Term "%1$s" could not be created: %2$s', 'migrateplus' ), $label, wp_strip_all_tags( $inserted->get_error_message() ) ) );
				}
				$term_id = (int) $clash; // The same name arrived twice (another slug): fill the term that is here.
			} else {
				$term_id = (int) $inserted['term_id'];
				$created = true;
				add_term_meta( $term_id, '_migrateplus_job_id', $job->data['id'] );
				++$job->data['created_total'];
			}
		}
		if ( '' !== $uid && ! get_term_meta( $term_id, '_migrateplus_source_uid', true ) ) {
			add_term_meta( $term_id, '_migrateplus_source_uid', $uid );
		}
		if ( '' !== $waits_for ) {
			update_term_meta( $term_id, self::PARENT_META, $waits_for );
		} else {
			delete_term_meta( $term_id, self::PARENT_META );
		}
		if ( $tax->hierarchical && '' !== $uid ) {
			self::adopt_waiting_children( $term_id, $taxonomy, $uid );
		}

		self::import_meta( $term_id, isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array() );
		if ( ! empty( $record['image'] ) ) {
			self::import_image( $term_id, MigratePlus_Util::stringify( $record['image'] ), $job, $warnings );
		}
		$acf = isset( $record['acf'] ) && is_array( $record['acf'] ) ? $record['acf'] : array();
		if ( $acf && MigratePlus_ACF::active() ) {
			MigratePlus_ACF::import_term_fields( $term_id, $taxonomy, $acf, $job, $opts, $warnings );
		} elseif ( $acf ) {
			$warnings[] = __( 'The term carries ACF fields but Advanced Custom Fields is not active.', 'migrateplus' );
		}
		if ( ! empty( $record['yoast'] ) && is_array( $record['yoast'] ) ) {
			MigratePlus_Yoast::import_term( $term_id, $taxonomy, $record['yoast'], $job, $opts, $warnings );
		}

		/* translators: 1: Created/Updated, 2: term name, 3: taxonomy */
		$message = sprintf( __( '%1$s term "%2$s" (%3$s).', 'migrateplus' ), $created ? __( 'Created', 'migrateplus' ) : __( 'Updated', 'migrateplus' ), $label, $taxonomy );
		return self::result( $created ? 'created' : 'updated', $term_id, self::with_warnings( $message, $warnings ), ! empty( $warnings ) );
	}

	/**
	 * A term record's "imported before" key, scoped like a post's.
	 *
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $record Term record.
	 * @return string '' without an id.
	 */
	public static function uid( MigratePlus_Job $job, array $record ) {
		if ( ! isset( $record['id'] ) || ! isset( $record['taxonomy'] ) ) {
			return '';
		}
		return MigratePlus_Importer::uid( $job, 'term:' . MigratePlus_Util::stringify( $record['taxonomy'] ) . ':' . MigratePlus_Util::stringify( $record['id'] ) );
	}

	/**
	 * The term a record names on this site: by the uid it was imported with, then
	 * by its exact slug, then by its "Parent > Child" path. Names are compared
	 * exactly along the path, and the term found by slug or path must carry the
	 * record's name, so another term that happens to share the slug is left alone:
	 * term_exists() matches the sanitized name first, so "C", "C#" and "C++"
	 * (all "c") would otherwise land on one term, renamed by each update.
	 *
	 * Menus and Yoast's primary term look terms up through here too.
	 *
	 * @param array  $record Term record: taxonomy, and any of slug, path, name.
	 * @param string $uid    From uid(), or ''.
	 * @return int 0 when it is not here.
	 */
	public static function find_term( array $record, $uid ) {
		$taxonomy = sanitize_key( MigratePlus_Util::stringify( $record['taxonomy'] ) );
		if ( '' !== $uid ) {
			$ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_key'   => '_migrateplus_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! is_wp_error( $ids ) && $ids ) {
				return (int) $ids[0];
			}
		}
		$name = isset( $record['name'] ) ? html_entity_decode( trim( MigratePlus_Util::stringify( $record['name'] ) ), ENT_QUOTES, 'UTF-8' ) : '';
		$slug = isset( $record['slug'] ) ? sanitize_title( MigratePlus_Util::stringify( $record['slug'] ) ) : '';
		$term = '' !== $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;
		if ( $term instanceof WP_Term && self::is_named( (int) $term->term_id, $taxonomy, $name ) ) {
			return (int) $term->term_id;
		}
		$path = isset( $record['path'] ) ? MigratePlus_Util::stringify( $record['path'] ) : '';
		$id   = '' !== $path ? self::term_at_path( $taxonomy, $path ) : 0;
		return $id && self::is_named( $id, $taxonomy, $name ) ? $id : 0;
	}

	/**
	 * Whether a term carries a name, compared exactly. A record without a name
	 * (a menu item may give only the slug) matches any.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name with entities decoded, or ''.
	 * @return bool
	 */
	protected static function is_named( $term_id, $taxonomy, $name ) {
		return '' === $name || html_entity_decode( (string) get_term_field( 'name', $term_id, $taxonomy, 'raw' ), ENT_QUOTES, 'UTF-8' ) === $name;
	}

	/**
	 * The term at a "Parent > Child" path, each step matched by its exact name
	 * under the step before it. A non-hierarchical taxonomy's path is the name.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $path     Path.
	 * @return int 0 when a step is not here.
	 */
	protected static function term_at_path( $taxonomy, $path ) {
		$hierarchical = is_taxonomy_hierarchical( $taxonomy );
		$parts        = $hierarchical ? array_map( 'trim', preg_split( '/\s*>\s*/', $path ) ) : array( trim( $path ) );
		$parent       = 0;
		foreach ( array_filter( $parts, 'strlen' ) as $part ) {
			$part = html_entity_decode( $part, ENT_QUOTES, 'UTF-8' );
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name'       => $part,
			);
			if ( $hierarchical ) {
				$args['parent'] = $parent;
			}
			// The query compares names as the database collation does ("c" is "C"): only an exact name counts.
			$terms = get_terms( $args );
			$found = 0;
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				if ( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) === $part ) {
					$found = (int) $term->term_id;
					break;
				}
			}
			if ( ! $found ) {
				return 0;
			}
			$parent = $found;
		}
		return $parent;
	}

	/**
	 * Terms imported before their parent wait for it: now that it is here, they move under it.
	 *
	 * @param int    $term_id  The term just imported.
	 * @param string $taxonomy Its taxonomy.
	 * @param string $uid      Its uid.
	 */
	protected static function adopt_waiting_children( $term_id, $taxonomy, $uid ) {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => self::PARENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( is_wp_error( $children ) ? array() : $children as $child ) {
			if ( (int) $child === (int) $term_id ) {
				continue;
			}
			if ( ! is_wp_error( wp_update_term( (int) $child, $taxonomy, array( 'parent' => (int) $term_id ) ) ) ) {
				delete_term_meta( (int) $child, self::PARENT_META );
			}
		}
	}

	/**
	 * The local id of a record's parent: parents come earlier in the file, so the
	 * one it names by source id was usually just imported. Older files without it
	 * fall back to the path.
	 *
	 * @param array           $record Term record.
	 * @param MigratePlus_Job $job    Job.
	 * @return int 0 for a top-level term or a parent that is not here.
	 */
	protected static function find_parent( array $record, MigratePlus_Job $job ) {
		if ( empty( $record['parent'] ) ) {
			return 0;
		}
		$taxonomy = sanitize_key( MigratePlus_Util::stringify( $record['taxonomy'] ) );
		return self::find_term(
			array(
				'taxonomy' => $taxonomy,
				'id'       => $record['parent'],
				'path'     => self::parent_path( isset( $record['path'] ) ? MigratePlus_Util::stringify( $record['path'] ) : '' ),
			),
			self::uid(
				$job,
				array(
					'taxonomy' => $taxonomy,
					'id'       => $record['parent'],
				)
			)
		);
	}

	/**
	 * "A > B > C" => "A > B".
	 *
	 * @param string $path Term path.
	 * @return string '' for a top-level term.
	 */
	protected static function parent_path( $path ) {
		$pos = strrpos( $path, ' > ' );
		return false === $pos ? '' : substr( $path, 0, $pos );
	}

	/**
	 * Replace a term's custom fields with the record's, as comment meta imports.
	 *
	 * @param int   $term_id Term id.
	 * @param array $meta    Exported meta.
	 */
	protected static function import_meta( $term_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			$key = MigratePlus_Util::stringify( $key );
			if ( '' === $key || '_' === $key[0] || 'thumbnail_id' === $key || is_protected_meta( $key, 'term' ) ) {
				continue;
			}
			$rows = is_array( $value ) && isset( $value['_migrateplus_rows'] ) && is_array( $value['_migrateplus_rows'] ) ? $value['_migrateplus_rows'] : array( $value );
			delete_term_meta( $term_id, $key );
			foreach ( $rows as $one ) {
				add_term_meta( $term_id, $key, wp_slash( $one ) );
			}
		}
	}

	/**
	 * Download a term's image (a WooCommerce category thumbnail) and point
	 * thumbnail_id at the new attachment.
	 *
	 * @param int             $term_id  Term id.
	 * @param string          $url      Image URL.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 */
	protected static function import_image( $term_id, $url, MigratePlus_Job $job, array &$warnings ) {
		$base = isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		$id   = MigratePlus_Media::sideload( $url, 0, '', $base, $job->data['id'] );
		if ( is_wp_error( $id ) ) {
			/* translators: 1: image URL, 2: error */
			$warnings[] = sprintf( __( 'Its image could not be imported (%1$s): %2$s', 'migrateplus' ), $url, $id->get_error_message() );
			return;
		}
		update_term_meta( $term_id, 'thumbnail_id', (int) $id );
	}

	/**
	 * @param string   $message  Message.
	 * @param string[] $warnings Warnings.
	 * @return string
	 */
	protected static function with_warnings( $message, array $warnings ) {
		return $warnings ? $message . ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', array_unique( $warnings ) ) : $message;
	}

	/**
	 * Whether the person exporting may do something; WP-CLI without --user runs
	 * with the shell account's access.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	protected static function user_can( $capability ) {
		return ! get_current_user_id() || current_user_can( $capability );
	}

	/**
	 * @param string $action  created|updated|skipped|failed.
	 * @param int    $term_id Term id.
	 * @param string $message Message.
	 * @param bool   $warn    Warning.
	 * @return array
	 */
	protected static function result( $action, $term_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $term_id,
			'message' => $message,
			'warning' => $warn,
			'section' => 'terms',
		);
	}
}
