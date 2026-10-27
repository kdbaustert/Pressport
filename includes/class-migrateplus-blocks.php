<?php
/**
 * Block markup that points at other content by id.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block content names other content by the old site's ids: a synced pattern
 * (<!-- wp:block {"ref":12} -->), a navigation block and its links, the
 * attachment behind an image, cover, video, audio, file or gallery block, the
 * posts and terms a query or latest posts block lists, a WooCommerce block's
 * products and categories. After a post is imported, those ids are pointed at
 * the content imported from them. Authors are left as they are: users are not
 * imported with their old ids.
 *
 * A target imported later in the file is linked when it arrives: the post keeps
 * one _migrateplus_pending_ref row per id it still waits for (the id's uid, so
 * only the same source matches), and the importer calls link_pending() for
 * every post it saves. The markup is then relinked from the copy kept when the
 * post was first relinked, with the job that kept it, so an id is never mapped
 * twice: once mapped, 340 could be mistaken for the old site's #340.
 *
 * Patterns, navigation menus, templates and template parts are posts, so they
 * export and import like any other type; this class is what makes them work
 * once they are here.
 */
class MigratePlus_Blocks {

	const PENDING_META = '_migrateplus_pending_ref';

	/**
	 * A ref's id while its post is not here: no post has it, so the block shows nothing.
	 *
	 * @var int
	 */
	const NOWHERE = -1;

	/**
	 * Post meta keeping a waiting post's markup as the import saved it, with the
	 * old site's ids: { content, job, media, written }.
	 *
	 * @var string
	 */
	const SOURCE_META = '_migrateplus_block_source';

	/**
	 * Post types of the block editor that an export can carry, in import order:
	 * patterns before the content that uses them, navigation and templates after
	 * the pages they link to.
	 *
	 * @var string[]
	 */
	const FIRST = array( 'wp_block' );
	const LAST  = array( 'wp_navigation', 'wp_template_part', 'wp_template' );

	/**
	 * Taxonomies these types need that an export would otherwise leave out as
	 * hidden: the theme a template belongs to, the area of a template part.
	 *
	 * @var string[]
	 */
	const HIDDEN_TAXONOMIES = array( 'wp_theme', 'wp_template_part_area' );

	/**
	 * Blocks whose attributes name content by id, and the attribute(s) that do.
	 * An attribute may hold one id or a list (of ids, or of objects with an id).
	 * A template part's "theme" is a name, and is left alone.
	 *
	 * @var array[] block => [ attribute => what it names: post, ref, attachment, link,
	 *              term:<taxonomy>, or query (a query block's query) ]. A ref is a
	 *              post that, until it is here, is pointed at NOWHERE: its old id
	 *              may be another post here, which the block would then show.
	 */
	const REFS = array(
		'core/block'                       => array( 'ref' => 'ref' ),
		'core/navigation'                  => array( 'ref' => 'ref' ),
		'core/navigation-link'             => array( 'id' => 'link' ),
		'core/navigation-submenu'          => array( 'id' => 'link' ),
		'core/image'                       => array( 'id' => 'attachment' ),
		'core/cover'                       => array( 'id' => 'attachment' ),
		'core/video'                       => array( 'id' => 'attachment' ),
		'core/audio'                       => array( 'id' => 'attachment' ),
		'core/file'                        => array( 'id' => 'attachment' ),
		'core/media-text'                  => array( 'mediaId' => 'attachment' ),
		'core/gallery'                     => array( 'ids' => 'attachment' ),
		'core/post-featured-image'         => array(),
		'core/query'                       => array( 'query' => 'query' ),
		'core/latest-posts'                => array( 'categories' => 'term:category' ),
		'core/page-list'                   => array( 'parentPageID' => 'post' ),
		'woocommerce/single-product'       => array( 'productId' => 'post' ),
		'woocommerce/featured-product'     => array( 'productId' => 'post' ),
		'woocommerce/reviews-by-product'   => array( 'productId' => 'post' ),
		'woocommerce/handpicked-products'  => array( 'products' => 'post' ),
		'woocommerce/featured-category'    => array( 'categoryId' => 'term:product_cat' ),
		'woocommerce/reviews-by-category'  => array( 'categoryIds' => 'term:product_cat' ),
		'woocommerce/product-category'     => array( 'categories' => 'term:product_cat' ),
		'woocommerce/product-best-sellers' => array( 'categories' => 'term:product_cat' ),
		'woocommerce/product-new'          => array( 'categories' => 'term:product_cat' ),
		'woocommerce/product-on-sale'      => array( 'categories' => 'term:product_cat' ),
		'woocommerce/product-top-rated'    => array( 'categories' => 'term:product_cat' ),
	);

