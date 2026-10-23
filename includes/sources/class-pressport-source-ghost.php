<?php
/**
 * Ghost (Content API) source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls published posts or pages from a Ghost site via the Content API.
 */
class PressPort_Source_Ghost extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'ghost';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'Ghost site (Content API)', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'site_url',
				'label'    => __( 'Site URL', 'pressport' ),
				'type'     => 'url',
				'required' => true,
			),
			array(
				'key'      => 'api_key',
				'label'    => __( 'Content API key', 'pressport' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'Ghost Admin → Settings → Integrations → Add custom integration.', 'pressport' ),
			),
			array(
				'key'     => 'resource',
				'label'   => __( 'Content', 'pressport' ),
				'type'    => 'select',
				'default' => 'posts',
				'options' => array(
					'posts' => __( 'Posts', 'pressport' ),
					'pages' => __( 'Pages', 'pressport' ),
				),
			),
		);
	}

	/**
	 * @param int $page  Page.
	 * @param int $limit Limit.
	 * @return string
	 */
	protected function url( $page, $limit ) {
		$resource = 'pages' === $this->get( 'resource' ) ? 'pages' : 'posts';
		return add_query_arg(
			array(
				'key'     => rawurlencode( $this->get( 'api_key' ) ),
				'limit'   => $limit,
				'page'    => $page,
				'include' => 'tags,authors',
				'formats' => 'html',
				'order'   => 'published_at%20asc',
			),
			$this->base_url( $this->get( 'site_url' ) ) . '/ghost/api/content/' . $resource . '/'
		);
	}

	/** @inheritDoc */
	public function prepare() {
		$settings = parent::prepare();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$result = PressPort_Util::get_json( $this->url( 1, 1 ), array( 'Accept-Version' => 'v5.0' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->settings['total'] = isset( $result['body']['meta']['pagination']['total'] ) ? (int) $result['body']['meta']['pagination']['total'] : null;
		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$page   = null === $cursor ? 1 : (int) $cursor;
		$result = PressPort_Util::get_json( $this->url( $page, $limit ), array( 'Accept-Version' => 'v5.0' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$resource = 'pages' === $this->get( 'resource' ) ? 'pages' : 'posts';
		$items    = isset( $result['body'][ $resource ] ) ? (array) $result['body'][ $resource ] : array();
		$records  = array();
		foreach ( $items as $item ) {
			$tags = array();
			foreach ( isset( $item['tags'] ) ? (array) $item['tags'] : array() as $tag ) {
				// Ghost internal tags start with "#".
				if ( isset( $tag['name'] ) && 0 !== strpos( $tag['name'], '#' ) ) {
					$tags[] = $tag['name'];
				}
			}
			$records[] = array(
				'id'                   => isset( $item['id'] ) ? $item['id'] : '',
				'title'                => isset( $item['title'] ) ? $item['title'] : '',
				'html'                 => isset( $item['html'] ) ? $item['html'] : '',
				'excerpt'              => ! empty( $item['custom_excerpt'] ) ? $item['custom_excerpt'] : ( isset( $item['excerpt'] ) ? $item['excerpt'] : '' ),
				'slug'                 => isset( $item['slug'] ) ? $item['slug'] : '',
				'published_at'         => isset( $item['published_at'] ) ? $item['published_at'] : '',
				'updated_at'           => isset( $item['updated_at'] ) ? $item['updated_at'] : '',
				'feature_image'        => isset( $item['feature_image'] ) ? (string) $item['feature_image'] : '',
				'feature_image_alt'    => isset( $item['feature_image_alt'] ) ? (string) $item['feature_image_alt'] : '',
				'featured'             => ! empty( $item['featured'] ) ? '1' : '0',
				'tags'                 => $tags,
				'primary_tag'          => isset( $item['primary_tag']['name'] ) ? $item['primary_tag']['name'] : '',
				'author_name'          => isset( $item['primary_author']['name'] ) ? $item['primary_author']['name'] : '',
				'author_slug'          => isset( $item['primary_author']['slug'] ) ? $item['primary_author']['slug'] : '',
				'url'                  => isset( $item['url'] ) ? $item['url'] : '',
				'meta_title'           => isset( $item['meta_title'] ) ? (string) $item['meta_title'] : '',
				'meta_description'     => isset( $item['meta_description'] ) ? (string) $item['meta_description'] : '',
				'visibility'           => isset( $item['visibility'] ) ? $item['visibility'] : '',
			);
		}
		$next = isset( $result['body']['meta']['pagination']['next'] ) ? $result['body']['meta']['pagination']['next'] : null;
		return array(
			'records' => $records,
			'next'    => $next ? (int) $next : null,
			'total'   => $this->get( 'total', null ),
		);
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return array(
			'source_id'      => 'id',
			'post_title'     => 'title',
			'post_content'   => 'html',
			'post_excerpt'   => 'excerpt',
			'post_date'      => 'published_at',
			'post_name'      => 'slug',
			'post_author'    => 'author_name',
			'categories'     => 'primary_tag',
			'tags'           => 'tags',
			'featured_image' => 'feature_image',
		);
	}
}
