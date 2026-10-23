<?php
/**
 * Source registry.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry of available sources. Extend with the "pressport_sources" filter:
 *
 *     add_filter( 'pressport_sources', function ( $sources ) {
 *         $sources['my_cms'] = 'My_CMS_Source'; // implements PressPort_Source
 *         return $sources;
 *     } );
 */
class PressPort_Sources {

	/**
	 * Map of source id => class name.
	 *
	 * @return string[]
	 */
	public static function all() {
		$sources = array(
			'csv'       => 'PressPort_Source_CSV',
			'json'      => 'PressPort_Source_JSON',
			'wordpress' => 'PressPort_Source_WordPress',
			'drupal'    => 'PressPort_Source_Drupal',
			'ghost'     => 'PressPort_Source_Ghost',
		);
		return (array) apply_filters( 'pressport_sources', $sources );
	}

	/**
	 * Instantiate a source.
	 *
	 * @param string $id       Source id.
	 * @param array  $settings Settings.
	 * @return PressPort_Source|WP_Error
	 */
	public static function create( $id, array $settings ) {
		$all = self::all();
		if ( empty( $all[ $id ] ) || ! class_exists( $all[ $id ] ) ) {
			/* translators: %s: source id */
			return new WP_Error( 'pressport_source', sprintf( __( 'Unknown source "%s".', 'pressport' ), $id ) );
		}
		$class = $all[ $id ];
		$obj   = new $class( $settings );
		if ( ! $obj instanceof PressPort_Source ) {
			return new WP_Error( 'pressport_source', __( 'Source class must implement PressPort_Source.', 'pressport' ) );
		}
		return $obj;
	}

	/**
	 * Guess a mapping from a list of source field names.
	 *
	 * @param string[] $fields Source fields.
	 * @return array target => source field
	 */
	public static function guess_mapping( array $fields ) {
		$synonyms = array(
			'source_id'      => array( 'id', 'uuid', 'guid', 'external_id', 'source_id', 'nid', 'post_id', 'legacy_id' ),
			'post_title'     => array( 'title', 'post_title', 'headline', 'name', 'subject', 'title.rendered' ),
			'post_content'   => array( 'content', 'post_content', 'body', 'body.value', 'html', 'content_html', 'content.rendered', 'text', 'content_text', 'description' ),
			'post_excerpt'   => array( 'excerpt', 'post_excerpt', 'summary', 'teaser', 'body.summary', 'custom_excerpt', 'excerpt.rendered' ),
			'post_date'      => array( 'date', 'post_date', 'published_at', 'date_published', 'published', 'publish_date', 'pubdate', 'created', 'created_at' ),
			'post_name'      => array( 'slug', 'post_name', 'url_slug', 'path.alias' ),
			'post_status'    => array( 'status', 'post_status', 'state' ),
			'post_author'    => array( 'author', 'author_email', 'author_name', 'author.name', 'authors.0.name', 'post_author', 'creator', 'byline' ),
			'categories'     => array( 'categories', 'category', 'post_category', 'section' ),
			'tags'           => array( 'tags', 'tag', 'keywords', 'post_tag', 'post_tags' ),
			'featured_image' => array( 'featured_image', 'featured_image_url', 'image', 'image_url', 'thumbnail', 'feature_image', 'cover_image', 'banner_image', 'image.url' ),
		);
		$lower = array();
		foreach ( $fields as $field ) {
			$lower[ strtolower( (string) $field ) ] = $field;
		}
		$map  = array();
		$used = array();
		foreach ( $synonyms as $target => $names ) {
			foreach ( $names as $name ) {
				if ( isset( $lower[ $name ] ) && ! isset( $used[ $lower[ $name ] ] ) ) {
					$map[ $target ]          = $lower[ $name ];
					$used[ $lower[ $name ] ] = true;
					break;
				}
			}
		}
		return $map;
	}
}