	/**
	 * Point the ids in a post's block markup at the content imported from them.
	 * The importer calls this once the post is saved with the file's markup.
	 *
	 * @param int             $post_id Post id.
	 * @param MigratePlus_Job $job     Job.
	 * @return int Ids still waiting for their target.
	 */
	public static function relink( $post_id, MigratePlus_Job $job ) {
		$post  = get_post( $post_id );
		$media = MigratePlus_Media::take_block_ids( (int) $post_id );
		if ( ! $post || ! has_blocks( $post->post_content ) ) {
			return 0;
		}
		return self::relink_from( (int) $post_id, $post->post_content, $post->post_content, $job, $media );
	}

	/**
	 * Relink a post from its source markup, save the result and remember what it still waits for.
	 *
	 * @param int             $post_id Post id.
	 * @param string          $source  Markup with the old site's ids (those import_content_images() mapped aside).
	 * @param string          $current The post's markup now.
	 * @param MigratePlus_Job $job     The job the source came with.
	 * @param int[]           $media   Ids import_content_images() mapped, old => new.
	 * @return int Ids still waiting for their target.
	 */
	protected static function relink_from( $post_id, $source, $current, MigratePlus_Job $job, array $media ) {
		$pending = array();
		$content = self::relink_content( $source, $job, $pending, $media );
		$written = $current;
		if ( $content !== $current ) {
			// Straight to the table: wp_update_post() would run the content through filters again and bump the modified date.
			global $wpdb;
			if ( false !== $wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $post_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$written = $content;
			}
			clean_post_cache( $post_id );
		}
		$pending = array_values( array_unique( $pending ) );
		delete_post_meta( $post_id, self::PENDING_META );
		foreach ( $pending as $old ) {
			add_post_meta( $post_id, self::PENDING_META, MigratePlus_Importer::uid( $job, $old ) );
		}
		if ( $pending || $written !== $content ) {
			// Kept for when a target arrives, or to try again when the write failed.
			$keep = array(
				'content' => $source,
				'job'     => (string) $job->data['id'],
				'media'   => $media,
				'written' => md5( $written ),
			);
			update_post_meta( $post_id, self::SOURCE_META, wp_slash( $keep ) );
		} else {
			delete_post_meta( $post_id, self::SOURCE_META );
		}
		return count( $pending );
	}

