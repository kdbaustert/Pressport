<?php
/**
 * Remote WordPress site source (REST API).
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls posts/pages/CPTs from another WordPress site through /wp/v2.
 * With an Application Password it uses context=edit so raw block markup,
 * drafts and private content come across intact.
 */
class PressPort_Source_WordPress extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'wordpress';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'WordPress site (REST API)', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'site_url',
				'label'    => __( 'Site URL', 'pressport' ),
				'type'     => 'url',
				'required' => true,
				'help'     => __( 'e.g. https://old-site.com', 'pressport' ),
			),
			array(
				'key'     => 'rest_base',
				'label'   => __( 'Content type (REST base)', 'pressport' ),
				'type'    => 'text',
				'default' => 'posts',
				'help'    => __( '"posts", "pages", or a custom post type REST base such as "products".', 'pressport' ),
			),
			array(
				'key'   => 'username',
				'label' => __( 'Username (optional)', 'pressport' ),
				'type'  => 'text',
			),
			array(
				'key'   => 'app_password',
				'label' => __( 'Application password (optional)', 'pressport' ),
				'type'  => 'password',
				'help'  => __( 'Enables raw block content, drafts and private posts. Create one under Users → Profile on the source site.', 'pressport' ),
			),
		);
	}

	/**
	 * @return array
	 */
	protected function auth() {
		return PressPort_Util::basic_auth( $this->get( 'username' ), str_replace( ' ', '', (string) $this->get( 'app_password' ) ) );
	}

	/**
	 * @param array $args Query args.
	 * @return string
	 */
	protected function endpoint( $args ) {
		$base  = $this->get( 'api_base' );
		$route = '/wp/v2/' . rawurlencode( $this->get( 'rest_base', 'posts' ) );
		// Works for both "/wp-json" and "/?rest_route=" bases.
		return add_query_arg( $args, $base . $route );
	}

	/**
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array
	 */
	protected function query_args( $page, $per_page ) {
		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
			'orderby'  => 'id',
			'order'    => 'asc',
			'_embed'   => 1,
		);
		if ( $this->auth() ) {
			$args['context'] = 'edit';
			$args['status']  = 'any';
		}
		return $args;
	}

	/** @inheritDoc */
	public function prepare() {
		$settings = parent::prepare();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$site  = $this->base_url( $this->get( 'site_url' ) );
		$bases = array( $site . '/wp-json', $site . '/?rest_route=' );
		$error = null;
		foreach ( $bases as $base ) {
			$this->settings['api_base'] = $base;
			$result                     = PressPort_Util::get_json( $this->endpoint( $this->query_args( 1, 1 ) ), $this->auth() );
			if ( ! is_wp_error( $result ) && is_array( $result['body'] ) ) {
				$this->settings['total'] = isset( $result['headers']['x-wp-total'] ) ? (int) $result['headers']['x-wp-total'] : null;
				return $this->settings;
			}
			$error = is_wp_error( $result ) ? $result : new WP_Error( 'pressport_wp', __( 'Unexpected response from the WordPress REST API.', 'pressport' ) );
		}
		return $error;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$page   = null === $cursor ? 1 : (int) $cursor;
		$limit  = min( 100, max( 1, (int) $limit ) );
		$result = PressPort_Util::get_json( $this->endpoint( $this->query_args( $page, $limit ) ), $this->auth() );
		if ( is_wp_error( $result ) ) {
			// WP returns 400 when paging past the end.
			if ( $page > 1 && false !== strpos( $result->get_error_message(), 'HTTP 400' ) ) {
				return array(
					'records' => array(),
					'next'    => null,
					'total'   => $this->get( 'total', null ),
				);
			}
			return $result;
		}
		$items       = is_array( $result['body'] ) ? $result['body'] : array();
		$total_pages = isset( $result['headers']['x-wp-totalpages'] ) ? (int) $result['headers']['x-wp-totalpages'] : null;
		$has_more    = null !== $total_pages ? $page < $total_pages : count( $items ) === $limit;

		return array(
			'records' => array_map( array( $this, 'normalize' ), $items ),
			'next'    => $has_more ? $page + 1 : null,
			'total'   => $this->get( 'total', null ),
		);
	}

	/**
	 * Convert a REST item into a flat record.
	 *
	 * @param array $item Item.
	 * @return array
	 */
	public function normalize( $item ) {
		$pick = static function ( $value ) {
			if ( is_array( $value ) ) {
				return isset( $value['raw'] ) ? $value['raw'] : ( isset( $value['rendered'] ) ? $value['rendered'] : '' );
			}
			return $value;
		};

		$embedded = isset( $item['_embedded'] ) ? $item['_embedded'] : array();
		$record   = array(
			'id'             => isset( $item['id'] ) ? $item['id'] : '',
			'title'          => $pick( isset( $item['title'] ) ? $item['title'] : '' ),
			'content'        => $pick( isset( $item['content'] ) ? $item['content'] : '' ),
			'excerpt'        => $pick( isset( $item['excerpt'] ) ? $item['excerpt'] : '' ),
			'date'           => isset( $item['date'] ) ? $item['date'] : '',
			'date_gmt'       => isset( $item['date_gmt'] ) ? $item['date_gmt'] : '',
			'modified'       => isset( $item['modified'] ) ? $item['modified'] : '',
			'slug'           => isset( $item['slug'] ) ? $item['slug'] : '',
			'status'         => isset( $item['status'] ) ? $item['status'] : '',
			'type'           => isset( $item['type'] ) ? $item['type'] : '',
			'link'           => isset( $item['link'] ) ? $item['link'] : '',
			'parent'         => isset( $item['parent'] ) ? $item['parent'] : '',
			'menu_order'     => isset( $item['menu_order'] ) ? $item['menu_order'] : '',
			'comment_status' => isset( $item['comment_status'] ) ? $item['comment_status'] : '',
			'format'         => isset( $item['format'] ) ? $item['format'] : '',
			'sticky'         => ! empty( $item['sticky'] ) ? '1' : '0',
			'template'       => isset( $item['template'] ) ? $item['template'] : '',
			'author_name'    => '',
			'author_slug'    => '',
			'categories'     => array(),
			'tags'           => array(),
			'featured_image' => '',
			'featured_image_alt' => '',
		);

		if ( ! empty( $embedded['author'][0] ) && is_array( $embedded['author'][0] ) ) {
			$record['author_name'] = isset( $embedded['author'][0]['name'] ) ? $embedded['author'][0]['name'] : '';
			$record['author_slug'] = isset( $embedded['author'][0]['slug'] ) ? $embedded['author'][0]['slug'] : '';
		}
		if ( ! empty( $embedded['wp:featuredmedia'][0]['source_url'] ) ) {
			$record['featured_image']     = $embedded['wp:featuredmedia'][0]['source_url'];
			$record['featured_image_alt'] = isset( $embedded['wp:featuredmedia'][0]['alt_text'] ) ? $embedded['wp:featuredmedia'][0]['alt_text'] : '';
		}
		if ( ! empty( $embedded['wp:term'] ) && is_array( $embedded['wp:term'] ) ) {
			foreach ( $embedded['wp:term'] as $group ) {
				foreach ( (array) $group as $term ) {
					if ( empty( $term['taxonomy'] ) || ! isset( $term['name'] ) ) {
						continue;
					}
					$name = html_entity_decode( $term['name'], ENT_QUOTES, 'UTF-8' );
					if ( 'category' === $term['taxonomy'] ) {
						$record['categories'][] = $name;
					} elseif ( 'post_tag' === $term['taxonomy'] ) {
						$record['tags'][] = $name;
					} else {
						$record[ 'terms.' . $term['taxonomy'] ][] = $name;
					}
				}
			}
		}
		foreach ( array( 'meta', 'acf' ) as $group ) {
			if ( ! empty( $item[ $group ] ) && is_array( $item[ $group ] ) ) {
				$record += PressPort_Util::flatten( $item[ $group ], $group );
			}
		}
		return $record;
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return array(
			'source_id'      => 'id',
			'post_title'     => 'title',
			'post_content'   => 'content',
			'post_excerpt'   => 'excerpt',
			'post_date'      => 'date',
			'post_name'      => 'slug',
			'post_status'    => 'status',
			'post_author'    => 'author_slug',
			'menu_order'     => 'menu_order',
			'categories'     => 'categories',
			'tags'           => 'tags',
			'featured_image' => 'featured_image',
		);
	}
}
