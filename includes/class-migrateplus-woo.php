<?php
/**
 * WooCommerce product integration.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports and imports WooCommerce products through the WC CRUD API, so the data
 * lands in the same shape WooCommerce stores it (prices, stock, attributes,
 * downloads, variations) rather than as raw protected meta.
 *
 * A product exports under the item's "woo" key. On import the same payload is
 * read back with MigratePlus_ACF::read() (it reconstructs nested data from the
 * flattened record) and applied to a WC_Product. Images and files travel as
 * URLs; related products (up-sells, cross-sells, grouped children) travel as
 * SKUs and source ids; attribute options travel as term names.
 */
class MigratePlus_Woo {

	/**
	 * Meta key prefix for a related product that was not on this site yet. The
	 * rest of the key is md5( "uid:<source uid>" ) or md5( "sku:<sku>" ) of the
	 * missing product; the value is the relation (upsell, cross_sell, grouped).
	 * When a product with that uid or SKU is imported, link_pending() adds it to
	 * the waiting product and deletes the meta.
	 *
	 * @var string
	 */
	const PENDING_META = '_migrateplus_pending_';

	/**
	 * Core product fields read and written through WC getters/setters.
	 *
	 * field key => WC getter suffix (set_<suffix> / get_<suffix>).
	 *
	 * @var string[]
	 */
	const SCALAR_FIELDS = array(
		'sku',
		'regular_price',
		'sale_price',
		'tax_status',
		'tax_class',
		'stock_status',
		'backorders',
		'weight',
		'length',
		'width',
		'height',
		'catalog_visibility',
		'purchase_note',
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

		$with_images = ! empty( $args['include_image'] );
		$item['woo'] = self::product_data( $product, $with_images );

		if ( $product instanceof WC_Product_Variable ) {
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
			'upsell_refs'        => self::refs( $product->get_upsell_ids() ),
			'cross_sell_refs'    => self::refs( $product->get_cross_sell_ids() ),
		);

		if ( method_exists( $product, 'get_low_stock_amount' ) ) {
			$data['low_stock_amount'] = $product->get_low_stock_amount();
		}

		if ( $product->get_downloadable() ) {
			$data = array_merge( $data, self::downloads_data( $product ) );
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
			$data['grouped_refs'] = self::refs( $product->get_children() );
		}
		if ( $product instanceof WC_Product_Variable ) {
			$data['default_attributes'] = self::export_attribute_values( $product->get_default_attributes() );
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
			// 'edit': in view context a variation without its own SKU reports the parent's.
			'sku'            => $variation->get_sku( 'edit' ),
			'regular_price'  => $variation->get_regular_price(),
			'sale_price'     => $variation->get_sale_price(),
			'sale_from'      => $from ? $from->date( 'Y-m-d H:i:s' ) : '',
			'sale_to'        => $to ? $to->date( 'Y-m-d H:i:s' ) : '',
			'manage_stock'   => $variation->get_manage_stock(),
			'stock_quantity' => $variation->get_stock_quantity(),
			'stock_status'   => $variation->get_stock_status(),
			'backorders'     => $variation->get_backorders(),
			'weight'         => $variation->get_weight(),
			'length'         => $variation->get_length(),
			'width'          => $variation->get_width(),
			'height'         => $variation->get_height(),
			'tax_class'      => $variation->get_tax_class(),
			'virtual'        => $variation->get_virtual(),
			'downloadable'   => $variation->get_downloadable(),
			'description'    => $variation->get_description(),
			'enabled'        => 'publish' === $variation->get_status(),
			'menu_order'     => $variation->get_menu_order(),
			'attributes'     => self::export_attribute_values( $variation->get_variation_attributes( false ) ),
		);
		// The variation's own class: "view" would return the parent's when it has none.
		$class_id               = $variation->get_shipping_class_id( 'edit' );
		$class                  = $class_id ? get_term( $class_id, 'product_shipping_class' ) : null;
		$data['shipping_class'] = $class instanceof WP_Term ? $class->slug : '';
		if ( method_exists( $variation, 'get_low_stock_amount' ) ) {
			$data['low_stock_amount'] = $variation->get_low_stock_amount();
		}
		if ( $variation->get_downloadable() ) {
			$data = array_merge( $data, self::downloads_data( $variation ) );
		}
		if ( $with_images ) {
			$url           = wp_get_attachment_url( $variation->get_image_id() );
			$data['image'] = $url ? $url : '';
		}
		return $data;
	}