	/**
	 * A post the import just saved: posts whose blocks were waiting for it are linked up.
	 *
	 * @param int             $post_id   Post id of the new post.
	 * @param string          $source_id Its id on the old site.
	 * @param MigratePlus_Job $job       Job.
	 */
	public static function link_pending( $post_id, $source_id, MigratePlus_Job $job ) {
		if ( '' === (string) $source_id ) {
			return;
		}
		$waiting = get_posts(
			array(
				'post_type'        => array_keys( get_post_types() ),
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => self::PENDING_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => MigratePlus_Importer::uid( $job, $source_id ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $waiting as $id ) {
			if ( (int) $id !== (int) $post_id ) {
				self::relink_waiting( (int) $id, $job );
			}
		}
	}

	/**
	 * Relink a waiting post from the markup kept for it, with the job that kept it.
	 * A post edited since is left as it is, and stops waiting.
	 *
	 * @param int             $post_id Post id.
	 * @param MigratePlus_Job $job     The job whose post arrived (same source, as the uid matched).
	 */
	protected static function relink_waiting( $post_id, MigratePlus_Job $job ) {
		$post = get_post( $post_id );
		$kept = get_post_meta( $post_id, self::SOURCE_META, true );
		if ( ! $post || ! is_array( $kept ) || ! isset( $kept['content'] ) || ( isset( $kept['written'] ) && md5( $post->post_content ) !== $kept['written'] ) ) {
			delete_post_meta( $post_id, self::PENDING_META );
			delete_post_meta( $post_id, self::SOURCE_META );
			return;
		}
		$recorder = ! empty( $kept['job'] ) ? MigratePlus_Job::load( $kept['job'] ) : null;
		self::relink_from( $post_id, (string) $kept['content'], $post->post_content, $recorder ? $recorder : $job, isset( $kept['media'] ) ? array_map( 'intval', (array) $kept['media'] ) : array() );
	}

	/**
	 * Block markup with its ids pointed at this site's content.
	 *
	 * @param string          $content Block markup.
	 * @param MigratePlus_Job $job     Job.
	 * @param string[]        $pending Source ids whose target is not here yet (by reference).
	 * @param int[]           $media   Attachment ids import_content_images() already pointed at its copies,
	 *                                 old => new: image, cover, media & text, video, audio and file blocks holding
	 *                                 either are left alone, and other blocks naming an old one get its copy.
	 * @return string
	 */
	public static function relink_content( $content, MigratePlus_Job $job, array &$pending, array $media = array() ) {
		$images = array(); // Old attachment id => new, for the wp-image-N class in the markup.
		$done   = array_flip( array_merge( array_keys( $media ), array_values( $media ) ) );
		$out    = preg_replace_callback(
			'/<!--\s+wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+(\{.*?\})\s+(\/?)-->/s',
			static function ( $m ) use ( $job, &$pending, &$images, $media, $done ) {
				$name = ( '' !== $m[1] ? $m[1] : 'core/' ) . $m[2];
				if ( ! isset( self::REFS[ $name ] ) || ! self::REFS[ $name ] ) {
					return $m[0];
				}
				// Objects stay objects: decoded to arrays, an empty {} would be written back as [].
				$attrs = json_decode( $m[3] );
				if ( ! $attrs instanceof stdClass ) {
					return $m[0];
				}
				$mapped  = 0 === strpos( $name, 'core/' ) && in_array( substr( $name, 5 ), MigratePlus_Media::BLOCK_TYPES, true ) ? $done : array();
				$changed = false;
				foreach ( self::REFS[ $name ] as $attr => $kind ) {
					if ( ! isset( $attrs->$attr ) ) {
						continue;
					}
					if ( 'attachment' === $kind && is_scalar( $attrs->$attr ) && isset( $mapped[ (int) $attrs->$attr ] ) ) {
						continue; // Already one of this site's: import_content_images() put it there.
					}
					$ctx   = array(
						'job'     => $job,
						'attrs'   => (array) $attrs,
						'media'   => $media,
						'images'  => &$images,
						'pending' => &$pending,
					);
					$value = 'query' === $kind ? self::relink_query( $attrs->$attr, $ctx ) : self::relink_value( $kind, $attrs->$attr, $ctx );
					if ( wp_json_encode( $value ) === wp_json_encode( $attrs->$attr ) ) {
						continue; // Nothing found to change: the block is written back byte for byte.
					}
					$attrs->$attr = $value;
					$changed      = true;
					if ( 'link' === $kind ) {
						$attrs->url = isset( $attrs->kind ) && 'taxonomy' === $attrs->kind ? (string) get_term_link( (int) $value ) : (string) get_permalink( (int) $value );
					}
				}
				if ( ! $changed ) {
					return $m[0];
				}
				return '<!-- wp:' . ( '' !== $m[1] ? $m[1] : '' ) . $m[2] . ' ' . serialize_block_attributes( $attrs ) . ' ' . $m[4] . '-->';
			},
			$content
		);
		if ( null === $out || ! $images ) {
			return null === $out ? $content : $out;
		}
		// One pass, each class looked up once: with { 12: 340, 340: 977 }, replacing one id after another
		// would turn the image that became 340 into 977 as well.
		$replaced = preg_replace_callback(
			'/\bwp-image-(\d+)\b/',
			static function ( $m ) use ( $images, $done ) {
				return isset( $images[ (int) $m[1] ] ) && ! isset( $done[ (int) $m[1] ] ) ? 'wp-image-' . $images[ (int) $m[1] ] : $m[0];
			},
			$out
		);
		return null === $replaced ? $out : $replaced;
	}

	/**
	 * An attribute's id, or list of ids, pointed at this site's content. Ids not
	 * found stay as they are, but a ref's become NOWHERE.
	 *
	 * @param string $kind  What the ids name (see REFS).
	 * @param mixed  $value Id, list of ids, or list of objects with an id.
	 * @param array  $ctx   { job, attrs, media, images (by reference), pending (by reference) }.
	 * @return mixed
	 */
	protected static function relink_value( $kind, $value, array $ctx ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $i => $item ) {
				$value[ $i ] = self::relink_value( $kind, $item, $ctx );
			}
			return $value;
		}
		if ( $value instanceof stdClass ) {
			if ( isset( $value->id ) ) {
				$value     = clone $value;
				$value->id = self::relink_value( $kind, $value->id, $ctx );
			}
			return $value;
		}
		$new = self::resolve( $kind, $value, $ctx['attrs'], $ctx['job'], $ctx['pending'], $ctx['media'] );
		if ( ! $new && 'ref' === $kind && is_numeric( $value ) ) {
			return is_string( $value ) ? (string) self::NOWHERE : self::NOWHERE;
		}
		if ( ! $new || $new === (int) $value ) {
			return $value;
		}
		if ( 'attachment' === $kind ) {
			$ctx['images'][ (int) $value ] = $new;
		}
		return is_string( $value ) ? (string) $new : $new;
	}

