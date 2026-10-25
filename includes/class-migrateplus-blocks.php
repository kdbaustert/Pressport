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
 * attachment behind an image, cover, video, audio, file or gallery block. After
 * a post is imported, those ids are pointed at the posts imported from them.
 *
 * A target imported later in the file is linked when it arrives: the post keeps
 * one _migrateplus_pending_ref row per id it still waits for, and the importer
 * calls link_pending() for every post it saves.
 *
 * Patterns, navigation menus, templates and template parts are posts, so they
 * export and import like any other type; this class is what makes them work
 * once they are here.
 */
class MigratePlus_Blocks {

	const PENDING_META = '_migrateplus_pending_ref';

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
	 *
	 * @var array[] block => [ attribute => what it names: post, attachment, navigation or link ]
	 */
	const REFS = array(
		'core/block'               => array( 'ref' => 'post' ),
		'core/navigation'          => array( 'ref' => 'post' ),
		'core/navigation-link'     => array( 'id' => 'link' ),
		'core/navigation-submenu'  => array( 'id' => 'link' ),
		'core/image'               => array( 'id' => 'attachment' ),
		'core/cover'               => array( 'id' => 'attachment' ),
		'core/video'               => array( 'id' => 'attachment' ),
		'core/audio'               => array( 'id' => 'attachment' ),
		'core/file'                => array( 'id' => 'attachment' ),
		'core/media-text'          => array( 'mediaId' => 'attachment' ),
		'core/gallery'             => array( 'ids' => 'attachment' ),
		'core/post-featured-image' => array(),
	);

	/**
	 * Point the ids in a post's block markup at the content imported from them.
	 *
	 * @param int             $post_id Post id.
	 * @param MigratePlus_Job $job     Job.
	 * @return int Ids still waiting for their target.
	 */
	public static function relink( $post_id, MigratePlus_Job $job ) {
		$post = get_post( $post_id );
		if ( ! $post || ! has_blocks( $post->post_content ) ) {
			return 0;
		}
		$pending = array();
		$content = self::relink_content( $post->post_content, $job, $pending );
		if ( $content !== $post->post_content ) {
			// Straight to the table: wp_update_post() would run the content through filters again and bump the modified date.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $post_id );
		}
		delete_post_meta( $post_id, self::PENDING_META );
		foreach ( array_unique( $pending ) as $source_id ) {
			add_post_meta( $post_id, self::PENDING_META, $source_id );
		}
		return count( array_unique( $pending ) );
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
				'meta_value'       => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $waiting as $id ) {
			if ( (int) $id !== (int) $post_id ) {
				self::relink( (int) $id, $job );
			}
		}
	}

	/**
	 * Block markup with its ids pointed at this site's content.
	 *
	 * @param string          $content Block markup.
	 * @param MigratePlus_Job $job     Job.
	 * @param string[]        $pending Source ids whose target is not here yet (by reference).
	 * @return string
	 */
	public static function relink_content( $content, MigratePlus_Job $job, array &$pending ) {
		$images = array(); // Old attachment id => new, for the wp-image-N class in the markup.
		$out    = preg_replace_callback(
			'/<!--\s+wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+(\{.*?\})\s+(\/?)-->/s',
			static function ( $m ) use ( $job, &$pending, &$images ) {
				$name = ( '' !== $m[1] ? $m[1] : 'core/' ) . $m[2];
				if ( ! isset( self::REFS[ $name ] ) || ! self::REFS[ $name ] ) {
					return $m[0];
				}
				$attrs = json_decode( $m[3], true );
				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}
				$changed = false;
				foreach ( self::REFS[ $name ] as $attr => $kind ) {
					if ( ! isset( $attrs[ $attr ] ) ) {
						continue;
					}
					if ( 'ids' === $attr && is_array( $attrs[ $attr ] ) ) {
						foreach ( $attrs[ $attr ] as $i => $old ) {
							$new = self::resolve( $kind, $old, $attrs, $job, $pending );
							if ( $new && $new !== (int) $old ) {
								$images[ (int) $old ] = $new;
								$attrs[ $attr ][ $i ] = $new;
								$changed              = true;
							}
						}
						continue;
					}
					$new = self::resolve( $kind, $attrs[ $attr ], $attrs, $job, $pending );
					if ( ! $new || $new === (int) $attrs[ $attr ] ) {
						continue;
					}
					if ( 'attachment' === $kind ) {
						$images[ (int) $attrs[ $attr ] ] = $new;
					}
					$attrs[ $attr ] = $new;
					$changed        = true;
					if ( 'link' === $kind ) {
						$attrs['url'] = isset( $attrs['kind'] ) && 'taxonomy' === $attrs['kind'] ? (string) get_term_link( $new ) : (string) get_permalink( $new );
					}
				}
				if ( ! $changed ) {
					return $m[0];
				}
				return '<!-- wp:' . ( '' !== $m[1] ? $m[1] : '' ) . $m[2] . ' ' . serialize_block_attributes( $attrs ) . ' ' . $m[4] . '-->';
			},
			$content
		);
		if ( null === $out ) {
			return $content;
		}
		foreach ( $images as $old => $new ) {
			$out = preg_replace( '/\bwp-image-' . (int) $old . '\b/', 'wp-image-' . (int) $new, $out );
		}
		return $out;
	}

	/**
	 * This site's id for content the old site named by id.
	 *
	 * @param string          $kind    post, attachment, navigation or link.
	 * @param mixed           $old     Id on the old site.
	 * @param array           $attrs   The block's attributes (a link says whether it points at a post or a term).
	 * @param MigratePlus_Job $job     Job.
	 * @param string[]        $pending Source ids not here yet (by reference).
	 * @return int 0 when it is not here.
	 */
	protected static function resolve( $kind, $old, array $attrs, MigratePlus_Job $job, array &$pending ) {
		if ( ! is_numeric( $old ) || (int) $old <= 0 ) {
			return 0;
		}
		$old = (string) (int) $old;
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
