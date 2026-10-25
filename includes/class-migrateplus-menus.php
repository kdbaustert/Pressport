<?php
/**
 * Navigation menus in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports the site's nav menus with their items and theme locations, and
 * rebuilds them as the last stage of an import, after the posts and terms
 * the items point at are on the site.
 *
 * An item names its target the way the exporter names related posts
 * ({ id, slug, title, type }) or terms ({ id, slug, name, path, taxonomy }).
 * On import it is matched to the post imported from that id, then to a post
 * or term with that slug (a term also by its "Parent > Child" path); an item
 * whose target is not found is skipped with a warning.
 *
 * A menu is matched by slug (then by name, which WordPress keeps unique), so
 * importing the same file again fills the same menu. Items carry
 * _migrateplus_source_uid like posts do, so they are updated, not added twice.
 * An item made by hand in a menu that is already here, pointing at the same
 * target, is taken over rather than doubled. Menus and items the import
 * creates carry _migrateplus_job_id for rollback; an item an earlier import of
 * the same source created that the file no longer has is removed, and items
 * made by hand never are.
 */
class MigratePlus_Menus {

	/**
	 * How many menus an export would carry.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every side section counts with the export args.
		return self::user_can( 'edit_theme_options' ) ? count( wp_get_nav_menus() ) : 0;
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
	 * Every menu on this site as portable data.
	 *
	 * @param array $args Export args (unused: menus have no filters).
	 * @return array[] { id, name, slug, description, locations, items }
	 */
	public static function export( array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every side section exports with the export args.
		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			if ( $menu_id ) {
				$locations[ (int) $menu_id ][] = (string) $location;
			}
		}
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( self::menu_posts( $menu->term_id ) as $post ) {
				$items[] = self::export_item( $post );
			}
			$out[] = array(
				'id'          => (int) $menu->term_id,
				'name'        => $menu->name,
				'slug'        => $menu->slug,
				'description' => $menu->description,
				'locations'   => isset( $locations[ $menu->term_id ] ) ? $locations[ $menu->term_id ] : array(),
				'items'       => $items,
			);
		}
		return $out;
	}

	/**
	 * A menu's saved items, in menu order. Read straight from the posts rather than
	 * through wp_get_nav_menu_items(), whose filters fill in titles and URLs.
	 *
	 * @param int $menu_id Menu term id.
	 * @return WP_Post[]
	 */
	protected static function menu_posts( $menu_id ) {
		return get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'publish', // Drafts are items still being added in the Customizer.
				'posts_per_page'   => -1,
				'orderby'          => 'menu_order',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_id',
						'terms'    => (int) $menu_id,
					),
				),
			)
		);
	}

	/**
	 * @param WP_Post $post Menu item.
	 * @return array
	 */
	protected static function export_item( WP_Post $post ) {
		$type      = (string) get_post_meta( $post->ID, '_menu_item_type', true );
		$object    = (string) get_post_meta( $post->ID, '_menu_item_object', true );
		$object_id = (int) get_post_meta( $post->ID, '_menu_item_object_id', true );
		$ref       = null;
		if ( 'post_type' === $type ) {
			$ref = MigratePlus_Exporter::post_ref( $object_id );
		} elseif ( 'taxonomy' === $type ) {
			$term = get_term( $object_id, $object );
			if ( $term instanceof WP_Term ) {
				$ref = array(
					'id'       => (int) $term->term_id,
					'slug'     => $term->slug,
					'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'path'     => MigratePlus_Exporter::term_path( $term, is_taxonomy_hierarchical( $object ) ),
					'taxonomy' => $object,
				);
			}
		}
		$classes = get_post_meta( $post->ID, '_menu_item_classes', true );
		return array(
			'id'          => (int) $post->ID,
			'title'       => $post->post_title, // Empty: the item shows its target's title.
			'type'        => $type,
			'object'      => $object,
			'ref'         => $ref,
			'url'         => (string) get_post_meta( $post->ID, '_menu_item_url', true ),
			'parent'      => (int) get_post_meta( $post->ID, '_menu_item_menu_item_parent', true ),
			'order'       => (int) $post->menu_order,
			'classes'     => array_values( array_filter( is_array( $classes ) ? array_map( 'strval', $classes ) : array(), 'strlen' ) ),
			'target'      => (string) get_post_meta( $post->ID, '_menu_item_target', true ),
			'xfn'         => (string) get_post_meta( $post->ID, '_menu_item_xfn', true ),
			'attr_title'  => $post->post_excerpt,
			'description' => trim( $post->post_content ), // WordPress saves " " so an item without a title still gets a post.
		);
	}

	/**
	 * Whether a job still has menus to import after its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['menus_file'] ) && ! empty( $job->data['options']['import_menus'] );
	}

	/**
	 * The menus stage, as MigratePlus_Runner::run_side() drives one: all menus in
	 * one request (they hold no files to download).
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array { done: true, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		return array(
			'done'    => true,
			'results' => self::import( $job ),
		);
	}

	/**
	 * The menus a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['menus_file'] ) ? (string) $job->data['settings']['menus_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_menus_cache', __( 'The cached menus are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * Import every menu in the job's file. Items of a menu that is already here
	 * (same slug, or same name) are added to it; items imported before are
	 * updated, or left alone when the import skips records imported before.
	 *
	 * @param MigratePlus_Job $job Job (menu_locations and created_total are updated in place).
	 * @return array[] One result per menu, as MigratePlus_Importer::import() returns them, plus 'menu' => true.
	 */
	public static function import( MigratePlus_Job $job ) {
		$menus = self::load( $job );
		if ( is_wp_error( $menus ) ) {
			return array( self::result( 'failed', 0, $menus->get_error_message() ) );
		}
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'edit_theme_options' ) ) {
			return array( self::result( 'skipped', 0, __( 'Menus were not imported: you are not allowed to edit menus on this site.', 'migrateplus' ), true ) );
		}
		$opts = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		// A dry run saved none of the file's posts, so items pointing at them count as found when the file has them.
		$opts['planned'] = ! empty( $opts['dry_run'] ) ? self::planned( $job ) : array();
		$results         = array();
		foreach ( $menus as $menu ) {
			if ( is_array( $menu ) ) {
				$results[] = self::import_menu( $menu, $job, $opts );
			}
		}
		// Navigation Menu widgets imported before their menu existed are pointed at it now.
		if ( empty( $opts['dry_run'] ) ) {
			MigratePlus_Site::resolve_widget_menus( $job );
		}
		return $results;
	}

	/**
	 * The uids of every record in a job's file.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return true[] uid => true.
	 */
	protected static function planned( MigratePlus_Job $job ) {
		$source = $job->source();
		$field  = isset( $job->data['mapping']['fields']['source_id'] ) ? $job->data['mapping']['fields']['source_id'] : '';
		$uids   = array();
		if ( is_wp_error( $source ) || '' === $field ) {
			return $uids;
		}
		$cursor = null;
		do {
			$batch = $source->fetch( $cursor, 200 );
			if ( is_wp_error( $batch ) ) {
				break;
			}
			foreach ( $batch['records'] as $record ) {
				if ( isset( $record[ $field ] ) ) {
					$uids[ MigratePlus_Importer::uid( $job, MigratePlus_Util::stringify( $record[ $field ] ) ) ] = true;
				}
			}
			$cursor = $batch['next'];
		} while ( null !== $cursor && $batch['records'] );
		return $uids;
	}

	/**
	 * @param array           $menu Menu as exported.
	 * @param MigratePlus_Job $job  Job.
	 * @param array           $opts Job options.
	 * @return array Result.
	 */
	protected static function import_menu( array $menu, MigratePlus_Job $job, array $opts ) {
		$dry   = ! empty( $opts['dry_run'] );
		$name  = isset( $menu['name'] ) ? trim( MigratePlus_Util::stringify( $menu['name'] ) ) : '';
		$slug  = isset( $menu['slug'] ) ? sanitize_title( MigratePlus_Util::stringify( $menu['slug'] ) ) : '';
		$name  = '' !== $name ? $name : $slug;
		$label = '' !== $name ? $name : __( '(unnamed menu)', 'migrateplus' );
		if ( '' === $name ) {
			return self::result( 'failed', 0, __( 'Menu without a name or slug was not imported.', 'migrateplus' ) );
		}

		$existing = '' !== $slug ? get_term_by( 'slug', $slug, 'nav_menu' ) : false;
		$existing = $existing ? $existing : get_term_by( 'name', $name, 'nav_menu' );
		$menu_id  = $existing instanceof WP_Term ? (int) $existing->term_id : 0;
		$created  = false;
		if ( ! $menu_id && ! $dry ) {
			$menu_id = wp_update_nav_menu_object(
				0,
				wp_slash(
					array(
						'menu-name'   => $name,
						'description' => isset( $menu['description'] ) ? MigratePlus_Util::stringify( $menu['description'] ) : '',
					)
				)
			);
			if ( is_wp_error( $menu_id ) ) {
				/* translators: 1: menu name, 2: error */
				return self::result( 'failed', 0, sprintf( __( 'Menu "%1$s" could not be created: %2$s', 'migrateplus' ), $label, wp_strip_all_tags( $menu_id->get_error_message() ) ) );
			}
			// Keep the exported slug, which a later import of the same file matches on.
			if ( '' !== $slug ) {
				wp_update_term( $menu_id, 'nav_menu', array( 'slug' => $slug ) );
			}
			add_term_meta( $menu_id, '_migrateplus_job_id', $job->data['id'] );
			$created = true;
			++$job->data['created_total'];
		}

		$warnings = array();
		$counts   = self::import_items( $menu_id, isset( $menu['items'] ) && is_array( $menu['items'] ) ? $menu['items'] : array(), $job, $opts, $warnings );
		self::assign_locations( $menu_id, isset( $menu['locations'] ) ? (array) $menu['locations'] : array(), $job, $dry, $warnings );

		$verb    = $dry ? ( $menu_id ? __( 'Would update', 'migrateplus' ) : __( 'Would create', 'migrateplus' ) ) : ( $created ? __( 'Created', 'migrateplus' ) : __( 'Updated', 'migrateplus' ) );
		$message = sprintf(
			/* translators: 1: Created/Updated, 2: menu name, 3: items added, 4: items updated, 5: items skipped */
			__( '%1$s menu "%2$s": %3$d items added, %4$d updated, %5$d skipped.', 'migrateplus' ),
			$verb,
			$label,
			$counts['created'],
			$counts['updated'],
			$counts['skipped']
		);
		if ( $counts['removed'] ) {
			$message .= ' ' . sprintf(
				$dry
					/* translators: %d: number of menu items */
					? _n( 'Would remove %d item the file no longer has.', 'Would remove %d items the file no longer has.', $counts['removed'], 'migrateplus' )
					/* translators: %d: number of menu items */
					: _n( 'Removed %d item the file no longer has.', 'Removed %d items the file no longer has.', $counts['removed'], 'migrateplus' ),
				$counts['removed']
			);
		}
		if ( $dry && $menu_id ) {
			// Matched by slug, then name: say which menu here the items would go into.
			$message = sprintf(
				/* translators: 1: menu name in the file, 2: items added, 3: items updated, 4: items skipped, 5: name of the menu on this site */
				__( 'Would update menu "%1$s": %2$d items added, %3$d updated, %4$d skipped, in the existing menu "%5$s".', 'migrateplus' ),
				$label,
				$counts['created'],
				$counts['updated'],
				$counts['skipped'],
				$existing->name
			);
		}
		if ( $warnings ) {
			$message .= ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', $warnings );
		}
		return self::result( $created || ( $dry && ! $menu_id ) ? 'created' : 'updated', $menu_id, $message, ! empty( $warnings ) );
	}

	/**
	 * Add or update a menu's items, parents before children, then remove the
	 * items earlier imports of this source created that the file no longer has.
	 *
	 * @param int             $menu_id  Menu (0 in a dry run for a menu that does not exist yet).
	 * @param array[]         $items    Items as exported.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int[] { created, updated, skipped, removed }
	 */
	protected static function import_items( $menu_id, array $items, MigratePlus_Job $job, array $opts, array &$warnings ) {
		$counts   = array_fill_keys( array( 'created', 'updated', 'skipped', 'removed' ), 0 );
		$local    = array(); // Item id on the old site => item id here.
		$pending  = array_values( array_filter( $items, 'is_array' ) );
		$handmade = self::handmade_items( $menu_id );
		usort(
			$pending,
			static function ( $a, $b ) {
				return ( isset( $a['order'] ) ? (int) $a['order'] : 0 ) - ( isset( $b['order'] ) ? (int) $b['order'] : 0 );
			}
		);
		$place = static function ( array $item, $parent_id ) use ( $menu_id, $job, $opts, &$warnings, &$counts, &$local, &$handmade ) {
			$id = self::import_item( $menu_id, $item, $parent_id, $job, $opts, $warnings, $counts, $handmade );
			if ( $id && isset( $item['id'] ) ) {
				$local[ (string) (int) $item['id'] ] = $id;
			}
		};
		// Menu order lists parents first, but a child listed before its parent waits for it.
		// A child whose parent was skipped goes to the top level.
		do {
			$left = array();
			foreach ( $pending as $item ) {
				$parent = isset( $item['parent'] ) ? (string) (int) $item['parent'] : '0';
				if ( '0' !== $parent && ! isset( $local[ $parent ] ) && self::listed( $parent, $pending ) ) {
					$left[] = $item;
					continue;
				}
				$place( $item, isset( $local[ $parent ] ) ? $local[ $parent ] : 0 );
			}
			$progress = count( $left ) < count( $pending );
			$pending  = $left;
		} while ( $pending && $progress );
		foreach ( $pending as $item ) {
			$place( $item, 0 ); // Parents that point at each other.
		}
		$counts['removed'] = self::remove_dropped_items( $menu_id, $items, $job, ! empty( $opts['dry_run'] ) );
		return $counts;
	}

	/**
	 * A menu's items that no import made, by what they point at, so an item in
	 * the file can take one over instead of adding its double.
	 *
	 * @param int $menu_id Menu, or 0.
	 * @return int[] target key (see target_key()) => item id.
	 */
	protected static function handmade_items( $menu_id ) {
		$out = array();
		foreach ( $menu_id ? self::menu_posts( $menu_id ) : array() as $post ) {
			if ( '' !== (string) get_post_meta( $post->ID, '_migrateplus_source_uid', true ) ) {
				continue;
			}
			$key = self::target_key(
				(string) get_post_meta( $post->ID, '_menu_item_type', true ),
				(string) get_post_meta( $post->ID, '_menu_item_object', true ),
				(int) get_post_meta( $post->ID, '_menu_item_object_id', true ),
				(string) get_post_meta( $post->ID, '_menu_item_url', true )
			);
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $post->ID;
			}
		}
		return $out;
	}

	/**
	 * What an item points at, as one string: the URL of a custom link, else its type, object and object id.
	 *
	 * @param string $type      Item type.
	 * @param string $object    Object (post type, taxonomy, "custom").
	 * @param int    $object_id Object id.
	 * @param string $url       URL of a custom link.
	 * @return string
	 */
	protected static function target_key( $type, $object, $object_id, $url ) {
		return 'custom' === $type ? 'custom|' . untrailingslashit( $url ) : $type . '|' . $object . '|' . (int) $object_id;
	}

	/**
	 * Delete the items an import of this job's source created in a menu that the
	 * file no longer lists (deleted on the old site). Items without the job tag
	 * (made by hand, or taken over) and items of another source are kept.
	 *
	 * @param int             $menu_id Menu, or 0.
	 * @param array[]         $items   Items as exported.
	 * @param MigratePlus_Job $job     Job.
	 * @param bool            $dry     Count only.
	 * @return int Items removed (or that would be).
	 */
	protected static function remove_dropped_items( $menu_id, array $items, MigratePlus_Job $job, $dry ) {
		if ( ! $menu_id ) {
			return 0;
		}
		$listed = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && '' !== MigratePlus_Util::stringify( $item['id'] ) ) {
				$listed[ MigratePlus_Importer::uid( $job, 'menu-item:' . MigratePlus_Util::stringify( $item['id'] ) ) ] = true;
			}
		}
		$removed = 0;
		foreach ( self::menu_posts( $menu_id ) as $post ) {
			$uid       = (string) get_post_meta( $post->ID, '_migrateplus_source_uid', true );
			$source_id = (string) get_post_meta( $post->ID, '_migrateplus_source_id', true );
			// The uid is scoped to its source: recomputed under this job, it only matches for the same source.
			if ( '' === $uid || isset( $listed[ $uid ] ) || '' === (string) get_post_meta( $post->ID, '_migrateplus_job_id', true ) || MigratePlus_Importer::uid( $job, $source_id ) !== $uid ) {
				continue;
			}
			if ( $dry || wp_delete_post( $post->ID, true ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Whether an item id is among the items still to place.
	 *
	 * @param string  $id    Item id on the old site.
	 * @param array[] $items Items.
	 * @return bool
	 */
	protected static function listed( $id, array $items ) {
		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) && (string) (int) $item['id'] === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add or update one item.
	 *
	 * @param int             $menu_id   Menu.
	 * @param array           $item      Item as exported.
	 * @param int             $parent_id Parent item here, or 0.
	 * @param MigratePlus_Job $job       Job.
	 * @param array           $opts      Job options.
	 * @param string[]        $warnings  Warnings (by reference).
	 * @param int[]           $counts    Counts (by reference).
	 * @param int[]           $handmade  Items made by hand, from handmade_items(): one taken over leaves the list (by reference).
	 * @return int The item here (in a dry run: 1 for an item that would be saved), or 0 when skipped.
	 */
	protected static function import_item( $menu_id, array $item, $parent_id, MigratePlus_Job $job, array $opts, array &$warnings, array &$counts, array &$handmade ) {
		$dry    = ! empty( $opts['dry_run'] );
		$read   = static function ( $key ) use ( $item ) {
			return isset( $item[ $key ] ) ? MigratePlus_Util::stringify( $item[ $key ] ) : '';
		};
		$title  = $read( 'title' );
		$target = self::resolve_target( $item, $job, $opts );
		if ( is_wp_error( $target ) ) {
			++$counts['skipped'];
			$warnings[] = $target->get_error_message();
			return 0;
		}
		$uid      = '' !== $read( 'id' ) ? MigratePlus_Importer::uid( $job, 'menu-item:' . $read( 'id' ) ) : '';
		$existing = $uid ? MigratePlus_Importer::find_by_uid( $uid, 'nav_menu_item' ) : 0;
		$key      = self::target_key( $target['type'], $target['object'], $target['object_id'], $target['url'] );
		if ( ! $existing && isset( $handmade[ $key ] ) ) {
			// The same link made by hand in the menu here: fill that one rather than adding a second.
			$existing = $handmade[ $key ];
			unset( $handmade[ $key ] );
		}
		if ( $existing && 'skip' === $opts['duplicate'] ) {
			++$counts['skipped'];
			return $existing;
		}
		if ( $dry ) {
			++$counts[ $existing ? 'updated' : 'created' ];
			return $existing ? $existing : 1;
		}
		$classes = isset( $item['classes'] ) ? MigratePlus_Util::to_list( $item['classes'] ) : array();
		$id      = wp_update_nav_menu_item(
			$menu_id,
			$existing,
			array(
				'menu-item-object-id'   => $target['object_id'],
				'menu-item-object'      => $target['object'],
				'menu-item-parent-id'   => (int) $parent_id,
				'menu-item-position'    => max( 1, (int) $read( 'order' ) ),
				'menu-item-type'        => $target['type'],
				'menu-item-title'       => wp_slash( $title ),
				'menu-item-url'         => $target['url'],
				'menu-item-description' => wp_slash( $read( 'description' ) ),
				'menu-item-attr-title'  => wp_slash( $read( 'attr_title' ) ),
				'menu-item-target'      => '_blank' === $read( 'target' ) ? '_blank' : '',
				'menu-item-classes'     => implode( ' ', $classes ),
				'menu-item-xfn'         => $read( 'xfn' ),
				'menu-item-status'      => 'publish',
			)
		);
		if ( is_wp_error( $id ) || ! $id ) {
			++$counts['skipped'];
			/* translators: 1: item title, 2: error */
			$warnings[] = sprintf( __( 'Menu item "%1$s" could not be saved: %2$s', 'migrateplus' ), '' !== $title ? $title : $read( 'id' ), is_wp_error( $id ) ? $id->get_error_message() : '' );
			return 0;
		}
		if ( $uid ) {
			update_post_meta( $id, '_migrateplus_source_uid', $uid );
			update_post_meta( $id, '_migrateplus_source_id', 'menu-item:' . $read( 'id' ) );
		}
		if ( ! $existing ) {
			update_post_meta( $id, '_migrateplus_job_id', $job->data['id'] );
			++$job->data['created_total'];
		}
		++$counts[ $existing ? 'updated' : 'created' ];
		return (int) $id;
	}

	/**
	 * What an item points at on this site.
	 *
	 * @param array           $item Item as exported.
	 * @param MigratePlus_Job $job  Job.
	 * @param array           $opts Job options.
	 * @return array|WP_Error { type, object, object_id, url }, or why the item is skipped.
	 */
	protected static function resolve_target( array $item, MigratePlus_Job $job, array $opts ) {
		$type   = isset( $item['type'] ) ? sanitize_key( MigratePlus_Util::stringify( $item['type'] ) ) : 'custom';
		$object = isset( $item['object'] ) ? sanitize_key( MigratePlus_Util::stringify( $item['object'] ) ) : '';
		$ref    = isset( $item['ref'] ) && is_array( $item['ref'] ) ? $item['ref'] : array();
		$title  = isset( $item['title'] ) && '' !== MigratePlus_Util::stringify( $item['title'] ) ? MigratePlus_Util::stringify( $item['title'] ) : ( isset( $ref['title'] ) ? MigratePlus_Util::stringify( $ref['title'] ) : ( isset( $ref['name'] ) ? MigratePlus_Util::stringify( $ref['name'] ) : '' ) );
		$out    = array(
			'type'      => $type,
			'object'    => $object,
			'object_id' => 0,
			'url'       => '',
		);
		switch ( $type ) {
			case 'post_type':
				$id = self::find_post( $ref, $object, $job, $opts['planned'] );
				if ( ! $id ) {
					/* translators: 1: item title, 2: post type */
					return new WP_Error( 'migrateplus_menu_target', sprintf( __( 'Menu item "%1$s" was skipped: its %2$s is not on this site.', 'migrateplus' ), $title, $object ) );
				}
				$out['object']    = get_post_type( $id );
				$out['object_id'] = $id;
				return $out;

			case 'taxonomy':
				$id = taxonomy_exists( $object ) ? self::find_term( $ref, $object, $job ) : 0;
				if ( ! $id ) {
					/* translators: 1: item title, 2: taxonomy */
					return new WP_Error( 'migrateplus_menu_target', sprintf( __( 'Menu item "%1$s" was skipped: its %2$s term is not on this site.', 'migrateplus' ), $title, $object ) );
				}
				$out['object_id'] = $id;
				return $out;

			case 'post_type_archive':
				if ( ! post_type_exists( $object ) ) {
					/* translators: 1: item title, 2: post type */
					return new WP_Error( 'migrateplus_menu_target', sprintf( __( 'Menu item "%1$s" was skipped: post type "%2$s" does not exist on this site.', 'migrateplus' ), $title, $object ) );
				}
				return $out;

			case 'custom':
				$url = isset( $item['url'] ) ? trim( MigratePlus_Util::stringify( $item['url'] ) ) : '';
				if ( ! empty( $opts['rewrite_links'] ) ) {
					$url = self::rewrite_url( $url, isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '' );
				}
				$out['object'] = 'custom';
				$out['url']    = $url;
				return $out;

			default:
				/* translators: 1: item title, 2: item type */
				return new WP_Error( 'migrateplus_menu_target', sprintf( __( 'Menu item "%1$s" was skipped: items of type "%2$s" are not supported.', 'migrateplus' ), $title, $type ) );
		}
	}

	/**
	 * The post an item points at: the one imported from its id, else one of its type with its slug.
	 *
	 * @param array           $ref     { id, slug, title, type }.
	 * @param string          $type    Post type on the old site.
	 * @param MigratePlus_Job $job     Job.
	 * @param true[]          $planned Dry run: uids of the file's records, which a real import would create first.
	 * @return int -1 for a planned post in a dry run.
	 */
	protected static function find_post( array $ref, $type, MigratePlus_Job $job, array $planned ) {
		$all = array_keys( get_post_types() );
		$uid = ! empty( $ref['id'] ) ? MigratePlus_Importer::uid( $job, MigratePlus_Util::stringify( $ref['id'] ) ) : '';
		$id  = '' !== $uid ? MigratePlus_Importer::find_by_uid( $uid, $all, $job->data['id'] ) : 0;
		if ( ! $id && isset( $planned[ $uid ] ) ) {
			return -1;
		}
		if ( $id || empty( $ref['slug'] ) ) {
			return $id;
		}
		$ids = get_posts(
			array(
				'post_type'        => post_type_exists( $type ) ? $type : $all,
				'name'             => sanitize_title( MigratePlus_Util::stringify( $ref['slug'] ) ),
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The term an item points at, found as the terms section finds a term: by the
	 * uid it was imported with, then its slug, then its exact "Parent > Child" path.
	 *
	 * @param array           $ref      { id, slug, name, path, taxonomy }.
	 * @param string          $taxonomy Taxonomy.
	 * @param MigratePlus_Job $job      Job.
	 * @return int
	 */
	protected static function find_term( array $ref, $taxonomy, MigratePlus_Job $job ) {
		$name = isset( $ref['name'] ) ? MigratePlus_Util::stringify( $ref['name'] ) : '';
		$uid  = ! empty( $ref['id'] ) ? MigratePlus_Terms::uid(
			$job,
			array(
				'taxonomy' => $taxonomy,
				'id'       => $ref['id'],
			)
		) : '';
		return MigratePlus_Terms::find_term(
			array(
				'taxonomy' => $taxonomy,
				'slug'     => isset( $ref['slug'] ) ? $ref['slug'] : '',
				'path'     => isset( $ref['path'] ) ? MigratePlus_Util::stringify( $ref['path'] ) : $name,
				'name'     => $name,
			),
			$uid
		);
	}

	/**
	 * Point a custom link to the old site at this one, by the same rules as links in
	 * content: the whole host must match, and files under wp-content are left alone.
	 *
	 * @param string $url  URL.
	 * @param string $base The site the export came from.
	 * @return string
	 */
	public static function rewrite_url( $url, $base ) {
		$old = untrailingslashit( preg_replace( '#^https?:#i', '', (string) $base ) );
		$new = untrailingslashit( home_url() );
		if ( '' === $url || ! preg_match( '#^//[^/]#', $old ) || 0 === strcasecmp( $old, preg_replace( '#^https?:#i', '', $new ) ) ) {
			return $url;
		}
		return (string) preg_replace( '#^(?:https?:)?' . preg_quote( $old, '#' ) . '(?=[/?\#]|$)(?!/wp-content/)#i', $new, $url );
	}

	/**
	 * Assign a menu to the theme locations it had, when the active theme has them.
	 * The location's previous menu is remembered, so rollback can put it back.
	 *
	 * @param int             $menu_id   Menu (0 in a dry run for a menu that does not exist yet).
	 * @param string[]        $locations Locations on the old site.
	 * @param MigratePlus_Job $job       Job (menu_locations updated in place).
	 * @param bool            $dry       Dry run.
	 * @param string[]        $warnings  Warnings (by reference).
	 */
	protected static function assign_locations( $menu_id, array $locations, MigratePlus_Job $job, $dry, array &$warnings ) {
		$registered = get_registered_nav_menus();
		$current    = get_nav_menu_locations();
		$changed    = false;
		foreach ( $locations as $location ) {
			$location = sanitize_key( MigratePlus_Util::stringify( $location ) );
			if ( '' === $location ) {
				continue;
			}
			if ( ! isset( $registered[ $location ] ) ) {
				/* translators: %s: theme location */
				$warnings[] = sprintf( __( 'Theme location "%s" does not exist in the active theme, so the menu was not assigned to it.', 'migrateplus' ), $location );
				continue;
			}
			$before = isset( $current[ $location ] ) ? (int) $current[ $location ] : 0;
			if ( $dry || $before === (int) $menu_id ) {
				continue;
			}
			$saved                                    = isset( $job->data['menu_locations'][ $location ] ) ? $job->data['menu_locations'][ $location ] : array( $before, 0 );
			$job->data['menu_locations'][ $location ] = array( (int) $saved[0], (int) $menu_id );

			$current[ $location ] = (int) $menu_id;
			$changed              = true;
		}
		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $current );
		}
	}

	/**
	 * After a rollback: give each theme location the import assigned its previous
	 * menu again, unless it was changed by hand since.
	 *
	 * @param MigratePlus_Job $job Job (menu_locations cleared in place).
	 */
	public static function restore_locations( MigratePlus_Job $job ) {
		if ( empty( $job->data['menu_locations'] ) ) {
			return;
		}
		$current = get_nav_menu_locations();
		foreach ( (array) $job->data['menu_locations'] as $location => $pair ) {
			list( $before, $after ) = array_map( 'intval', (array) $pair + array( 0, 0 ) );
			$now                    = isset( $current[ $location ] ) ? (int) $current[ $location ] : 0;
			// A menu the rollback deleted leaves its locations at 0.
			if ( ( $now === $after || 0 === $now ) && ( 0 === $before || wp_get_nav_menu_object( $before ) ) ) {
				$current[ $location ] = $before;
			}
		}
		set_theme_mod( 'nav_menu_locations', $current );
		$job->data['menu_locations'] = array();
	}

	/**
	 * @param string $action  created|updated|skipped|failed.
	 * @param int    $menu_id Menu.
	 * @param string $message Message.
	 * @param bool   $warn    Has warnings.
	 * @return array
	 */
	protected static function result( $action, $menu_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $menu_id,
			'message' => $message,
			'warning' => $warn,
			'menu'    => true,
			'section' => 'nav_menu',
		);
	}
}
