<?php
/**
 * Maps one source record onto a WordPress post.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Record → post importer.
 *
 * Developer hooks:
 *  - filter migrateplus_record( array $record, MigratePlus_Job $job )               Modify/skip (return false) a record.
 *  - filter migrateplus_post_data( array $postarr, array $record, MigratePlus_Job ) Adjust wp_insert_post() args.
 *  - action migrateplus_after_import_record( int $post_id, array $record, MigratePlus_Job $job, string $action )
 */
class MigratePlus_Importer {

	// Comment meta: the comment's id on the old site, scoped to the source like _migrateplus_source_uid.
	const COMMENT_SOURCE_META = '_migrateplus_source_comment_uid';

	protected static $authors = array();

	/**
	 * Mappable WordPress fields.
	 *
	 * @return array key => [label, help]
	 */
	public static function targets() {
		return array(
			'source_id'          => array( __( 'Source ID (for de-duplication)', 'migrateplus' ), __( 'Unique ID in the source. Used to skip or update on re-runs.', 'migrateplus' ) ),
			'post_type'          => array( __( 'Post type', 'migrateplus' ), __( 'Imports each record as this post type when the site has it (post, page, product…). Otherwise "Import as" is used.', 'migrateplus' ) ),
			'post_title'         => array( __( 'Title', 'migrateplus' ), '' ),
			'post_content'       => array( __( 'Content', 'migrateplus' ), __( 'HTML or block markup.', 'migrateplus' ) ),
			'post_excerpt'       => array( __( 'Excerpt', 'migrateplus' ), '' ),
			'post_date'          => array( __( 'Publish date', 'migrateplus' ), __( 'Any format strtotime() understands, or a Unix timestamp.', 'migrateplus' ) ),
			'post_name'          => array( __( 'Slug', 'migrateplus' ), __( 'Paths like /blog/my-post use the last segment.', 'migrateplus' ) ),
			'post_status'        => array( __( 'Status', 'migrateplus' ), __( 'publish/draft/pending/private, or true/false, 1/0.', 'migrateplus' ) ),
			'post_author'        => array( __( 'Author', 'migrateplus' ), __( 'Matched by email, login, slug, then display name.', 'migrateplus' ) ),
			'post_author_login'  => array( __( 'Author login', 'migrateplus' ), __( 'Tried when no user has the author\'s email: the same person often has a different email on each site.', 'migrateplus' ) ),
			'post_author_name'   => array( __( 'Author display name', 'migrateplus' ), __( 'Tried after email and login, and used as the name of an author Migrate Plus creates.', 'migrateplus' ) ),
			'menu_order'         => array( __( 'Menu order', 'migrateplus' ), '' ),
			'comment_status'     => array( __( 'Comment status', 'migrateplus' ), __( 'open or closed', 'migrateplus' ) ),
			'comments'           => array( __( 'Comments', 'migrateplus' ), __( 'The list of comments in a Migrate Plus export. Added to the post when "Import comments" is on.', 'migrateplus' ) ),
			'post_parent'        => array( __( 'Parent', 'migrateplus' ), __( 'Source ID of the parent item. A parent imported later is linked when it arrives.', 'migrateplus' ) ),
			'post_password'      => array( __( 'Password', 'migrateplus' ), __( 'Password-protects the post.', 'migrateplus' ) ),
			'page_template'      => array( __( 'Page template', 'migrateplus' ), __( 'Template file or slug, e.g. templates/full-width.php.', 'migrateplus' ) ),
			'post_format'        => array( __( 'Post format', 'migrateplus' ), __( 'aside, gallery, link, image, quote, status, video, audio or chat.', 'migrateplus' ) ),
			'categories'         => array( __( 'Categories', 'migrateplus' ), __( 'List or "A, B" / "A|B". Use "Parent > Child" for hierarchy.', 'migrateplus' ) ),
			'tags'               => array( __( 'Tags', 'migrateplus' ), '' ),
			'featured_image'     => array( __( 'Featured image URL', 'migrateplus' ), '' ),
			'featured_image_alt' => array( __( 'Featured image alt text', 'migrateplus' ), '' ),
			'media_url'          => array( __( 'Media file URL', 'migrateplus' ), __( 'Media imports only: the file to download into the Media Library.', 'migrateplus' ) ),
			'media_alt'          => array( __( 'Media alt text', 'migrateplus' ), __( 'Media imports only.', 'migrateplus' ) ),
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
	 * @param array           $record Flat record.
	 * @param MigratePlus_Job $job    Job.
	 * @return array { action: created|updated|skipped|failed, post_id: int, message: string }
	 */
	public static function import( array $record, MigratePlus_Job $job ) {
		$record = apply_filters( 'migrateplus_record', $record, $job );
		if ( false === $record ) {
			return self::result( 'skipped', 0, __( 'Skipped by migrateplus_record filter.', 'migrateplus' ) );
		}

		$mapping = $job->data['mapping'];
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$dry     = ! empty( $opts['dry_run'] );
		$type    = self::type_for( $record, $job );
		$base    = self::base_url( $job );
		if ( ! self::importable_post_type( $type ) ) {
			/* translators: %s: post type */
			return self::result( 'failed', 0, sprintf( __( 'Not imported: you cannot add items of type "%s".', 'migrateplus' ), $type ) );
		}

		$title   = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_title' ) );
		$content = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_content' ) );
		$label   = '' !== $title ? $title : __( '(untitled)', 'migrateplus' );

		if ( '' === trim( $title ) && '' === trim( $content ) && 'attachment' !== $type ) {
			return self::result( 'skipped', 0, __( 'Empty record (no title or content).', 'migrateplus' ) );
		}

		// De-duplication.
		$source_id = MigratePlus_Util::stringify( self::value( $record, $mapping, 'source_id' ) );
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
			return self::result( 'skipped', $existing, sprintf( __( 'Already imported: "%1$s" (#%2$d).', 'migrateplus' ), $label, $existing ) );
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
				return self::result( 'skipped', $match['post_id'], sprintf( __( 'Already on this site: "%1$s" (#%2$d) has the same %3$s.', 'migrateplus' ), $label, $match['post_id'], $match['label'] ) );
			}
		}
		$updating = $existing && 'update' === $opts['duplicate'];

		// A dry run must not create the authors it would assign.
		$postarr = self::post_fields( $record, $job, $type, ! $dry && ! empty( $opts['create_authors'] ) );
		$author  = isset( $postarr['post_author'] ) ? '' : self::author_label( $record, $mapping );
		if ( $updating ) {
			$postarr['ID'] = $existing;
		} else {
			$postarr += array(
				'post_status' => $opts['default_status'],
				'post_author' => (int) $opts['default_author'],
			);
			// A new post gets an empty title or content when the record has none. Media get theirs in
			// save_media(), which only finds out there whether the file is already in the Media Library.
			if ( 'attachment' !== $type ) {
				$postarr += array(
					'post_title'   => '',
					'post_content' => '',
				);
			}
		}

		// Parent: the post imported from the parent's source ID. A parent that is not here yet links up when it is imported.
		// Any post type: uids are unique per source, and in an export of several types a parent can be of another one.
		$parent     = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_parent' ) );
		$parent_uid = '0' !== $parent ? self::uid( $job, $parent ) : '';
		$parent_uid = $parent_uid !== $uid ? $parent_uid : '';
		$parent_id  = $parent_uid ? self::find_by_uid( $parent_uid, array_keys( get_post_types() ), $job->data['id'] ) : 0;
		if ( ! $parent_id && $parent_uid && ! empty( $opts['skip_existing'] ) ) {
			// A parent skipped as "already on this site" was matched by its slug and never got a uid,
			// so nothing would ever link this post to it: find it the same way.
			$parent_id = self::find_parent_by_slug( $record, $mapping );
		}
		if ( $parent_id ) {
			$postarr['post_parent'] = $parent_id;
		}

		$postarr = apply_filters( 'migrateplus_post_data', $postarr, $record, $job );
		$content = isset( $postarr['post_content'] ) ? (string) $postarr['post_content'] : '';

		$warnings = array();
		// WP-CLI without --user has no user to check, and runs with the shell account's access.
		$user = get_current_user_id();
		if ( $user ) {
			$caps = get_post_type_object( $type )->cap;
			if ( $updating && ! current_user_can( 'edit_post', $existing ) ) {
				/* translators: 1: title, 2: post id */
				return self::result( 'failed', $existing, sprintf( __( 'Not updated: you are not allowed to edit "%1$s" (#%2$d).', 'migrateplus' ), $label, $existing ) );
			}
			$status = isset( $postarr['post_status'] ) ? $postarr['post_status'] : '';
			if ( ! $updating && 'attachment' !== $type && in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( $caps->publish_posts ) ) {
				$postarr['post_status'] = 'pending';
				$warnings[]             = __( 'You are not allowed to publish, so it was saved as pending review.', 'migrateplus' );
			}
			// Updating, the author to compare with is the one the post has: keeping it needs no permission.
			$current = $updating ? (int) get_post_field( 'post_author', $existing ) : $user;
			if ( isset( $postarr['post_author'] ) && (int) $postarr['post_author'] !== $current && (int) $postarr['post_author'] !== $user && ! current_user_can( $caps->edit_others_posts ) ) {
				if ( $updating ) {
					unset( $postarr['post_author'] );
					$warnings[] = __( 'You are not allowed to give it another author, so it keeps its author.', 'migrateplus' );
				} else {
					$postarr['post_author'] = $user;
					$warnings[]             = __( 'You are not allowed to give it another author, so you are its author.', 'migrateplus' );
				}
			}
		}

		if ( $dry ) {
			/* translators: 1: action, 2: title */
			return self::result( $updating ? 'updated' : 'created', $existing, sprintf( __( '[dry run] Would %1$s "%2$s".', 'migrateplus' ), $updating ? __( 'update', 'migrateplus' ) : __( 'create', 'migrateplus' ), $label ) );
		}

		if ( 'attachment' === $type ) {
			$post_id = self::save_media( $postarr, self::value( $record, $mapping, 'media_url' ), self::value( $record, $mapping, 'media_alt' ), $base, $job->data['id'], $updating, $updating && in_array( $existing, $replace, true ), $force_new );
		} else {
			$post_id = $updating ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: title, 2: error */
			return self::result( 'failed', 0, sprintf( __( 'Failed "%1$s": %2$s', 'migrateplus' ), $label, $post_id->get_error_message() ) );
		}

		if ( ! $updating ) {
			update_post_meta( $post_id, '_migrateplus_job_id', $job->data['id'] );
		}
		if ( $uid ) {
			update_post_meta( $post_id, '_migrateplus_source_uid', $uid );
			update_post_meta( $post_id, '_migrateplus_source_id', wp_slash( $source_id ) );
			MigratePlus_ACF::link_pending( $post_id, $uid );
			// Children imported before this post have been waiting for it as their parent.
			$children = get_posts(
				array(
					// Not 'any': that leaves out post types hidden from search.
					'post_type'        => array_keys( get_post_types() ),
					'post_status'      => 'any',
					'meta_key'         => '_migrateplus_parent_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
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
				delete_post_meta( $child, '_migrateplus_parent_uid' );
			}
		}
		if ( $parent_uid && ! $parent_id ) {
			update_post_meta( $post_id, '_migrateplus_parent_uid', $parent_uid );
		} elseif ( $parent_id ) {
			delete_post_meta( $post_id, '_migrateplus_parent_uid' );
		}
		// Before the custom fields: ACF groups can be shown by page template or post format.
		$template = sanitize_text_field( MigratePlus_Util::stringify( self::value( $record, $mapping, 'page_template' ) ) );
		if ( '' !== $template && ! self::protected_meta( '_wp_page_template', $template, $type, $job ) ) {
			update_post_meta( $post_id, '_wp_page_template', wp_slash( $template ) );
		}
		$format = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_format' ) );
		if ( '' !== $format && post_type_supports( $type, 'post-formats' ) ) {
			set_post_format( $post_id, $format );
		}

		if ( '' !== $author ) {
			$warnings[] = sprintf(
				$updating
					/* translators: %s: author email, login or name */
					? __( 'Author "%s" was not found on this site, so the post keeps its author.', 'migrateplus' )
					/* translators: %s: author email, login or name */
					: __( 'Author "%s" was not found on this site, so the default author was used.', 'migrateplus' ),
				$author
			);
		}

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
			$names                  = MigratePlus_Util::to_list( $raw );
			if ( ! $names ) {
				continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $type, $taxonomy ) ) {
				/* translators: %s: taxonomy */
				$warnings[] = sprintf( __( 'Taxonomy "%s" is not registered for this post type.', 'migrateplus' ), $taxonomy );
				continue;
			}
			$term_ids = self::resolve_terms( $taxonomy, $names, ! empty( $opts['create_terms'] ), $warnings );
			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}