	/**
	 * Downloadable files, download limit and expiry of a product or variation.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array
	 */
	protected static function downloads_data( WC_Product $product ) {
		$downloads = array();
		foreach ( $product->get_downloads() as $download ) {
			$row   = array(
				'name' => $download->get_name(),
				'file' => $download->get_file(),
			);
			$fetch = self::signed_file_url( $row['file'] );
			if ( '' !== $fetch ) {
				$row['fetch'] = $fetch;
			}
			$downloads[] = $row;
		}
		return array(
			'downloads'       => $downloads,
			'download_limit'  => $product->get_download_limit(),
			'download_expiry' => $product->get_download_expiry(),
		);
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
	 * Attribute values keyed by taxonomy / attribute name: a variation's chosen
	 * values, or a variable product's defaults. Taxonomy values are stored as
	 * slugs, so they are converted to term names.
	 *
	 * @param array $values name => value.
	 * @return array
	 */
	protected static function export_attribute_values( array $values ) {
		$out = array();
		foreach ( $values as $name => $value ) {
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

	/**
	 * Source id and SKU of each related product, so a product without a SKU, or
	 * one imported later in the file, can still be matched on import.
	 *
	 * @param int[] $ids Product ids.
	 * @return array[]
	 */
	protected static function refs( $ids ) {
		$refs = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$refs[] = array(
					'id'  => (int) $id,
					'sku' => $product->get_sku(),
				);
			}
		}
		return $refs;
	}

	// Signed file links.

	/**
	 * A link the importing site can fetch a downloadable file through. Files sold
	 * as downloads usually sit in woocommerce_uploads, which denies direct
	 * requests, so the export carries a link that serves this one file until it
	 * expires. The signature covers the file and the expiry, so the link can't be
	 * reused for another file or kept alive.
	 *
	 * @param string $file File URL as WooCommerce stores it.
	 * @return string Link, or '' when the file is not on this site.
	 */
	protected static function signed_file_url( $file ) {
		if ( '' === self::local_file_path( $file ) ) {
			return '';
		}
		$expires = time() + DAY_IN_SECONDS;
		return add_query_arg(
			array(
				'action'  => 'migrateplus_file',
				'file'    => rawurlencode( $file ),
				'expires' => $expires,
				'sig'     => self::file_signature( $file, $expires ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * @param string $file    File URL.
	 * @param int    $expires Unix time.
	 * @return string
	 */
	protected static function file_signature( $file, $expires ) {
		return hash_hmac( 'sha256', $file . '|' . (int) $expires, wp_salt( 'auth' ) );
	}

	/**
	 * The readable file on this site behind a download URL, resolved the way
	 * WooCommerce resolves it when it serves the download.
	 *
	 * @param string $file File URL or path.
	 * @return string Absolute path, or ''.
	 */
	protected static function local_file_path( $file ) {
		if ( '' === (string) $file || ! class_exists( 'WC_Download_Handler' ) ) {
			return '';
		}
		$parsed = WC_Download_Handler::parse_file_path( $file );
		if ( ! empty( $parsed['remote_file'] ) ) {
			return '';
		}
		$path = realpath( (string) $parsed['file_path'] );
		$root = realpath( ABSPATH );
		$wpc  = realpath( WP_CONTENT_DIR );
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		// Only files inside this WordPress install, whatever the URL resolves to.
		foreach ( array( $root, $wpc ) as $base ) {
			if ( $base && 0 === strpos( $path, trailingslashit( $base ) ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Serve a file through a signed link (admin-ajax, no login needed: the
	 * importing site fetches it).
	 */
	public static function serve_file() {
		// phpcs:disable WordPress.Security.NonceVerification -- the signature is the check.
		$file    = isset( $_GET['file'] ) ? (string) wp_unslash( $_GET['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$expires = isset( $_GET['expires'] ) ? (int) $_GET['expires'] : 0;
		$sig     = isset( $_GET['sig'] ) ? (string) wp_unslash( $_GET['sig'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable

		if ( $expires < time() || ! hash_equals( self::file_signature( $file, $expires ), $sig ) ) {
			wp_die( esc_html__( 'This file link has expired or is not valid. Export again for a new one.', 'migrateplus' ), '', array( 'response' => 403 ) );
		}
		$path = self::local_file_path( $file );
		if ( '' === $path ) {
			wp_die( esc_html__( 'File not found.', 'migrateplus' ), '', array( 'response' => 404 ) );
		}
		$type = wp_check_filetype( $path );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( wp_basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// Import.

	/**
	 * Apply an exported WooCommerce payload to a post.
	 *
	 * @param int             $post_id  Post id.
	 * @param array           $record   Flat record.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param string          $base     Base URL for relative media.
	 * @param string[]        $warnings Warnings (by reference).
	 */
	public static function import( $post_id, array $record, MigratePlus_Job $job, array $opts, $base, array &$warnings ) {
		$woo = self::read_payload( $record );
		if ( null === $woo ) {
			return;
		}
		if ( ! self::active() ) {
			$warnings[] = __( 'WooCommerce product data was skipped because WooCommerce is not active on this site.', 'migrateplus' );
			return;
		}

		$type    = isset( $woo['type'] ) ? sanitize_key( $woo['type'] ) : 'simple';
		$product = self::product_for( (int) $post_id, $type, $warnings );
		if ( ! $product ) {
			$warnings[] = __( 'Could not build the WooCommerce product.', 'migrateplus' );
			return;
		}

		$ctx = array(
			'post_id'      => (int) $post_id,
			'base'         => (string) $base,
			'job_id'       => $job->data['id'],
			'create_terms' => ! empty( $opts['create_terms'] ),
			'images'       => ! empty( $opts['import_featured_image'] ),
			// Related products are matched by MigratePlus_Importer::uid( job, source id ).
			'job'          => $job,
			// Attribute taxonomy => custom attribute key, for attributes whose taxonomy is missing here.
			'custom_keys'  => array(),
		);

		// The post already exists, so a WooCommerce validation error must not fail the
		// whole record: report it and keep whatever else was set.
		try {
			self::apply_scalars( $product, $woo, $warnings );
			self::apply_stock( $product, $woo );
			$ctx['custom_keys'] = self::apply_attributes( $product, isset( $woo['attributes'] ) ? (array) $woo['attributes'] : array(), $ctx, $warnings );
			self::apply_shipping_class( $product, $woo, $ctx );
			self::apply_downloads( $product, $woo, $ctx, $warnings );
			self::apply_relations( $product, $woo, $ctx, $warnings );
			self::apply_type_extras( $product, $woo, $ctx, $warnings );
		} catch ( Exception $e ) {
			$warnings[] = __( 'WooCommerce: ', 'migrateplus' ) . $e->getMessage();
		}

		if ( $ctx['images'] && ! empty( $woo['gallery'] ) ) {
			$product->set_gallery_image_ids( self::sideload_all( (array) $woo['gallery'], $ctx, $warnings ) );
		}

		try {
			$product->save();
		} catch ( Exception $e ) {
			$warnings[] = __( 'WooCommerce: ', 'migrateplus' ) . $e->getMessage();
			return;
		}
		self::link_pending( $product, $warnings );

		// Variable-* types (e.g. variable-subscription) extend WC_Product_Variable.
		if ( $product instanceof WC_Product_Variable && ! empty( $woo['variations'] ) ) {
			self::import_variations( $product, (array) $woo['variations'], isset( $woo['default_attributes'] ) ? (array) $woo['default_attributes'] : array(), $ctx, $warnings );
		}
	}

	/**
	 * Read the "woo" payload from a record, decoding it if it arrives as a JSON string.
	 *
	 * @param array $record Flat record.
	 * @return array|null
	 */
	protected static function read_payload( array $record ) {
		$raw = MigratePlus_ACF::read( $record, 'woo' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}
		return is_array( $raw ) && $raw ? $raw : null;
	}

	/**
	 * A WC_Product of the right subclass wrapping an existing post.
	 *
	 * @param int      $post_id  Post id.
	 * @param string   $type     Product type.
	 * @param string[] $warnings Warnings (by reference).
	 * @return WC_Product|null
	 */
	protected static function product_for( $post_id, $type, array &$warnings ) {
		// WooCommerce's own lookup, so types added by extensions (subscriptions, bundles) resolve.
		$class = class_exists( 'WC_Product_Factory' ) ? WC_Product_Factory::get_product_classname( $post_id, $type ) : 'WC_Product_Simple';
		if ( 'WC_Product_Simple' === $class && 'simple' !== $type ) {
			/* translators: %s: product type */
			$warnings[] = sprintf( __( 'Product type "%s" is not available on this site (is the extension that adds it active?), so the product was imported as a simple product.', 'migrateplus' ), $type );
		}
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
		// Fields where an empty value means "none", so an update clears them. A field missing from the record is left alone.
		$clearable = array( 'sale_price', 'tax_class', 'weight', 'length', 'width', 'height', 'purchase_note' );
		foreach ( self::SCALAR_FIELDS as $field ) {
			if ( array_key_exists( $field, $woo ) && ( '' !== (string) $woo[ $field ] || in_array( $field, $clearable, true ) ) ) {
				self::set( $product, 'set_' . $field, (string) $woo[ $field ], $warnings );
			}
		}
		foreach ( self::BOOL_FIELDS as $field ) {
			if ( array_key_exists( $field, $woo ) ) {
				self::set( $product, 'set_' . $field, self::truthy( $woo[ $field ] ), $warnings );
			}
		}
		foreach ( array(
			'sale_from' => 'set_date_on_sale_from',
			'sale_to'   => 'set_date_on_sale_to',
		) as $field => $method ) {
			if ( array_key_exists( $field, $woo ) ) {
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
	 * @param string[]   $warnings   Warnings (by reference).
	 * @return string[] Taxonomy => custom attribute key, for each taxonomy that fell back to a custom attribute.
	 */
	protected static function apply_attributes( WC_Product $product, array $attributes, array $ctx, array &$warnings ) {
		$objects     = array();
		$position    = 0;
		$custom_keys = array();
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
					/* translators: %s: attribute name */
					$warnings[] = sprintf( __( 'Attribute "%s" was left off: none of its values match a term on this site. Turn on "Create missing categories and terms" to create them.', 'migrateplus' ), '' !== $name ? $name : $resolved_tax );
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
				if ( '' !== $taxonomy ) {
					// WooCommerce keys a custom attribute's variation values by sanitize_title( name ).
					$custom_keys[ $taxonomy ] = sanitize_title( $object->get_name() );
					/* translators: 1: attribute name, 2: attribute taxonomy */
					$warnings[] = sprintf( __( 'Attribute "%1$s" was added as a custom attribute because the global attribute "%2$s" does not exist on this site.', 'migrateplus' ), $object->get_name(), $taxonomy );
				}
			}
			$objects[] = $object;
			++$position;
		}
		$product->set_attributes( $objects );
		return $custom_keys;
	}

	/**
	 * Resolve the shipping class slug to a term and set it.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $woo     Payload.
	 * @param array      $ctx     Context.
	 */
	protected static function apply_shipping_class( WC_Product $product, array $woo, array $ctx ) {
		if ( ! array_key_exists( 'shipping_class', $woo ) || ! taxonomy_exists( 'product_shipping_class' ) ) {
			return;
		}
		if ( '' === (string) $woo['shipping_class'] ) {
			$product->set_shipping_class_id( 0 );
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
	 * @param WC_Product $product  Product.
	 * @param array      $woo      Payload.
	 * @param array      $ctx      Context.
	 * @param string[]   $warnings Warnings (by reference).
	 */
	protected static function apply_downloads( WC_Product $product, array $woo, array $ctx, array &$warnings ) {
		if ( empty( $woo['downloads'] ) || ! class_exists( 'WC_Product_Download' ) ) {
			return;
		}
		// WooCommerce keys downloads by id, so each needs one or they overwrite each other.
		// Keep the id of a file the product already has: customers' download permissions use it.
		$existing = array();
		foreach ( $product->get_downloads() as $id => $current ) {
			$existing[ $current->get_file() ] = $id;
		}
		$downloads = array();
		foreach ( (array) $woo['downloads'] as $download ) {
			$file = isset( $download['file'] ) ? (string) $download['file'] : '';
			if ( self::is_remote_file( $file ) ) {
				$fetch = isset( $download['fetch'] ) ? (string) $download['fetch'] : '';
				$file  = self::copy_download_file( $file, '' !== $fetch ? $fetch : $file, $ctx, $warnings );
			}
			if ( '' === $file ) {
				continue;
			}
			$object = new WC_Product_Download();
			$object->set_id( isset( $existing[ $file ] ) ? $existing[ $file ] : md5( $file ) );
			$object->set_name( isset( $download['name'] ) ? (string) $download['name'] : wp_basename( $file ) );
			$object->set_file( $file );
			$downloads[] = $object;
		}
		try {
			$product->set_downloads( $downloads );
		} catch ( Exception $e ) {
			// E.g. a file type WooCommerce does not allow, or approved directories that exclude woocommerce_uploads.
			$warnings[] = __( 'Downloadable files were not added: ', 'migrateplus' ) . $e->getMessage();
		}
		if ( isset( $woo['download_limit'] ) && is_numeric( $woo['download_limit'] ) ) {
			$product->set_download_limit( (int) $woo['download_limit'] );
		}
		if ( isset( $woo['download_expiry'] ) && is_numeric( $woo['download_expiry'] ) ) {
			$product->set_download_expiry( (int) $woo['download_expiry'] );
		}
	}

	/**
	 * Whether a download URL points at another site (so it has to be copied here).
	 *
	 * @param string $file File URL or path.
	 * @return bool
	 */
	protected static function is_remote_file( $file ) {
		$host = (string) wp_parse_url( $file, PHP_URL_HOST );
		return '' !== $host && preg_match( '#^https?://#i', $file ) && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Copy a downloadable file from the old site into this site's protected
	 * woocommerce_uploads folder, as WooCommerce's own "Upload file" does, so it
	 * passes the approved download directories check and outlives the old site.
	 * The copy is a media item tagged with the job, so a rollback removes it.
	 *
	 * @param string   $url      File URL on the old site (identifies the file across imports).
	 * @param string   $fetch    Where to download it from: the export's signed link, or $url.
	 * @param array    $ctx      Context.
	 * @param string[] $warnings Warnings (by reference).
	 * @return string Local file URL, or '' when the copy failed.
	 */
	protected static function copy_download_file( $url, $fetch, array $ctx, array &$warnings ) {
		// Copied by an earlier import: reuse it, so re-imports keep the same file (and download id).
		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => '_migrateplus_download_source', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $found ) {
			return (string) wp_get_attachment_url( $found[0] );
		}

		MigratePlus_Media::load_deps();
		$tmp = MigratePlus_Media::download( $fetch ); // Applies the media size limit in Settings.
		if ( is_wp_error( $tmp ) ) {
			$data = $tmp->get_error_data();
			// Current exports sign every file in woocommerce_uploads, so a refused one without a link is from an older export.
			if ( $fetch === $url && is_array( $data ) && isset( $data['code'] ) && 403 === (int) $data['code'] && false !== strpos( $url, '/woocommerce_uploads/' ) ) {
				/* translators: %s: file name */
				$warnings[] = sprintf( __( 'Downloadable file "%s" could not be copied: the old site keeps it in a protected folder, and this export was made by an older version of MigratePlus that could not reach it. Export again on the old site and import the new file.', 'migrateplus' ), wp_basename( $url ) );
				return '';
			}
			/* translators: 1: file name, 2: error message */
			$warnings[] = sprintf( __( 'Downloadable file "%1$s" could not be copied: %2$s', 'migrateplus' ), wp_basename( $url ), $tmp->get_error_message() );
			return '';
		}

		$name = sanitize_file_name( wp_basename( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$dir  = static function ( $dirs ) {
			$dirs['subdir'] = '/woocommerce_uploads' . $dirs['subdir'];
			$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
			$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
			return $dirs;
		};
		add_filter( 'upload_dir', $dir );
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' ); // No thumbnails for files sold as downloads.
		$id = media_handle_sideload(
			array(
				'name'     => '' !== $name ? $name : 'download-' . md5( $url ),
				'tmp_name' => $tmp,
			),
			$ctx['post_id']
		);
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		remove_filter( 'upload_dir', $dir );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			/* translators: 1: file name, 2: error message */
			$warnings[] = sprintf( __( 'Downloadable file "%1$s" could not be copied: %2$s', 'migrateplus' ), wp_basename( $url ), $id->get_error_message() );
			return '';
		}
		update_post_meta( $id, '_migrateplus_download_source', $url );
		update_post_meta( $id, '_migrateplus_job_id', $ctx['job_id'] );
		return (string) wp_get_attachment_url( $id );
	}

	/**
	 * Up-sells and cross-sells, matched to local products by source id or SKU.
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $woo      Payload.
	 * @param array      $ctx      Context.
	 * @param string[]   $warnings Warnings (by reference).
	 */
	protected static function apply_relations( WC_Product $product, array $woo, array $ctx, array &$warnings ) {
		foreach ( array(
			'upsell'     => 'set_upsell_ids',
			'cross_sell' => 'set_cross_sell_ids',
		) as $field => $method ) {
			$refs = self::read_refs( $woo, $field );
			if ( null !== $refs ) {
				$product->$method( self::resolve_refs( $refs, $field, $ctx, $warnings ) );
			}
		}
	}

	/**
	 * A relation's references from the payload: "<field>_refs" rows (id and SKU),
	 * or "<field>_skus" from older exports.
	 *
	 * @param array  $woo   Payload.
	 * @param string $field upsell, cross_sell or grouped.
	 * @return array[]|null Null when the payload does not carry the relation.
	 */
	protected static function read_refs( array $woo, $field ) {
		if ( array_key_exists( $field . '_refs', $woo ) ) {
			return array_filter( (array) $woo[ $field . '_refs' ], 'is_array' );
		}
		if ( array_key_exists( $field . '_skus', $woo ) ) {
			$refs = array();
			foreach ( (array) $woo[ $field . '_skus' ] as $sku ) {
				$refs[] = array( 'sku' => (string) $sku );
			}
			return $refs;
		}
		return null;
	}

	/**
	 * Local product ids for a relation's references: by source id (a product an
	 * earlier record of the same source created), then by SKU. A reference not
	 * found is warned about and recorded under PENDING_META, so link_pending()
	 * adds it when that product is imported later.
	 *
	 * @param array[]  $refs     Rows with "id" and/or "sku".
	 * @param string   $field    upsell, cross_sell or grouped.
	 * @param array    $ctx      Context.
	 * @param string[] $warnings Warnings (by reference).
	 * @return int[]
	 */
	protected static function resolve_refs( array $refs, $field, array $ctx, array &$warnings ) {
		$post_id = $ctx['post_id'];
		// Drop what this relation waited for on an earlier import; what is still missing is recorded again below.
		foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
			if ( 0 === strpos( (string) $key, self::PENDING_META ) ) {
				delete_post_meta( $post_id, $key, $field );
			}
		}
		$ids = array();
		foreach ( $refs as $ref ) {
			$source_id = isset( $ref['id'] ) ? (string) $ref['id'] : '';
			$sku       = isset( $ref['sku'] ) ? (string) $ref['sku'] : '';
			$uid       = MigratePlus_Importer::uid( $ctx['job'], $source_id );
			$id        = '' !== $uid ? MigratePlus_Importer::find_by_uid( $uid, 'product' ) : 0;
			if ( ! $id && '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$id = (int) wc_get_product_id_by_sku( $sku );
			}
			if ( $id ) {
				$ids[] = $id;
				continue;
			}
			if ( '' === $uid && '' === $sku ) {
				continue;
			}
			add_post_meta( $post_id, self::PENDING_META . md5( '' !== $uid ? 'uid:' . $uid : 'sku:' . $sku ), $field );
			/* translators: %s: SKU or source product id */
			$warnings[] = sprintf( __( 'Related product %s (up-sell, cross-sell or grouped) is not on this site yet. It will be linked when it is imported.', 'migrateplus' ), '' !== $sku ? '"' . $sku . '"' : '#' . $source_id );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Add a just-imported product to the products that were waiting for it (see
	 * PENDING_META), matched by its source uid or SKU.
	 *
	 * @param WC_Product $product  Product just saved.
	 * @param string[]   $warnings Warnings (by reference).
	 */
	protected static function link_pending( WC_Product $product, array &$warnings ) {
		$keys = array();
		$uid  = (string) get_post_meta( $product->get_id(), '_migrateplus_source_uid', true );
		if ( '' !== $uid ) {
			$keys[] = 'uid:' . $uid;
		}
		if ( '' !== $product->get_sku() ) {
			$keys[] = 'sku:' . $product->get_sku();
		}
		$accessors = array(
			'upsell'     => array( 'get_upsell_ids', 'set_upsell_ids' ),
			'cross_sell' => array( 'get_cross_sell_ids', 'set_cross_sell_ids' ),
			'grouped'    => array( 'get_children', 'set_children' ),
		);
		foreach ( $keys as $key ) {
			$meta_key = self::PENDING_META . md5( $key );
			$waiting  = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $waiting as $waiting_id ) {
				$other = wc_get_product( $waiting_id );
				foreach ( array_unique( get_post_meta( $waiting_id, $meta_key, false ) ) as $field ) {
					if ( $other && isset( $accessors[ $field ] ) && method_exists( $other, $accessors[ $field ][1] ) ) {
						list( $get, $set ) = $accessors[ $field ];
						$other->$set( array_values( array_unique( array_merge( $other->$get(), array( $product->get_id() ) ) ) ) );
					}
				}
				delete_post_meta( $waiting_id, $meta_key );
				if ( ! $other ) {
					continue;
				}
				try {
					$other->save();
				} catch ( Exception $e ) {
					$warnings[] = __( 'WooCommerce: ', 'migrateplus' ) . $e->getMessage();
				}
			}
		}
	}

	/**
	 * External and grouped product extras.
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $woo      Payload.
	 * @param array      $ctx      Context.
	 * @param string[]   $warnings Warnings (by reference).
	 */
	protected static function apply_type_extras( WC_Product $product, array $woo, array $ctx, array &$warnings ) {
		if ( $product->is_type( 'external' ) ) {
			if ( ! empty( $woo['product_url'] ) ) {
				$product->set_product_url( esc_url_raw( (string) $woo['product_url'] ) );
			}
			if ( ! empty( $woo['button_text'] ) ) {
				$product->set_button_text( (string) $woo['button_text'] );
			}
		}
		$refs = $product->is_type( 'grouped' ) ? self::read_refs( $woo, 'grouped' ) : null;
		if ( null !== $refs ) {
			$product->set_children( self::resolve_refs( $refs, 'grouped', $ctx, $warnings ) );
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
					// An empty sale price clears the sale on update; other empty values are left alone.
					if ( array_key_exists( $field, $data ) && ( '' !== (string) $data[ $field ] || 'sale_price' === $field ) ) {
						self::set( $variation, 'set_' . $field, (string) $data[ $field ], $warnings );
					}
				}
				foreach ( array( 'manage_stock', 'virtual', 'downloadable' ) as $field ) {
					if ( array_key_exists( $field, $data ) ) {
						self::set( $variation, 'set_' . $field, self::truthy( $data[ $field ] ), $warnings );
					}
				}
				self::apply_stock( $variation, $data );
				self::apply_shipping_class( $variation, $data, $ctx );
				self::apply_downloads( $variation, $data, $ctx, $warnings );
				if ( isset( $data['menu_order'] ) && is_numeric( $data['menu_order'] ) ) {
					$variation->set_menu_order( (int) $data['menu_order'] );
				}
				foreach ( array(
					'sale_from' => 'set_date_on_sale_from',
					'sale_to'   => 'set_date_on_sale_to',
				) as $field => $method ) {
					if ( array_key_exists( $field, $data ) ) {
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
				$warnings[] = __( 'WooCommerce variation: ', 'migrateplus' ) . $e->getMessage();
			}
		}

		if ( $defaults ) {
			$parent->set_default_attributes( self::resolve_variation_attributes( $defaults, $ctx ) );
			$parent->save();
		}
		// The parent was saved before its variations existed, so WooCommerce marked it out of
		// stock. Recompute its stock status and price range now rather than at request end.
		WC_Product_Variable::sync( $parent->get_id() );
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
			} elseif ( isset( $ctx['custom_keys'][ $name ] ) ) {
				// The parent stores this taxonomy as a custom attribute: use its key, or WooCommerce discards the value.
				$out[ $ctx['custom_keys'][ $name ] ] = $value;
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
			// "Any" is stored as an empty value but exported as a missing key: treat both alike.
			if ( '' === (string) $value ) {
				continue;
			}
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
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WooCommerce would.
				apply_filters(
					'woocommerce_taxonomy_args_' . $taxonomy, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WooCommerce would.
					array(
						'hierarchical' => true,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				)
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
		$id = MigratePlus_Media::sideload( $url, $ctx['post_id'], '', $ctx['base'], $ctx['job_id'] );
		if ( is_wp_error( $id ) ) {
			$warnings[] = __( 'WooCommerce image: ', 'migrateplus' ) . $id->get_error_message();
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
			$warnings[] = __( 'WooCommerce: ', 'migrateplus' ) . $e->getMessage();
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
