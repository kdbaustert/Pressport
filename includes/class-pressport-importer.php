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
			'post_type'          => array( __( 'Post type', 'pressport' ), __( 'Imports each record as this post type when the site has it (post, page, product…). Otherwise "Import as" is used.', 'pressport' ) ),
			'post_title'         => array( __( 'Title', 'pressport' ), '' ),
			'post_content'       => array( __( 'Content', 'pressport' ), __( 'HTML or block markup.', 'pressport' ) ),
			'post_excerpt'       => array( __( 'Excerpt', 'pressport' ), '' ),
			'post_date'          => array( __( 'Publish date', 'pressport' ), __( 'Any format strtotime() understands, or a Unix timestamp.', 'pressport' ) ),
			'post_name'          => array( __( 'Slug', 'pressport' ), __( 'Paths like /blog/my-post use the last segment.', 'pressport' ) ),
			'post_status'        => array( __( 'Status', 'pressport' ), __( 'publish/draft/pending/private, or true/false, 1/0.', 'pressport' ) ),
			'post_author'        => array( __( 'Author', 'pressport' ), __( 'Matched by email, login, slug, then display name.', 'pressport' ) ),
			'menu_order'         => array( __( 'Menu order', 'pressport' ), '' ),
			'comment_status'     => array( __( 'Comment status', 'pressport' ), __( 'open or closed', 'pressport' ) ),
			'post_parent'        => array( __( 'Parent', 'pressport' ), __( 'Source ID of the parent item. A parent imported later is linked when it arrives.', 'pressport' ) ),
			'post_password'      => array( __( 'Password', 'pressport' ), __( 'Password-protects the post.', 'pressport' ) ),
			'page_template'      => array( __( 'Page template', 'pressport' ), __( 'Template file or slug, e.g. templates/full-width.php.', 'pressport' ) ),
			'post_format'        => array( __( 'Post format', 'pressport' ), __( 'aside, gallery, link, image, quote, status, video, audio or chat.', 'pressport' ) ),
			'categories'         => array( __( 'Categories', 'pressport' ), __( 'List or "A, B" / "A|B". Use "Parent > Child" for hierarchy.', 'pressport' ) ),
			'tags'               => array( __( 'Tags', 'pressport' ), '' ),
			'featured_image'     => array( __( 'Featured image URL', 'pressport' ), '' ),
			'featured_image_alt' => array( __( 'Featured image alt text', 'pressport' ), '' ),
			'media_url'          => array( __( 'Media file URL', 'pressport' ), __( 'Media imports only: the file to download into the Media Library.', 'pressport' ) ),
			'media_alt'          => array( __( 'Media alt text', 'pressport' ), __( 'Media imports only.', 'pressport' ) ),
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
	 * @param array         $record Flat record.
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
		$type    = self::type_for( $record, $job );
		$base    = self::base_url( $job );
		if ( ! self::importable_post_type( $type ) ) {
			/* translators: %s: post type */
			return self::result( 'failed', 0, sprintf( __( 'Not imported: you cannot add items of type "%s".', 'pressport' ), $type ) );
		}

		$title   = PressPort_Util::stringify( self::value( $record, $mapping, 'post_title' ) );
		$content = PressPort_Util::stringify( self::value( $record, $mapping, 'post_content' ) );
		$label   = '' !== $title ? $title : __( '(untitled)', 'pressport' );

		if ( '' === trim( $title ) && '' === trim( $content ) && 'attachment' !== $type ) {
			return self::result( 'skipped', 0, __( 'Empty record (no title or content).', 'pressport' ) );
		}

		// De-duplication.
		$source_id = PressPort_Util::stringify( self::value( $record, $mapping, 'source_id' ) );
		$uid       = self::uid( $job, $source_id );
		$existing  = $uid ? self::find_by_uid( $uid, $type ) : 0;

		// Posts whose changes the user reviewed and approved are updated, whatever the duplicate mode.
		$replace  = array_map( 'intval', isset( $opts['replace_ids'] ) ? (array) $opts['replace_ids'] : array() );
		$approved = array_merge( array_map( 'intval', isset( $opts['update_ids'] ) ? (array) $opts['update_ids'] : array() ), $replace );
		// "Keep both": a new item beside the one already here.
		$copy      = array_map( 'intval', isset( $opts['copy_ids'] ) ? (array) $opts['copy_ids'] : array() );
		$force_new = false;
		if ( $existing && in_array( $existing, $copy, true ) ) {
			$existing  = 0;
			$force_new = true;
		}
		if ( $existing && in_array( $existing, $approved, true ) ) {
			$opts['duplicate'] = 'update';
		}
		if ( $existing && 'skip' === $opts['duplicate'] ) {
			/* translators: 1: title, 2: post id */
			return self::result( 'skipped', $existing, sprintf( __( 'Already imported: "%1$s" (#%2$d).', 'pressport' ), $label, $existing ) );
		}
		if ( ! $existing && ! $force_new && ( ! empty( $opts['skip_existing'] ) || $approved ) ) {
			$match = self::match_existing( $record, $mapping, $type );
			if ( $match && in_array( $match['post_id'], $copy, true ) ) {
				$force_new = true;
			} elseif ( $match && in_array( $match['post_id'], $approved, true ) ) {
				$existing          = $match['post_id'];
				$opts['duplicate'] = 'update';
			} elseif ( $match && ! empty( $opts['skip_existing'] ) ) {
				/* translators: 1: title, 2: post id, 3: what matched (slug, SKU, file name) */
				return self::result( 'skipped', $match['post_id'], sprintf( __( 'Already on this site: "%1$s" (#%2$d) has the same %3$s.', 'pressport' ), $label, $match['post_id'], $match['label'] ) );
			}
		}
		$updating = $existing && 'update' === $opts['duplicate'];

		// A dry run must not create the authors it would assign.
		$postarr = self::post_fields( $record, $job, $type, ! $dry && ! empty( $opts['create_authors'] ) );
		if ( $updating ) {
			$postarr['ID'] = $existing;
		} else {
			$postarr += array(
				'post_status' => $opts['default_status'],
				'post_author' => (int) $opts['default_author'],
			);
		}

		// Parent: the post imported from the parent's source ID. A parent that is not here yet links up when it is imported.
		$parent     = PressPort_Util::stringify( self::value( $record, $mapping, 'post_parent' ) );
		$parent_uid = '0' !== $parent ? self::uid( $job, $parent ) : '';
		$parent_uid = $parent_uid !== $uid ? $parent_uid : '';
		$parent_id  = $parent_uid ? self::find_by_uid( $parent_uid, 'attachment' === $type ? array_keys( get_post_types() ) : $type ) : 0;
		if ( $parent_id ) {
			$postarr['post_parent'] = $parent_id;
		}

		$postarr = apply_filters( 'pressport_post_data', $postarr, $record, $job );
		$content = isset( $postarr['post_content'] ) ? (string) $postarr['post_content'] : '';

		if ( $dry ) {
			/* translators: 1: action, 2: title */
			return self::result( $updating ? 'updated' : 'created', $existing, sprintf( __( '[dry run] Would %1$s "%2$s".', 'pressport' ), $updating ? __( 'update', 'pressport' ) : __( 'create', 'pressport' ), $label ) );
		}

		if ( 'attachment' === $type ) {
			$post_id = self::save_media( $postarr, self::value( $record, $mapping, 'media_url' ), self::value( $record, $mapping, 'media_alt' ), $base, $job->data['id'], $updating, $updating && in_array( $existing, $replace, true ), $force_new );
		} else {
			$post_id = $updating ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		}
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
			PressPort_ACF::link_pending( $post_id, $uid );
			// Children imported before this post have been waiting for it as their parent.
			$children = get_posts(
				array(
					// Not 'any': that leaves out post types hidden from search.
					'post_type'        => array_keys( get_post_types() ),
					'post_status'      => 'any',
					'meta_key'         => '_pressport_parent_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'           => 'ids',
					'posts_per_page'   => -1,
					'suppress_filters' => true,
				)
			);
			foreach ( $children as $child ) {
				wp_update_post(
					array(
						'ID'          => $child,
						'post_parent' => $post_id,
					)
				);
				delete_post_meta( $child, '_pressport_parent_uid' );
			}
		}
		if ( $parent_uid && ! $parent_id ) {
			update_post_meta( $post_id, '_pressport_parent_uid', $parent_uid );
		} elseif ( $parent_id ) {
			delete_post_meta( $post_id, '_pressport_parent_uid' );
		}
		// Before the custom fields: ACF groups can be shown by page template or post format.
		$template = PressPort_Util::stringify( self::value( $record, $mapping, 'page_template' ) );
		if ( '' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $template ) );
		}
		$format = PressPort_Util::stringify( self::value( $record, $mapping, 'post_format' ) );
		if ( '' !== $format && post_type_supports( $type, 'post-formats' ) ) {
			set_post_format( $post_id, $format );
		}

		$warnings = array();

		// Taxonomies, before the custom fields: ACF groups can be shown by category or term.
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
			$term_ids = self::resolve_terms( $taxonomy, $names, ! empty( $opts['create_terms'] ), $warnings );
			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}

		// Custom fields. Keys that belong to an ACF field are saved through ACF.
		$acf_map = isset( $mapping['acf'] ) ? (array) $mapping['acf'] : array();
		// PressPort exports from before "meta_native" carried array values as JSON text.
		$embedded    = isset( $job->data['settings']['pressport_mapping'] ) ? (array) $job->data['settings']['pressport_mapping'] : null;
		$legacy_meta = null !== $embedded && empty( $embedded['meta_native'] );
		foreach ( (array) $mapping['meta'] as $row ) {
			if ( empty( $row['key'] ) || empty( $row['source'] ) ) {
				continue;
			}
			$key = sanitize_text_field( $row['key'] );
			if ( PressPort_ACF::active() && ! isset( $acf_map[ $key ] ) && PressPort_ACF::get_field_object( $key, $post_id ) ) {
				$acf_map[ $key ] = $row['source'];
				continue;
			}
			$value = PressPort_ACF::read( $record, $row['source'] );
			if ( null === $value ) {
				continue;
			}
			if ( $legacy_meta && is_string( $value ) && 0 === strpos( $row['source'], 'meta.' ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			if ( is_array( $value ) && array( '_pressport_rows' ) === array_keys( $value ) ) {
				// A key stored in several rows.
				delete_post_meta( $post_id, $key );
				foreach ( (array) $value['_pressport_rows'] as $one ) {
					add_post_meta( $post_id, $key, wp_slash( $one ) );
				}
				continue;
			}
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		// ACF fields.
		PressPort_ACF::import( $post_id, $record, $acf_map, $job, $opts, $base, $warnings );

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

		// WooCommerce product fields (prices, stock, attributes, variations).
		if ( PressPort_Woo::is_product_type( $type ) ) {
			PressPort_Woo::import( $post_id, $record, $job, $opts, $base, $warnings );
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
	 * The post type a record imports as: "Import as", unless a mapped Post type names one this site has.
	 *
	 * @param array         $record Flat record.
	 * @param PressPort_Job $job    Job.
	 * @return string
	 */
	public static function type_for( array $record, PressPort_Job $job ) {
		$opts   = array_merge( PressPort_Job::default_options(), (array) $job->data['options'] );
		$type   = post_type_exists( $opts['post_type'] ) ? $opts['post_type'] : 'post';
		$routed = sanitize_key( PressPort_Util::stringify( self::value( $record, $job->data['mapping'], 'post_type' ) ) );
		return '' !== $routed && self::importable_post_type( $routed ) ? $routed : $type;
	}

	/**
	 * The site a job's records come from: the base for relative media paths.
	 * A PressPort export names its site.
	 *
	 * @param PressPort_Job $job Job.
	 * @return string
	 */
	protected static function base_url( PressPort_Job $job ) {
		$s = $job->data['settings'];
		foreach ( array( 'site_url', 'url', 'pressport_site' ) as $key ) {
			if ( ! empty( $s[ $key ] ) ) {
				return (string) $s[ $key ];
			}
		}
		return '';
	}

	/**
	 * Where a job's records come from, as part of each record's "imported before"
	 * key, so post #5 from one source is never taken for post #5 from another: the
	 * site for a PressPort export, otherwise the feed URL or the file's name. The
	 * name, not its content, so re-importing an edited copy of a file still updates
	 * what it imported before. Admin uploads are saved under a random name, which
	 * would never match again; they use the original name when the upload kept it.
	 *
	 * @param PressPort_Job $job Job.
	 * @return string
	 */
	public static function source_key( PressPort_Job $job ) {
		$base = self::base_url( $job );
		if ( '' !== $base ) {
			return $base;
		}
		$s = $job->data['settings'];
		if ( ! empty( $s['file_name'] ) ) {
			return 'file:' . $s['file_name'];
		}
		if ( ! empty( $s['file_url'] ) ) {
			return (string) $s['file_url'];
		}
		// Sanitized like the name the admin upload keeps, so WP-CLI and the admin agree.
		$name = empty( $s['file'] ) ? '' : sanitize_file_name( wp_basename( (string) $s['file'] ) );
		return '' === $name || preg_match( '/^upload-[A-Za-z0-9]{12}\./', $name ) ? '' : 'file:' . $name;
	}

	/**
	 * A record's "imported before" key (_pressport_source_uid): its source id,
	 * scoped to where the job's records come from (see source_key()).
	 *
	 * @param PressPort_Job $job       Job.
	 * @param string        $source_id Id in the source.
	 * @return string '' without a source id.
	 */
	public static function uid( PressPort_Job $job, $source_id ) {
		$source_id = (string) $source_id;
		return '' === $source_id ? '' : md5( $job->data['source'] . '|' . self::source_key( $job ) . '|' . $source_id );
	}

	/**
	 * Point href links to the old site at this one. Images, and links to files under
	 * wp-content, are left alone: those files only exist here once they are downloaded.
	 *
	 * @param string $html Content.
	 * @param string $base The site the records come from.
	 * @return string
	 */
	public static function rewrite_links( $html, $base ) {
		$old = untrailingslashit( preg_replace( '#^https?:#i', '', (string) $base ) );
		$new = untrailingslashit( home_url() );
		if ( '' === $html || ! preg_match( '#^//[^/]#', $old ) || 0 === strcasecmp( $old, preg_replace( '#^https?:#i', '', $new ) ) || false === stripos( $html, $old ) ) {
			return $html;
		}
		return preg_replace_callback(
			'#(\shref\s*=\s*(["\']))(?:https?:)?' . preg_quote( $old, '#' ) . '(?=[/?\#]|\2)(?!/wp-content/)#i',
			static function ( $m ) use ( $new ) {
				return $m[1] . $new;
			},
			$html
		);
	}

	/**
	 * Whether a record is already on this site, without importing it: imported
	 * before from this source (unless the import updates those), or matching
	 * content that is already here by slug, SKU or file name.
	 *
	 * @param array         $record Flat record.
	 * @param PressPort_Job $job    Job (mapping and options as they will run).
	 * @return array|null { kind: before|existing, post_id: int, label: string }
	 */
	public static function find_duplicate( array $record, PressPort_Job $job ) {
		$mapping   = $job->data['mapping'];
		$opts      = array_merge( PressPort_Job::default_options(), (array) $job->data['options'] );
		$type      = self::type_for( $record, $job );
		$source_id = PressPort_Util::stringify( self::value( $record, $mapping, 'source_id' ) );
		$existing  = '' !== $source_id ? self::find_by_uid( self::uid( $job, $source_id ), $type ) : 0;
		if ( $existing ) {
			// Updating records imported before is what the import was asked to do, so those are not duplicates.
			return 'update' === $opts['duplicate'] ? null : array(
				'kind'    => 'before',
				'post_id' => $existing,
				'label'   => '',
				'changes' => self::changes_for( $record, $job, $existing, $type ),
				'image'   => 'attachment' === $type ? self::media_review( $record, $mapping, $existing, self::base_url( $job ) ) : null,
			);
		}
		$match = self::match_existing( $record, $mapping, $type );
		return $match ? array_merge(
			$match,
			array(
				'kind'    => 'existing',
				'changes' => self::changes_for( $record, $job, $match['post_id'], $type ),
				'image'   => 'attachment' === $type ? self::media_review( $record, $mapping, $match['post_id'], self::base_url( $job ) ) : null,
			)
		) : null;
	}

	/**
	 * A Media duplicate side by side: the item on this site and the file in the
	 * import, with the details (title, caption, description, alt text) that differ.
	 *
	 * @param array  $record  Flat record.
	 * @param array  $mapping Mapping.
	 * @param int    $post_id Existing attachment.
	 * @param string $base    Base URL for a relative file URL.
	 * @return array { current, current_full, imported, is_image, changes }
	 */
	protected static function media_review( array $record, array $mapping, $post_id, $base ) {
		$post    = get_post( $post_id );
		$read    = static function ( $target ) use ( $record, $mapping ) {
			$value = self::value( $record, $mapping, $target );
			return null === $value ? null : trim( str_replace( "\r\n", "\n", PressPort_Util::stringify( $value ) ) );
		};
		$fields  = array(
			'title'       => array( __( 'Title', 'pressport' ), $post->post_title, $read( 'post_title' ) ),
			'caption'     => array( __( 'Caption', 'pressport' ), $post->post_excerpt, $read( 'post_excerpt' ) ),
			'description' => array( __( 'Description', 'pressport' ), $post->post_content, $read( 'post_content' ) ),
			'alt'         => array( __( 'Alt text', 'pressport' ), (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ), $read( 'media_alt' ) ),
		);
		$changes = array();
		foreach ( $fields as $key => list( $label, $old, $new ) ) {
			$old = trim( (string) $old );
			if ( null !== $new && $new !== $old ) {
				$changes[ $key ] = array(
					'label' => $label,
					'old'   => $old,
					'new'   => $new,
				);
			}
		}
		$thumb = wp_get_attachment_image_src( $post_id, 'medium' );
		return array(
			'current'      => $thumb ? $thumb[0] : '',
			'current_full' => (string) wp_get_attachment_url( $post_id ),
			'imported'     => PressPort_Media::absolutize( (string) $read( 'media_url' ), $base ),
			'is_image'     => wp_attachment_is_image( $post_id ),
			'changes'      => $changes,
		);
	}

	/**
	 * What importing a record would change in an existing post, for the core
	 * fields the import writes, each worked out the way import() writes it.
	 * Products and media carry much more than these fields, so they are not
	 * reviewed this way.
	 *
	 * @param array         $record  Flat record.
	 * @param PressPort_Job $job     Job.
	 * @param int           $post_id Existing post.
	 * @param string        $type    Post type the record imports as.
	 * @return array field => { label, old, new }; empty when nothing differs.
	 */
	public static function changes_for( array $record, PressPort_Job $job, $post_id, $type ) {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $type || PressPort_Woo::is_product_type( $type ) ) {
			return array();
		}
		$mapping = $job->data['mapping'];
		$postarr = apply_filters( 'pressport_post_data', array( 'ID' => (int) $post_id ) + self::post_fields( $record, $job, $type, false ), $record, $job );
		// Title and content are always set; the others only when the record has them.
		$new    = static function ( $key, $target = '' ) use ( $postarr, $record, $mapping ) {
			if ( ! isset( $postarr[ $key ] ) || ( '' !== $target && null === self::value( $record, $mapping, $target ) ) ) {
				return null;
			}
			return (string) $postarr[ $key ];
		};
		$author = static function ( $id ) {
			$user = get_userdata( (int) $id );
			return $user ? $user->display_name : '';
		};

		// field => [ label, current value, imported value or null when not mapped / not set ].
		$fields  = array(
			'title'   => array( __( 'Title', 'pressport' ), $post->post_title, $new( 'post_title', 'post_title' ) ),
			'content' => array( __( 'Content', 'pressport' ), $post->post_content, $new( 'post_content', 'post_content' ) ),
			'excerpt' => array( __( 'Excerpt', 'pressport' ), $post->post_excerpt, $new( 'post_excerpt' ) ),
			'status'  => array( __( 'Status', 'pressport' ), $post->post_status, $new( 'post_status' ) ),
			'date'    => array( __( 'Publish date', 'pressport' ), $post->post_date, $new( 'post_date' ) ),
			'slug'    => array( __( 'Slug', 'pressport' ), $post->post_name, $new( 'post_name' ) ),
			'author'  => array( __( 'Author', 'pressport' ), $author( $post->post_author ), isset( $postarr['post_author'] ) ? $author( $postarr['post_author'] ) : null ),
		);
		$changes = array();
		foreach ( $fields as $key => list( $label, $old, $new ) ) {
			if ( null === $new ) {
				continue; // The import leaves this field alone.
			}
			$old = trim( str_replace( "\r\n", "\n", (string) $old ) );
			$new = trim( str_replace( "\r\n", "\n", (string) $new ) );
			if ( $new !== $old ) {
				$changes[ $key ] = array(
					'label' => $label,
					'old'   => $old,
					'new'   => $new,
				);
			}
		}
		return $changes;
	}

	/**
	 * The post fields a record sets, worked out the same way for import() and
	 * changes_for(): everything but the ID, the defaults for a new post and the parent.
	 *
	 * @param array         $record         Flat record.
	 * @param PressPort_Job $job            Job.
	 * @param string        $type           Post type the record imports as.
	 * @param bool          $create_authors Create an author that is not on this site.
	 * @return array wp_insert_post() args.
	 */
	protected static function post_fields( array $record, PressPort_Job $job, $type, $create_authors ) {
		$mapping = $job->data['mapping'];
		$opts    = array_merge( PressPort_Job::default_options(), (array) $job->data['options'] );
		$content = PressPort_Util::stringify( self::value( $record, $mapping, 'post_content' ) );
		if ( ! empty( $opts['rewrite_links'] ) ) {
			$content = self::rewrite_links( $content, self::base_url( $job ) );
		}

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => wp_strip_all_tags( html_entity_decode( PressPort_Util::stringify( self::value( $record, $mapping, 'post_title' ) ), ENT_QUOTES, 'UTF-8' ) ),
			'post_content' => $content,
		);

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

		$slug = self::slug_from( self::value( $record, $mapping, 'post_name' ) );
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}

		$author = PressPort_Util::stringify( self::value( $record, $mapping, 'post_author' ) );
		if ( '' !== $author ) {
			$author_id = self::resolve_author( $author, $create_authors );
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
		$password = self::value( $record, $mapping, 'post_password' );
		if ( null !== $password ) {
			$postarr['post_password'] = PressPort_Util::stringify( $password );
		}
		return $postarr;
	}

	/**
	 * The slug a value names: the last segment of a path or URL, so
	 * "/blog/my-post/" is "my-post".
	 *
	 * @param mixed $value Slug, path or URL.
	 * @return string '' when there is none.
	 */
	public static function slug_from( $value ) {
		$value    = PressPort_Util::stringify( $value );
		$path     = false !== strpos( $value, '/' ) ? (string) wp_parse_url( $value, PHP_URL_PATH ) : $value;
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		return $segments ? sanitize_title( end( $segments ) ) : '';
	}

	/**
	 * Content already on this site that a record would duplicate: a product with
	 * the same SKU, media with the same file name, or anything of the same type
	 * with the same slug. Slugs and SKUs are unique on a site, so a match is
	 * almost certainly the same item.
	 *
	 * @param array  $record  Flat record.
	 * @param array  $mapping Mapping.
	 * @param string $type    Post type the record imports as.
	 * @return array|null { post_id: int, label: string }
	 */
	protected static function match_existing( array $record, array $mapping, $type ) {
		global $wpdb;
		if ( 'attachment' === $type ) {
			$url = PressPort_Util::stringify( self::value( $record, $mapping, 'media_url' ) );
			$id  = '' !== $url ? PressPort_Media::find_existing( $url ) : 0;
			if ( $id ) {
				// Downloaded by an earlier import, e.g. as a featured image.
				return array(
					'post_id' => $id,
					'label'   => __( 'file', 'pressport' ),
				);
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$name = sanitize_file_name( wp_basename( rawurldecode( $path ) ) );
			if ( '' === $name ) {
				return null;
			}
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) LIMIT 1", $name, '%/' . $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id ? array(
				'post_id' => $id,
				'label'   => __( 'file name', 'pressport' ),
			) : null;
		}
		if ( PressPort_Woo::is_product_type( $type ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku = isset( $record['woo.sku'] ) ? trim( PressPort_Util::stringify( $record['woo.sku'] ) ) : '';
			$id  = '' !== $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			if ( $id && 'product' === get_post_type( $id ) ) {
				return array(
					'post_id' => $id,
					'label'   => __( 'SKU', 'pressport' ),
				);
			}
		}
		$slug = self::slug_from( self::value( $record, $mapping, 'post_name' ) );
		if ( '' === $slug ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'name'             => $slug,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return $ids ? array(
			'post_id' => (int) $ids[0],
			'label'   => __( 'slug', 'pressport' ),
		) : null;
	}

	/**
	 * Save a Media record: download its file into the Media Library (or reuse the
	 * copy an earlier import made of the same URL), then apply title, caption,
	 * description and alt text.
	 *
	 * @param array  $postarr  Post data (ID set when updating a record imported before).
	 * @param mixed  $url      File URL from the record.
	 * @param mixed  $alt      Alt text from the record.
	 * @param string $base     Base URL for relative paths.
	 * @param string $job_id   Job id.
	 * @param bool   $updating Set to true when the file was already in the Media Library (by reference).
	 * @param bool   $replace  Replace the existing item's file with the imported one (the user chose this).
	 * @param bool   $new      Always add a new item, even when this file was downloaded before ("Keep both").
	 * @return int|WP_Error Attachment id.
	 */
	protected static function save_media( array $postarr, $url, $alt, $base, $job_id, &$updating, $replace = false, $new = false ) {
		unset( $postarr['post_type'], $postarr['post_status'] ); // Attachments keep "inherit".
		$alt = PressPort_Util::stringify( $alt );
		if ( $replace && ! empty( $postarr['ID'] ) ) {
			$replaced = PressPort_Media::replace_file( $postarr['ID'], PressPort_Util::stringify( $url ), $base );
			if ( is_wp_error( $replaced ) ) {
				return $replaced;
			}
		}
		if ( empty( $postarr['ID'] ) ) {
			$url = PressPort_Media::absolutize( PressPort_Util::stringify( $url ), $base );
			if ( '' === $url ) {
				return new WP_Error( 'pressport_media_file', __( 'No file URL to import.', 'pressport' ) );
			}
			// Already downloaded by an earlier import (e.g. as a featured image): update that item, and
			// leave it out of this import's rollback, as with any post an import only updated.
			$updating = ! $new && PressPort_Media::find_existing( $url );
			$id       = PressPort_Media::sideload( $url, 0, $alt, '', $job_id, ! $new );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$postarr['ID'] = $id;
		}
		if ( '' !== $alt ) {
			update_post_meta( $postarr['ID'], '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		$result = wp_update_post( wp_slash( $postarr ), true );
		return is_wp_error( $result ) ? $result : (int) $postarr['ID'];
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
	 * Whether records may be imported into a post type: a registered content type
	 * with an admin UI, excluding the site editor's and menus' types, that the
	 * current user can edit. WP-CLI runs as the shell account, usually with no
	 * WordPress user, so it skips the capability check.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function importable_post_type( $type ) {
		$object = get_post_type_object( $type );
		if ( ! $object || ! $object->show_ui || in_array( $type, array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'nav_menu_item' ), true ) ) {
			return false;
		}
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( $object->cap->edit_posts );
	}

	/**
	 * Find a post previously imported with this uid.
	 *
	 * @param string          $uid  Uid.
	 * @param string|string[] $type Post type, or several.
	 * @return int
	 */
	public static function find_by_uid( $uid, $type ) {
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
			'publish' => array( 'publish', 'published', 'live', 'active', 'public', '1', 'true', 'yes', 'on' ),
			'draft'   => array( 'draft', 'unpublished', 'inactive', '0', 'false', 'no', 'off', 'archived' ),
			'pending' => array( 'pending', 'review', 'in_review', 'pending_review' ),
			'private' => array( 'private', 'members', 'paid' ),
			'future'  => array( 'future', 'scheduled' ),
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
		// A cached miss is only final when nothing would be created.
		if ( isset( self::$authors[ $value ] ) && ( self::$authors[ $value ] || ! $create ) ) {
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

		// WP-CLI without --user has no user to check, and runs with the shell account's access.
		$may_create = ( defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id() ) || current_user_can( 'create_users' );
		if ( ! $id && $create && $may_create && ! is_numeric( $value ) ) {
			$login = sanitize_user( is_email( $value ) ? strstr( $value, '@', true ) : sanitize_title( $value ), true );
			$login = $login ? $login : 'author';
			$base  = $login;
			$i     = 2;
			while ( username_exists( $login ) ) {
				$login = $base . ( $i++ );
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
	 * @param string[] $warnings Warnings for terms left out (by reference).
	 * @return int[]
	 */
	public static function resolve_terms( $taxonomy, array $names, $create, &$warnings = array() ) {
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
						if ( ! $existing ) {
							/* translators: 1: term name, 2: taxonomy, 3: error */
							$warnings[] = sprintf( __( 'Term "%1$s" (%2$s) could not be created: %3$s', 'pressport' ), $part, $taxonomy, $term->get_error_message() );
						}
						$term = $existing ? array( 'term_id' => (int) $existing ) : null;
					}
				} elseif ( ! $term ) {
					/* translators: 1: term name, 2: taxonomy */
					$warnings[] = sprintf( __( 'Term "%1$s" (%2$s) was not found and creating terms is off.', 'pressport' ), $part, $taxonomy );
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
