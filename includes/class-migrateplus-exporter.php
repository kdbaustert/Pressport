<?php
/**
 * Export content in a format MigratePlus can import.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes posts to a JSON file, in batches, with everything MigratePlus needs
 * to rebuild them elsewhere: core fields, the author's name (and login when the
 * person exporting may list users; the importing site picks its own author),
 * hierarchical terms, featured image URL, custom fields and ACF fields (images as URLs, related posts
 * as { id, slug, title } references, terms as names, users as emails) and,
 * when asked for, approved comments.
 *
 * JSON exports embed a field mapping, so importing the file maps itself.
 */
class MigratePlus_Exporter {

	const STATE_PREFIX = 'migrateplus_export_';
	// Not under STATE_PREFIX: cleanup() deletes everything there after a day.
	const LOG_PREFIX = 'migrateplus_exportlog_';
	const LOG_INDEX  = 'migrateplus_exportlog_ids';

	/**
	 * Post types with an admin screen that are not content to move: the site editor's and
	 * menus' types (which the importer refuses too), ACF's settings, and WooCommerce orders,
	 * which with HPOS keep their data in tables of their own.
	 */
	// Global styles travel with the site settings, ACF's definitions and orders as sections of their own.
	const NOT_EXPORTED = array( 'wp_global_styles', 'nav_menu_item', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	/**
	 * Whether a post type may be exported: one with an admin screen, as the importer
	 * requires, that is not in NOT_EXPORTED. Whether the user may edit it is checked apart.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function exportable_type( $type ) {
		$object = get_post_type_object( $type );
		// Templates and template parts have no admin screen of their own (the Site Editor edits them), but are content.
		return $object && ( $object->show_ui || MigratePlus_Blocks::is_template_type( $type ) ) && ! in_array( $type, self::NOT_EXPORTED, true );
	}

	/**
	 * Sanitized export args the current user may run: every type exportable and editable,
	 * and the menu, site-settings and order sections only with their capability.
	 *
	 * @param array $raw Raw args.
	 * @return array|WP_Error
	 */
	public static function args_for_user( array $raw ) {
		$args = self::sanitize_args( $raw );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		foreach ( $args['post_types'] as $type ) {
			if ( ! self::exportable_type( $type ) ) {
				/* translators: %s: post type label */
				return new WP_Error( 'migrateplus_export_type', sprintf( __( '%s can\'t be exported.', 'migrateplus' ), self::type_label( $type ) ) );
			}
			if ( ! current_user_can( get_post_type_object( $type )->cap->edit_posts ) ) {
				/* translators: %s: post type label */
				return new WP_Error( 'migrateplus_export_cap', sprintf( __( 'You are not allowed to export %s.', 'migrateplus' ), self::type_label( $type ) ) );
			}
		}
		// A toggle hidden from users who may not see its section still arrives with its default: leave the section out.
		foreach ( array(
			'include_menus'         => 'edit_theme_options',
			'include_site_settings' => 'manage_options',
			'include_orders'        => 'manage_woocommerce',
		) as $arg => $capability ) {
			if ( ! current_user_can( $capability ) ) {
				$args[ $arg ] = 0;
			}
		}
		return $args;
	}

	/**
	 * Default export arguments.
	 *
	 * @return array
	 */
	public static function defaults() {
		$s = MigratePlus_Settings::get();
		return array(
			'post_types'              => array( 'post' ),
			'post_ids'                => array(),
			'statuses'                => (array) $s['export_statuses'],
			'after'                   => '',
			'before'                  => '',
			'include_acf'             => (int) $s['export_include_acf'],
			'include_meta'            => (int) $s['export_include_meta'],
			'include_terms'           => (int) $s['export_include_terms'],
			'include_term_data'       => (int) $s['export_include_term_data'],
			'include_image'           => (int) $s['export_include_image'],
			'include_woo'             => (int) $s['export_include_woo'],
			'include_comments'        => (int) $s['export_include_comments'],
			'include_menus'           => (int) $s['export_include_menus'],
			'include_acf_definitions' => (int) $s['export_include_acf_definitions'],
			'include_acf_options'     => (int) $s['export_include_acf_options'],
			'include_site_settings'   => (int) $s['export_include_site_settings'],
			'site_sections'           => array_keys( MigratePlus_Site::sections() ), // Which parts of the site settings; all of them to start.
			'include_orders'          => (int) $s['export_include_orders'],
			'include_yoast'           => (int) $s['export_include_yoast'],
			'exclude_meta'            => self::meta_patterns( $s['export_exclude_meta'] ),
			'gzip'                    => MigratePlus_Util::zlib_available() ? (int) $s['export_gzip'] : 0,
		);
	}

	/**
	 * The sections an export can carry next to its items, in the order the file
	 * holds them (import order). Each class exports with the export args and
	 * counts what it would export; a batched one exports a page at a time
	 * (export_batch( args, cursor, limit ) => { items, next }) because there can
	 * be too many to hold at once.
	 *
	 * @return array[] key => { arg, class, label, batched }
	 */
	public static function side_providers() {
		return array(
			'terms'           => array(
				'arg'     => 'include_term_data',
				'class'   => 'MigratePlus_Terms',
				'label'   => __( 'terms', 'migrateplus' ),
				'batched' => true,
			),
			'acf_definitions' => array(
				'arg'     => 'include_acf_definitions',
				'class'   => 'MigratePlus_ACF_Definitions',
				'label'   => __( 'ACF definitions', 'migrateplus' ),
				'batched' => false,
			),
			'acf_options'     => array(
				'arg'     => 'include_acf_options',
				'class'   => 'MigratePlus_ACF_Options',
				'label'   => __( 'ACF options pages', 'migrateplus' ),
				'batched' => false,
			),
			'site_settings'   => array(
				'arg'     => 'include_site_settings',
				'class'   => 'MigratePlus_Site',
				'label'   => __( 'site settings', 'migrateplus' ),
				'batched' => false,
			),
			'menus'           => array(
				'arg'     => 'include_menus',
				'class'   => 'MigratePlus_Menus',
				'label'   => __( 'navigation menus', 'migrateplus' ),
				'batched' => false,
			),
			'orders'          => array(
				'arg'     => 'include_orders',
				'class'   => 'MigratePlus_Orders',
				'label'   => __( 'orders', 'migrateplus' ),
				'batched' => true,
			),
		);
	}

	/**
	 * How much each side section an export asks for would hold.
	 *
	 * @param array $args Sanitized args.
	 * @return int[] key => count, only the sections that are on.
	 */
	public static function side_counts( array $args ) {
		$out = array();
		foreach ( self::side_providers() as $key => $side ) {
			if ( ! empty( $args[ $side['arg'] ] ) && class_exists( $side['class'] ) ) {
				$out[ $key ] = (int) call_user_func( array( $side['class'], 'count' ), $args );
			}
		}
		return $out;
	}

	/**
	 * Meta keys to leave out, from a list or text with one key per line (or comma separated).
	 * A trailing * matches every key starting with what comes before it.
	 *
	 * @param string|array $raw Keys.
	 * @return string[]
	 */
	public static function meta_patterns( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n,]+/', (string) $raw );
		$keys  = array();
		foreach ( $items as $item ) {
			$star = '*' === substr( trim( (string) $item ), -1 );
			$key  = sanitize_text_field( rtrim( trim( (string) $item ), '*' ) );
			if ( '' !== $key ) {
				$keys[] = $key . ( $star ? '*' : '' );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param string   $key      Meta key.
	 * @param string[] $patterns From meta_patterns().
	 * @return bool
	 */
	public static function meta_excluded( $key, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( '*' === substr( $pattern, -1 ) ? 0 === strpos( $key, substr( $pattern, 0, -1 ) ) : $key === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize arguments from a request / CLI.
	 *
	 * @param array $raw Raw args.
	 * @return array|WP_Error
	 */
	public static function sanitize_args( array $raw ) {
		$args = array_merge( self::defaults(), $raw );
		if ( isset( $raw['post_type'] ) && ! isset( $raw['post_types'] ) ) {
			$args['post_types'] = $raw['post_type']; // One type, or several separated by commas.
		}
		$types = is_array( $args['post_types'] ) ? $args['post_types'] : explode( ',', (string) $args['post_types'] );
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', array_map( 'strval', $types ) ) ) ) ) );
		// Hand-picked posts: the file holds exactly these. They name their own types,
		// and the status and date filters are dropped so no pick is silently lost.
		$ids = is_array( $args['post_ids'] ) ? $args['post_ids'] : explode( ',', (string) $args['post_ids'] );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				/* translators: %d: post ID */
				return new WP_Error( 'migrateplus_export_post', sprintf( __( 'Post %d was not found.', 'migrateplus' ), $id ) );
			}
		}
		if ( $ids ) {
			$types            = array_values( array_unique( array_map( 'get_post_type', $ids ) ) );
			$args['statuses'] = 'any';
			$args['after']    = '';
			$args['before']   = '';
		}
		// The parts of the site settings. None picked ('' from a form, which drops an empty list) means none, not all.
		$sections = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', is_array( $args['site_sections'] ) ? $args['site_sections'] : explode( ',', (string) $args['site_sections'] ) ) ) ) );
		$sections = $sections ? MigratePlus_Site::picked_sections( array( 'site_sections' => $sections ) ) : array();
		if ( ! $sections && ! empty( $args['include_site_settings'] ) ) {
			return new WP_Error( 'migrateplus_export_site_sections', __( 'Pick at least one part of the site settings to export, or leave the site settings out.', 'migrateplus' ) );
		}
		// Orders and the site settings are content types of their own on the Export screen: a file can hold them alone.
		$records_only = ! empty( $args['include_orders'] ) || ! empty( $args['include_site_settings'] ) || ! empty( $args['include_menus'] );
		if ( ! $types && ! $records_only ) {
			return new WP_Error( 'migrateplus_export_type', __( 'Choose at least one content type to export.', 'migrateplus' ) );
		}
		foreach ( $types as $type ) {
			if ( ! post_type_exists( $type ) ) {
				/* translators: %s: post type */
				return new WP_Error( 'migrateplus_export_type', sprintf( __( 'Unknown post type "%s".', 'migrateplus' ), $type ) );
			}
		}
		// The file is imported in this order. Media first, so featured images, ACF images and
		// galleries further down reuse the files the media section downloaded (by their URL);
		// synced patterns next, so the content that embeds them finds them; navigation menus
		// and templates last, after the pages they link to.
		$first    = array_merge( array( 'attachment' ), MigratePlus_Blocks::FIRST );
		$types    = array_values( array_unique( array_merge( array_intersect( $first, $types ), array_diff( $types, $first, MigratePlus_Blocks::LAST ), array_intersect( MigratePlus_Blocks::LAST, $types ) ) ) );
		$allowed  = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$asked    = array_filter( array_map( 'trim', array_map( 'strval', is_array( $args['statuses'] ) ? $args['statuses'] : explode( ',', (string) $args['statuses'] ) ) ), 'strlen' );
		$statuses = array_values( array_intersect( array_map( 'sanitize_key', $asked ), array_merge( $allowed, array( 'any' ) ) ) );
		// Statuses that were asked for but are all unknown would otherwise export every status.
		if ( $asked && ! $statuses ) {
			/* translators: %s: statuses */
			return new WP_Error( 'migrateplus_export_status', sprintf( __( 'Unknown status "%s". Use publish, draft, pending, private, future or any.', 'migrateplus' ), implode( ', ', $asked ) ) );
		}
		if ( in_array( 'any', $statuses, true ) || ! $statuses ) {
			$statuses = $allowed;
		}
		foreach ( array( 'after', 'before' ) as $key ) {
			$value = trim( (string) $args[ $key ] );
			$valid = DateTime::createFromFormat( '!Y-m-d', $value );
			// An unreadable date would otherwise export everything, from the first post on.
			if ( '' !== $value && ( ! $valid || $valid->format( 'Y-m-d' ) !== $value ) ) {
				/* translators: %s: date */
				return new WP_Error( 'migrateplus_export_date', sprintf( __( '"%s" is not a date: use YYYY-MM-DD.', 'migrateplus' ), $value ) );
			}
			$args[ $key ] = $value;
		}
		if ( ! empty( $args['gzip'] ) && ! MigratePlus_Util::zlib_available() ) {
			return new WP_Error( 'migrateplus_export_gzip', __( 'This server cannot compress the export: PHP\'s zlib extension is missing. Export it uncompressed instead.', 'migrateplus' ) );
		}
		return array(
			'post_types'              => $types,
			'post_ids'                => $ids,
			'statuses'                => $statuses,
			'after'                   => $args['after'],
			'before'                  => $args['before'],
			'include_acf'             => empty( $args['include_acf'] ) ? 0 : 1,
			'include_meta'            => empty( $args['include_meta'] ) ? 0 : 1,
			'include_terms'           => empty( $args['include_terms'] ) ? 0 : 1,
			'include_term_data'       => empty( $args['include_term_data'] ) ? 0 : 1,
			'include_image'           => empty( $args['include_image'] ) ? 0 : 1,
			'include_woo'             => empty( $args['include_woo'] ) ? 0 : 1,
			'include_comments'        => empty( $args['include_comments'] ) ? 0 : 1,
			'include_menus'           => empty( $args['include_menus'] ) ? 0 : 1,
			'include_acf_definitions' => empty( $args['include_acf_definitions'] ) ? 0 : 1,
			'include_acf_options'     => empty( $args['include_acf_options'] ) ? 0 : 1,
			'include_site_settings'   => empty( $args['include_site_settings'] ) ? 0 : 1,
			'site_sections'           => $sections,
			'include_orders'          => empty( $args['include_orders'] ) ? 0 : 1,
			'include_yoast'           => empty( $args['include_yoast'] ) ? 0 : 1,
			'exclude_meta'            => self::meta_patterns( $args['exclude_meta'] ),
			'gzip'                    => empty( $args['gzip'] ) ? 0 : 1,
		);
	}

	/**
	 * WP_Query args for one post type of an export.
	 *
	 * @param array  $args  Export args.
	 * @param string $type  Post type.
	 * @param int    $page  1-based page.
	 * @param int    $limit Page size.
	 * @return array
	 */
	protected static function query_args( array $args, $type, $page, $limit ) {
		$q = array(
			'post_type'              => $type,
			'post_status'            => 'attachment' === $type ? array( 'inherit' ) : $args['statuses'], // Media has no publish status of its own.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => $limit,
			'paged'                  => max( 1, (int) $page ),
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => ! empty( $args['include_terms'] ),
		);
		if ( ! empty( $args['post_ids'] ) ) {
			$q['post__in'] = $args['post_ids'];
		}
		if ( 'attachment' === $type ) {
			// Files sold as product downloads stay private: they travel with the product export instead.
			$q['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array(
					'key'     => '_wp_attached_file',
					'value'   => 'woocommerce_uploads/',
					'compare' => 'NOT LIKE',
				),
				array(
					'key'     => '_wp_attached_file',
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$range = array();
		if ( $args['after'] ) {
			$range['after'] = $args['after'];
		}
		if ( $args['before'] ) {
			$range['before'] = $args['before'];
		}
		if ( $range ) {
			$range['inclusive'] = true;
			$q['date_query']    = array( $range );
		}
		return $q;
	}

	/**
	 * Posts matching the export, per post type.
	 *
	 * @param array $args Export args.
	 * @return int[] post type => count, in export order.
	 */
	public static function counts( array $args ) {
		$out = array();
		foreach ( $args['post_types'] as $type ) {
			$q            = new WP_Query( array_merge( self::query_args( $args, $type, 1, 1 ), array( 'fields' => 'ids' ) ) );
			$out[ $type ] = (int) $q->found_posts;
		}
		return $out;
	}

	// Stateful, batched export (admin AJAX and WP-CLI share this).

	/**
	 * Start an export: create the work file and remember the state.
	 *
	 * @param array $args Sanitized args.
	 * @return array|WP_Error State.
	 */
	public static function start( array $args ) {
		$id  = strtolower( wp_generate_password( 12, false ) );
		$dir = MigratePlus_Util::ensure_storage_dir();
		// The id is in every admin-ajax URL for the export. The files' names carry a secret part
		// as well, which never leaves the server, so the storage folder's files can't be guessed
		// from it on a server that ignores the folder's .htaccess.
		$secret = strtolower( wp_generate_password( 20, false ) );
		$state  = array(
			'id'          => $id,
			'args'        => $args,
			'user'        => get_current_user_id(),
			'created'     => time(),
			'counts'      => self::counts( $args ),
			'done'        => 0,
			'type_pos'    => 0, // The post type being exported, in args['post_types'].
			'type_done'   => array(),
			'last_id'     => 0,
			'work'        => $dir . 'export-' . $id . '-' . $secret . '.jsonl', // One item per line until finished.
			'file'        => '',
			'dest'        => '', // The final file while finish() writes it.
			'meta_keys'   => array(),
			'acf_names'   => array(),
			'taxes'       => array(),
			'yoast'       => 0, // Whether any item carried Yoast SEO fields.
			'menus'       => 0,
			'terms'       => 0,
			'sides'       => array(), // Side section => how many it holds, once written.
			'side_pos'    => 0, // The batched side section being exported, in batched_sides().
			'side_cursor' => null, // Where its last page stopped.
			'complete'    => false,
		);

		$state['total'] = array_sum( $state['counts'] );
		if ( false === file_put_contents( $state['work'], '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'migrateplus_export_write', __( 'Could not write to the uploads folder.', 'migrateplus' ) );
		}
		self::save_state( $state );
		self::log_start( $state );
		return $state;
	}

	// Export history: a lasting record of each export, kept after its file expires.

	/**
	 * @param string $id Export id.
	 * @return array|null
	 */
	public static function history_entry( $id ) {
		$entry = get_option( self::LOG_PREFIX . sanitize_key( $id ) );
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Every logged export, newest first, without their log lines.
	 *
	 * @return array[]
	 */
	public static function history() {
		$out = array();
		foreach ( (array) get_option( self::LOG_INDEX, array() ) as $id ) {
			$entry = self::history_entry( $id );
			if ( $entry ) {
				$state          = self::load_state( $id );
				$entry['lines'] = count( $entry['log'] );
				// The file is only kept for a day; an export whose state is gone before it finished was abandoned.
				$entry['available'] = $state && ! empty( $state['complete'] ) && is_readable( $state['file'] );
				if ( 'running' === $entry['status'] && ! $state ) {
					$entry['status'] = 'stopped';
				}
				unset( $entry['log'] );
				$out[] = $entry;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $out;
	}

	/**
	 * Remove an export from history (and its file, if it is still there).
	 *
	 * @param string $id Export id.
	 */
	public static function forget( $id ) {
		$id    = sanitize_key( $id );
		$state = self::load_state( $id );
		if ( $state ) {
			self::delete( $state );
		}
		delete_option( self::LOG_PREFIX . $id );
		MigratePlus_Util::update_option_locked(
			self::LOG_INDEX,
			static function ( $ids ) use ( $id ) {
				return array_values( array_diff( (array) $ids, array( $id ) ) );
			}
		);
	}

	/**
	 * @param array $state State, just started.
	 */
	protected static function log_start( array $state ) {
		$args   = $state['args'];
		$labels = array_map( array( __CLASS__, 'type_label' ), $args['post_types'] );
		foreach ( array(
			'include_orders'        => __( 'Orders', 'migrateplus' ),
			'include_site_settings' => __( 'Site settings', 'migrateplus' ),
		) as $arg => $label ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$labels[] = $label;
			}
		}
		$user     = get_userdata( (int) $state['user'] );
		$statuses = MigratePlus_Settings::export_statuses();
		$included = array(
			'include_terms'           => __( 'terms', 'migrateplus' ),
			'include_term_data'       => __( 'term data', 'migrateplus' ),
			'include_image'           => __( 'featured images', 'migrateplus' ),
			'include_meta'            => __( 'custom fields', 'migrateplus' ),
			'include_acf'             => __( 'ACF fields', 'migrateplus' ),
			'include_woo'             => __( 'WooCommerce data', 'migrateplus' ),
			'include_comments'        => __( 'approved comments', 'migrateplus' ),
			'include_yoast'           => __( 'Yoast SEO fields', 'migrateplus' ),
			'include_menus'           => __( 'navigation menus', 'migrateplus' ),
			'include_acf_definitions' => __( 'ACF definitions', 'migrateplus' ),
			'include_acf_options'     => __( 'ACF options pages', 'migrateplus' ),
			'include_site_settings'   => __( 'site settings', 'migrateplus' ),
			'include_orders'          => __( 'orders', 'migrateplus' ),
		);
		$products = array_filter( $args['post_types'], array( 'MigratePlus_Woo', 'is_product_type' ) );
		foreach ( $included as $key => $label ) {
			if ( empty( $args[ $key ] ) || ( 'include_woo' === $key && ! $products ) || ( 'include_yoast' === $key && ! MigratePlus_Yoast::active() ) ) {
				unset( $included[ $key ] );
			}
		}
		$per_type = array();
		foreach ( $state['counts'] as $type => $n ) {
			$per_type[] = self::type_label( $type ) . ': ' . (int) $n;
		}
		$entry = array(
			'id'        => $state['id'],
			'created'   => $state['created'],
			'finished'  => 0,
			'user'      => $user ? $user->display_name : '',
			'via'       => defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'admin',
			'post_type' => implode( ',', $args['post_types'] ),
			'label'     => implode( ', ', $labels ),
			'total'     => (int) $state['total'],
			'done'      => 0,
			'size'      => 0,
			'filename'  => '',
			'status'    => 'running',
			'log'       => array(),
		);
		$lines = array(
			/* translators: 1: post type, 2: count */
			sprintf( __( 'Exporting %1$s: %2$d items match.', 'migrateplus' ), $entry['label'], $entry['total'] ),
			/* translators: %s: count per post type */
			count( $per_type ) > 1 ? sprintf( __( 'In import order: %s.', 'migrateplus' ), implode( ', ', $per_type ) ) : '',
			! empty( $args['post_ids'] ) ? sprintf(
				/* translators: %s: number of posts */
				_n( '%s hand-picked item.', '%s hand-picked items.', count( $args['post_ids'] ), 'migrateplus' ),
				count( $args['post_ids'] )
			) : '',
			array( 'attachment' ) === $args['post_types'] || ! empty( $args['post_ids'] ) ? '' : sprintf(
				/* translators: %s: statuses */
				__( 'Status: %s.', 'migrateplus' ),
				implode(
					', ',
					array_map(
						static function ( $s ) use ( $statuses ) {
							return isset( $statuses[ $s ] ) ? $statuses[ $s ] : $s;
						},
						$args['statuses']
					)
				)
			),
			$args['after'] || $args['before'] ? sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'Published between %1$s and %2$s.', 'migrateplus' ),
				$args['after'] ? $args['after'] : __( 'the first post', 'migrateplus' ),
				$args['before'] ? $args['before'] : __( 'today', 'migrateplus' )
			) : '',
			/* translators: %s: list of what is included */
			sprintf( __( 'Including: %s.', 'migrateplus' ), $included ? implode( ', ', $included ) : __( 'post fields only', 'migrateplus' ) ),
			/* translators: %s: meta keys */
			! empty( $args['include_meta'] ) && ! empty( $args['exclude_meta'] ) ? sprintf( __( 'Leaving out custom fields: %s.', 'migrateplus' ), implode( ', ', $args['exclude_meta'] ) ) : '',
			! empty( $args['gzip'] ) ? __( 'Writing a compressed file (.json.gz).', 'migrateplus' ) : '',
		);
		foreach ( array_filter( $lines ) as $line ) {
			$entry['log'][] = self::log_line( 'info', $line );
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
		$id = $entry['id'];
		MigratePlus_Util::update_option_locked(
			self::LOG_INDEX,
			static function ( $ids ) use ( $id ) {
				return array_values( array_unique( array_merge( (array) $ids, array( $id ) ) ) );
			}
		);
	}

	/**
	 * Add lines to an export's log and update its totals.
	 *
	 * @param array $state State.
	 * @param array $lines From log_line().
	 */
	protected static function log_progress( array $state, array $lines ) {
		$entry = self::history_entry( $state['id'] );
		if ( ! $entry ) {
			return;
		}
		$entry['done'] = (int) $state['done'];
		$entry['log']  = array_merge( $entry['log'], $lines );
		if ( ! empty( $state['error'] ) ) {
			$entry['status']   = 'failed';
			$entry['finished'] = time();
			$entry['log'][]    = self::log_line( 'error', $state['error'] );
		} elseif ( ! empty( $state['complete'] ) ) {
			$entry['status']   = 'complete';
			$entry['finished'] = time();
			$entry['size']     = (int) $state['size'];
			$entry['filename'] = $state['filename'];
			$entry['log'][]    = self::log_line(
				'success',
				/* translators: 1: count, 2: file name, 3: size */
				sprintf( __( 'Wrote %1$d items to %2$s (%3$s).', 'migrateplus' ), $entry['done'], $entry['filename'], size_format( $entry['size'] ) )
			);
			$providers = self::side_providers();
			foreach ( isset( $state['sides'] ) ? (array) $state['sides'] : array() as $key => $n ) {
				if ( ! $n || ! isset( $providers[ $key ] ) ) {
					continue;
				}
				$entry['log'][] = self::log_line(
					'info',
					'site_settings' === $key
						? __( 'Included the site settings.', 'migrateplus' )
						/* translators: 1: count, 2: what (terms, orders...) */
						: sprintf( __( 'Included %1$d %2$s.', 'migrateplus' ), (int) $n, $providers[ $key ]['label'] )
				);
			}
		}
		if ( count( $entry['log'] ) > MigratePlus_Job::MAX_LOG ) {
			$entry['log'] = array_slice( $entry['log'], -MigratePlus_Job::MAX_LOG );
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
	}

	/**
	 * @param string $type Post type.
	 * @return string Its plural label.
	 */
	public static function type_label( $type ) {
		$object = get_post_type_object( $type );
		return $object ? $object->labels->name : $type;
	}

	/**
	 * @param string $level   info|success|warning|error.
	 * @param string $message Message.
	 * @return array
	 */
	protected static function log_line( $level, $message ) {
		return array(
			't' => time(),
			'l' => $level,
			'm' => $message,
		);
	}

	/**
	 * @param string $id Export id.
	 * @return array|null
	 */
	public static function load_state( $id ) {
		$state = get_option( self::STATE_PREFIX . sanitize_key( $id ) );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array $state State.
	 */
	protected static function save_state( array $state ) {
		update_option( self::STATE_PREFIX . $state['id'], $state, false );
	}

	/**
	 * Export the next batch, unless another request is exporting one of this export now.
	 *
	 * @param array $state State (updated in place).
	 * @param int   $limit Posts per batch.
	 * @return array State.
	 */
	public static function run_batch( array &$state, $limit = 50 ) {
		global $wpdb;
		if ( $state['complete'] ) {
			return $state;
		}
		// Two requests on one export (a retry after a timeout) would each append the same posts to the file.
		$name  = 'migrateplus_lock_export_' . $state['id'];
		$token = ( time() + MigratePlus_Runner::LOCK_TTL ) . ':' . wp_generate_password( 8, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the options API has no atomic insert.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );
		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' !== $held && (int) $held < time() ) {
				// Left by a request that died while it held the lock.
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );
			}
		}
		// phpcs:enable
		if ( ! $taken ) {
			return $state;
		}
		// The request that held the lock may have moved the export on since this one loaded it.
		wp_cache_delete( self::STATE_PREFIX . $state['id'], 'options' );
		$fresh = self::load_state( $state['id'] );
		if ( $fresh ) {
			$state = $fresh;
		}
		try {
			self::next_batch( $state, $limit );
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $state;
	}

	/**
	 * Export the next batch.
	 *
	 * @param array $state State (updated in place).
	 * @param int   $limit Posts per batch.
	 * @return array State.
	 */
	protected static function next_batch( array &$state, $limit ) {
		global $wpdb;
		if ( $state['complete'] ) {
			return $state;
		}
		// One post type per batch, in the order of args['post_types']; then the batched side sections.
		$types = $state['args']['post_types'];
		if ( (int) $state['type_pos'] >= count( $types ) ) {
			return self::run_side_batch( $state, $limit );
		}
		$type = $types[ (int) $state['type_pos'] ];
		// Page by ID, not offset: posts deleted between batches would shift later ones out of the export.
		$after = isset( $state['last_id'] ) ? (int) $state['last_id'] : 0;
		// Hand-picked posts: only the picks, not the whole type. The ids come from sanitize_args, already absint'd.
		$picks = empty( $state['args']['post_ids'] ) ? '' : ' AND ID IN (' . implode( ',', array_map( 'intval', $state['args']['post_ids'] ) ) . ')';
		$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s{$picks} AND ID > %d ORDER BY ID ASC LIMIT %d", $type, $after, $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $ids ? ( new WP_Query( array_merge( self::query_args( $state['args'], $type, 1, $limit ), array( 'post__in' => $ids ) ) ) )->posts : array();
		$lines = '';
		$log   = array();
		$skips = 0;
		if ( empty( $state['people_noted'] ) && ! self::user_can( 'list_users' ) ) {
			// Said once, so whoever imports the file knows why the old authors' logins and commenters' emails are missing.
			$state['people_noted'] = 1;
			$log[]                 = self::log_line( 'warning', __( 'Author logins and commenter emails are left out, because you are not allowed to list users. The importing site keeps only the authors\' names with their posts.', 'migrateplus' ) );
		}
		foreach ( $posts as $post ) {
			// Others' drafts and private posts, and posts of types with stricter rights, stay out of the file as they stay out of the editor.
			if ( ! self::user_can( 'edit_post', $post->ID ) ) {
				++$skips;
				continue;
			}
			$item  = self::export_post( $post, $state['args'] );
			$json  = wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$title = '' !== $post->post_title ? $post->post_title : __( '(untitled)', 'migrateplus' );
			if ( false === $json ) {
				// Left out, the item would go missing from a file that says it is complete.
				/* translators: 1: title, 2: post id, 3: JSON error */
				self::fail( $state, sprintf( __( 'Could not write "%1$s" (#%2$d) to the export file: %3$s', 'migrateplus' ), $title, $post->ID, json_last_error_msg() ) );
				break;
			}
			$lines .= $json . "\n";
			/* translators: 1: title, 2: post id */
			$log[]              = self::log_line( 'success', sprintf( __( 'Exported "%1$s" (#%2$d).', 'migrateplus' ), $title, $post->ID ) );
			$state['meta_keys'] = array_values( array_unique( array_merge( $state['meta_keys'], array_keys( $item['meta'] ) ) ) );
			$state['acf_names'] = array_values( array_unique( array_merge( $state['acf_names'], array_keys( $item['acf'] ) ) ) );
			$state['taxes']     = array_values( array_unique( array_merge( $state['taxes'], array_keys( $item['terms'] ) ) ) );
			$state['yoast']     = ! empty( $state['yoast'] ) || ! empty( $item['yoast'] ) ? 1 : 0;
			++$state['done'];
			$state['type_done'][ $type ] = ( isset( $state['type_done'][ $type ] ) ? $state['type_done'][ $type ] : 0 ) + 1;
		}
		if ( $skips ) {
			// A count only: their titles are what the user may not see.
			/* translators: %d: number of items */
			$log[] = self::log_line( 'warning', sprintf( _n( 'Left out %d item you are not allowed to edit.', 'Left out %d items you are not allowed to edit.', $skips, 'migrateplus' ), $skips ) );
		}
		// An item that could not be encoded has failed the export already.
		if ( empty( $state['error'] ) && '' !== $lines && false === file_put_contents( $state['work'], $lines, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::fail( $state );
		} elseif ( empty( $state['error'] ) ) {
			$state['last_id'] = $ids ? end( $ids ) : $after;
			if ( count( $ids ) < $limit ) {
				++$state['type_pos'];
				$state['last_id'] = 0;
			}
			// Not when "done" reaches "total": that was counted at the start, and posts added since would end the export before its last types.
			if ( $state['type_pos'] >= count( $types ) && ! self::batched_sides( $state ) ) {
				self::finish( $state );
			}
		}
		self::save_state( $state );
		self::log_progress( $state, $log );
		return $state;
	}

	/**
	 * The side sections exported a page at a time, after the post types.
	 *
	 * @param array $state State.
	 * @return string[] Keys of side_providers().
	 */
	protected static function batched_sides( array $state ) {
		$out = array();
		foreach ( self::side_providers() as $key => $side ) {
			if ( self::is_batched( $side ) && ! empty( $state['args'][ $side['arg'] ] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Whether a side section is exported a page at a time: marked batched, and its class
	 * has export_batch(). One without it is built whole in finish(), as before.
	 *
	 * @param array $side From side_providers().
	 * @return bool
	 */
	protected static function is_batched( array $side ) {
		return $side['batched'] && class_exists( $side['class'] ) && method_exists( $side['class'], 'export_batch' );
	}

	/**
	 * The batched side section being exported, or '' once they are all written.
	 *
	 * @param array $state State.
	 * @return string
	 */
	public static function current_side( array $state ) {
		$sides = self::batched_sides( $state );
		return (int) $state['type_pos'] >= count( $state['args']['post_types'] ) && isset( $sides[ (int) $state['side_pos'] ] ) ? $sides[ (int) $state['side_pos'] ] : '';
	}

	/**
	 * One page of a batched side section, to a work file of its own.
	 *
	 * @param array $state State (updated in place).
	 * @param int   $limit Items per page.
	 * @return array State.
	 */
	protected static function run_side_batch( array &$state, $limit ) {
		$key = self::current_side( $state );
		if ( '' === $key ) {
			self::finish( $state );
			self::save_state( $state );
			self::log_progress( $state, array() );
			return $state;
		}
		$side  = self::side_providers()[ $key ];
		$page  = call_user_func( array( $side['class'], 'export_batch' ), $state['args'], $state['side_cursor'], $limit );
		$lines = '';
		$log   = array();
		foreach ( $page['items'] as $item ) {
			$json = wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				/* translators: 1: section (orders...), 2: JSON error */
				self::fail( $state, sprintf( __( 'Could not write the %1$s to the export file: %2$s', 'migrateplus' ), $side['label'], json_last_error_msg() ) );
				break;
			}
			$lines .= $json . "\n";
		}
		if ( empty( $state['error'] ) && '' !== $lines && false === file_put_contents( self::side_work_file( $state, $key ), $lines, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::fail( $state );
		} elseif ( empty( $state['error'] ) ) {
			$state['sides'][ $key ] = ( isset( $state['sides'][ $key ] ) ? (int) $state['sides'][ $key ] : 0 ) + count( $page['items'] );
			$state['side_cursor']   = $page['next'];
			if ( count( $page['items'] ) ) {
				/* translators: 1: count, 2: what (orders...) */
				$log[] = self::log_line( 'success', sprintf( __( 'Exported %1$d %2$s.', 'migrateplus' ), count( $page['items'] ), $side['label'] ) );
			}
			if ( null === $page['next'] ) {
				++$state['side_pos'];
				$state['side_cursor'] = null;
				if ( '' === self::current_side( $state ) ) {
					self::finish( $state );
				}
			}
		}
		self::save_state( $state );
		self::log_progress( $state, $log );
		return $state;
	}

	/**
	 * @param array  $state State.
	 * @param string $key   Side section.
	 * @return string Path of its work file (one item per line).
	 */
	protected static function side_work_file( array $state, $key ) {
		return substr( $state['work'], 0, -6 ) . '-' . str_replace( '_', '-', $key ) . '.jsonl';
	}

	/**
	 * Build the final JSON file from the work file.
	 *
	 * @param array $state State (updated in place).
	 */
	protected static function finish( array &$state ) {
		$args = $state['args'];
		// Compressed while it is written, line by line like the plain file, so memory stays flat whatever its size.
		$gzip = ! empty( $args['gzip'] ) && MigratePlus_Util::zlib_available();
		// ".gz" after sanitizing: sanitize_file_name() would turn the inner ".json" into ".json_".
		$kinds = $args['post_types'];
		foreach ( array(
			'include_orders'        => 'orders',
			'include_site_settings' => 'site-settings',
		) as $arg => $kind ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$kinds[] = $kind;
			}
		}
		$name = sanitize_file_name( 'migrateplus-' . ( 1 === count( $kinds ) ? $kinds[0] : 'content' ) . '-' . gmdate( 'Y-m-d-His' ) . '.json' ) . ( $gzip ? '.gz' : '' );

		// Side sections next to the items, each under its own top-level key, in import order.
		// The batched ones were written page by page to work files of their own. The others are
		// built here, before the file is opened, so one that runs out of memory or time leaves
		// no file behind.
		$sides      = array();
		$sides_json = array();
		$batched    = array();
		foreach ( self::side_providers() as $key => $side ) {
			if ( empty( $args[ $side['arg'] ] ) || ! class_exists( $side['class'] ) ) {
				continue;
			}
			if ( self::is_batched( $side ) ) {
				if ( ! empty( $state['sides'][ $key ] ) && file_exists( self::side_work_file( $state, $key ) ) ) {
					$batched[ $key ] = self::side_work_file( $state, $key );
				}
				continue;
			}
			$data = call_user_func( array( $side['class'], 'export' ), $args );
			if ( ! $data ) {
				continue;
			}
			$sides[ $key ]      = $data;
			$sides_json[ $key ] = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $sides_json[ $key ] ) {
				/* translators: 1: section (terms, menus), 2: JSON error */
				self::fail( $state, sprintf( __( 'Could not write the %1$s to the export file: %2$s', 'migrateplus' ), $side['label'], json_last_error_msg() ) );
				return;
			}
		}

		// Named like the work file, secret part included. Saved in the state before it is opened,
		// so delete() and the daily cleanup find what a request that dies while writing leaves.
		$dest          = substr( $state['work'], 0, -6 ) . '-' . $name;
		$state['dest'] = $dest;
		self::save_state( $state );
		$in  = fopen( $state['work'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = $gzip ? gzopen( $dest, 'wb6' ) : fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( $out ) {
				$gzip ? gzclose( $out ) : fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			self::fail( $state );
			return;
		}
		// "post_type" only when there is one: older MigratePlus versions import such a file as that type.
		$types  = $args['post_types'];
		$header = array(
			'version'  => MIGRATEPLUS_VERSION,
			'exported' => gmdate( 'c' ),
			'site'     => home_url(),
		);
		if ( 1 === count( $types ) ) {
			$header['post_type'] = $types[0];
		}
		$header['post_types'] = $types;
		$header['counts']     = (object) $state['type_done'];
		$header['count']      = $state['done'];
		$header['mapping']    = self::mapping_for( $state );
		// A full disk must not leave a cut-off file marked complete: every write is checked.
		$write = static function ( $data ) use ( $out, $gzip ) {
			return strlen( $data ) === ( $gzip ? gzwrite( $out, $data ) : fwrite( $out, $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		};
		$ok    = $write( '{"migrateplus":' . wp_json_encode( $header, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . ",\n\"items\":[\n" );
		$first = true;
		while ( $ok && false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$ok    = $write( ( $first ? '' : ",\n" ) . $line );
			$first = false;
		}
		$ok = $ok && $write( "\n]" );
		// Sections written whole, then the batched ones streamed from their work files, all in import order.
		foreach ( self::side_providers() as $key => $side ) {
			if ( isset( $sides_json[ $key ] ) ) {
				$ok = $ok && $write( ",\n\"" . $key . '":' . $sides_json[ $key ] );
			} elseif ( isset( $batched[ $key ] ) ) {
				$ok      = $ok && $write( ",\n\"" . $key . "\":[\n" );
				$side_in = fopen( $batched[ $key ], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$first   = true;
				while ( $ok && $side_in && false !== ( $line = fgets( $side_in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					$line = rtrim( $line, "\r\n" );
					if ( '' === $line ) {
						continue;
					}
					$ok    = $write( ( $first ? '' : ",\n" ) . $line );
					$first = false;
				}
				if ( $side_in ) {
					fclose( $side_in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				$ok = $ok && $write( "\n]" );
			}
		}
		$ok = $ok && $write( "}\n" );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ok = ( $gzip ? gzclose( $out ) : fclose( $out ) ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $ok ) {
			wp_delete_file( $dest );
			self::fail( $state );
			return;
		}
		wp_delete_file( $state['work'] );
		foreach ( $batched as $file ) {
			wp_delete_file( $file );
		}

		$state['file'] = $dest;
		$state['dest'] = '';
		foreach ( $sides as $key => $data ) {
			$state['sides'][ $key ] = count( $data );
		}
		// From the counts, which batched sections have too.
		$state['menus']    = isset( $state['sides']['menus'] ) ? (int) $state['sides']['menus'] : 0;
		$state['terms']    = isset( $state['sides']['terms'] ) ? (int) $state['sides']['terms'] : 0;
		$state['filename'] = $name;
		$state['size']     = (int) filesize( $dest );
		$state['complete'] = true;
	}

	/**
	 * Stop an export whose file could not be written. It counts as complete so
	 * batch loops end, with no file to download.
	 *
	 * @param array  $state   State (updated in place).
	 * @param string $message Why, when it is not a failed write.
	 */
	protected static function fail( array &$state, $message = '' ) {
		$state['error']    = '' !== $message ? $message : __( 'Could not write the export file in the uploads folder.', 'migrateplus' );
		$state['complete'] = true;
		$state['file']     = '';
		$state['filename'] = '';
		$state['size']     = 0;
	}

	/**
	 * Mapping that MigratePlus applies automatically when it imports this export.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function mapping_for( array $state ) {
		$fields = array(
			'source_id'         => 'id',
			'post_type'         => 'post_type',
			'post_title'        => 'title',
			'post_content'      => 'content',
			'post_excerpt'      => 'excerpt',
			'post_date'         => 'date_gmt',
			'post_name'         => 'slug',
			'post_status'       => 'status',
			'post_author'       => 'author',
			'post_author_login' => 'author_login',
			'post_author_name'  => 'author_name',
			'menu_order'        => 'menu_order',
			'comment_status'    => 'comment_status',
			'post_parent'       => 'parent.id',
			'post_password'     => 'password',
			'page_template'     => 'template',
			'post_format'       => 'format',
		);
		if ( ! empty( $state['args']['include_terms'] ) ) {
			if ( in_array( 'category', $state['taxes'], true ) ) {
				$fields['categories'] = 'terms.category';
			}
			if ( in_array( 'post_tag', $state['taxes'], true ) ) {
				$fields['tags'] = 'terms.post_tag';
			}
		}
		if ( ! empty( $state['args']['include_comments'] ) ) {
			$fields['comments'] = 'comments';
		}
		if ( ! empty( $state['yoast'] ) ) {
			$fields['yoast'] = 'yoast';
		}
		$fields['source_url'] = 'link';
		// One mapping for every post type in the file: media fields are only read for media, featured images for the rest.
		$types = $state['args']['post_types'];
		if ( in_array( 'attachment', $types, true ) ) {
			$fields['media_url'] = 'file';
			$fields['media_alt'] = 'alt';
		}
		if ( array_diff( $types, array( 'attachment' ) ) && ! empty( $state['args']['include_image'] ) ) {
			$fields['featured_image']     = 'featured_image';
			$fields['featured_image_alt'] = 'featured_image_alt';
		}
		$taxonomies = array();
		foreach ( $state['taxes'] as $tax ) {
			if ( ! in_array( $tax, array( 'category', 'post_tag' ), true ) ) {
				$taxonomies[] = array(
					'taxonomy' => $tax,
					'source'   => 'terms.' . $tax,
				);
			}
		}
		$meta = array();
		foreach ( $state['meta_keys'] as $key ) {
			$meta[] = array(
				'key'    => $key,
				'source' => 'meta.' . $key,
			);
		}
		$acf = array();
		foreach ( $state['acf_names'] as $name ) {
			$acf[ $name ] = 'acf.' . $name;
		}
		return array(
			'fields'      => $fields,
			'meta'        => $meta,
			'taxonomies'  => $taxonomies,
			'acf'         => $acf,
			'meta_native' => 1, // Arrays in "meta" are JSON arrays/objects, not JSON text as in older exports.
		);
	}

	/**
	 * One post as a portable record.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $args Export args.
	 * @return array
	 */
	public static function export_post( WP_Post $post, array $args ) {
		$author = get_userdata( (int) $post->post_author );
		// Logins only for those who may see them in Users; the importing site keeps them as a note.
		$people = self::user_can( 'list_users' );
		$item   = array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'date'           => $post->post_date,
			'date_gmt'       => self::date_gmt( $post ),
			'modified_gmt'   => gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'post_type'      => $post->post_type,
			// The login, not the email: the importing site picks the author, and keeps this as a note.
			'author'         => $author && $people ? $author->user_login : '',
			'author_name'    => $author ? $author->display_name : '',
			'author_login'   => $author && $people ? $author->user_login : '',
			'parent'         => $post->post_parent ? self::post_ref( (int) $post->post_parent ) : null,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'password'       => self::user_can( 'edit_post', $post->ID ) ? $post->post_password : '',
			'template'       => (string) get_post_meta( $post->ID, '_wp_page_template', true ),
			'format'         => (string) get_post_format( $post ),
			'link'           => get_permalink( $post ),
			'terms'          => array(),
			'meta'           => array(),
			'acf'            => array(),
		);

		if ( 'attachment' === $post->post_type ) {
			// Caption and description are the excerpt and content; the file itself travels as a URL.
			$item['file']      = (string) wp_get_attachment_url( $post->ID );
			$item['alt']       = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
			$item['mime_type'] = $post->post_mime_type;
		} elseif ( ! empty( $args['include_image'] ) ) {
			$thumb                      = (int) get_post_thumbnail_id( $post );
			$item['featured_image']     = $thumb ? (string) wp_get_attachment_url( $thumb ) : '';
			$item['featured_image_id']  = $thumb;
			$item['featured_image_alt'] = $thumb ? (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) : '';
		}

		if ( ! empty( $args['include_terms'] ) ) {
			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
				// Hidden taxonomies stay out, except the theme and area a template belongs to.
				if ( 'post_format' === $tax->name || ( ! $tax->public && ! $tax->show_ui && ! in_array( $tax->name, MigratePlus_Blocks::HIDDEN_TAXONOMIES, true ) ) ) {
					continue;
				}
				$terms = get_the_terms( $post, $tax->name );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$item['terms'][ $tax->name ] = array_map(
						static function ( $t ) use ( $tax ) {
							return self::term_path( $t, $tax->hierarchical );
						},
						$terms
					);
				}
			}
		}

		$acf_names = array();
		if ( ! empty( $args['include_acf'] ) && MigratePlus_ACF::active() ) {
			foreach ( MigratePlus_ACF::fields_for_post_type( $post->post_type, $post->ID ) as $f ) {
				$field = function_exists( 'acf_get_field' ) ? acf_get_field( $f['key'] ) : null;
				if ( ! $field ) {
					continue;
				}
				$raw = get_field( $field['key'], $post->ID, false );
				if ( null === $raw || '' === $raw || ( false === $raw && 'true_false' !== $field['type'] ) ) {
					continue;
				}
				$item['acf'][ $field['name'] ] = self::export_acf_value( $field, $raw );
				$acf_names[ $field['name'] ]   = true;
			}
		}

		if ( ! empty( $args['include_meta'] ) ) {
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				// Skip private keys (ACF references, edit locks, MigratePlus tracking) and ACF values exported above.
				if ( '' === $key || '_' === $key[0] || isset( $acf_names[ $key ] ) || is_protected_meta( $key, 'post' ) ) {
					continue;
				}
				if ( ! empty( $args['exclude_meta'] ) && self::meta_excluded( $key, $args['exclude_meta'] ) ) {
					continue;
				}
				if ( MigratePlus_ACF::active() && 0 === strpos( (string) get_post_meta( $post->ID, '_' . $key, true ), 'field_' ) ) {
					continue; // Sub-field rows of an ACF repeater/group, e.g. items_0_title.
				}
				// Arrays stay arrays; a key stored in several rows becomes { "_migrateplus_rows": [ ... ] }.
				$rows                 = array_map( 'maybe_unserialize', $values );
				$item['meta'][ $key ] = count( $rows ) > 1 ? array( '_migrateplus_rows' => $rows ) : $rows[0];
			}
		}

		if ( ! empty( $args['include_woo'] ) && MigratePlus_Woo::is_product_type( $post->post_type ) ) {
			MigratePlus_Woo::export_product( $post, $item, $args );
		}
		if ( 'shop_coupon' === $post->post_type ) {
			MigratePlus_Woo::export_coupon( $post, $item );
		}

		if ( ! empty( $args['include_comments'] ) ) {
			$item['comments'] = self::export_comments( $post, $args );
		}

		if ( ! empty( $args['include_yoast'] ) && MigratePlus_Yoast::active() ) {
			$seo = MigratePlus_Yoast::export_post( $post );
			if ( $seo ) {
				$item['yoast'] = $seo;
			}
		}

		return apply_filters( 'migrateplus_export_item', $item, $post, $args );
	}

	/**
	 * When a post was published, as UTC. Drafts and posts pending review have no GMT date
	 * until they are published, only the local one; without it the importing site would
	 * date them the day of the import.
	 *
	 * @param WP_Post $post Post.
	 * @return string ISO 8601, or '' when the post has no date at all.
	 */
	protected static function date_gmt( WP_Post $post ) {
		if ( '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			return gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) );
		}
		return '0000-00-00 00:00:00' === $post->post_date ? '' : get_gmt_from_date( $post->post_date, 'c' );
	}

	/**
	 * A post's approved comments, oldest first, each with its parent's id so replies
	 * can be threaded again. Pending, spam and trashed comments stay behind: they
	 * are moderation work on this site, not content to move.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $args Export args (custom field keys to leave out apply to comment meta too).
	 * @return array[]
	 */
	protected static function export_comments( WP_Post $post, array $args ) {
		$out    = array();
		$people = self::user_can( 'list_users' );
		foreach ( get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		) as $c ) {
			$user = $c->user_id ? get_userdata( (int) $c->user_id ) : false;
			$meta = array();
			foreach ( get_comment_meta( $c->comment_ID ) as $key => $values ) {
				if ( '' === $key || '_' === $key[0] || is_protected_meta( $key, 'comment' ) ) {
					continue;
				}
				if ( ! empty( $args['exclude_meta'] ) && self::meta_excluded( $key, $args['exclude_meta'] ) ) {
					continue;
				}
				$rows         = array_map( 'maybe_unserialize', $values );
				$meta[ $key ] = count( $rows ) > 1 ? array( '_migrateplus_rows' => $rows ) : $rows[0];
			}
			$out[] = array(
				'id'           => (int) $c->comment_ID,
				'parent'       => (int) $c->comment_parent,
				'author'       => $c->comment_author,
				'author_email' => $people ? $c->comment_author_email : '',
				'author_url'   => $c->comment_author_url,
				'date'         => $c->comment_date,
				'date_gmt'     => '0000-00-00 00:00:00' === $c->comment_date_gmt ? '' : gmdate( 'c', strtotime( $c->comment_date_gmt . ' UTC' ) ),
				'content'      => $c->comment_content,
				'approved'     => $c->comment_approved,
				'type'         => $c->comment_type,
				'user_email'   => $user && $people ? $user->user_email : '',
				'meta'         => $meta,
			);
		}
		return $out;
	}

	/**
	 * Whether the person exporting may do something. Nobody logged in is WP-CLI
	 * without --user, which runs with the shell account's access, as it always has.
	 *
	 * @param string $capability Capability.
	 * @param mixed  ...$args    Object id, for meta capabilities.
	 * @return bool
	 */
	protected static function user_can( $capability, ...$args ) {
		return ! get_current_user_id() || current_user_can( $capability, ...$args );
	}

	/**
	 * Portable reference to a post.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	public static function post_ref( $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			return null;
		}
		return array(
			'id'    => (int) $p->ID,
			'slug'  => $p->post_name,
			'title' => $p->post_title,
			'type'  => $p->post_type,
		);
	}

	/**
	 * Term name, as "Parent > Child" for hierarchical taxonomies.
	 *
	 * @param WP_Term $term         Term.
	 * @param bool    $hierarchical Hierarchical.
	 * @return string
	 */
	public static function term_path( WP_Term $term, $hierarchical ) {
		$name = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		if ( ! $hierarchical || ! $term->parent ) {
			return $name;
		}
		$names = array( $name );
		$guard = 0;
		$p     = $term;
		while ( $p->parent && $guard++ < 20 ) {
			$p = get_term( $p->parent, $term->taxonomy );
			if ( ! $p instanceof WP_Term ) {
				break;
			}
			array_unshift( $names, html_entity_decode( $p->name, ENT_QUOTES, 'UTF-8' ) );
		}
		return implode( ' > ', $names );
	}

	/**
	 * Raw ACF value => portable value that MigratePlus_ACF::convert() reads back.
	 *
	 * @param array $field Field.
	 * @param mixed $raw   Raw stored value (get_field( ..., false )).
	 * @return mixed
	 */
	public static function export_acf_value( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'image':
			case 'file':
				return is_numeric( $raw ) ? (string) wp_get_attachment_url( (int) $raw ) : $raw;

			case 'gallery':
				return array_values(
					array_filter(
						array_map(
							static function ( $id ) {
								return is_numeric( $id ) ? (string) wp_get_attachment_url( (int) $id ) : (string) $id;
							},
							(array) $raw
						)
					)
				);

			case 'post_object':
			case 'relationship':
			case 'page_link':
				$refs = array();
				foreach ( (array) $raw as $id ) {
					$refs[] = is_numeric( $id ) ? self::post_ref( (int) $id ) : $id;
				}
				$refs = array_values( array_filter( $refs ) );
				return 'relationship' === $field['type'] || ! empty( $field['multiple'] ) ? $refs : ( $refs ? $refs[0] : '' );

			case 'taxonomy':
				$tax   = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'category';
				$names = array();
				foreach ( (array) $raw as $id ) {
					$term = get_term( (int) $id, $tax );
					if ( $term instanceof WP_Term ) {
						$names[] = self::term_path( $term, is_taxonomy_hierarchical( $tax ) );
					}
				}
				return $names;

			case 'user':
				// Email and login: the same person often has a different email on each site.
				// Only the display name for those who may not list users; the importer matches that too.
				$people = self::user_can( 'list_users' );
				$users  = array();
				foreach ( (array) $raw as $id ) {
					$u = get_userdata( (int) $id );
					if ( $u ) {
						$users[] = $people ? array(
							'email' => $u->user_email,
							'login' => $u->user_login,
						) : $u->display_name;
					}
				}
				return ! empty( $field['multiple'] ) ? $users : ( $users ? $users[0] : '' );

			case 'repeater':
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$rows[] = self::export_row( (array) $field['sub_fields'], (array) $row );
				}
				return $rows;

			case 'clone': // Its value is keyed by the cloned fields' keys, like a group's.
			case 'group':
				return self::export_row( (array) $field['sub_fields'], is_array( $raw ) ? $raw : array() );

			case 'flexible_content':
				$layouts = array();
				foreach ( isset( $field['layouts'] ) ? (array) $field['layouts'] : array() as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$name = isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
					if ( isset( $layouts[ $name ] ) ) {
						$rows[] = array( 'acf_fc_layout' => $name ) + self::export_row( (array) $layouts[ $name ]['sub_fields'], (array) $row );
					}
				}
				return $rows;

			default:
				return $raw;
		}
	}

	/**
	 * Raw row keyed by sub field keys => portable row keyed by sub field names.
	 *
	 * @param array[] $subs Sub fields.
	 * @param array   $row  Raw row.
	 * @return array
	 */
	protected static function export_row( array $subs, array $row ) {
		$out = array();
		foreach ( $subs as $sub ) {
			if ( empty( $sub['name'] ) ) {
				continue;
			}
			$raw = array_key_exists( $sub['key'], $row ) ? $row[ $sub['key'] ] : ( array_key_exists( $sub['name'], $row ) ? $row[ $sub['name'] ] : null );
			if ( null !== $raw ) {
				$out[ $sub['name'] ] = self::export_acf_value( $sub, $raw );
			}
		}
		return $out;
	}

	/**
	 * Delete an export's files and state.
	 *
	 * @param array $state State.
	 */
	public static function delete( array $state ) {
		foreach ( self::batched_sides( $state ) as $key ) {
			if ( file_exists( self::side_work_file( $state, $key ) ) ) {
				wp_delete_file( self::side_work_file( $state, $key ) );
			}
		}
		foreach ( array( 'work', 'file', 'dest' ) as $k ) {
			if ( ! empty( $state[ $k ] ) && file_exists( $state[ $k ] ) ) {
				wp_delete_file( $state[ $k ] );
			}
		}
		delete_option( self::STATE_PREFIX . $state['id'] );
	}

	/**
	 * Remove exports older than a day (runs with the daily cleanup), or every one.
	 *
	 * @param bool $all Every export, whatever its age (on deactivation). The cron hook passes ''.
	 */
	public static function cleanup( $all = false ) {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::STATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			$state = get_option( $name );
			if ( is_array( $state ) && ( true === $all || empty( $state['created'] ) || $state['created'] < time() - DAY_IN_SECONDS ) ) {
				self::delete( $state );
			}
		}
	}
}