		// Custom fields. Keys that belong to an ACF field are saved through ACF.
		$acf_map = isset( $mapping['acf'] ) ? (array) $mapping['acf'] : array();
		// MigratePlus exports from before "meta_native" carried array values as JSON text.
		$embedded    = isset( $job->data['settings']['migrateplus_mapping'] ) ? (array) $job->data['settings']['migrateplus_mapping'] : null;
		$legacy_meta = null !== $embedded && empty( $embedded['meta_native'] );
		foreach ( (array) $mapping['meta'] as $row ) {
			if ( empty( $row['key'] ) || empty( $row['source'] ) ) {
				continue;
			}
			$key = sanitize_text_field( $row['key'] );
			if ( MigratePlus_ACF::active() && ! isset( $acf_map[ $key ] ) && MigratePlus_ACF::get_field_object( $key, $post_id ) ) {
				$acf_map[ $key ] = $row['source'];
				continue;
			}
			$value = MigratePlus_ACF::read( $record, $row['source'] );
			if ( null === $value ) {
				continue;
			}
			if ( $legacy_meta && is_string( $value ) && 0 === strpos( $row['source'], 'meta.' ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			if ( self::protected_meta( $key, $value, $type, $job ) ) {
				continue;
			}
			$rows = self::meta_rows( $value );
			if ( null !== $rows ) {
				// A key stored in several rows.
				delete_post_meta( $post_id, $key );
				foreach ( $rows as $one ) {
					add_post_meta( $post_id, $key, wp_slash( $one ) );
				}
				continue;
			}
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		// ACF fields.
		MigratePlus_ACF::import( $post_id, $record, $acf_map, $job, $opts, $base, $warnings );

		// Featured image.
		$image = MigratePlus_Util::stringify( self::value( $record, $mapping, 'featured_image' ) );
		if ( '' !== $image && ! empty( $opts['import_featured_image'] ) ) {
			$alt = MigratePlus_Util::stringify( self::value( $record, $mapping, 'featured_image_alt' ) );
			$att = MigratePlus_Media::sideload( $image, $post_id, $alt, $base, $job->data['id'] );
			if ( is_wp_error( $att ) ) {
				$warnings[] = __( 'Featured image: ', 'migrateplus' ) . $att->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $att );
			}
		}

		// Inline content images.
		if ( ! empty( $opts['import_content_images'] ) && '' !== $content ) {
			$errors  = array();
			$updated = MigratePlus_Media::import_content_images( $content, $post_id, $base, $job->data['id'], $errors );
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
				$warnings[] = __( 'Content image: ', 'migrateplus' ) . $error;
			}
		}

		// WooCommerce product fields (prices, stock, attributes, variations).
		if ( MigratePlus_Woo::is_product_type( $type ) ) {
			MigratePlus_Woo::import( $post_id, $record, $job, $opts, $base, $warnings );
		} elseif ( 'shop_coupon' === $type ) {
			// After the custom fields: a coupon's product and category restrictions arrived there as the old site's ids.
			MigratePlus_Woo::import_coupon( $post_id, $record, $job, $opts, $warnings );
		}

		$comments = empty( $mapping['fields']['comments'] ) ? null : MigratePlus_ACF::read( $record, $mapping['fields']['comments'] );
		$added    = ! empty( $opts['import_comments'] ) && is_array( $comments ) && $comments ? self::import_comments( $post_id, $comments, $job, $warnings ) : 0;

		$action = $updating ? 'updated' : 'created';
		do_action( 'migrateplus_after_import_record', $post_id, $record, $job, $action );

		/* translators: 1: Created/Updated, 2: title, 3: post id */
		$message = sprintf( __( '%1$s "%2$s" (#%3$d).', 'migrateplus' ), $updating ? __( 'Updated', 'migrateplus' ) : __( 'Created', 'migrateplus' ), $label, $post_id );
		if ( $added ) {
			/* translators: %d: number of comments */
			$message .= ' ' . sprintf( _n( 'Added %d comment.', 'Added %d comments.', $added, 'migrateplus' ), $added );
		}
		if ( $warnings ) {
			$message .= ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', $warnings );
		}
		$result             = self::result( $action, $post_id, $message, ! empty( $warnings ) );
		$result['comments'] = $added;
		return $result;
	}