	/**
	 * A query block's query with the posts and terms it names pointed at this site's.
	 *
	 * @param mixed $query The block's "query" attribute.
	 * @param array $ctx   As relink_value() takes it.
	 * @return mixed
	 */
	protected static function relink_query( $query, array $ctx ) {
		if ( ! $query instanceof stdClass ) {
			return $query;
		}
		$query = clone $query;
		foreach ( array( 'exclude', 'parents' ) as $key ) {
			if ( isset( $query->$key ) ) {
				$query->$key = self::relink_value( 'ref', $query->$key, $ctx );
			}
		}
		if ( isset( $query->taxQuery ) && $query->taxQuery instanceof stdClass ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the block's own name.
			$query->taxQuery = self::relink_tax_query( $query->taxQuery, $ctx ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		return $query;
	}

	/**
	 * A query block's taxQuery ({ taxonomy: [ term ids ] }, possibly under include/exclude) with this site's term ids.
	 *
	 * @param stdClass $tax_query The taxQuery.
	 * @param array    $ctx       As relink_value() takes it.
	 * @return stdClass
	 */
	protected static function relink_tax_query( stdClass $tax_query, array $ctx ) {
		$tax_query = clone $tax_query;
		foreach ( get_object_vars( $tax_query ) as $key => $ids ) {
			if ( $ids instanceof stdClass ) {
				$tax_query->$key = self::relink_tax_query( $ids, $ctx );
			} elseif ( is_array( $ids ) && taxonomy_exists( (string) $key ) ) {
				$tax_query->$key = self::relink_value( 'term:' . $key, $ids, $ctx );
			}
		}
		return $tax_query;
	}

	/**
	 * This site's id for content the old site named by id.
	 *
	 * @param string          $kind    post, ref, attachment, link or term:<taxonomy>.
	 * @param mixed           $old     Id on the old site.
	 * @param array           $attrs   The block's attributes (a link says whether it points at a post or a term).
	 * @param MigratePlus_Job $job     Job.
	 * @param string[]        $pending Source ids not here yet (by reference): posts, attachments and links only,
	 *                                 since terms are imported before any post.
	 * @param int[]           $media   Attachments import_content_images() made, old id => new.
	 * @return int 0 when it is not here.
	 */
	protected static function resolve( $kind, $old, array $attrs, MigratePlus_Job $job, array &$pending, array $media = array() ) {
		if ( ! is_numeric( $old ) || (int) $old <= 0 ) {
			return 0;
		}
		$old = (string) (int) $old;
		if ( 0 === strpos( $kind, 'term:' ) ) {
			$taxonomy = substr( $kind, 5 );
			return taxonomy_exists( $taxonomy ) ? MigratePlus_Terms::find_term(
				array( 'taxonomy' => $taxonomy ),
				MigratePlus_Terms::uid(
					$job,
					array(
						'taxonomy' => $taxonomy,
						'id'       => $old,
					)
				)
			) : 0;
		}
		if ( 'attachment' === $kind && isset( $media[ $old ] ) ) {
			return (int) $media[ $old ];
		}
		if ( 'link' === $kind && isset( $attrs['kind'] ) && 'taxonomy' === $attrs['kind'] ) {
			// A term link: by the slug in its URL, since terms are not imported by id.
			$taxonomy = isset( $attrs['type'] ) ? sanitize_key( $attrs['type'] ) : '';
			$taxonomy = 'category' === $taxonomy || 'tag' === $taxonomy ? ( 'tag' === $taxonomy ? 'post_tag' : 'category' ) : $taxonomy;
			$slug     = isset( $attrs['url'] ) ? MigratePlus_Importer::slug_from( $attrs['url'] ) : '';
			$term     = '' !== $slug && taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $slug, $taxonomy ) : false;
			return $term instanceof WP_Term ? (int) $term->term_id : 0;
		}
		$types = 'attachment' === $kind ? 'attachment' : array_keys( get_post_types() );
		$id    = MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, $old ), $types, $job->data['id'] );
		if ( ! $id && 'link' === $kind && isset( $attrs['url'] ) ) {
			// Pages skipped as already here never got a uid: match the slug in the link.
			$slug = MigratePlus_Importer::slug_from( $attrs['url'] );
			$type = isset( $attrs['type'] ) && post_type_exists( sanitize_key( $attrs['type'] ) ) ? sanitize_key( $attrs['type'] ) : array_keys( get_post_types() );
			$hit  = '' !== $slug ? get_posts(
				array(
					'name'             => $slug,
					'post_type'        => $type,
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			) : array();
			$id   = $hit ? (int) $hit[0] : 0;
		}
		if ( ! $id ) {
			$pending[] = $old;
		}
		return (int) $id;
	}

	/**
	 * Whether a post type is one of the block editor's, imported with its theme term.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_template_type( $type ) {
		return in_array( $type, array( 'wp_template', 'wp_template_part' ), true );
	}

	/**
	 * A template or template part of the active theme with this slug: the one
	 * the import updates, since two templates with one slug would both be "single".
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug.
	 * @return int 0 when the theme has no customised template with it.
	 */
	public static function find_template( $type, $slug ) {
		if ( '' === $slug || ! self::is_template_type( $type ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'name'             => $slug,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}
}
