<?php
/**
 * Drupal (JSON:API) source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls nodes of one content type from Drupal 8+ via the core JSON:API module.
 * Related files, media, taxonomy terms and authors are resolved from "included".
 */
class PressPort_Source_Drupal extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'drupal';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'Drupal site (JSON:API)', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'site_url',
				'label'    => __( 'Site URL', 'pressport' ),
				'type'     => 'url',
				'required' => true,
				'help'     => __( 'The JSON:API module must be enabled on the Drupal site.', 'pressport' ),
			),
			array(
				'key'     => 'bundle',
				'label'   => __( 'Content type (machine name)', 'pressport' ),
				'type'    => 'text',
				'default' => 'article',
				'help'    => __( 'e.g. "article", "page", "blog_post".', 'pressport' ),
			),
			array(
				'key'   => 'include',
				'label' => __( 'Relationships to include', 'pressport' ),
				'type'  => 'text',
				'help'  => __( 'Comma separated, e.g. "field_image,field_tags,uid". Leave empty to auto-detect. Use dot paths for media, e.g. "field_media.field_media_image".', 'pressport' ),
			),
			array(
				'key'   => 'username',
				'label' => __( 'Username (optional)', 'pressport' ),
				'type'  => 'text',
			),
			array(
				'key'   => 'password',
				'label' => __( 'Password (optional)', 'pressport' ),
				'type'  => 'password',
				'help'  => __( 'Requires the Basic Auth module; needed for unpublished content.', 'pressport' ),
			),
		);
	}

	/**
	 * @return array
	 */
	protected function auth() {
		return PressPort_Util::basic_auth( $this->get( 'username' ), $this->get( 'password' ) );
	}

	/**
	 * @param int  $offset  Offset.
	 * @param int  $limit   Limit.
	 * @param bool $include Whether to include relationships.
	 * @return string
	 */
	protected function url( $offset, $limit, $include = true ) {
		$url  = $this->base_url( $this->get( 'site_url' ) ) . '/jsonapi/node/' . rawurlencode( $this->get( 'bundle', 'article' ) );
		$args = array(
			'page[offset]' => $offset,
			'page[limit]'  => $limit,
			'sort'         => 'drupal_internal__nid',
		);
		if ( $include && '' !== (string) $this->get( 'include' ) ) {
			$args['include'] = preg_replace( '/\s+/', '', $this->get( 'include' ) );
		}
		return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/** @inheritDoc */
	public function prepare() {
		$settings = parent::prepare();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$result = PressPort_Util::get_json( $this->url( 0, 1, false ), $this->auth() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['body']['data'] ) ) {
			return new WP_Error( 'pressport_drupal', __( 'Unexpected JSON:API response. Check the site URL and content type.', 'pressport' ) );
		}
		if ( '' === (string) $this->get( 'include' ) && ! empty( $result['body']['data'][0]['relationships'] ) ) {
			$skip     = array( 'node_type', 'revision_uid', 'revision_user', 'menu_link' );
			$includes = array();
			foreach ( $result['body']['data'][0]['relationships'] as $name => $rel ) {
				if ( ! in_array( $name, $skip, true ) && ! empty( $rel['data'] ) ) {
					$includes[] = $name;
				}
			}
			$this->settings['include'] = implode( ',', $includes );
		}
		$this->settings['total'] = isset( $result['body']['meta']['count'] ) ? (int) $result['body']['meta']['count'] : null;
		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$offset = null === $cursor ? 0 : (int) $cursor;
		$limit  = min( 50, max( 1, (int) $limit ) );
		$result = PressPort_Util::get_json( $this->url( $offset, $limit ), $this->auth() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$body     = $result['body'];
		$data     = isset( $body['data'] ) ? (array) $body['data'] : array();
		$included = array();
		foreach ( isset( $body['included'] ) ? (array) $body['included'] : array() as $inc ) {
			if ( isset( $inc['type'], $inc['id'] ) ) {
				$included[ $inc['type'] . ':' . $inc['id'] ] = $inc;
			}
		}
		$records = array();
		foreach ( $data as $node ) {
			$records[] = $this->normalize( $node, $included );
		}
		return array(
			'records' => $records,
			'next'    => ! empty( $body['links']['next'] ) ? $offset + count( $data ) : null,
			'total'   => $this->get( 'total', null ),
		);
	}

	/**
	 * Turn a related resource into a usable scalar (URL, name or title).
	 *
	 * @param array $ref      Relationship identifier { type, id }.
	 * @param array $included Included map.
	 * @param int   $depth    Recursion depth.
	 * @return string
	 */
	protected function resolve( $ref, $included, $depth = 0 ) {
		$key = ( isset( $ref['type'] ) ? $ref['type'] : '' ) . ':' . ( isset( $ref['id'] ) ? $ref['id'] : '' );
		if ( ! isset( $included[ $key ] ) ) {
			return '';
		}
		$res   = $included[ $key ];
		$attrs = isset( $res['attributes'] ) ? $res['attributes'] : array();
		if ( ! empty( $attrs['uri']['url'] ) ) {
			$url = $attrs['uri']['url'];
			return 0 === strpos( $url, 'http' ) ? $url : $this->base_url( $this->get( 'site_url' ) ) . $url;
		}
		// Media entity: follow its first file relationship.
		if ( $depth < 2 && 0 === strpos( $res['type'], 'media--' ) && ! empty( $res['relationships'] ) ) {
			foreach ( $res['relationships'] as $name => $rel ) {
				if ( 'thumbnail' !== $name && ! empty( $rel['data']['type'] ) && 'file--file' === $rel['data']['type'] ) {
					$url = $this->resolve( $rel['data'], $included, $depth + 1 );
					if ( $url ) {
						return $url;
					}
				}
			}
		}
		foreach ( array( 'name', 'display_name', 'title', 'label' ) as $field ) {
			if ( isset( $attrs[ $field ] ) && is_scalar( $attrs[ $field ] ) ) {
				return (string) $attrs[ $field ];
			}
		}
		return '';
	}

	/**
	 * @param array $node     Node resource.
	 * @param array $included Included map.
	 * @return array
	 */
	protected function normalize( $node, $included ) {
		$attrs  = isset( $node['attributes'] ) ? (array) $node['attributes'] : array();
		$record = array( 'uuid' => isset( $node['id'] ) ? $node['id'] : '' );
		$record += PressPort_Util::flatten( $attrs );

		foreach ( isset( $node['relationships'] ) ? (array) $node['relationships'] : array() as $name => $rel ) {
			if ( empty( $rel['data'] ) || in_array( $name, array( 'node_type', 'revision_uid' ), true ) ) {
				continue;
			}
			if ( isset( $rel['data']['type'] ) ) {
				$value = $this->resolve( $rel['data'], $included );
				if ( '' !== $value ) {
					$record[ $name ] = $value;
				}
				if ( ! empty( $rel['data']['meta']['alt'] ) ) {
					$record[ $name . '_alt' ] = $rel['data']['meta']['alt'];
				}
			} else {
				$values = array();
				foreach ( $rel['data'] as $ref ) {
					$value = $this->resolve( $ref, $included );
					if ( '' !== $value ) {
						$values[] = $value;
					}
				}
				if ( $values ) {
					$record[ $name ] = $values;
				}
			}
		}
		return $record;
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		$map = array(
			'source_id'    => 'drupal_internal__nid',
			'post_title'   => 'title',
			'post_content' => 'body.value',
			'post_excerpt' => 'body.summary',
			'post_date'    => 'created',
			'post_name'    => 'path.alias',
			'post_status'  => 'status',
			'post_author'  => 'uid',
		);
		$include = (string) $this->get( 'include' );
		if ( false !== strpos( $include, 'field_image' ) ) {
			$map['featured_image'] = 'field_image';
		}
		if ( false !== strpos( $include, 'field_tags' ) ) {
			$map['tags'] = 'field_tags';
		}
		return $map;
	}
}
