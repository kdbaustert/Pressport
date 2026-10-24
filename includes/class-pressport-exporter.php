<?php
/**
 * Export content in a format PressPort can import.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes posts to a JSON file, in batches, with everything PressPort needs
 * to rebuild them elsewhere: core fields, author email, hierarchical terms,
 * featured image URL, custom fields and ACF fields (images as URLs, related posts
 * as { id, slug, title } references, terms as names, users as emails).
 *
 * JSON exports embed a field mapping, so importing the file maps itself.
 */
class PressPort_Exporter {

	const STATE_PREFIX = 'pressport_export_';
	// Not under STATE_PREFIX: cleanup() deletes everything there after a day.
	const LOG_PREFIX = 'pressport_exportlog_';
	const LOG_INDEX  = 'pressport_exportlog_ids';

	/**
	 * Default export arguments.
	 *
	 * @return array
	 */
	public static function defaults() {
		$s = PressPort_Settings::get();
		return array(
			'post_type'     => 'post',
			'statuses'      => (array) $s['export_statuses'],
			'after'         => '',
			'before'        => '',
			'include_acf'   => (int) $s['export_include_acf'],
			'include_meta'  => (int) $s['export_include_meta'],
			'include_terms' => (int) $s['export_include_terms'],
			'include_image' => (int) $s['export_include_image'],
			'include_woo'   => (int) $s['export_include_woo'],
			'exclude_meta'  => self::meta_patterns( $s['export_exclude_meta'] ),
		);
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
	protected static function meta_excluded( $key, array $patterns ) {
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
		$args      = array_merge( self::defaults(), $raw );
		$post_type = sanitize_key( $args['post_type'] );
		if ( ! post_type_exists( $post_type ) ) {
			/* translators: %s: post type */
			return new WP_Error( 'pressport_export_type', sprintf( __( 'Unknown post type "%s".', 'pressport' ), $post_type ) );
		}
		$allowed  = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$statuses = is_array( $args['statuses'] ) ? $args['statuses'] : explode( ',', (string) $args['statuses'] );
		$statuses = array_values( array_intersect( array_map( 'sanitize_key', array_map( 'trim', $statuses ) ), array_merge( $allowed, array( 'any' ) ) ) );
		if ( in_array( 'any', $statuses, true ) || ! $statuses ) {
			$statuses = $allowed;
		}
		if ( 'attachment' === $post_type ) {
			$statuses = array( 'inherit' ); // Media has no publish status of its own.
		}
		$date = static function ( $value ) {
			$value = trim( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return array(
			'post_type'     => $post_type,
			'statuses'      => $statuses,
			'after'         => $date( $args['after'] ),
			'before'        => $date( $args['before'] ),
			'include_acf'   => empty( $args['include_acf'] ) ? 0 : 1,
			'include_meta'  => empty( $args['include_meta'] ) ? 0 : 1,
			'include_terms' => empty( $args['include_terms'] ) ? 0 : 1,
			'include_image' => empty( $args['include_image'] ) ? 0 : 1,
			'include_woo'   => empty( $args['include_woo'] ) ? 0 : 1,
			'exclude_meta'  => self::meta_patterns( $args['exclude_meta'] ),
		);
	}

	/**
	 * WP_Query args for an export.
	 *
	 * @param array $args  Export args.
	 * @param int   $page  1-based page.
	 * @param int   $limit Page size.
	 * @return array
	 */
	protected static function query_args( array $args, $page, $limit ) {
		$q = array(
			'post_type'              => $args['post_type'],
			'post_status'            => $args['statuses'],
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => $limit,
			'paged'                  => max( 1, (int) $page ),
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => ! empty( $args['include_terms'] ),
		);
		if ( 'attachment' === $args['post_type'] ) {
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
	 * Count posts matching the export.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) {
		$q = new WP_Query( array_merge( self::query_args( $args, 1, 1 ), array( 'fields' => 'ids' ) ) );
		return (int) $q->found_posts;
	}

	// Stateful, batched export (admin AJAX and WP-CLI share this).

	/**
	 * Start an export: create the work file and remember the state.
	 *
	 * @param array $args Sanitized args.
	 * @return array|WP_Error State.
	 */
	public static function start( array $args ) {
		$id    = strtolower( wp_generate_password( 12, false ) );
		$dir   = PressPort_Util::ensure_storage_dir();
		$state = array(
			'id'        => $id,
			'args'      => $args,
			'user'      => get_current_user_id(),
			'created'   => time(),
			'total'     => self::count( $args ),
			'done'      => 0,
			'last_id'   => 0,
			'work'      => $dir . 'export-' . $id . '.jsonl', // One item per line until finished.
			'file'      => '',
			'meta_keys' => array(),
			'acf_names' => array(),
			'taxes'     => array(),
			'complete'  => false,
		);
		if ( false === file_put_contents( $state['work'], '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'pressport_export_write', __( 'Could not write to the uploads folder.', 'pressport' ) );
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
		update_option( self::LOG_INDEX, array_values( array_diff( (array) get_option( self::LOG_INDEX, array() ), array( $id ) ) ), false );
	}

	/**
	 * @param array $state State, just started.
	 */
	protected static function log_start( array $state ) {
		$args     = $state['args'];
		$type     = get_post_type_object( $args['post_type'] );
		$user     = get_userdata( (int) $state['user'] );
		$statuses = PressPort_Settings::export_statuses();
		$included = array(
			'include_terms' => __( 'terms', 'pressport' ),
			'include_image' => __( 'featured images', 'pressport' ),
			'include_meta'  => __( 'custom fields', 'pressport' ),
			'include_acf'   => __( 'ACF fields', 'pressport' ),
			'include_woo'   => __( 'WooCommerce data', 'pressport' ),
		);
		foreach ( $included as $key => $label ) {
			if ( empty( $args[ $key ] ) || ( 'include_woo' === $key && ! PressPort_Woo::is_product_type( $args['post_type'] ) ) ) {
				unset( $included[ $key ] );
			}
		}
		$entry = array(
			'id'        => $state['id'],
			'created'   => $state['created'],
			'finished'  => 0,
			'user'      => $user ? $user->display_name : '',
			'via'       => defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'admin',
			'post_type' => $args['post_type'],
			'label'     => $type ? $type->labels->name : $args['post_type'],
			'total'     => (int) $state['total'],
			'done'      => 0,
			'size'      => 0,
			'filename'  => '',
			'status'    => 'running',
			'log'       => array(),
		);
		$lines = array(
			/* translators: 1: post type, 2: count */
			sprintf( __( 'Exporting %1$s: %2$d items match.', 'pressport' ), $entry['label'], $entry['total'] ),
			'attachment' === $args['post_type'] ? '' : sprintf(
				/* translators: %s: statuses */
				__( 'Status: %s.', 'pressport' ),
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
				__( 'Published between %1$s and %2$s.', 'pressport' ),
				$args['after'] ? $args['after'] : __( 'the first post', 'pressport' ),
				$args['before'] ? $args['before'] : __( 'today', 'pressport' )
			) : '',
			/* translators: %s: list of what is included */
			sprintf( __( 'Including: %s.', 'pressport' ), $included ? implode( ', ', $included ) : __( 'post fields only', 'pressport' ) ),
			/* translators: %s: meta keys */
			! empty( $args['include_meta'] ) && ! empty( $args['exclude_meta'] ) ? sprintf( __( 'Leaving out custom fields: %s.', 'pressport' ), implode( ', ', $args['exclude_meta'] ) ) : '',
		);
		foreach ( array_filter( $lines ) as $line ) {
			$entry['log'][] = self::log_line( 'info', $line );
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
		$ids   = (array) get_option( self::LOG_INDEX, array() );
		$ids[] = $entry['id'];
		update_option( self::LOG_INDEX, array_values( array_unique( $ids ) ), false );
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
				sprintf( __( 'Wrote %1$d items to %2$s (%3$s).', 'pressport' ), $entry['done'], $entry['filename'], size_format( $entry['size'] ) )
			);
		}
		if ( count( $entry['log'] ) > PressPort_Job::MAX_LOG ) {
			$entry['log'] = array_slice( $entry['log'], -PressPort_Job::MAX_LOG );
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
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
	 * Export the next batch.
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
		// Page by ID, not offset: posts deleted between batches would shift later ones out of the export.
		$after = isset( $state['last_id'] ) ? (int) $state['last_id'] : 0;
		$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", $state['args']['post_type'], $after, $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts = $ids ? ( new WP_Query( array_merge( self::query_args( $state['args'], 1, $limit ), array( 'post__in' => $ids ) ) ) )->posts : array();
		$lines = '';
		$log   = array();
		foreach ( $posts as $post ) {
			$item   = self::export_post( $post, $state['args'] );
			$lines .= wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			/* translators: 1: title, 2: post id */
			$log[]              = self::log_line( 'success', sprintf( __( 'Exported "%1$s" (#%2$d).', 'pressport' ), '' !== $post->post_title ? $post->post_title : __( '(untitled)', 'pressport' ), $post->ID ) );
			$state['meta_keys'] = array_values( array_unique( array_merge( $state['meta_keys'], array_keys( $item['meta'] ) ) ) );
			$state['acf_names'] = array_values( array_unique( array_merge( $state['acf_names'], array_keys( $item['acf'] ) ) ) );
			$state['taxes']     = array_values( array_unique( array_merge( $state['taxes'], array_keys( $item['terms'] ) ) ) );
			++$state['done'];
		}
		if ( '' !== $lines && false === file_put_contents( $state['work'], $lines, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::fail( $state );
		} else {
			$state['last_id'] = $ids ? end( $ids ) : $after;
			if ( count( $ids ) < $limit || $state['done'] >= $state['total'] ) {
				self::finish( $state );
			}
		}
		self::save_state( $state );
		self::log_progress( $state, $log );
		return $state;
	}

	/**
	 * Build the final JSON file from the work file.
	 *
	 * @param array $state State (updated in place).
	 */
	protected static function finish( array &$state ) {
		$args = $state['args'];
		$name = sanitize_file_name( 'pressport-' . $args['post_type'] . '-' . gmdate( 'Y-m-d-His' ) . '.json' );
		$dest = PressPort_Util::storage_dir() . 'export-' . $state['id'] . '-' . $name;
		$in   = fopen( $state['work'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out  = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( $out ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			self::fail( $state );
			return;
		}
		$header = array(
			'version'   => PRESSPORT_VERSION,
			'exported'  => gmdate( 'c' ),
			'site'      => home_url(),
			'post_type' => $args['post_type'],
			'count'     => $state['done'],
			'mapping'   => self::mapping_for( $state ),
		);
		// A full disk must not leave a cut-off file marked complete: every write is checked.
		$write = static function ( $data ) use ( $out ) {
			return strlen( $data ) === fwrite( $out, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		};
		$ok    = $write( '{"pressport":' . wp_json_encode( $header, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . ",\n\"items\":[\n" );
		$first = true;
		while ( $ok && false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$ok    = $write( ( $first ? '' : ",\n" ) . $line );
			$first = false;
		}
		$ok = $ok && $write( "\n]}\n" );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ok = fclose( $out ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $ok ) {
			wp_delete_file( $dest );
			self::fail( $state );
			return;
		}
		wp_delete_file( $state['work'] );

		$state['file']     = $dest;
		$state['filename'] = $name;
		$state['size']     = (int) filesize( $dest );
		$state['complete'] = true;
	}

	/**
	 * Stop an export whose file could not be written. It counts as complete so
	 * batch loops end, with no file to download.
	 *
	 * @param array $state State (updated in place).
	 */
	protected static function fail( array &$state ) {
		$state['error']    = __( 'Could not write the export file in the uploads folder.', 'pressport' );
		$state['complete'] = true;
		$state['file']     = '';
		$state['filename'] = '';
		$state['size']     = 0;
	}

	/**
	 * Mapping that PressPort applies automatically when it imports this export.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function mapping_for( array $state ) {
		$fields = array(
			'source_id'      => 'id',
			'post_type'      => 'post_type',
			'post_title'     => 'title',
			'post_content'   => 'content',
			'post_excerpt'   => 'excerpt',
			'post_date'      => 'date_gmt',
			'post_name'      => 'slug',
			'post_status'    => 'status',
			'post_author'    => 'author',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
			'post_parent'    => 'parent.id',
			'post_password'  => 'password',
			'page_template'  => 'template',
			'post_format'    => 'format',
		);
		if ( ! empty( $state['args']['include_terms'] ) ) {
			if ( in_array( 'category', $state['taxes'], true ) ) {
				$fields['categories'] = 'terms.category';
			}
			if ( in_array( 'post_tag', $state['taxes'], true ) ) {
				$fields['tags'] = 'terms.post_tag';
			}
		}
		if ( 'attachment' === $state['args']['post_type'] ) {
			$fields['media_url'] = 'file';
			$fields['media_alt'] = 'alt';
		} elseif ( ! empty( $state['args']['include_image'] ) ) {
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
		$item   = array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'date'           => $post->post_date,
			'date_gmt'       => '0000-00-00 00:00:00' === $post->post_date_gmt ? '' : gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) ),
			'modified_gmt'   => gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'post_type'      => $post->post_type,
			'author'         => $author ? $author->user_email : '',
			'author_name'    => $author ? $author->display_name : '',
			'author_login'   => $author ? $author->user_login : '',
			'parent'         => $post->post_parent ? self::post_ref( (int) $post->post_parent ) : null,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'password'       => $post->post_password,
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
				if ( 'post_format' === $tax->name || ( ! $tax->public && ! $tax->show_ui ) ) {
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
		if ( ! empty( $args['include_acf'] ) && PressPort_ACF::active() ) {
			foreach ( PressPort_ACF::fields_for_post_type( $post->post_type, $post->ID ) as $f ) {
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
				// Skip private keys (ACF references, edit locks, PressPort tracking) and ACF values exported above.
				if ( '' === $key || '_' === $key[0] || isset( $acf_names[ $key ] ) || is_protected_meta( $key, 'post' ) ) {
					continue;
				}
				if ( ! empty( $args['exclude_meta'] ) && self::meta_excluded( $key, $args['exclude_meta'] ) ) {
					continue;
				}
				if ( PressPort_ACF::active() && 0 === strpos( (string) get_post_meta( $post->ID, '_' . $key, true ), 'field_' ) ) {
					continue; // Sub-field rows of an ACF repeater/group, e.g. items_0_title.
				}
				// Arrays stay arrays; a key stored in several rows becomes { "_pressport_rows": [ ... ] }.
				$rows                 = array_map( 'maybe_unserialize', $values );
				$item['meta'][ $key ] = count( $rows ) > 1 ? array( '_pressport_rows' => $rows ) : $rows[0];
			}
		}

		if ( ! empty( $args['include_woo'] ) && PressPort_Woo::is_product_type( $post->post_type ) ) {
			PressPort_Woo::export_product( $post, $item, $args );
		}

		return apply_filters( 'pressport_export_item', $item, $post, $args );
	}

	/**
	 * Portable reference to a post.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	protected static function post_ref( $id ) {
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
	protected static function term_path( WP_Term $term, $hierarchical ) {
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
	 * Raw ACF value => portable value that PressPort_ACF::convert() reads back.
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
				$users = array();
				foreach ( (array) $raw as $id ) {
					$u = get_userdata( (int) $id );
					if ( $u ) {
						$users[] = array(
							'email' => $u->user_email,
							'login' => $u->user_login,
						);
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
		foreach ( array( 'work', 'file' ) as $k ) {
			if ( ! empty( $state[ $k ] ) && file_exists( $state[ $k ] ) ) {
				wp_delete_file( $state[ $k ] );
			}
		}
		delete_option( self::STATE_PREFIX . $state['id'] );
	}

	/**
	 * Remove exports older than a day (runs with the daily cleanup).
	 */
	public static function cleanup() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::STATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			$state = get_option( $name );
			if ( is_array( $state ) && ( empty( $state['created'] ) || $state['created'] < time() - DAY_IN_SECONDS ) ) {
				self::delete( $state );
			}
		}
	}
}
