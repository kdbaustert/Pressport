<?php
/**
 * Any URL or feed source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports from any URL (or uploaded file): RSS 2.0 / RSS 1.0 / Atom feeds,
 * WordPress export (WXR) files, generic XML, JSON or CSV. The format is
 * detected from the content. CSV and JSON are handed to the CSV / JSON
 * sources; XML is converted to a list of records first and then handled
 * like JSON, so batching and re-runs behave the same everywhere.
 */
class PressPort_Source_Remote extends PressPort_Source_Base {

	const NS_CONTENT = 'purl.org/rss/1.0/modules/content';
	const NS_DC      = 'purl.org/dc/elements';
	const NS_WP      = 'wordpress.org/export';
	const NS_ATOM    = 'www.w3.org/2005/Atom';
	const NS_MEDIA   = 'search.yahoo.com/mrss';
	const NS_PP      = 'pressport';

	/** @inheritDoc */
	public static function id() {
		return 'remote';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'URL or feed', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'            => 'file',
				'label'          => __( 'URL', 'pressport' ),
				'type'           => 'file',
				'accept'         => '.xml,.rss,.atom,.json,.csv,.tsv,.txt',
				'required'       => true,
				'remote'         => true,
				'remote_default' => 'url',
				'help'           => __( 'An RSS or Atom feed, a WordPress export (WXR) file, or any URL or API endpoint that returns XML, JSON or CSV. The format is detected automatically.', 'pressport' ),
			),
			array(
				'key'     => 'format',
				'label'   => __( 'Format', 'pressport' ),
				'type'    => 'select',
				'default' => 'auto',
				'options' => array(
					'auto' => __( 'Auto-detect', 'pressport' ),
					'xml'  => __( 'XML (RSS, Atom, WordPress export, other XML)', 'pressport' ),
					'json' => __( 'JSON', 'pressport' ),
					'csv'  => __( 'CSV / TSV', 'pressport' ),
				),
			),
			array(
				'key'   => 'records_path',
				'label' => __( 'Records path', 'pressport' ),
				'type'  => 'text',
				'help' => __( 'Optional. JSON: dot path to the list, e.g. "data.posts". XML: path to the repeating element, e.g. "catalog.product". Leave empty to auto-detect.', 'pressport' ),
			),
			array(
				'key'     => 'item_types',
				'label'   => __( 'WordPress export: post types', 'pressport' ),
				'type'    => 'text',
				'default' => 'post',
				'help'    => __( 'Only used for WordPress export (WXR) files. Comma separated, e.g. "post, page". Use "*" for all content types.', 'pressport' ),
			),
			array(
				'key'   => 'headers',
				'label' => __( 'Request headers', 'pressport' ),
				'type'  => 'textarea',
				'help' => __( 'Optional, one per line, e.g. "Authorization: Bearer abc123".', 'pressport' ),
			),
		);
	}

	/** @inheritDoc */
	protected function remote_headers() {
		return PressPort_Util::parse_header_lines( (string) $this->get( 'headers' ) );
	}

	/** @inheritDoc */
	public function prepare() {
		$settings = parent::prepare(); // Downloads the URL into storage.
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$path = (string) $this->get( 'file' );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'pressport_remote_missing', __( 'The downloaded file could not be read.', 'pressport' ) );
		}

		$format = (string) $this->get( 'format', 'auto' );
		if ( ! in_array( $format, array( 'xml', 'json', 'csv' ), true ) ) {
			$format = self::sniff( $path );
			if ( is_wp_error( $format ) ) {
				return $format;
			}
		}

		$temp = '';
		if ( 'csv' === $format ) {
			$delegate = new PressPort_Source_CSV(
				array(
					'file'      => $path,
					'delimiter' => 'auto',
				)
			);
		} elseif ( 'json' === $format ) {
			$delegate = new PressPort_Source_JSON(
				array(
					'file'         => $path,
					'records_path' => trim( (string) $this->get( 'records_path' ) ),
				)
			);
		} else {
			$records = $this->parse_xml( $path );
			if ( is_wp_error( $records ) ) {
				return $records;
			}
			if ( ! $records ) {
				return new WP_Error( 'pressport_empty', __( 'The feed contains no items.', 'pressport' ) );
			}
			$temp = PressPort_Util::ensure_storage_dir() . 'xml-' . wp_generate_password( 12, false ) . '.json';
			file_put_contents( $temp, wp_json_encode( $records ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$delegate = new PressPort_Source_JSON( array( 'file' => $temp ) );
		}

		$prepared = $delegate->prepare();
		if ( $temp && file_exists( $temp ) ) {
			wp_delete_file( $temp ); // The JSON source keeps its own cache copy.
		}
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$this->settings['detected_format']   = $format;
		$this->settings['delegate']          = 'csv' === $format ? 'csv' : 'json';
		$this->settings['delegate_settings'] = $prepared;
		$this->settings['total']             = isset( $prepared['total'] ) ? (int) $prepared['total'] : null;
		// Top-level so the job cleans it up when deleted or refreshed.
		$this->settings['cache_file'] = isset( $prepared['cache_file'] ) ? $prepared['cache_file'] : '';

		return $this->settings;
	}

	/**
	 * The CSV or JSON source that reads the prepared data.
	 *
	 * @return PressPort_Source|WP_Error
	 */
	protected function delegate() {
		$settings = (array) $this->get( 'delegate_settings', array() );
		if ( ! $settings ) {
			return new WP_Error( 'pressport_remote_state', __( 'The source has not been prepared. Start a new import.', 'pressport' ) );
		}
		return 'csv' === $this->get( 'delegate' ) ? new PressPort_Source_CSV( $settings ) : new PressPort_Source_JSON( $settings );
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$delegate = $this->delegate();
		return is_wp_error( $delegate ) ? $delegate : $delegate->fetch( $cursor, $limit );
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		$delegate = $this->delegate();
		return is_wp_error( $delegate ) ? array() : $delegate->suggested_mapping();
	}

	/**
	 * Detect the format from the first bytes of a file.
	 *
	 * @param string $path Path.
	 * @return string|WP_Error xml|json|csv
	 */
	public static function sniff( $path ) {
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 0 === strpos( $head, "\xEF\xBB\xBF" ) ) {
			$head = substr( $head, 3 );
		}
		$head = ltrim( $head );
		if ( '' === $head ) {
			return new WP_Error( 'pressport_remote_empty', __( 'The file is empty.', 'pressport' ) );
		}
		if ( '{' === $head[0] || '[' === $head[0] ) {
			return 'json';
		}
		if ( '<' === $head[0] ) {
			if ( preg_match( '/^<(!doctype\s+html|html[\s>]|head[\s>]|body[\s>])/i', $head ) ) {
				return new WP_Error( 'pressport_remote_html', __( 'The URL returned a web page, not a feed or data file. Look for the site\'s RSS feed or an export/API link.', 'pressport' ) );
			}
			return 'xml';
		}
		return 'csv';
	}

	// XML.

	/**
	 * Parse an XML file into a list of records.
	 *
	 * @param string $path Path.
	 * @return array[]|WP_Error
	 */
	protected function parse_xml( $path ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'pressport_xml_ext', __( 'The PHP DOM extension is required to import XML.', 'pressport' ) );
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// LIBXML_NONET: never fetch external resources. Entities are not substituted (no LIBXML_NOENT).
		$ok    = $dom->load( $path, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_NOCDATA | LIBXML_COMPACT );
		$error = libxml_get_last_error();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok || ! $dom->documentElement ) {
			$detail = $error ? trim( $error->message ) . ' (line ' . $error->line . ')' : '';
			return new WP_Error( 'pressport_xml', __( 'The file is not valid XML. ', 'pressport' ) . $detail );
		}

		$root = $dom->documentElement;
		$name = strtolower( (string) $root->localName );
		$path = trim( (string) $this->get( 'records_path' ) );

		if ( '' !== $path ) {
			return self::generic_records( $root, $path );
		}
		if ( 'rss' === $name || 'rdf' === $name ) {
			$channel = self::first( $root, 'channel' );
			if ( $channel && self::first( $channel, 'wxr_version', self::NS_WP ) ) {
				return $this->wxr_records( $channel );
			}
			$items = $channel ? self::children( $channel, 'item' ) : array();
			if ( ! $items ) {
				$items = self::children( $root, 'item' ); // RSS 1.0 keeps items beside the channel.
			}
			return array_map( array( __CLASS__, 'rss_record' ), $items );
		}
		if ( 'feed' === $name ) {
			return array_map( array( __CLASS__, 'atom_record' ), self::children( $root, 'entry' ) );
		}
		return self::generic_records( $root, '' );
	}

	/**
	 * Child elements by local name, optionally restricted to a namespace.
	 *
	 * @param DOMElement  $el    Parent.
	 * @param string      $local Local name.
	 * @param string|null $ns    null = any namespace, '' = no namespace, otherwise a substring of the namespace URI.
	 * @return DOMElement[]
	 */
	protected static function children( DOMElement $el, $local, $ns = null ) {
		$out = array();
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || $child->localName !== $local ) {
				continue;
			}
			$uri = (string) $child->namespaceURI;
			if ( null === $ns || ( '' === $ns && '' === $uri ) || ( '' !== $ns && false !== strpos( $uri, $ns ) ) ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * @param DOMElement  $el    Parent.
	 * @param string      $local Local name.
	 * @param string|null $ns    See children().
	 * @return DOMElement|null
	 */
	protected static function first( DOMElement $el, $local, $ns = null ) {
		$found = self::children( $el, $local, $ns );
		return $found ? $found[0] : null;
	}

	/**
	 * Trimmed text of the first matching child.
	 *
	 * @param DOMElement  $el    Parent.
	 * @param string      $local Local name.
	 * @param string|null $ns    See children().
	 * @return string
	 */
	protected static function text( DOMElement $el, $local, $ns = null ) {
		$child = self::first( $el, $local, $ns );
		return $child ? trim( $child->textContent ) : '';
	}

	/**
	 * Convert an element to an array: attributes as "@name", repeated children as lists,
	 * text-only elements as strings.
	 *
	 * @param DOMElement $el    Element.
	 * @param int        $depth Depth.
	 * @return array|string
	 */
	public static function element_to_array( DOMElement $el, $depth = 0 ) {
		$out   = array();
		$lists = array();
		foreach ( $el->attributes as $attr ) {
			$out[ '@' . $attr->nodeName ] = $attr->value;
		}
		$has_children = false;
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$has_children = true;
			$key          = $child->nodeName;
			$value        = $depth < 8 ? self::element_to_array( $child, $depth + 1 ) : trim( $child->textContent );
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			} else {
				if ( empty( $lists[ $key ] ) ) {
					$out[ $key ]   = array( $out[ $key ] );
					$lists[ $key ] = true;
				}
				$out[ $key ][] = $value;
			}
		}
		if ( ! $has_children ) {
			$text = trim( $el->textContent );
			if ( ! $out ) {
				return $text;
			}
			if ( '' !== $text ) {
				$out['#text'] = $text;
			}
		}
		return $out;
	}

	/**
	 * Normalize an RSS item.
	 *
	 * @param DOMElement $item Item.
	 * @return array
	 */
	public static function rss_record( DOMElement $item ) {
		$raw = self::element_to_array( $item );
		$raw = is_array( $raw ) ? $raw : array();

		$encoded     = self::text( $item, 'encoded', self::NS_CONTENT );
		$description = self::text( $item, 'description', '' );
		$categories  = array();
		foreach ( self::children( $item, 'category', '' ) as $cat ) {
			$categories[] = trim( $cat->textContent );
		}

		$image = '';
		foreach ( self::children( $item, 'enclosure', '' ) as $enc ) {
			$type = (string) $enc->getAttribute( 'type' );
			$url  = (string) $enc->getAttribute( 'url' );
			if ( $url && ( 0 === strpos( $type, 'image/' ) || ( '' === $type && preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $url ) ) ) ) {
				$image = $url;
				break;
			}
		}
		if ( ! $image ) {
			foreach ( array( 'content', 'thumbnail' ) as $tag ) {
				foreach ( self::children( $item, $tag, self::NS_MEDIA ) as $media ) {
					$medium = (string) $media->getAttribute( 'medium' );
					$type   = (string) $media->getAttribute( 'type' );
					if ( $media->getAttribute( 'url' ) && ( 'thumbnail' === $tag || 'image' === $medium || 0 === strpos( $type, 'image/' ) || ( '' === $medium && '' === $type ) ) ) {
						$image = (string) $media->getAttribute( 'url' );
						break 2;
					}
				}
			}
		}

		$link = self::text( $item, 'link', '' );
		unset( $raw['content:encoded'], $raw['description'] );

		return array_merge(
			$raw,
			array(
				'id'         => self::text( $item, 'guid', '' ) ?: $link,
				'title'      => self::text( $item, 'title', '' ),
				'link'       => $link,
				'date'       => self::text( $item, 'pubDate', '' ) ?: self::text( $item, 'date', self::NS_DC ),
				'content'    => '' !== $encoded ? $encoded : $description,
				'excerpt'    => '' !== $encoded ? $description : '',
				'author'     => self::text( $item, 'creator', self::NS_DC ) ?: self::text( $item, 'author', '' ),
				'categories' => array_values( array_filter( $categories, 'strlen' ) ),
				'image'      => $image,
			)
		);
	}

	/**
	 * Normalize an Atom entry.
	 *
	 * @param DOMElement $entry Entry.
	 * @return array
	 */
	public static function atom_record( DOMElement $entry ) {
		$raw = self::element_to_array( $entry );
		$raw = is_array( $raw ) ? $raw : array();

		$link  = '';
		$image = '';
		foreach ( self::children( $entry, 'link', self::NS_ATOM ) as $l ) {
			$rel  = $l->getAttribute( 'rel' ) ?: 'alternate';
			$href = (string) $l->getAttribute( 'href' );
			if ( 'alternate' === $rel && ! $link ) {
				$link = $href;
			} elseif ( 'enclosure' === $rel && 0 === strpos( (string) $l->getAttribute( 'type' ), 'image/' ) && ! $image ) {
				$image = $href;
			}
		}
		if ( ! $image ) {
			$thumb = self::first( $entry, 'thumbnail', self::NS_MEDIA ) ?: self::first( $entry, 'content', self::NS_MEDIA );
			$image = $thumb ? (string) $thumb->getAttribute( 'url' ) : '';
		}

		$categories = array();
		foreach ( self::children( $entry, 'category', self::NS_ATOM ) as $cat ) {
			$categories[] = $cat->getAttribute( 'label' ) ?: $cat->getAttribute( 'term' );
		}

		$author = '';
		$a      = self::first( $entry, 'author', self::NS_ATOM );
		if ( $a ) {
			$author = self::text( $a, 'email', self::NS_ATOM ) ?: self::text( $a, 'name', self::NS_ATOM );
		}

		$summary = self::atom_text( self::first( $entry, 'summary', self::NS_ATOM ) );
		$content = self::atom_text( self::first( $entry, 'content', self::NS_ATOM ) );

		unset( $raw['content'], $raw['summary'] );

		return array_merge(
			$raw,
			array(
				'id'         => self::text( $entry, 'id', self::NS_ATOM ) ?: $link,
				'title'      => self::atom_text( self::first( $entry, 'title', self::NS_ATOM ) ),
				'link'       => $link,
				'date'       => self::text( $entry, 'published', self::NS_ATOM ) ?: self::text( $entry, 'updated', self::NS_ATOM ),
				'updated'    => self::text( $entry, 'updated', self::NS_ATOM ),
				'content'    => '' !== $content ? $content : $summary,
				'excerpt'    => '' !== $content ? $summary : '',
				'author'     => $author,
				'categories' => array_values( array_filter( $categories, 'strlen' ) ),
				'image'      => $image,
			)
		);
	}

	/**
	 * Text of an Atom text construct (type text, html or xhtml).
	 *
	 * @param DOMElement|null $el Element.
	 * @return string
	 */
	protected static function atom_text( $el ) {
		if ( ! $el ) {
			return '';
		}
		if ( 'xhtml' === $el->getAttribute( 'type' ) ) {
			$html = '';
			$wrap = null;
			foreach ( $el->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$wrap = $child; // The required <div> wrapper.
					break;
				}
			}
			foreach ( ( $wrap ? $wrap : $el )->childNodes as $child ) {
				$html .= $el->ownerDocument->saveXML( $child );
			}
			return trim( $html );
		}
		return trim( $el->textContent );
	}

	/**
	 * Records from a WordPress export (WXR) channel.
	 *
	 * @param DOMElement $channel Channel.
	 * @return array[]
	 */
	protected function wxr_records( DOMElement $channel ) {
		$types = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $this->get( 'item_types', 'post' ) ) ) ) );
		$all   = in_array( '*', $types, true );
		if ( ! $types ) {
			$types = array( 'post' );
		}
		$skip = array( 'attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_font_family', 'wp_font_face' );

		$authors = array();
		foreach ( self::children( $channel, 'author', self::NS_WP ) as $a ) {
			$authors[ self::text( $a, 'author_login', self::NS_WP ) ] = array(
				'email' => self::text( $a, 'author_email', self::NS_WP ),
				'name'  => self::text( $a, 'author_display_name', self::NS_WP ),
			);
		}

		$items       = self::children( $channel, 'item' );
		$attachments = array();
		foreach ( $items as $item ) {
			if ( 'attachment' === self::text( $item, 'post_type', self::NS_WP ) ) {
				$attachments[ self::text( $item, 'post_id', self::NS_WP ) ] = self::text( $item, 'attachment_url', self::NS_WP );
			}
		}

		// The default ("post") finds nothing in an export of pages or a custom post type:
		// fall back to the type the file says it holds, then to all content types.
		if ( ! $all && array( 'post' ) === array_values( $types ) ) {
			$present = array();
			foreach ( $items as $item ) {
				$present[ self::text( $item, 'post_type', self::NS_WP ) ] = true;
			}
			if ( empty( $present['post'] ) ) {
				$declared = self::text( $channel, 'post_type', self::NS_PP );
				if ( '' !== $declared && ! empty( $present[ $declared ] ) ) {
					$types = array( $declared );
				} else {
					$all = true;
				}
			}
		}

		$records = array();
		foreach ( $items as $item ) {
			$type = self::text( $item, 'post_type', self::NS_WP );
			if ( $all ? in_array( $type, $skip, true ) : ! in_array( $type, $types, true ) ) {
				continue;
			}

			$meta = array();
			foreach ( self::children( $item, 'postmeta', self::NS_WP ) as $pm ) {
				$key = self::text( $pm, 'meta_key', self::NS_WP );
				if ( '' !== $key && ! in_array( $key, array( '_edit_lock', '_edit_last', '_pingme', '_encloseme' ), true ) && 0 !== strpos( $key, '_wp_old_' ) ) {
					$meta[ $key ] = self::text( $pm, 'meta_value', self::NS_WP );
				}
			}

			$categories = array();
			$tags       = array();
			$terms      = array();
			foreach ( self::children( $item, 'category', '' ) as $cat ) {
				$domain = (string) $cat->getAttribute( 'domain' );
				$name   = trim( $cat->textContent );
				if ( 'category' === $domain ) {
					$categories[] = $name;
				} elseif ( 'post_tag' === $domain ) {
					$tags[] = $name;
				} elseif ( '' !== $domain && 'post_format' !== $domain ) {
					$terms[ $domain ][] = $name;
				}
			}

			$login  = self::text( $item, 'creator', self::NS_DC );
			$author = isset( $authors[ $login ] ) ? $authors[ $login ] : array(
				'email' => '',
				'name'  => '',
			);
			// PressPort exports carry full term paths ("Parent > Child") and portable ACF values.
			$pp_terms = json_decode( self::text( $item, 'terms', self::NS_PP ), true );
			if ( is_array( $pp_terms ) ) {
				$categories = isset( $pp_terms['category'] ) ? (array) $pp_terms['category'] : array();
				$tags       = isset( $pp_terms['post_tag'] ) ? (array) $pp_terms['post_tag'] : array();
				unset( $pp_terms['category'], $pp_terms['post_tag'], $pp_terms['post_format'] );
				$terms = $pp_terms;
			}
			$acf = json_decode( self::text( $item, 'acf', self::NS_PP ), true );
			$woo = json_decode( self::text( $item, 'woo', self::NS_PP ), true );
			$thumb  = isset( $meta['_thumbnail_id'] ) && isset( $attachments[ $meta['_thumbnail_id'] ] ) ? $attachments[ $meta['_thumbnail_id'] ] : self::text( $item, 'featured_image', self::NS_PP );

			$records[] = array(
				'id'             => self::text( $item, 'post_id', self::NS_WP ),
				'title'          => self::text( $item, 'title', '' ),
				'content'        => self::text( $item, 'encoded', self::NS_CONTENT ),
				'excerpt'        => self::text( $item, 'encoded', 'excerpt' ),
				'date'           => self::text( $item, 'post_date', self::NS_WP ),
				'date_gmt'       => self::text( $item, 'post_date_gmt', self::NS_WP ),
				'slug'           => self::text( $item, 'post_name', self::NS_WP ),
				'status'         => self::text( $item, 'status', self::NS_WP ),
				'post_type'      => $type,
				'parent'         => self::text( $item, 'post_parent', self::NS_WP ),
				'menu_order'     => self::text( $item, 'menu_order', self::NS_WP ),
				'comment_status' => self::text( $item, 'comment_status', self::NS_WP ),
				'author'         => $author['email'] ?: ( $author['name'] ?: $login ),
				'author_login'   => $login,
				'author_name'    => $author['name'],
				'link'           => self::text( $item, 'link', '' ),
				'guid'           => self::text( $item, 'guid', '' ),
				'categories'     => $categories,
				'tags'           => $tags,
				'image'          => $thumb,
				'terms'          => $terms,
				'meta'           => $meta,
				'acf'            => is_array( $acf ) ? $acf : array(),
				'woo'            => is_array( $woo ) ? $woo : array(),
				'image_alt'      => self::text( $item, 'featured_image_alt', self::NS_PP ),
			);
		}
		return $records;
	}

	/**
	 * Records from any XML: by element path, or the first repeating element.
	 *
	 * @param DOMElement $root Document element.
	 * @param string     $path Dot path, e.g. "catalog.product" (the root name is optional).
	 * @return array[]|WP_Error
	 */
	protected static function generic_records( DOMElement $root, $path ) {
		if ( '' !== $path ) {
			$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
			if ( $segments && ( $segments[0] === $root->nodeName || $segments[0] === $root->localName ) && count( $segments ) > 1 ) {
				array_shift( $segments );
			}
			$nodes = array( $root );
			foreach ( $segments as $segment ) {
				$next = array();
				foreach ( $nodes as $node ) {
					foreach ( $node->childNodes as $child ) {
						if ( $child instanceof DOMElement && ( $child->nodeName === $segment || $child->localName === $segment ) ) {
							$next[] = $child;
						}
					}
				}
				$nodes = $next;
			}
			if ( ! $nodes ) {
				/* translators: %s: records path */
				return new WP_Error( 'pressport_xml_path', sprintf( __( 'No elements found at path "%s".', 'pressport' ), $path ) );
			}
		} else {
			$nodes = self::find_repeating( $root, 0 );
			if ( ! $nodes ) {
				return new WP_Error( 'pressport_xml_records', __( 'Could not find repeating records in the XML. Set the records path, e.g. "catalog.product".', 'pressport' ) );
			}
		}

		$records = array();
		foreach ( $nodes as $node ) {
			$value     = self::element_to_array( $node );
			$records[] = is_array( $value ) ? $value : array( 'value' => $value );
		}
		return $records;
	}

	/**
	 * Breadth-first search for the first element with 2+ same-named children.
	 *
	 * @param DOMElement $el    Element.
	 * @param int        $depth Depth.
	 * @return DOMElement[]
	 */
	protected static function find_repeating( DOMElement $el, $depth ) {
		$queue = array( $el );
		while ( $queue ) {
			$node   = array_shift( $queue );
			$groups = array();
			foreach ( $node->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$groups[ $child->nodeName ][] = $child;
					$queue[]                      = $child;
				}
			}
			$best = array();
			foreach ( $groups as $group ) {
				if ( count( $group ) > 1 && count( $group ) > count( $best ) ) {
					$best = $group;
				}
			}
			if ( $best ) {
				return $best;
			}
			if ( count( $queue ) > 5000 || ++$depth > 2000 ) {
				break;
			}
		}
		return array();
	}
}
