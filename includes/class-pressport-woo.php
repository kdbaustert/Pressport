<?php
/**
 * WooCommerce product integration.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports and imports WooCommerce products through the WC CRUD API, so the data
 * lands in the same shape WooCommerce stores it (prices, stock, attributes,
 * downloads, variations) rather than as raw protected meta.
 *
 * A product exports under the item's "woo" key. On import the same payload is
 * read back with PressPort_ACF::read() (it reconstructs nested data from the
 * flattened record) and applied to a WC_Product. Images and files travel as
 * URLs; related products (up-sells, cross-sells, grouped children) travel as
 * SKUs; attribute options travel as term names.
 */
class PressPort_Woo {

	/**
	 * Core product fields read and written through WC getters/setters.
	 *
	 * field key => WC getter suffix (set_<suffix> / get_<suffix>).
	 *
	 * @var string[]
	 */
	const SCALAR_FIELDS = array(
		'sku', 'regular_price', 'sale_price', 'tax_status', 'tax_class',
		'stock_status', 'backorders', 'weight', 'length', 'width', 'height',
		'catalog_visibility', 'purchase_note',
	);

	/**
	 * Boolean product fields.
	 *
	 * @var string[]
	 */
	const BOOL_FIELDS = array( 'manage_stock', 'sold_individually', 'virtual', 'downloadable', 'featured' );

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Attribute' );
	}

	/**
	 * Whether a post type is the WooCommerce product type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_product_type( $post_type ) {
		return 'product' === $post_type;
	}

	// Export.

	/**
	 * Add the WooCommerce payload to an export item, and remove the taxonomies
	 * WooCommerce manages from the generic term list (product type, visibility,
	 * shipping class and attribute taxonomies).
	 *
	 * @param WP_Post $post With-images flag comes from the export args.
	 * @param array   $item Export item (by reference).
	 * @param array   $args Export args.
	 */
	public static function export_product( WP_Post $post, array &$item, array $args ) {
		if ( ! self::active() || 'product' !== $post->post_type ) {
			return;
		}
		$product = wc_get_product( $post );
		if ( ! $product ) {
			return;
		}

		$with_images   = ! empty( $args['include_image'] );
		$item['woo']   = self::product_data( $product, $with_images );

		if ( $product->is_type( 'variable' ) ) {
			$variations = array();
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$variations[] = self::variation_data( $variation, $with_images );
				}
			}
			$item['woo']['variations'] = $variations;
		}

		foreach ( array_keys( (array) $item['terms'] ) as $tax ) {
			if ( in_array( $tax, array( 'product_type', 'product_visibility', 'product_shipping_class' ), true ) || 0 === strpos( $tax, 'pa_' ) ) {
				unset( $item['terms'][ $tax ] );
			}
		}
	}

	/**
	 * Portable data for one product.
	 *
	 * @param WC_Product $product     Product.
	 * @param bool       $with_images Include image URLs.
	 * @return array
	 */
	protected static function product_data( WC_Product $product, $with_images ) {
		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();
		$data = array(
			'type'               => $product->get_type(),
			'sku'                => $product->get_sku(),
			'regular_price'      => $product->get_regular_price(),
			'sale_price'         => $product->get_sale_price(),
			'sale_from'          => $from ? $from->date( 'Y-m-d H:i:s' ) : '',
			'sale_to'            => $to ? $to->date( 'Y-m-d H:i:s' ) : '',
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'manage_stock'       => $product->get_manage_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'backorders'         => $product->get_backorders(),
			'sold_individually'  => $product->get_sold_individually(),
			'weight'             => $product->get_weight(),
			'length'             => $product->get_length(),
			'width'              => $product->get_width(),
			'height'             => $product->get_height(),
			'shipping_class'     => $product->get_shipping_class(),
			'virtual'            => $product->get_virtual(),
			'downloadable'       => $product->get_downloadable(),
			'featured'           => $product->get_featured(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'purchase_note'      => $product->get_purchase_note(),
			'attributes'         => self::export_attributes( $product ),
			'upsell_skus'        => self::skus( $product->get_upsell_ids() ),
			'cross_sell_skus'    => self::skus( $product->get_cross_sell_ids() ),
		);

		if ( method_exists( $product, 'get_low_stock_amount' ) ) {
			$data['low_stock_amount'] = $product->get_low_stock_amount();
		}

		if ( $product->get_downloadable() ) {
			$downloads = array();
			foreach ( $product->get_downloads() as $download ) {
				$downloads[] = array(
					'name' => $download->get_name(),
					'file' => $download->get_file(),
				);
			}
			$data['downloads']       = $downloads;
			$data['download_limit']  = $product->get_download_limit();
			$data['download_expiry'] = $product->get_download_expiry();
		}

		if ( $with_images ) {
			$gallery = array();
			foreach ( $product->get_gallery_image_ids() as $image_id ) {
				$url = wp_get_attachment_url( $image_id );
				if ( $url ) {
					$gallery[] = $url;
				}
			}
			$data['gallery'] = $gallery;
		}

		if ( $product->is_type( 'external' ) ) {
			$data['product_url'] = $product->get_product_url();
			$data['button_text'] = $product->get_button_text();
		}
		if ( $product->is_type( 'grouped' ) ) {
			$data['grouped_skus'] = self::skus( $product->get_children() );
		}
		if ( $product->is_type( 'variable' ) ) {
			$data['default_attributes'] = self::export_default_attributes( $product );
		}

		return $data;
	}

	/**
	 * Portable data for one variation.
	 *
	 * @param WC_Product_Variation $variation   Variation.
	 * @param bool                 $with_images Include image URL.
	 * @return array
	 */
	protected static function variation_data( WC_Product_Variation $variation, $with_images ) {
		$from = $variation->get_date_on_sale_from();
		$to   = $variation->get_date_on_sale_to();
		$data = array(
			'sku'             => $variation->get_sku(),
			'regular_price'   => $variation->get_regular_price(),
			'sale_price'      => $variation->get_sale_price(),
			'sale_from'       => $from ? $from->date( 'Y-m-d H:i:s' ) : '',
			'sale_to'         => $to ? $to->date( 'Y-m-d H:i:s' ) : '',
			'manage_stock'    => $variation->get_manage_stock(),
			'stock_quantity'  => $variation->get_stock_quantity(),
			'stock_status'    => $variation->get_stock_status(),
			'backorders'      => $variation->get_backorders(),
			'weight'          => $variation->get_weight(),
			'length'          => $variation->get_length(),
			'width'           => $variation->get_width(),
			'height'          => $variation->get_height(),
			'tax_class'       => $variation->get_tax_class(),
			'virtual'         => $variation->get_virtual(),
			'downloadable'    => $variation->get_downloadable(),
			'description'     => $variation->get_description(),
			'enabled'         => 'publish' === $variation->get_status(),
			'menu_order'      => $variation->get_menu_order(),
			'attributes'      => self::export_variation_attributes( $variation ),
		);
		if ( $with_images ) {
			$url           = wp_get_attachment_url( $variation->get_image_id() );
			$data['image'] = $url ? $url : '';
		}
		return $data;
	}

	/**
	 * Attributes as portable rows: taxonomy attributes carry their term names,
	 * custom attributes carry their string options.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	protected static function export_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$row = array(
				'name'      => '',
				'taxonomy'  => '',
				'options'   => array(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
				'position'  => $attribute->get_position(),
			);
			if ( $attribute->is_taxonomy() ) {
				$taxonomy        = $attribute->get_name();
				$row['taxonomy'] = $taxonomy;
				$row['name']     = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
				foreach ( $attribute->get_options() as $term_id ) {
					$term = get_term( (int) $term_id, $taxonomy );
					if ( $term instanceof WP_Term ) {
						$row['options'][] = $term->name;
					}
				}
			} else {
				$row['name']    = $attribute->get_name();
				$row['options'] = array_values( array_map( 'strval', $attribute->get_options() ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * A variation's chosen attribute values, keyed by taxonomy / attribute name.
	 * Taxonomy values are stored as slugs, so they are converted to term names.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	protected static function export_variation_attributes( WC_Product_Variation $variation ) {
		$out = array();
		foreach ( $variation->get_variation_attributes( false ) as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			if ( taxonomy_exists( $name ) ) {
				$term          = get_term_by( 'slug', $value, $name );
				$out[ $name ]  = $term instanceof WP_Term ? $term->name : $value;
			} else {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Default variation attributes (variable products), slugs converted to names.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	protected static function export_default_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_default_attributes() as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			if ( taxonomy_exists( $name ) ) {
				$term         = get_term_by( 'slug', $value, $name );
				$out[ $name ] = $term instanceof WP_Term ? $term->name : $value;
			} else {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * SKUs for a list of product ids (missing SKUs are skipped).
	 *
	 * @param int[] $ids Product ids.
	 * @return string[]
	 */
	protected static function skus( $ids ) {
		$skus = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && '' !== $product->get_sku() ) {
				$skus[] = $product->get_sku();
			}
		}
		return $skus;
	}

	// Import.

	/**
	 * Apply an exported WooCommerce payload to a post.
	 *
	 * @param int           $post_id  Post id.
	 * @param array         $record   Flat record.
	 * @param PressPort_Job $job      Job.
	 * @param array         $opts     Job options.
	 * @param string        $base     Base URL for relative media.
	 * @param string[]      $warnings Warnings (by reference).
	 */
	public static function import( $post_id, array $record, PressPort_Job $job, array $opts, $base, array &$warnings ) {
		$woo = self::read_payload( $record );
		if ( null === $woo ) {
			return;
		}
		if ( ! self::active() ) {
			$warnings[] = __( 'WooCommerce product data was skipped because WooCommerce is not active on this site.', 'pressport' );
			return;
		}

		$type    = isset( $woo['type'] ) ? sanitize_key( $woo['type'] ) : 'simple';
		$product = self::product_for( (int) $post_id, $type );
		if ( ! $product ) {
			$warnings[] = __( 'Could not build the WooCommerce product.', 'pressport' );
			return;
		}

		$ctx = array(
			'post_id'      => (int) $post_id,
			'base'         => (string) $base,
			'job_id'       => $job->data['id'],
			'create_terms' => ! empty( $opts['create_terms'] ),
			'images'       => ! empty( $opts['import_featured_image'] ),
		);

		self::apply_scalars( $product, $woo, $warnings );
		self::apply_stock( $product, $woo );
		self::apply_attributes( $product, isset( $woo['attributes'] ) ? (array) $woo['attributes'] : array(), $ctx );
		self::apply_shipping_class( $product, $woo, $ctx );
		self::apply_downloads( $product, $woo );
		self::apply_relations( $product, $woo );
		self::apply_type_extras( $product, $woo );

		if ( $ctx['images'] && ! empty( $woo['gallery'] ) ) {
			$product->set_gallery_image_ids( self::sideload_all( (array) $woo['gallery'], $ctx, $warnings ) );
		}

		try {
			$product->save();
		} catch ( Exception $e ) {
			$warnings[] = __( 'WooCommerce: ', 'pressport' ) . $e->getMessage();
			return;
		}

		if ( 'variable' === $type && ! empty( $woo['variations'] ) ) {
			self::import_variations( $product, (array) $woo['variations'], isset( $woo['default_attributes'] ) ? (array) $woo['default_attributes'] : array(), $ctx, $warnings );
		}
	}

	/**
	 * Read the "woo" payload from a record, decoding a JSON string (CSV) if needed.
	 *
	 * @param array $record Flat record.
	 * @return array|null
	 */
	protected static function read_payload( array $record ) {
		$raw = PressPort_ACF::read( $record, 'woo' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}
		return is_array( $raw ) && $raw ? $raw : null;
	}

	/**
	 * A WC_Product of the right subclass wrapping an existing post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $type    Product type.
	 * @return WC_Product|null
	 */
	protected static function product_for( $post_id, $type ) {
		$classes = array(
			'simple'   => 'WC_Product_Simple',
			'variable' => 'WC_Product_Variable',
			'grouped'  => 'WC_Product_Grouped',
			'external' => 'WC_Product_External',
		);
		$class = isset( $classes[ $type ] ) && class_exists( $classes[ $type ] ) ? $classes[ $type ] : 'WC_Product_Simple';
		try {
			return new $class( $post_id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Set the scalar and boolean fields through their WC setters.
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $woo      Payload.
	 * @param string[]   $warnings Warnings (by reference).
	 */
	protected static function apply_scalars( WC_Product $product, array $woo, array &$warnings ) {
		foreach ( self::SCALAR_FIELDS as $field ) {
			if ( array_key_exists( $field, $woo ) && '' !== (string) $woo[ $field ] ) {
				self::set( $product, 'set_' . $field, (string) $woo[ $field ], $warnings );
			}
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			if ( array_key_exists( $field, $woo ) ) {
				self::set( $product, 'set_' . $field, self::truthy( $woo[ $field ] ), $warnings );
			}
		}
		foreach ( array( 'sale_from' => 'set_date_on_sale_from', 'sale_to' => 'set_date_on_sale_to', 'purchase_note' => 'set_purchase_note' ) as $field => $method ) {
			if ( ! empty( $woo[ $field ] ) ) {
				self::set( $product, $method, (string) $woo[ $field ], $warnings );
			}
		}
	}

	/**
	 * Stock management (quantity only applies when stock is managed).
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 */
	protected static function apply_stock( WC_Product $product, array $woo ) {
		if ( ! empty( $woo['manage_stock'] ) && self::truthy( $woo['manage_stock'] ) && isset( $woo['stock_quantity'] ) && is_numeric( $woo['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $woo['stock_quantity'] );
		}
		if ( isset( $woo['low_stock_amount'] ) && is_numeric( $woo['low_stock_amount'] ) && method_exists( $product, 'set_low_stock_amount' ) ) {
			$product->set_low_stock_amount( (int) $woo['low_stock_amount'] );
		}
	}

	/**
	 * Build and set product attributes. Taxonomy attributes resolve (and, with
	 * "create missing terms", create) their global attribute and terms; when that
	 * is not possible they fall back to a custom attribute so no data is lost.
	 *
	 * @param WC_Product $product    Product.
	 * @param array[]    $attributes Attribute rows.
	 * @param array      $ctx        Context.
	 */
	protected static function apply_attributes( WC_Product $product, array $attributes, array $ctx ) {
		$objects  = array();
		$position = 0;
		foreach ( $attributes as $attr ) {
			$name     = isset( $attr['name'] ) ? (string) $attr['name'] : '';
			$taxonomy = isset( $attr['taxonomy'] ) ? (string) $attr['taxonomy'] : '';
			$options  = isset( $attr['options'] ) ? (array) $attr['options'] : array();
			if ( '' === $name && ! $options ) {
				continue;
			}

			$object = new WC_Product_Attribute();
			$object->set_visible( self::truthy( isset( $attr['visible'] ) ? $attr['visible'] : true ) );
			$object->set_variation( self::truthy( isset( $attr['variation'] ) ? $attr['variation'] : false ) );
			$object->set_position( isset( $attr['position'] ) ? (int) $attr['position'] : $position );

			$resolved_tax = '' !== $taxonomy ? self::ensure_attribute_taxonomy( $taxonomy, $name, $ctx['create_terms'] ) : '';
			if ( '' !== $resolved_tax ) {
				$term_ids = array();
				foreach ( $options as $option ) {
					$term_id = self::ensure_term( $resolved_tax, (string) $option, $ctx['create_terms'] );
					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				if ( ! $term_ids ) {
					continue;
				}
				$object->set_id( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $resolved_tax ) : 0 );
				$object->set_name( $resolved_tax );
				$object->set_options( $term_ids );
			} else {
				// Custom attribute (or a taxonomy we could not create): keep the names.
				$object->set_id( 0 );
				$object->set_name( '' !== $name ? $name : $taxonomy );
				$object->set_options( array_values( array_map( 'strval', $options ) ) );
			}
			$objects[] = $object;
			++$position;
		}
		$product->set_attributes( $objects );
	}

	/**
	 * Resolve the shipping class slug to a term and set it.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 * @param array      $ctx     Context.
	 */
	protected static function apply_shipping_class( WC_Product $product, array $woo, array $ctx ) {
		if ( empty( $woo['shipping_class'] ) || ! taxonomy_exists( 'product_shipping_class' ) ) {
			return;
		}
		$term_id = self::ensure_term( 'product_shipping_class', (string) $woo['shipping_class'], $ctx['create_terms'] );
		if ( $term_id ) {
			$product->set_shipping_class_id( $term_id );
		}
	}

	/**
	 * Downloadable files.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 */
	protected static function apply_downloads( WC_Product $product, array $woo ) {
		if ( empty( $woo['downloads'] ) || ! class_exists( 'WC_Product_Download' ) ) {
			return;
		}
		$downloads = array();
		foreach ( (array) $woo['downloads'] as $download ) {
			$file = isset( $download['file'] ) ? (string) $download['file'] : '';
			if ( '' === $file ) {
				continue;
			}
			$object = new WC_Product_Download();
			$object->set_name( isset( $download['name'] ) ? (string) $download['name'] : wp_basename( $file ) );
			$object->set_file( $file );
			$downloads[] = $object;
		}
		$product->set_downloads( $downloads );
		if ( isset( $woo['download_limit'] ) && is_numeric( $woo['download_limit'] ) ) {
			$product->set_download_limit( (int) $woo['download_limit'] );
		}
		if ( isset( $woo['download_expiry'] ) && is_numeric( $woo['download_expiry'] ) ) {
			$product->set_download_expiry( (int) $woo['download_expiry'] );
		}
	}

	/**
	 * Up-sells and cross-sells, matched to local products by SKU.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 */
	protected static function apply_relations( WC_Product $product, array $woo ) {
		foreach ( array( 'upsell_skus' => 'set_upsell_ids', 'cross_sell_skus' => 'set_cross_sell_ids' ) as $field => $method ) {
			if ( empty( $woo[ $field ] ) ) {
				continue;
			}
			$ids = self::ids_for_skus( (array) $woo[ $field ] );
			if ( $ids ) {
				$product->$method( $ids );
			}
		}
	}

	/**
	 * External and grouped product extras.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 */
	protected static function apply_type_extras( WC_Product $product, array $woo ) {
		if ( $product->is_type( 'external' ) ) {
			if ( ! empty( $woo['product_url'] ) ) {
				$product->set_product_url( esc_url_raw( (string) $woo['product_url'] ) );
			}
			if ( ! empty( $woo['button_text'] ) ) {
				$product->set_button_text( (string) $woo['button_text'] );
			}
		}
		if ( $product->is_type( 'grouped' ) && ! empty( $woo['grouped_skus'] ) ) {
			$ids = self::ids_for_skus( (array) $woo['grouped_skus'] );
			if ( $ids ) {
				$product->set_children( $ids );
			}
		}
	}

	/**
	 * Create or update each variation of a variable product.
	 *
	 * @param WC_Product $parent     Parent product.
	 * @param array[]    $variations Variation payloads.
	 * @param array      $defaults   Default attributes (name => value).
	 * @param array      $ctx        Context.
	 * @param string[]   $warnings   Warnings (by reference).
	 */
	protected static function import_variations( WC_Product $parent, array $variations, array $defaults, array $ctx, array &$warnings ) {
		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			return;
		}

		// Index existing children so a re-import updates rather than duplicates.
		$by_sku = array();
		$by_sig = array();
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof WC_Product_Variation ) {
				continue;
			}
			if ( '' !== $child->get_sku() ) {
				$by_sku[ $child->get_sku() ] = $child_id;
			}
			$by_sig[ self::variation_signature( $child->get_attributes() ) ] = $child_id;
		}

		foreach ( $variations as $data ) {
			$resolved = self::resolve_variation_attributes( isset( $data['attributes'] ) ? (array) $data['attributes'] : array(), $ctx );
			$sku      = isset( $data['sku'] ) ? (string) $data['sku'] : '';

			$existing = 0;
			if ( '' !== $sku && isset( $by_sku[ $sku ] ) ) {
				$existing = $by_sku[ $sku ];
			} else {
				$sig = self::variation_signature( $resolved );
				if ( isset( $by_sig[ $sig ] ) ) {
					$existing = $by_sig[ $sig ];
				}
			}

			try {
				$variation = $existing ? new WC_Product_Variation( $existing ) : new WC_Product_Variation();
				$variation->set_parent_id( $parent->get_id() );
				$variation->set_attributes( $resolved );
				$variation->set_status( isset( $data['enabled'] ) && ! self::truthy( $data['enabled'] ) ? 'private' : 'publish' );

				foreach ( array( 'sku', 'regular_price', 'sale_price', 'tax_class', 'weight', 'length', 'width', 'height', 'stock_status', 'backorders', 'description' ) as $field ) {
					if ( array_key_exists( $field, $data ) && '' !== (string) $data[ $field ] ) {
						self::set( $variation, 'set_' . $field, (string) $data[ $field ], $warnings );
					}
				}
				foreach ( array( 'manage_stock', 'virtual', 'downloadable' ) as $field ) {
					if ( array_key_exists( $field, $data ) ) {
						self::set( $variation, 'set_' . $field, self::truthy( $data[ $field ] ), $warnings );
					}
				}
				if ( ! empty( $data['manage_stock'] ) && self::truthy( $data['manage_stock'] ) && isset( $data['stock_quantity'] ) && is_numeric( $data['stock_quantity'] ) ) {
					$variation->set_stock_quantity( (int) $data['stock_quantity'] );
				}
				if ( isset( $data['menu_order'] ) && is_numeric( $data['menu_order'] ) ) {
					$variation->set_menu_order( (int) $data['menu_order'] );
				}
				foreach ( array( 'sale_from' => 'set_date_on_sale_from', 'sale_to' => 'set_date_on_sale_to' ) as $field => $method ) {
					if ( ! empty( $data[ $field ] ) ) {
						self::set( $variation, $method, (string) $data[ $field ], $warnings );
					}
				}
				if ( $ctx['images'] && ! empty( $data['image'] ) ) {
					$image_id = self::sideload( (string) $data['image'], $ctx, $warnings );
					if ( $image_id ) {
						$variation->set_image_id( $image_id );
					}
				}
				$variation->save();
			} catch ( Exception $e ) {
				$warnings[] = __( 'WooCommerce variation: ', 'pressport' ) . $e->getMessage();
			}
		}

		if ( $defaults ) {
			$parent->set_default_attributes( self::resolve_variation_attributes( $defaults, $ctx ) );
			$parent->save();
		}
	}

	/**
	 * Turn exported variation attribute values (term names or custom strings) into
	 * the slug => value map WC_Product_Variation::set_attributes() expects.
	 *
	 * @param array $attributes name => value.
	 * @param array $ctx        Context.
	 * @return array
	 */
	protected static function resolve_variation_attributes( array $attributes, array $ctx ) {
		$out = array();
		foreach ( $attributes as $name => $value ) {
			$value    = (string) $value;
			$taxonomy = taxonomy_exists( $name ) ? $name : ( taxonomy_exists( 'pa_' . $name ) ? 'pa_' . $name : '' );
			if ( '' !== $taxonomy ) {
				$term_id = self::ensure_term( $taxonomy, $value, $ctx['create_terms'] );
				$term    = $term_id ? get_term( $term_id, $taxonomy ) : null;
				if ( $term instanceof WP_Term ) {
					$out[ $taxonomy ] = $term->slug;
					continue;
				}
				$out[ $taxonomy ] = sanitize_title( $value );
			} else {
				$out[ sanitize_title( $name ) ] = $value;
			}
		}
		return $out;
	}

	/**
	 * A stable signature for a variation's attribute map, for matching on re-import.
	 *
	 * @param array $attributes name => value.
	 * @return string
	 */
	protected static function variation_signature( array $attributes ) {
		$pairs = array();
		foreach ( $attributes as $key => $value ) {
			$pairs[] = strtolower( (string) $key ) . '=' . strtolower( (string) $value );
		}
		sort( $pairs );
		return implode( '|', $pairs );
	}

	/**
	 * Ensure a global attribute taxonomy exists; create it when allowed.
	 *
	 * @param string $taxonomy Taxonomy, e.g. "pa_color".
	 * @param string $label    Human label.
	 * @param bool   $create   Whether to create it when missing.
	 * @return string The taxonomy name, or '' when unavailable.
	 */
	protected static function ensure_attribute_taxonomy( $taxonomy, $label, $create ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}
		if ( ! $create || ! function_exists( 'wc_create_attribute' ) || 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return '';
		}
		$slug   = substr( $taxonomy, 3 );
		$result = wc_create_attribute(
			array(
				'name' => '' !== $label ? $label : $slug,
				'slug' => $slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			return '';
		}
		// Register it now so terms can be added within this request.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
				apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, array( 'hierarchical' => true, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) )
			);
		}
		return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
	}

	/**
	 * Resolve a term by name (then slug) in a taxonomy, creating it when allowed.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Term name.
	 * @param bool   $create   Whether to create it when missing.
	 * @return int Term id, or 0.
	 */
	protected static function ensure_term( $taxonomy, $name, $create ) {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		}
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}
		if ( ! $create ) {
			return 0;
		}
		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) {
			$existing = $created->get_error_data( 'term_exists' );
			return $existing ? (int) $existing : 0;
		}
		return (int) $created['term_id'];
	}

	/**
	 * Local product ids for a list of SKUs.
	 *
	 * @param string[] $skus SKUs.
	 * @return int[]
	 */
	protected static function ids_for_skus( array $skus ) {
		$ids = array();
		foreach ( $skus as $sku ) {
			$sku = (string) $sku;
			$id  = '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sideload a list of image URLs into the media library.
	 *
	 * @param string[] $urls     URLs.
	 * @param array    $ctx      Context.
	 * @param string[] $warnings Warnings (by reference).
	 * @return int[]
	 */
	protected static function sideload_all( array $urls, array $ctx, array &$warnings ) {
		$ids = array();
		foreach ( $urls as $url ) {
			$id = self::sideload( (string) $url, $ctx, $warnings );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sideload one image URL, collecting any error as a warning.
	 *
	 * @param string   $url      URL.
	 * @param array    $ctx      Context.
	 * @param string[] $warnings Warnings (by reference).
	 * @return int Attachment id, or 0.
	 */
	protected static function sideload( $url, array $ctx, array &$warnings ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}
		$id = PressPort_Media::sideload( $url, $ctx['post_id'], '', $ctx['base'], $ctx['job_id'] );
		if ( is_wp_error( $id ) ) {
			$warnings[] = __( 'WooCommerce image: ', 'pressport' ) . $id->get_error_message();
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Call a WC setter, turning a WC_Data_Exception into a warning.
	 *
	 * @param object   $object   Product or variation.
	 * @param string   $method   Setter.
	 * @param mixed    $value    Value.
	 * @param string[] $warnings Warnings (by reference).
	 */
	protected static function set( $object, $method, $value, array &$warnings ) {
		if ( ! method_exists( $object, $method ) ) {
			return;
		}
		try {
			$object->$method( $value );
		} catch ( Exception $e ) {
			$warnings[] = __( 'WooCommerce: ', 'pressport' ) . $e->getMessage();
		}
	}

	/**
	 * Interpret a stored value as a boolean (flattened payloads use "1"/"0").
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