	/**
	 * The author a record names, for a warning when none of it matched a user.
	 *
	 * @param array $record  Flat record.
	 * @param array $mapping Mapping.
	 * @return string '' when the record names no author.
	 */
	protected static function author_label( array $record, array $mapping ) {
		foreach ( array( 'post_author_name', 'post_author', 'post_author_login' ) as $target ) {
			$value = trim( MigratePlus_Util::stringify( self::value( $record, $mapping, $target ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Whether a custom field is one WordPress, WooCommerce or Migrate Plus keeps for
	 * itself, which a mapping must not write: a file path, an attachment's sizes or a
	 * menu item's target written raw would break the post, and the tracking keys would
	 * break rollback and "imported before". Not every key starting with an underscore:
	 * SEO plugins keep their fields that way (_yoast_wpseo_*). The featured image has
	 * its own field. A page template is fine when the theme has it. The first time a
	 * job skips a key, its log says so.
	 *
	 * @param string          $key   Meta key.
	 * @param mixed           $value Value to write.
	 * @param string          $type  Post type.
	 * @param MigratePlus_Job $job   Job (its log, and the keys it already reported).
	 * @return bool
	 */
	protected static function protected_meta( $key, $value, $type, MigratePlus_Job $job ) {
		$exact    = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_thumbnail_id', '_edit_lock', '_edit_last', '_wp_old_slug', '_downloadable_files', '_product_attributes', '_product_version' );
		$prefixes = array( '_wp_trash_meta_', '_menu_item_', '_migrateplus_' );
		$blocked  = in_array( $key, $exact, true );
		foreach ( $prefixes as $prefix ) {
			$blocked = $blocked || 0 === strpos( $key, $prefix );
		}
		if ( '_wp_page_template' === $key ) {
			// The theme's list, not get_page_templates(): that is an admin function, missing from WP-CLI and cron.
			$blocked = ! is_string( $value ) || ( 'default' !== $value && ! array_key_exists( $value, wp_get_theme()->get_page_templates( null, $type ) ) );
		}
		if ( ! $blocked ) {
			return false;
		}
		$reported = isset( $job->data['protected_meta'] ) ? (array) $job->data['protected_meta'] : array();
		if ( ! in_array( $key, $reported, true ) ) {
			$job->data['protected_meta'][] = $key;
			$job->log(
				'warning',
				'_wp_page_template' === $key
					/* translators: %s: page template */
					? sprintf( __( 'Page template "%s" is not in the active theme, so it was not set.', 'migrateplus' ), is_string( $value ) ? $value : '' )
					/* translators: %s: meta key */
					: sprintf( __( 'Custom field "%s" is kept by WordPress, WooCommerce or Migrate Plus, so it was not imported.', 'migrateplus' ), $key )
			);
		}
		return true;
	}

	/**
	 * The post a record's parent names by slug: the record carries the parent as
	 * { id, slug, type } (mapped from "parent.id"), as menu items carry their target.
	 *
	 * @param array $record  Flat record.
	 * @param array $mapping Mapping.
	 * @return int 0 when the record names no slug or no post has it.
	 */
	protected static function find_parent_by_slug( array $record, array $mapping ) {
		$source = isset( $mapping['fields']['post_parent'] ) ? (string) $mapping['fields']['post_parent'] : '';
		if ( ! preg_match( '/^(.+)\.id$/', $source, $m ) || empty( $record[ $m[1] . '.slug' ] ) ) {
			return 0;
		}
		$type = isset( $record[ $m[1] . '.type' ] ) ? sanitize_key( MigratePlus_Util::stringify( $record[ $m[1] . '.type' ] ) ) : '';
		$ids  = get_posts(
			array(
				'post_type'        => post_type_exists( $type ) ? $type : array_keys( get_post_types() ),
				'name'             => sanitize_title( MigratePlus_Util::stringify( $record[ $m[1] . '.slug' ] ) ),
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The rows of a meta key the exporter found stored in several rows, written as
	 * { "_migrateplus_rows": [ ... ] }, or "_pressport_rows" in files exported before the rename.
	 *
	 * @param mixed $value Value from the record.
	 * @return array|null Null for a value stored in one row.
	 */
	protected static function meta_rows( $value ) {
		if ( ! is_array( $value ) || 1 !== count( $value ) ) {
			return null;
		}
		foreach ( array( '_migrateplus_rows', '_pressport_rows' ) as $marker ) {
			if ( array_key_exists( $marker, $value ) ) {
				return (array) $value[ $marker ];
			}
		}
		return null;
	}

	/**
	 * Add a MigratePlus export's comments to a post, replies under their parents.
	 * Each comment keeps a key for its id on the old site, so importing the same
	 * file again updates the comments it added instead of adding them twice.
	 * Comments added here carry the job id, so rollback can remove them from
	 * posts it does not delete.
	 *
	 * @param int             $post_id  Post.
	 * @param array[]         $comments Comments as exported.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Number of comments added.
	 */
	protected static function import_comments( $post_id, array $comments, MigratePlus_Job $job, array &$warnings ) {
		$local = array(); // Source key => comment id on this site.
		foreach ( get_comments(
			array(
				'post_id'  => $post_id,
				'status'   => 'any',
				'type'     => 'all',
				'meta_key' => self::COMMENT_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		) as $existing ) {
			$local[ (string) get_comment_meta( $existing->comment_ID, self::COMMENT_SOURCE_META, true ) ] = (int) $existing->comment_ID;
		}
		$read    = static function ( array $c, $key ) {
			return isset( $c[ $key ] ) ? MigratePlus_Util::stringify( $c[ $key ] ) : '';
		};
		$added   = 0;
		$orphans = array(); // Comment id => parent's source key, for replies listed before their parent.
		foreach ( $comments as $c ) {
			if ( ! is_array( $c ) || '' === $read( $c, 'id' ) ) {
				continue;
			}
			$key    = self::uid( $job, 'comment:' . $read( $c, 'id' ) );
			$parent = '' !== $read( $c, 'parent' ) && '0' !== $read( $c, 'parent' ) ? self::uid( $job, 'comment:' . $read( $c, 'parent' ) ) : '';
			$user   = is_email( $read( $c, 'user_email' ) ) ? get_user_by( 'email', $read( $c, 'user_email' ) ) : false;
			$type   = sanitize_key( $read( $c, 'type' ) );
			$status = strtolower( $read( $c, 'approved' ) );
			$data   = array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $read( $c, 'author' ),
				'comment_author_email' => $read( $c, 'author_email' ),
				'comment_author_url'   => $read( $c, 'author_url' ),
				'comment_author_IP'    => '',
				'comment_content'      => $read( $c, 'content' ),
				'comment_type'         => '' === $type ? 'comment' : $type,
				'comment_parent'       => '' !== $parent && isset( $local[ $parent ] ) ? $local[ $parent ] : 0,
				'user_id'              => $user ? (int) $user->ID : 0,
				// Anything but approved, spam or trash waits for moderation.
				'comment_approved'     => in_array( $status, array( '1', 'spam', 'trash' ), true ) ? $status : ( 'approve' === $status || 'approved' === $status ? '1' : '0' ),
			);
			$date   = self::parse_date( '' !== $read( $c, 'date_gmt' ) ? $read( $c, 'date_gmt' ) : $read( $c, 'date' ) );
			if ( $date ) {
				$data['comment_date']     = $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
				$data['comment_date_gmt'] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			if ( isset( $local[ $key ] ) ) {
				$id = $local[ $key ];
				// Slashed, as wp_update_comment() expects; it runs the comment filters itself. 0 means nothing changed.
				$ok = ! is_wp_error( wp_update_comment( wp_slash( array( 'comment_ID' => $id ) + $data ), true ) );
			} else {
				// The same filters (kses on the content among them) a comment posted on this site goes through.
				$id = (int) wp_insert_comment( wp_filter_comment( wp_slash( $data ) ) );
				$ok = $id > 0;
				if ( $ok ) {
					add_comment_meta( $id, self::COMMENT_SOURCE_META, $key );
					add_comment_meta( $id, '_migrateplus_job_id', $job->data['id'] );
					$local[ $key ] = $id;
					++$added;
				}
			}
			if ( ! $ok ) {
				/* translators: %s: comment id on the old site */
				$warnings[] = sprintf( __( 'Comment #%s could not be saved.', 'migrateplus' ), $read( $c, 'id' ) );
				continue;
			}
			if ( '' !== $parent && ! $data['comment_parent'] ) {
				$orphans[ $id ] = $parent;
			}
			foreach ( isset( $c['meta'] ) && is_array( $c['meta'] ) ? $c['meta'] : array() as $meta_key => $value ) {
				$meta_key = sanitize_text_field( (string) $meta_key );
				if ( '' === $meta_key || is_protected_meta( $meta_key, 'comment' ) ) {
					continue;
				}
				delete_comment_meta( $id, $meta_key );
				$rows = self::meta_rows( $value );
				$rows = null !== $rows ? $rows : array( $value );
				foreach ( $rows as $one ) {
					add_comment_meta( $id, $meta_key, wp_slash( $one ) );
				}
			}
		}
		foreach ( $orphans as $id => $parent ) {
			if ( isset( $local[ $parent ] ) ) {
				wp_update_comment(
					array(
						'comment_ID'     => $id,
						'comment_parent' => $local[ $parent ],
					)
				);
			}
		}
		// WooCommerce works a product's rating out inside wp_insert_comment(), before the review's
		// "rating" meta above exists (comment_meta passed to it is saved after the recount too). Recount
		// now. A no-op for other post types.
		if ( class_exists( 'WC_Comments' ) ) {
			WC_Comments::clear_transients( $post_id );
		}
		return $added;
	}

	/**
	 * The post type a record imports as: "Import as", unless a mapped Post type names one this site has.
	 *
	 * @param array           $record Flat record.
	 * @param MigratePlus_Job $job    Job.
	 * @return string
	 */
	public static function type_for( array $record, MigratePlus_Job $job ) {
		$opts   = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$type   = post_type_exists( $opts['post_type'] ) ? $opts['post_type'] : 'post';
		$routed = sanitize_key( MigratePlus_Util::stringify( self::value( $record, $job->data['mapping'], 'post_type' ) ) );
		return '' !== $routed && self::importable_post_type( $routed ) ? $routed : $type;
	}

	/**
	 * The site a job's records come from: the base for relative media paths.
	 * A MigratePlus export names its site.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return string
	 */
	protected static function base_url( MigratePlus_Job $job ) {
		$s = $job->data['settings'];
		foreach ( array( 'site_url', 'url', 'migrateplus_site' ) as $key ) {
			if ( ! empty( $s[ $key ] ) ) {
				return (string) $s[ $key ];
			}
		}
		return '';
	}

	/**
	 * Where a job's records come from, as part of each record's "imported before"
	 * key, so post #5 from one source is never taken for post #5 from another: the
	 * site for a MigratePlus export, otherwise the feed URL or the file's name. The
	 * name, not its content, so re-importing an edited copy of a file still updates
	 * what it imported before. Admin uploads are saved under a random name, which
	 * would never match again; they use the original name when the upload kept it.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return string
	 */
	public static function source_key( MigratePlus_Job $job ) {
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
	 * A record's "imported before" key (_migrateplus_source_uid): its source id,
	 * scoped to where the job's records come from (see source_key()).
	 *
	 * @param MigratePlus_Job $job       Job.
	 * @param string          $source_id Id in the source.
	 * @return string '' without a source id.
	 */
	public static function uid( MigratePlus_Job $job, $source_id ) {
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
	 * @param array           $record Flat record.
	 * @param MigratePlus_Job $job    Job (mapping and options as they will run).
	 * @return array|null { kind: before|existing, post_id: int, label: string }
	 */
	public static function find_duplicate( array $record, MigratePlus_Job $job ) {
		$mapping   = $job->data['mapping'];
		$opts      = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$type      = self::type_for( $record, $job );
		$source_id = MigratePlus_Util::stringify( self::value( $record, $mapping, 'source_id' ) );
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
			return null === $value ? null : trim( str_replace( "\r\n", "\n", MigratePlus_Util::stringify( $value ) ) );
		};
		$fields  = array(
			'title'       => array( __( 'Title', 'migrateplus' ), $post->post_title, $read( 'post_title' ) ),
			'caption'     => array( __( 'Caption', 'migrateplus' ), $post->post_excerpt, $read( 'post_excerpt' ) ),
			'description' => array( __( 'Description', 'migrateplus' ), $post->post_content, $read( 'post_content' ) ),
			'alt'         => array( __( 'Alt text', 'migrateplus' ), (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ), $read( 'media_alt' ) ),
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
			'imported'     => MigratePlus_Media::absolutize( (string) $read( 'media_url' ), $base ),
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
	 * @param array           $record  Flat record.
	 * @param MigratePlus_Job $job     Job.
	 * @param int             $post_id Existing post.
	 * @param string          $type    Post type the record imports as.
	 * @return array field => { label, old, new }; empty when nothing differs.
	 */
	public static function changes_for( array $record, MigratePlus_Job $job, $post_id, $type ) {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $type || MigratePlus_Woo::is_product_type( $type ) ) {
			return array();
		}
		$postarr = apply_filters( 'migrateplus_post_data', array( 'ID' => (int) $post_id ) + self::post_fields( $record, $job, $type, false ), $record, $job );
		// Each field is set only when the record has it, as an update writes it.
		$new    = static function ( $key ) use ( $postarr ) {
			return isset( $postarr[ $key ] ) ? (string) $postarr[ $key ] : null;
		};
		$author = static function ( $id ) {
			$user = get_userdata( (int) $id );
			return $user ? $user->display_name : '';
		};

		// field => [ label, current value, imported value or null when not mapped / not set ].
		$fields  = array(
			'title'   => array( __( 'Title', 'migrateplus' ), $post->post_title, $new( 'post_title' ) ),
			'content' => array( __( 'Content', 'migrateplus' ), $post->post_content, $new( 'post_content' ) ),
			'excerpt' => array( __( 'Excerpt', 'migrateplus' ), $post->post_excerpt, $new( 'post_excerpt' ) ),
			'status'  => array( __( 'Status', 'migrateplus' ), $post->post_status, $new( 'post_status' ) ),
			'date'    => array( __( 'Publish date', 'migrateplus' ), $post->post_date, $new( 'post_date' ) ),
			'slug'    => array( __( 'Slug', 'migrateplus' ), $post->post_name, $new( 'post_name' ) ),
			'author'  => array( __( 'Author', 'migrateplus' ), $author( $post->post_author ), isset( $postarr['post_author'] ) ? $author( $postarr['post_author'] ) : null ),
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
	 * @param array           $record         Flat record.
	 * @param MigratePlus_Job $job            Job.
	 * @param string          $type           Post type the record imports as.
	 * @param bool            $create_authors Create an author that is not on this site.
	 * @return array wp_insert_post() args.
	 */
	protected static function post_fields( array $record, MigratePlus_Job $job, $type, $create_authors ) {
		$mapping = $job->data['mapping'];
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );

		// Title and content only when the record has them, so an update leaves the ones it lacks alone.
		$postarr = array( 'post_type' => $type );
		$title   = self::value( $record, $mapping, 'post_title' );
		if ( null !== $title ) {
			// Only real tags (and script/style with their contents) are removed, after decoding: stripping
			// everything from a "<" on would turn "I <3 PHP" into "I".
			$title                 = html_entity_decode( MigratePlus_Util::stringify( $title ), ENT_QUOTES, 'UTF-8' );
			$title                 = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $title );
			$postarr['post_title'] = trim( (string) preg_replace( '#<!--.*?-->|</?[a-zA-Z][^<>]*>#s', '', $title ) );
		}
		$content = self::value( $record, $mapping, 'post_content' );
		if ( null !== $content ) {
			$content = MigratePlus_Util::stringify( $content );
			if ( ! empty( $opts['rewrite_links'] ) ) {
				$content = self::rewrite_links( $content, self::base_url( $job ) );
			}
			$postarr['post_content'] = $content;
		}

		$excerpt = self::value( $record, $mapping, 'post_excerpt' );
		if ( null !== $excerpt ) {
			// Excerpts may hold HTML (a Migrate Plus export carries it as written): keep what a post may contain.
			$postarr['post_excerpt'] = wp_kses_post( MigratePlus_Util::stringify( $excerpt ) );
		}

		$status = self::value( $record, $mapping, 'post_status' );
		if ( null !== $status && '' !== MigratePlus_Util::stringify( $status ) ) {
			$postarr['post_status'] = self::normalize_status( MigratePlus_Util::stringify( $status ), $opts['default_status'] );
		}
		// Also on update. A published post dated in the future is still scheduled: wp_insert_post() makes it "future".
		if ( ! empty( $opts['force_status'] ) ) {
			$postarr['post_status'] = $opts['force_status'];
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

		$author = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_author' ) );
		$login  = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_author_login' ) );
		$name   = MigratePlus_Util::stringify( self::value( $record, $mapping, 'post_author_name' ) );
		// Exports from before login and name were mapped keep matching on the one value, as they always did.
		$author_id = '' !== $login || '' !== $name ? self::resolve_exported_author( $author, $login, $name, $create_authors ) : ( '' !== $author ? self::resolve_author( $author, $create_authors ) : 0 );
		if ( $author_id ) {
			$postarr['post_author'] = $author_id;
		}

		$order = self::value( $record, $mapping, 'menu_order' );
		if ( null !== $order && is_numeric( $order ) ) {
			$postarr['menu_order'] = (int) $order;
		}
		$comments = strtolower( MigratePlus_Util::stringify( self::value( $record, $mapping, 'comment_status' ) ) );
		if ( '' !== $comments ) {
			$postarr['comment_status'] = in_array( $comments, array( 'open', '1', 'true', 'yes' ), true ) ? 'open' : 'closed';
		}
		$password = self::value( $record, $mapping, 'post_password' );
		if ( null !== $password ) {
			$postarr['post_password'] = MigratePlus_Util::stringify( $password );
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
		$value    = MigratePlus_Util::stringify( $value );
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
			$url = MigratePlus_Util::stringify( self::value( $record, $mapping, 'media_url' ) );
			$id  = '' !== $url ? MigratePlus_Media::find_existing( $url ) : 0;
			if ( $id ) {
				// Downloaded by an earlier import, e.g. as a featured image.
				return array(
					'post_id' => $id,
					'label'   => __( 'file', 'migrateplus' ),
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
				'label'   => __( 'file name', 'migrateplus' ),
			) : null;
		}
		if ( MigratePlus_Woo::is_product_type( $type ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku = isset( $record['woo.sku'] ) ? trim( MigratePlus_Util::stringify( $record['woo.sku'] ) ) : '';
			$id  = '' !== $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			if ( $id && 'product' === get_post_type( $id ) ) {
				return array(
					'post_id' => $id,
					'label'   => __( 'SKU', 'migrateplus' ),
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
			'label'   => __( 'slug', 'migrateplus' ),
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
		$alt = MigratePlus_Util::stringify( $alt );
		if ( $replace && ! empty( $postarr['ID'] ) ) {
			$replaced = MigratePlus_Media::replace_file( $postarr['ID'], MigratePlus_Util::stringify( $url ), $base );
			if ( is_wp_error( $replaced ) ) {
				return $replaced;
			}
		}
		if ( empty( $postarr['ID'] ) ) {
			$url = MigratePlus_Media::absolutize( MigratePlus_Util::stringify( $url ), $base );
			if ( '' === $url ) {
				return new WP_Error( 'migrateplus_media_file', __( 'No file URL to import.', 'migrateplus' ) );
			}
			// Already downloaded by an earlier import (e.g. as a featured image): update that item, and
			// leave it out of this import's rollback, as with any post an import only updated.
			$updating = ! $new && MigratePlus_Media::find_existing( $url );
			$id       = MigratePlus_Media::sideload( $url, 0, $alt, '', $job_id, ! $new );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$postarr['ID'] = $id;
			if ( ! $updating ) {
				// A new item, so a title or description the record lacks is empty, as for any new post.
				$postarr += array(
					'post_title'   => '',
					'post_content' => '',
				);
			}
		}
		if ( '' !== $alt ) {
			update_post_meta( $postarr['ID'], '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt ) ) );
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
	 * Find a post previously imported with this uid. "Import as copies" leaves several
	 * posts with the same uid: the one the given job made, else the newest.
	 *
	 * @param string          $uid    Uid.
	 * @param string|string[] $type   Post type, or several.
	 * @param string          $job_id Prefer the post this job imported.
	 * @return int
	 */
	public static function find_by_uid( $uid, $type, $job_id = '' ) {
		$args = array(
			'post_type'        => $type,
			'post_status'      => 'any',
			'meta_key'         => '_migrateplus_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'suppress_filters' => true,
		);
		if ( '' !== (string) $job_id ) {
			$ids = get_posts(
				$args + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_migrateplus_job_id',
							'value' => (string) $job_id,
						),
					),
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$ids = get_posts( $args );
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
		$value = trim( MigratePlus_Util::stringify( $value ) );
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
		if ( ! $id && $create && ! is_numeric( $value ) ) {
			$id = self::create_author( is_email( $value ) ? strstr( $value, '@', true ) : sanitize_title( $value ), is_email( $value ) ? $value : '', is_email( $value ) ? '' : $value );
		}

		self::$authors[ $value ] = $id;
		return $id;
	}

	/**
	 * Resolve the author of a MigratePlus export, which names them by email, login
	 * and display name: email first, then login (the same person often has a
	 * different email on each site), then the display name and the other ways
	 * resolve_author() looks. A user created for a missing author gets all three.
	 *
	 * @param string $email  Email.
	 * @param string $login  Login.
	 * @param string $name   Display name.
	 * @param bool   $create Create missing users (role: author).
	 * @return int
	 */
	public static function resolve_exported_author( $email, $login, $name, $create = false ) {
		$email = trim( (string) $email );
		$login = trim( (string) $login );
		$name  = trim( (string) $name );
		$cache = "\0" . $email . "\0" . $login . "\0" . $name; // Can't collide with a plain value cached by resolve_author().
		if ( isset( self::$authors[ $cache ] ) && ( self::$authors[ $cache ] || ! $create ) ) {
			return self::$authors[ $cache ];
		}
		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user && '' !== $login ) {
			$user = get_user_by( 'login', $login );
		}
		$id = $user ? (int) $user->ID : 0;
		if ( ! $id ) {
			$id = self::resolve_author( '' !== $name ? $name : ( '' !== $email ? $email : $login ), false );
		}
		if ( ! $id && $create ) {
			$id = self::create_author( '' !== $login ? $login : ( is_email( $email ) ? strstr( $email, '@', true ) : sanitize_title( $name ) ), is_email( $email ) ? $email : '', $name );
		}
		self::$authors[ $cache ] = $id;
		return $id;
	}

	/**
	 * Create a user for an author that is not on this site, when the current user may.
	 *
	 * @param string $login Login to start from (a number is added when it is taken).
	 * @param string $email Email, or ''.
	 * @param string $name  Display name, or '' to use the login.
	 * @return int 0 when not allowed or the user could not be created.
	 */
	protected static function create_author( $login, $email, $name ) {
		// WP-CLI without --user has no user to check, and runs with the shell account's access.
		$may_create = ( defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id() ) || current_user_can( 'create_users' );
		if ( ! $may_create ) {
			return 0;
		}
		$login = sanitize_user( (string) $login, true );
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
				'user_email'   => $email,
				'display_name' => '' !== $name ? $name : $login,
				'nickname'     => '' !== $name ? $name : $login,
				'role'         => 'author',
			)
		);
		return is_wp_error( $new ) ? 0 : (int) $new;
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
							$warnings[] = sprintf( __( 'Term "%1$s" (%2$s) could not be created: %3$s', 'migrateplus' ), $part, $taxonomy, $term->get_error_message() );
						}
						$term = $existing ? array( 'term_id' => (int) $existing ) : null;
					}
				} elseif ( ! $term ) {
					/* translators: 1: term name, 2: taxonomy */
					$warnings[] = sprintf( __( 'Term "%1$s" (%2$s) was not found and creating terms is off.', 'migrateplus' ), $part, $taxonomy );
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
