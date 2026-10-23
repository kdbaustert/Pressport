<?php
/**
 * Maps one source record onto a WordPress post.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Record → post importer.
 *
 * Developer hooks:
 *  - filter pressport_record( array $record, PressPort_Job $job )               Modify/skip (return false) a record.
 *  - filter pressport_post_data( array $postarr, array $record, PressPort_Job ) Adjust wp_insert_post() args.
 *  - action pressport_after_import_record( int $post_id, array $record, PressPort_Job $job, string $action )
 */
class PressPort_Importer {

	protected static $authors = array();

	/**
	 * Mappable WordPress fields.
	 *
	 * @return array key => [label, help]
	 */
	public static function targets() {
		return array(
			'source_id'          => array( __( 'Source ID (for de-duplication)', 'pressport' ), __( 'Unique ID in the source. Used to skip or update on re-runs.', 'pressport' ) ),
			'post_title'         => array( __( 'Title', 'pressport' ), '' ),
			'post_content'       => array( __( 'Content', 'pressport' ), __( 'HTML or block markup.', 'pressport' ) ),
			'post_excerpt'       => array( __( 'Excerpt', 'pressport' ), '' ),
			'post_date'          => array( __( 'Publish date', 'pressport' ), __( 'Any format strtotime() understands, or a Unix timestamp.', 'pressport' ) ),
			'post_name'          => array( __( 'Slug', 'pressport' ), __( 'Paths like /blog/my-post use the last segment.', 'pressport' ) ),
			'post_status'        => array( __( 'Status', 'pressport' ), __( 'publish/draft/pending/private, or true/false, 1/0.', 'pressport' ) ),
			'post_author'        => array( __( 'Author', 'pressport' ), __( 'Matched by email, login, slug, then display name.', 'pressport' ) ),
			'menu_order'         => array( __( 'Menu order', 'pressport' ), '' ),
			'comment_status'     => array( __( 'Comment status', 'pressport' ), __( 'open or closed', 'pressport' ) ),
			'categories'         => array( __( 'Categories', 'pressport' ), __( 'List or "A, B" / "A|B". Use "Parent > Child" for hierarchy.', 'pressport' ) ),
			'tags'               => array( __( 'Tags', 'pressport' ), '' ),
			'featured_image'     => array( __( 'Featured image URL', 'pressport' ), '' ),
			'featured_image_alt' => array( __( 'Featured image alt text', 'pressport' ), '' ),
		);
	}

	/**
	 * Read a mapped value from a record.
	 *
	 * @param array  $record  Record.
	 * @param array  $mapping Mapping.
	 * @param string $target  Target key.
	 * @return mixed|null
	 */
	protected static function value( $record, $mapping, $target ) {
		$source = isset( $mapping['fields'][ $target ] ) ? $mapping['fields'][ $target ] : '';
		if ( '' === $source || ! array_key_exists( $source, $record ) ) {
			return null;
		}
		return $record[ $source ];
	}

	/**
	 * Import a single record.
	 *
	 * @param array  $record Flat record.
	 * @param PressPort_Job $job    Job.
	 * @return array { action: created|updated|skipped|failed, post_id: int, message: string }
	 */
	public static function import( array $record, PressPort_Job $job ) {
		$record = apply_filters( 'pressport_record', $record, $job );
		if ( false === $record ) {
			return self::result( 'skipped', 0, __( 'Skipped by pressport_record filter.', 'pressport' ) );
		}

		$mapping = $job->data['mapping'];
		$opts    = array_merge( PressPort_Job::default_options(), (array) $job->data['options'] );
		$dry     = ! empty( $opts['dry_run'] );
		$type    = post_type_exists( $opts['post_type'] ) ? $opts['post_type'] : 'post';
		$base    = isset( $job->data['settings']['site_url'] ) ? $job->data['settings']['site_url'] : ( isset( $job->data['settings']['url'] ) ? $job->data['settings']['url'] : '' );

		$title   = PressPort_Util::stringify( self::value( $record, $mapping, 'post_title' ) );
		$content = PressPort_Util::stringify( self::value( $record, $mapping, 'post_content' ) );
		$label   = '' !== $title ? $title : __( '(untitled)', 'pressport' );

		if ( '' === trim( $title ) && '' === trim( $content ) ) {
			return self::result( 'skipped', 0, __( 'Empty record (no title or content).', 'pressport' ) );
		}

		// De-duplication.
		$source_id = PressPort_Util::stringify( self::value( $record, $mapping, 'source_id' ) );
		$uid       = '' !== $source_id ? md5( $job->data['source'] . '|' . $base . '|' . $source_id ) : '';
		$existing  = $uid ? self::find_by_uid( $uid, $type ) : 0;

		if ( $existing && 'skip' === $opts['duplicate'] ) {
			/* translators: 1: title, 2: post id */
			return self::result( 'skipped', $existing, sprintf( __( 'Already imported: "%1$s" (#%2$d).', 'pressport' ), $label, $existing ) );
		}
		$updating = $existing && 'update' === $opts['duplicate'];

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ),
			'post_content' => $content,
		);
		if ( $updating ) {
			$postarr['ID'] = $existing;
		} else {
			$postarr['post_status'] = $opts['default_status'];
			$postarr['post_author'] = (int) $opts['default_author'];
		}

		$excerpt = self::value( $record, $mapping, 'post_excerpt' );
		if ( null !== $excerpt ) {
			$postarr['post_excerpt'] = wp_strip_all_tags( PressPort_Util::stringify( $excerpt ) );
		}

		$status = self::value( $record, $mapping, 'post_status' );
		if ( null !== $status && '' !== PressPort_Util::stringify( $status ) ) {
			$postarr['post_status'] = self::normalize_status( PressPort_Util::stringify( $status ), $opts['default_status'] );
		}

		$date = self::parse_date( self::value( $record, $mapping, 'post_date' ) );
		if ( $date ) {
			$postarr['post_date']     = $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
			$postarr['post_date_gmt'] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$postarr['edit_date']     = true;
		}

		$slug = PressPort_Util::stringify( self::value( $record, $mapping, 'post_name' ) );
		if ( '' !== $slug ) {
			$segments            = array_values( array_filter( explode( '/', $slug ), 'strlen' ) );
			$postarr['post_name'] = sanitize_title( $segments ? end( $segments ) : $slug );
		}

		$author = PressPort_Util::stringify( self::value( $record, $mapping, 'post_author' ) );
		if ( '' !== $author && ! $dry ) {
			$author_id = self::resolve_author( $author, ! empty( $opts['create_authors'] ) );
			if ( $author_id ) {
				$postarr['post_author'] = $author_id;
			}
		}

		$order = self::value( $record, $mapping, 'menu_order' );
		if ( null !== $order && is_numeric( $order ) ) {
			$postarr['menu_order'] = (int) $order;
		}
		$comments = strtolower( PressPort_Util::stringify( self::value( $record, $mapping, 'comment_status' ) ) );
		if ( '' !== $comments ) {
			$postarr['comment_status'] = in_array( $comments, array( 'open', '1', 'true', 'yes' ), true ) ? 'open' : 'closed';
		}

		$postarr = apply_filters( 'pressport_post_data', $postarr, $record, $job );

		if ( $dry ) {
			/* translators: 1: action, 2: title */
			return self::result( $updating ? 'updated' : 'created', $existing, sprintf( __( '[dry run] Would %1$s "%2$s".', 'pressport' ), $updating ? __( 'update', 'pressport' ) : __( 'create', 'pressport' ), $label ) );
		}

		$post_id = $updating ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: title, 2: error */
			return self::result( 'failed', 0, sprintf( __( 'Failed "%1$s": %2$s', 'pressport' ), $label, $post_id->get_error_message() ) );
		}

		if ( ! $updating ) {
			update_post_meta( $post_id, '_pressport_job_id', $job->data['id'] );
		}
		if ( $uid ) {
			update_post_meta( $post_id, '_pressport_source_uid', $uid );
			update_post_meta( $post_id, '_pressport_source_id', $source_id );
		}

		$warnings = array();

		// Custom fields.
		foreach ( (array) $mapping['meta'] as $row ) {
			if ( empty( $row['key'] ) || empty( $row['source'] ) || ! array_key_exists( $row['source'], $record ) ) {
				continue;
			}
			update_post_meta( $post_id, sanitize_text_field( $row['key'] ), wp_slash( $record[ $row['source'] ] ) );
		}

		// Taxonomies.
		$tax_rows = array();
		if ( null !== self::value( $record, $mapping, 'categories' ) ) {
			$tax_rows[] = array( 'category', self::value( $record, $mapping, 'categories' ) );
		}
		if ( null !== self::value( $record, $mapping, 'tags' ) ) {
			$tax_rows[] = array( 'post_tag', self::value( $record, $mapping, 'tags' ) );
		}
		foreach ( (array) $mapping['taxonomies'] as $row ) {
			if ( ! empty( $row['taxonomy'] ) && ! empty( $row['source'] ) && array_key_exists( $row['source'], $record ) ) {
				$tax_rows[] = array( $row['taxonomy'], $record[ $row['source'] ] );
			}
		}
		foreach ( $tax_rows as $row ) {
			list( $taxonomy, $raw ) = $row;
			$names                  = PressPort_Util::to_list( $raw );
			if ( ! $names ) {
				continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $type, $taxonomy ) ) {
				/* translators: %s: taxonomy */
				$warnings[] = sprintf( __( 'Taxonomy "%s" is not registered for this post type.', 'pressport' ), $taxonomy );
				continue;
			}
			$term_ids = self::resolve_terms( $taxonomy, $names, ! empty( $opts['create_terms'] ) );
			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}

		// Featured image.
		$image = PressPort_Util::stringify( self::value( $record, $mapping, 'featured_image' ) );
		if ( '' !== $image && ! empty( $opts['import_featured_image'] ) ) {
			$alt = PressPort_Util::stringify( self::value( $record, $mapping, 'featured_image_alt' ) );
			$att = PressPort_Media::sideload( $image, $post_id, $alt, $base, $job->data['id'] );
			if ( is_wp_error( $att ) ) {
				$warnings[] = __( 'Featured image: ', 'pressport' ) . $att->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $att );
			}
		}

		// Inline content images.
		if ( ! empty( $opts['import_content_images'] ) && '' !== $content ) {
			$errors  = array();
			$updated = PressPort_Media::import_content_images( $content, $post_id, $base, $job->data['id'], $errors );
			if ( $updated !== $content ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $updated,
						)
					)
				);
			}
			foreach ( $errors as $error ) {
				$warnings[] = __( 'Content image: ', 'pressport' ) . $error;
			}
		}

		$action = $updating ? 'updated' : 'created';
		do_action( 'pressport_after_import_record', $post_id, $record, $job, $action );

		/* translators: 1: Created/Updated, 2: title, 3: post id */
		$message = sprintf( __( '%1$s "%2$s" (#%3$d).', 'pressport' ), $updating ? __( 'Updated', 'pressport' ) : __( 'Created', 'pressport' ), $label, $post_id );
		if ( $warnings ) {
			$message .= ' ' . __( 'Warnings:', 'pressport' ) . ' ' . implode( ' ', $warnings );
		}
		return self::result( $action, $post_id, $message, ! empty( $warnings ) );
	}

	/**
	 * @param string $action  Action.
	 * @param int    $post_id Post id.
	 * @param string $message Message.
	 * @param bool   $warn    Has warnings.
	 * @return array
	 */
	protected static function result( $action, $post_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $post_id,
			'message' => $message,
			'warning' => $warn,
		);
	}

	/**
	 * Find a post previously imported with this uid.
	 *
	 * @param string $uid  Uid.
	 * @param string $type Post type.
	 * @return int
	 */
	protected static function find_by_uid( $uid, $type ) {
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'meta_key'         => '_pressport_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Normalize a status value.
	 *
	 * @param string $value   Raw value.
	 * @param string $default Default.
	 * @return string
	 */
	public static function normalize_status( $value, $default ) {
		$v   = strtolower( trim( $value ) );
		$map = array(
			'publish'     => array( 'publish', 'published', 'live', 'active', 'public', '1', 'true', 'yes', 'on' ),
			'draft'       => array( 'draft', 'unpublished', 'inactive', '0', 'false', 'no', 'off', 'archived' ),
			'pending'     => array( 'pending', 'review', 'in_review', 'pending_review' ),
			'private'     => array( 'private', 'members', 'paid' ),
			'future'      => array( 'future', 'scheduled' ),
		);
		foreach ( $map as $status => $aliases ) {
			if ( in_array( $v, $aliases, true ) ) {
				return $status;
			}
		}
		return $default;
	}

	/**
	 * Parse a date value. Naive strings are interpreted in the site timezone.
	 *
	 * @param mixed $value Value.
	 * @return DateTimeImmutable|null
	 */
	public static function parse_date( $value ) {
		$value = trim( PressPort_Util::stringify( $value ) );
		if ( '' === $value ) {
			return null;
		}
		try {
			if ( preg_match( '/^\d{9,13}$/', $value ) ) {
				$ts = (int) $value;
				if ( $ts > 99999999999 ) {
					$ts = (int) floor( $ts / 1000 ); // Milliseconds.
				}
				return ( new DateTimeImmutable( '@' . $ts ) );
			}
			return new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			$ts = strtotime( $value );
			return $ts ? new DateTimeImmutable( '@' . $ts ) : null;
		}
	}

	/**
	 * Resolve an author string to a user id.
	 *
	 * @param string $value  Email, login, slug or display name.
	 * @param bool   $create Create missing users (role: author).
	 * @return int
	 */
	public static function resolve_author( $value, $create = false ) {
		$value = trim( $value );
		if ( isset( self::$authors[ $value ] ) ) {
			return self::$authors[ $value ];
		}
		$user = null;
		if ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', $value );
		}
		if ( ! $user ) {
			$user = get_user_by( 'slug', sanitize_title( $value ) );
		}
		if ( ! $user ) {
			$found = get_users(
				array(
					'search'         => $value,
					'search_columns' => array( 'display_name' ),
					'number'         => 1,
				)
			);
			$user  = $found ? $found[0] : null;
			if ( ! $user ) {
				$by_name = get_users(
					array(
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'key'   => 'nickname',
								'value' => $value,
							),
						),
						'number'     => 1,
					)
				);
				$user    = $by_name ? $by_name[0] : null;
			}
		}
		$id = $user ? (int) $user->ID : 0;

		if ( ! $id && $create && ! is_numeric( $value ) ) {
			$login = sanitize_user( is_email( $value ) ? strstr( $value, '@', true ) : sanitize_title( $value ), true );
			$login = $login ? $login : 'author';
			$base  = $login;
			$i     = 2;
			while ( username_exists( $login ) ) {
				$login = $base . $i++;
			}
			$new = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24 ),
					'user_email'   => is_email( $value ) ? $value : '',
					'display_name' => is_email( $value ) ? $login : $value,
					'nickname'     => is_email( $value ) ? $login : $value,
					'role'         => 'author',
				)
			);
			$id  = is_wp_error( $new ) ? 0 : (int) $new;
		}

		self::$authors[ $value ] = $id;
		return $id;
	}

	/**
	 * Resolve term names (or "Parent > Child" paths) into term ids.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param string[] $names    Names.
	 * @param bool     $create   Create missing terms.
	 * @return int[]
	 */
	public static function resolve_terms( $taxonomy, array $names, $create ) {
		$ids          = array();
		$hierarchical = is_taxonomy_hierarchical( $taxonomy );
		foreach ( $names as $name ) {
			$path   = $hierarchical ? array_map( 'trim', preg_split( '/\s*>\s*/', $name ) ) : array( $name );
			$parent = 0;
			$term   = null;
			foreach ( array_filter( $path, 'strlen' ) as $part ) {
				$part = html_entity_decode( $part, ENT_QUOTES, 'UTF-8' );
				$term = term_exists( $part, $taxonomy, $hierarchical ? $parent : null );
				if ( ! $term ) {
					$by_slug = get_term_by( 'slug', sanitize_title( $part ), $taxonomy );
					$term    = $by_slug && ( ! $hierarchical || (int) $by_slug->parent === (int) $parent ) ? array( 'term_id' => $by_slug->term_id ) : null;
				}
				if ( ! $term && $create ) {
					$term = wp_insert_term( $part, $taxonomy, $hierarchical ? array( 'parent' => $parent ) : array() );
					if ( is_wp_error( $term ) ) {
						$existing = $term->get_error_data( 'term_exists' );
						$term     = $existing ? array( 'term_id' => (int) $existing ) : null;
					}
				}
				if ( ! $term ) {
					break;
				}
				$parent = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
			if ( $term ) {
				$ids[] = $parent;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
