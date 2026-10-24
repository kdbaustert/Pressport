<?php
/**
 * WooCommerce products and coupons through an export and an import.
 *
 * @package MigratePlus
 */
class Test_Woo extends WP_UnitTestCase {

	// 1x1 PNG.
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/**
	 * URLs on the old site the import downloaded.
	 *
	 * @var string[]
	 */
	protected $requests = array();

	public function set_up() {
		parent::set_up();
		if ( ! MigratePlus_Woo::active() ) {
			$this->markTestSkipped( 'WooCommerce is not installed; composer install fetches it.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'fake_old_site' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_old_site' ) );
		// The uploads folder is shared with every other test run in this checkout: remove what the imports downloaded.
		foreach ( array( '_migrateplus_source_url', '_migrateplus_download_source' ) as $key ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_attachment( $id, true );
			}
		}
		parent::tear_down();
	}

	public function fake_old_site( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'http://old.test/' ) ) {
			return $pre;
		}
		$this->requests[] = $url;
		$pdf              = false !== strpos( $url, 'migrateplus_file' );
		$body             = $pdf ? "%PDF-1.4\n%%EOF\n" : base64_decode( self::PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		// download_url() streams to a temp file and reads the body from there.
		file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'headers'  => array( 'content-type' => $pdf ? 'application/pdf' : 'image/png' ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}

	/**
	 * Export to the end and return the file as if another site, http://old.test, wrote it.
	 *
	 * @param array $args Export args.
	 * @return array
	 */
	protected function export_doc( array $args = array() ) {
		$args  = array_merge(
			array(
				'post_type'     => 'product',
				'include_woo'   => 1,
				'include_terms' => 1,
				'include_image' => 1,
				'include_meta'  => 1,
			),
			$args
		);
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( $args ) );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );

		$home = untrailingslashit( home_url() );
		array_walk_recursive(
			$doc,
			static function ( &$value ) use ( $home ) {
				if ( is_string( $value ) ) {
					$value = str_replace( array( $home, rawurlencode( $home ) ), array( 'http://old.test', rawurlencode( 'http://old.test' ) ), $value );
				}
			}
		);
		return $doc;
	}

	/**
	 * Write the file and import it to the end.
	 *
	 * @param array $doc     Document.
	 * @param array $options Import options to change.
	 * @return MigratePlus_Job
	 */
	protected function import_doc( array $doc, array $options = array() ) {
		$file = wp_tempnam( 'migrateplus-woo.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		wp_delete_file( $file );
		$this->assertIsArray( $preview );
		$job                  = $preview['job'];
		$job->data['options'] = array_merge(
			$job->data['options'],
			array(
				'import_featured_image' => 1,
				'create_terms'          => 1,
			),
			$options
		);
		$job->save();
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			foreach ( $result['results'] as $row ) {
				$this->assertNotSame( 'failed', $row['action'], $row['message'] );
			}
		} while ( ! $result['done'] );
		return $job;
	}

	/**
	 * @param string $name  Name.
	 * @param array  $props Properties.
	 * @param string $class Product class.
	 * @return WC_Product
	 */
	protected function product( $name, array $props = array(), $class = 'WC_Product_Simple' ) {
		$product = new $class();
		$product->set_props( array_merge( array( 'name' => $name ), $props ) );
		$product->save();
		return $product;
	}

	/**
	 * Delete the originals, as if the import ran on another site.
	 *
	 * @param WC_Data ...$objects Products or coupons.
	 */
	protected function remove( ...$objects ) {
		foreach ( $objects as $object ) {
			wp_delete_post( $object->get_id(), true );
		}
	}

	/**
	 * @param int    $source_id ID on the old site.
	 * @param string $type      Post type.
	 * @return WC_Product|int The product, or the post ID for other types.
	 */
	protected function imported( $source_id, $type = 'product' ) {
		$ids = get_posts(
			array(
				'post_type'   => $type,
				'post_status' => 'any',
				'meta_key'    => '_migrateplus_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
			)
		);
		$this->assertCount( 1, $ids );
		return 'product' === $type ? wc_get_product( $ids[0] ) : $ids[0];
	}

	public function test_simple_product_round_trip() {
		$class   = wp_insert_term( 'Fragile', 'product_shipping_class' );
		$gallery = array(
			self::factory()->attachment->create_object( 'mug-side.png', 0, array( 'post_mime_type' => 'image/png' ) ),
			self::factory()->attachment->create_object( 'mug-top.png', 0, array( 'post_mime_type' => 'image/png' ) ),
		);
		$mug     = $this->product(
			'Mug',
			array(
				'status'             => 'publish',
				'sku'                => 'MUG-1',
				'regular_price'      => '12.00',
				'sale_price'         => '9.50',
				'date_on_sale_from'  => '2030-01-01 00:00:00',
				'date_on_sale_to'    => '2030-01-31 23:59:59',
				'tax_class'          => 'reduced-rate',
				'manage_stock'       => true,
				'stock_quantity'     => 40,
				'low_stock_amount'   => 5,
				'backorders'         => 'notify',
				'weight'             => '0.35',
				'length'             => '12',
				'width'              => '9',
				'height'             => '10',
				'shipping_class_id'  => $class['term_id'],
				'catalog_visibility' => 'search',
				'category_ids'       => array( self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Kitchen' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'tag_ids'            => array( self::factory()->term->create( array( 'taxonomy' => 'product_tag', 'name' => 'Ceramic' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'gallery_image_ids'  => $gallery,
			)
		);

		$doc = $this->export_doc();
		$this->assertSame( array( 'Kitchen' ), $doc['items'][0]['terms']['product_cat'] );
		$this->assertArrayNotHasKey( 'product_type', $doc['items'][0]['terms'] );
		$this->remove( $mug );
		$this->import_doc( $doc )->delete();

		$new = $this->imported( $mug->get_id() );
		$this->assertSame( 'MUG-1', $new->get_sku() );
		$this->assertSame( '12.00', $new->get_regular_price() );
		$this->assertSame( '9.50', $new->get_sale_price() );
		$this->assertSame( '2030-01-01', $new->get_date_on_sale_from()->date( 'Y-m-d' ) );
		$this->assertSame( '2030-01-31', $new->get_date_on_sale_to()->date( 'Y-m-d' ) );
		$this->assertSame( 'reduced-rate', $new->get_tax_class() );
		$this->assertSame( 40, $new->get_stock_quantity() );
		$this->assertSame( 5, $new->get_low_stock_amount() );
		$this->assertSame( 'notify', $new->get_backorders() );
		$this->assertSame( array( '0.35', '12', '9', '10' ), array( $new->get_weight(), $new->get_length(), $new->get_width(), $new->get_height() ) );
		$this->assertSame( 'fragile', $new->get_shipping_class() );
		$this->assertSame( 'search', $new->get_catalog_visibility() );
		$this->assertSame( array( 'Kitchen' ), wp_list_pluck( get_the_terms( $new->get_id(), 'product_cat' ), 'name' ) );
		$this->assertSame( array( 'Ceramic' ), wp_list_pluck( get_the_terms( $new->get_id(), 'product_tag' ), 'name' ) );

		$images = $new->get_gallery_image_ids();
		$this->assertSame( $doc['items'][0]['woo']['gallery'], $this->requests );
		$this->assertSame(
			$this->requests,
			array_map(
				static function ( $id ) {
					return get_post_meta( $id, '_migrateplus_source_url', true );
				},
				$images
			)
		);
	}

	public function test_variable_product_reimport_and_rollback() {
		wc_create_attribute(
			array(
				'name' => 'Color',
				'slug' => 'color',
			)
		);
		// WooCommerce registers attribute taxonomies on init, which has already run.
		register_taxonomy( 'pa_color', 'product' );
		$grey  = wp_insert_term( 'Grey', 'pa_color' );
		$navy  = wp_insert_term( 'Navy', 'pa_color' );
		$color = new WC_Product_Attribute();
		$color->set_id( wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
		$color->set_name( 'pa_color' );
		$color->set_options( array( $grey['term_id'], $navy['term_id'] ) );
		$color->set_variation( true );
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'S', 'M', 'L' ) );
		$size->set_variation( true );
		$hoodie = $this->product(
			'Hoodie',
			array(
				'sku'                => 'HOOD',
				'attributes'         => array( $color, $size ),
				'default_attributes' => array( 'pa_color' => 'grey' ),
			),
			'WC_Product_Variable'
		);
		$grey_s = $this->product(
			'',
			array(
				'parent_id'      => $hoodie->get_id(),
				'sku'            => 'HOOD-GREY-S',
				'regular_price'  => '45.00',
				'manage_stock'   => true,
				'stock_quantity' => 8,
				'attributes'     => array(
					'pa_color' => 'grey',
					'size'     => 'S',
				),
			),
			'WC_Product_Variation'
		);
		// Navy in any size, without a SKU of its own.
		$this->product(
			'',
			array(
				'parent_id'     => $hoodie->get_id(),
				'regular_price' => '49.00',
				'attributes'    => array(
					'pa_color' => 'navy',
					'size'     => '',
				),
			),
			'WC_Product_Variation'
		);

		$doc = $this->export_doc();
		$woo = $doc['items'][0]['woo'];
		$this->assertEqualSets( array( 'Grey', 'Navy' ), $woo['attributes'][0]['options'] );
		$this->assertSame( array( 'S', 'M', 'L' ), $woo['attributes'][1]['options'] );
		$this->assertSame( array( 'pa_color' => 'Grey' ), $woo['default_attributes'] );
		// In view context the navy variation would report the parent's SKU, and the import would then fail on a duplicate SKU.
		$this->assertEqualSets( array( 'HOOD-GREY-S', '' ), wp_list_pluck( $woo['variations'], 'sku' ) );

		$this->remove( $hoodie );
		$job = $this->import_doc( $doc );
		$new = $this->imported( $hoodie->get_id() );
		$this->assertSame( array( 'pa_color' => 'grey' ), $new->get_default_attributes() );
		$this->assertSame( '45.00', $new->get_variation_price( 'min' ) );
		$children = $new->get_children();
		$this->assertCount( 2, $children );
		$variation = wc_get_product( wc_get_product_id_by_sku( 'HOOD-GREY-S' ) );
		$this->assertSame(
			array(
				'pa_color' => 'grey',
				'size'     => 'S',
			),
			$variation->get_attributes()
		);
		$this->assertSame( 8, $variation->get_stock_quantity() );

		$variation->set_regular_price( '1.00' );
		$variation->save();
		$again = $this->import_doc( $doc, array( 'duplicate' => 'update' ) );
		$this->assertSame( 0, $again->data['stats']['created'] );
		$this->assertSame( 1, $again->data['stats']['updated'] );
		$again->delete();
		$this->assertSame( $children, wc_get_product( $new->get_id() )->get_children() );
		$this->assertSame( '45.00', wc_get_product( $variation->get_id() )->get_regular_price() );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job, 100 );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertNull( get_post( $new->get_id() ) );
		foreach ( $children as $id ) {
			$this->assertNull( get_post( $id ) );
		}
		$job->delete();
	}

	public function test_downloads_are_copied_through_the_signed_link() {
		$uploads = wp_upload_dir();
		$name    = 'field-guide-' . wp_generate_password( 6, false ) . '.pdf';
		wp_mkdir_p( $uploads['basedir'] . '/woocommerce_uploads' );
		file_put_contents( $uploads['basedir'] . '/woocommerce_uploads/' . $name, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$download = new WC_Product_Download();
		$download->set_id( 'guide' );
		$download->set_name( 'Field guide (PDF)' );
		$download->set_file( $uploads['baseurl'] . '/woocommerce_uploads/' . $name );
		$ebook = $this->product(
			'Field Guide',
			array(
				'sku'             => 'BOOK-FG',
				'regular_price'   => '15.00',
				'virtual'         => true,
				'downloadable'    => true,
				'downloads'       => array( $download ),
				'download_limit'  => 3,
				'download_expiry' => 30,
			)
		);

		$doc = $this->export_doc();
		wp_delete_file( $uploads['basedir'] . '/woocommerce_uploads/' . $name );
		$row = $doc['items'][0]['woo']['downloads'][0];
		$this->assertStringContainsString( 'action=migrateplus_file', $row['fetch'] );

		$this->remove( $ebook );
		$job = $this->import_doc( $doc );
		$new = $this->imported( $ebook->get_id() );
		// woocommerce_uploads refuses direct requests, so the copy has to come through the signed link.
		$this->assertSame( array( $row['fetch'] ), $this->requests );
		$this->assertSame( 3, $new->get_download_limit() );
		$this->assertSame( 30, $new->get_download_expiry() );
		$files = $new->get_downloads();
		$file  = reset( $files );
		$this->assertSame( 'Field guide (PDF)', $file->get_name() );
		$this->assertStringStartsWith( $uploads['baseurl'] . '/woocommerce_uploads/', $file->get_file() );

		$this->import_doc( $doc, array( 'duplicate' => 'update' ) )->delete();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( array_keys( $files ), array_keys( wc_get_product( $new->get_id() )->get_downloads() ) );

		$copy = attachment_url_to_postid( $file->get_file() );
		$path = get_attached_file( $copy );
		do {
			$result = MigratePlus_Runner::rollback_batch( $job, 100 );
		} while ( ! $result['done'] );
		$job->delete();
		$this->assertNull( get_post( $copy ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_related_products_link_by_old_id_or_sku() {
		// Created first, so the products it links to come later in the file.
		$set    = $this->product( 'Breakfast Set', array(), 'WC_Product_Grouped' );
		$mug    = $this->product( 'Mug', array( 'sku' => 'MUG-1' ) );
		$saucer = $this->product( 'Saucer' );
		$teapot = $this->product( 'Teapot', array( 'sku' => 'POT-1' ) );
		$set->set_children( array( $mug->get_id(), $saucer->get_id() ) );
		$set->save();
		$mug->set_upsell_ids( array( $saucer->get_id(), $teapot->get_id() ) );
		$mug->save();
		$teapot->set_cross_sell_ids( array( $mug->get_id() ) );
		$teapot->save();

		$doc = $this->export_doc();
		// As an export from before refs were added: the teapot's cross-sell has only its SKU.
		unset( $doc['items'][3]['woo']['cross_sell_refs'] );
		$this->remove( $set, $mug, $saucer, $teapot );
		$this->import_doc( $doc, array( 'batch_size' => 1 ) )->delete();

		$new = array_map( array( $this, 'imported' ), array( $set->get_id(), $mug->get_id(), $saucer->get_id(), $teapot->get_id() ) );
		$this->assertEqualSets( array( $new[1]->get_id(), $new[2]->get_id() ), $new[0]->get_children() );
		$this->assertEqualSets( array( $new[2]->get_id(), $new[3]->get_id() ), $new[1]->get_upsell_ids() );
		$this->assertSame( array( $new[1]->get_id() ), $new[3]->get_cross_sell_ids() );
		foreach ( $new as $product ) {
			$this->assertSame( array(), preg_grep( '/^' . MigratePlus_Woo::PENDING_META . '/', array_keys( get_post_meta( $product->get_id() ) ) ) );
		}
	}

	public function test_coupon_round_trip() {
		$coupon = new WC_Coupon();
		$coupon->set_props(
			array(
				'code'                 => 'spring15',
				'description'          => 'Spring sale',
				'discount_type'        => 'percent',
				'amount'               => '15',
				'date_expires'         => '2031-05-31',
				'usage_limit'          => 100,
				'usage_limit_per_user' => 1,
				'individual_use'       => true,
				'minimum_amount'       => '30.00',
				'email_restrictions'   => array( '*@example.com' ),
			)
		);
		$coupon->save();

		$doc = $this->export_doc( array( 'post_type' => 'shop_coupon' ) );
		$this->remove( $coupon );
		$this->import_doc( $doc )->delete();

		$new = new WC_Coupon( $this->imported( $coupon->get_id(), 'shop_coupon' ) );
		$this->assertSame( $new->get_id(), wc_get_coupon_id_by_code( 'spring15' ) );
		$this->assertSame( 'Spring sale', $new->get_description() );
		$this->assertSame( 'percent', $new->get_discount_type() );
		$this->assertSame( 15.0, (float) $new->get_amount() );
		$this->assertSame( '2031-05-31', $new->get_date_expires()->date( 'Y-m-d' ) );
		$this->assertSame( 100, $new->get_usage_limit() );
		$this->assertSame( 1, $new->get_usage_limit_per_user() );
		$this->assertTrue( $new->get_individual_use() );
		$this->assertSame( '30.00', $new->get_minimum_amount() );
		$this->assertSame( array( '*@example.com' ), $new->get_email_restrictions() );
	}

	public function test_coupon_product_restrictions_follow_the_products() {
		$mug    = $this->product( 'Mug', array( 'sku' => 'MUG-1' ) );
		$coupon = new WC_Coupon();
		$coupon->set_props(
			array(
				'code'        => 'mugs10',
				'amount'      => '10',
				'product_ids' => array( $mug->get_id() ),
			)
		);
		$coupon->save();

		$doc = $this->export_doc( array( 'post_types' => 'product,shop_coupon' ) );
		$this->remove( $mug, $coupon );
		$this->import_doc( $doc )->delete();

		$new = new WC_Coupon( 'mugs10' );
		$this->assertSame( array( $this->imported( $mug->get_id() )->get_id() ), $new->get_product_ids() );
	}

	public function test_products_and_media_in_one_file() {
		$photo = self::factory()->attachment->create_object(
			'mug.png',
			0,
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Mug photo',
			)
		);
		$mug   = $this->product(
			'Mug',
			array(
				'sku'               => 'MUG-1',
				'image_id'          => $photo,
				'gallery_image_ids' => array( $photo ),
			)
		);

		$doc = $this->export_doc( array( 'post_types' => 'product,attachment' ) );
		$this->assertSame( array( 'attachment', 'product' ), array_column( $doc['items'], 'post_type' ) );
		$this->remove( $mug );
		wp_delete_post( $photo, true );
		$this->import_doc( $doc )->delete();

		$media = $this->imported( $photo, 'attachment' );
		$new   = $this->imported( $mug->get_id() );
		$this->assertSame( $media, (int) $new->get_image_id() );
		$this->assertSame( array( $media ), $new->get_gallery_image_ids() );
		$this->assertSame( array( $doc['items'][0]['file'] ), $this->requests );
	}

	/**
	 * A custom attribute: the Size the product itself defines.
	 *
	 * @param string[] $options Values.
	 * @return WC_Product_Attribute
	 */
	protected function size( array $options ) {
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( $options );
		$size->set_variation( true );
		return $size;
	}

	public function test_variations_of_a_custom_attribute_keep_its_key_when_a_global_one_shares_the_name() {
		wc_create_attribute(
			array(
				'name' => 'Size',
				'slug' => 'size',
			)
		);
		register_taxonomy( 'pa_size', 'product' );
		$tee = $this->product(
			'Tee',
			array(
				'attributes'         => array( $this->size( array( 'S', 'M' ) ) ),
				'default_attributes' => array( 'size' => 'M' ),
			),
			'WC_Product_Variable'
		);
		$this->product(
			'',
			array(
				'parent_id'     => $tee->get_id(),
				'sku'           => 'TEE-M',
				'regular_price' => '20.00',
				'attributes'    => array( 'size' => 'M' ),
			),
			'WC_Product_Variation'
		);

		$doc = $this->export_doc();
		$this->remove( $tee );
		$this->import_doc( $doc )->delete();

		$this->assertSame( array( 'size' => 'M' ), wc_get_product( wc_get_product_id_by_sku( 'TEE-M' ) )->get_attributes() );
		$this->assertSame( array( 'size' => 'M' ), $this->imported( $tee->get_id() )->get_default_attributes() );
		$this->assertSame( array(), get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_variations_keep_inheriting_what_they_leave_to_the_parent() {
		$photo = self::factory()->attachment->create_object( 'tee.png', 0, array( 'post_mime_type' => 'image/png' ) );
		$tee   = $this->product(
			'Tee',
			array(
				'sku'            => 'TEE',
				'image_id'       => $photo,
				'tax_class'      => 'reduced-rate',
				'manage_stock'   => true,
				'stock_quantity' => 5,
				'backorders'     => 'notify',
				'weight'         => '0.3',
				'length'         => '30',
				'width'          => '20',
				'height'         => '2',
				'attributes'     => array( $this->size( array( 'S' ) ) ),
			),
			'WC_Product_Variable'
		);
		$this->product(
			'',
			array(
				'parent_id'     => $tee->get_id(),
				'sku'           => 'TEE-S',
				'regular_price' => '20.00',
				'attributes'    => array( 'size' => 'S' ),
			),
			'WC_Product_Variation'
		);

		$doc = $this->export_doc();
		$row = $doc['items'][0]['woo']['variations'][0];
		$this->assertSame( array( '', '', '', '', 'parent', 'no', '', '' ), array( $row['weight'], $row['length'], $row['width'], $row['height'], $row['tax_class'], $row['backorders'], $row['low_stock_amount'], $row['image'] ) );

		$this->remove( $tee );
		$this->import_doc( $doc )->delete();
		$variation = wc_get_product( wc_get_product_id_by_sku( 'TEE-S' ) );
		$this->assertSame( array( '', 'parent', 0 ), array( $variation->get_weight( 'edit' ), $variation->get_tax_class( 'edit' ), $variation->get_image_id( 'edit' ) ) );
		$this->assertSame( array( '0.3', 'reduced-rate', 'notify' ), array( $variation->get_weight(), $variation->get_tax_class(), $variation->get_backorders() ) );
	}

	public function test_up_sells_and_coupons_can_point_at_a_variation() {
		// Created first, so the variation it up-sells comes later in the file.
		$mug   = $this->product( 'Mug', array( 'sku' => 'MUG-1' ) );
		$tee   = $this->product( 'Tee', array( 'sku' => 'TEE', 'attributes' => array( $this->size( array( 'S', 'M' ) ) ) ), 'WC_Product_Variable' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$small = $this->product(
			'',
			array(
				'parent_id'     => $tee->get_id(),
				'regular_price' => '20.00',
				'attributes'    => array( 'size' => 'S' ),
			),
			'WC_Product_Variation'
		);
		$mug->set_upsell_ids( array( $small->get_id() ) );
		$mug->save();
		$coupon = new WC_Coupon();
		$coupon->set_props(
			array(
				'code'        => 'smalltee',
				'amount'      => '5',
				'product_ids' => array( $small->get_id() ),
			)
		);
		$coupon->save();

		$doc = $this->export_doc( array( 'post_types' => 'product,shop_coupon' ) );
		// The variation has no SKU of its own; in view context it would report the parent's.
		$this->assertSame( array(), $doc['items'][0]['woo']['upsell_skus'] );
		$this->remove( $mug, $tee, $coupon );
		$this->import_doc( $doc )->delete();

		$children = $this->imported( $tee->get_id() )->get_children();
		$this->assertCount( 1, $children );
		$this->assertSame( $children, $this->imported( $mug->get_id() )->get_upsell_ids() );
		$this->assertSame( $children, ( new WC_Coupon( 'smalltee' ) )->get_product_ids() );
	}

	public function test_downloads_stored_as_server_paths_are_copied_through_the_signed_link() {
		$uploads = wp_upload_dir();
		$name    = 'manual-' . wp_generate_password( 6, false ) . '.pdf';
		$path    = $uploads['basedir'] . '/woocommerce_uploads/' . $name;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$download = new WC_Product_Download();
		$download->set_id( 'manual' );
		$download->set_name( 'Manual' );
		$download->set_file( $path );
		$kettle = $this->product(
			'Kettle manual',
			array(
				'virtual'      => true,
				'downloadable' => true,
				'downloads'    => array( $download ),
			)
		);

		$doc = $this->export_doc();
		wp_delete_file( $path );
		$row = $doc['items'][0]['woo']['downloads'][0];
		$this->assertSame( $path, $row['file'] );
		$this->remove( $kettle );
		$this->import_doc( $doc )->delete();

		$this->assertSame( array( $row['fetch'] ), $this->requests );
		$files = $this->imported( $kettle->get_id() )->get_downloads();
		$this->assertStringStartsWith( $uploads['baseurl'] . '/woocommerce_uploads/', reset( $files )->get_file() );
	}

	public function test_an_export_imported_back_into_its_own_site_keeps_its_download_files() {
		$uploads = wp_upload_dir();
		$name    = 'guide-' . wp_generate_password( 6, false ) . '.pdf';
		$url     = $uploads['baseurl'] . '/woocommerce_uploads/' . $name;
		$path    = $uploads['basedir'] . '/woocommerce_uploads/' . $name;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$download = new WC_Product_Download();
		$download->set_id( 'guide' );
		$download->set_name( 'Guide' );
		$download->set_file( $url );
		$kettle = $this->product(
			'Kettle guide',
			array(
				'virtual'      => true,
				'downloadable' => true,
				'downloads'    => array( $download ),
			)
		);

		// The file stays where it is, as on the site the export was made on: its signed link may have
		// expired by the time the file comes back, so nothing should be fetched. Exported as this site,
		// not through export_doc(), which makes the file look as if old.test wrote it.
		$state = MigratePlus_Exporter::start(
			MigratePlus_Exporter::sanitize_args(
				array(
					'post_type'   => 'product',
					'include_woo' => 1,
				)
			)
		);
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 1 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );
		$this->assertNotSame( '', $doc['items'][0]['woo']['downloads'][0]['fetch'] );
		$this->remove( $kettle );
		$this->import_doc( $doc )->delete();
		wp_delete_file( $path );

		$this->assertSame( array(), $this->requests );
		$files = $this->imported( $kettle->get_id() )->get_downloads();
		$this->assertSame( $url, reset( $files )->get_file() );
	}

	public function test_imported_downloads_on_this_server_must_be_in_uploads() {
		$uploads = wp_upload_dir();
		$name    = 'manual-' . wp_generate_password( 6, false ) . '.pdf';
		wp_mkdir_p( $uploads['basedir'] . '/woocommerce_uploads' );
		file_put_contents( $uploads['basedir'] . '/woocommerce_uploads/' . $name, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$post     = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$manual   = $uploads['baseurl'] . '/woocommerce_uploads/' . $name;
		$warnings = array();

		MigratePlus_Woo::import(
			$post,
			array(
				'woo' => array(
					'downloadable' => true,
					'downloads'    => array(
						array(
							'name' => 'Config',
							'file' => site_url( '/wp-config-sample.php' ),
						),
						array(
							'name' => 'Loader',
							'file' => ABSPATH . 'wp-load.php',
						),
						array(
							'name' => 'Manual',
							'file' => $manual,
						),
					),
				),
			),
			new MigratePlus_Job( array( 'source' => 'json' ) ),
			array(),
			'',
			$warnings
		);
		wp_delete_file( $uploads['basedir'] . '/woocommerce_uploads/' . $name );

		$this->assertSame(
			array(
				'Downloadable file "' . site_url( '/wp-config-sample.php' ) . '" was left off: it is not a file in this site\'s uploads folder.',
				'Downloadable file "' . ABSPATH . 'wp-load.php" was left off: it is not a file in this site\'s uploads folder.',
			),
			$warnings
		);
		$files = wc_get_product( $post )->get_downloads();
		$this->assertCount( 1, $files );
		$this->assertSame( $manual, reset( $files )->get_file() );
	}

	public function test_attribute_values_with_no_term_here_are_named() {
		register_taxonomy( 'pa_color', 'product' );
		$grey     = wp_insert_term( 'Grey', 'pa_color' );
		$post     = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$warnings = array();

		MigratePlus_Woo::import(
			$post,
			array(
				'woo' => array(
					'attributes' => array(
						array(
							'name'     => 'Color',
							'taxonomy' => 'pa_color',
							'options'  => array( 'Grey', 'Teal', 'Rust' ),
						),
					),
				),
			),
			new MigratePlus_Job( array( 'source' => 'json' ) ),
			array( 'create_terms' => 0 ),
			'',
			$warnings
		);

		$this->assertSame( array( 'Attribute "Color" was added without "Teal", "Rust": no term on this site matches. Turn on "Create missing categories and terms" to create them.' ), $warnings );
		$this->assertSame( array( $grey['term_id'] ), array_map( 'intval', wc_get_product( $post )->get_attributes()['pa_color']->get_options() ) );
	}

	public function test_signed_links_only_serve_files_in_uploads() {
		$uploads   = wp_upload_dir();
		$name      = 'guide-' . wp_generate_password( 6, false ) . '.pdf';
		$files     = array(
			'woocommerce_uploads' => $uploads['basedir'] . '/woocommerce_uploads/' . $name,
			'migrateplus'         => MigratePlus_Util::ensure_storage_dir() . $name,
			'wp-content'          => WP_CONTENT_DIR . '/' . $name,
		);
		$downloads = array();
		foreach ( $files as $key => $path ) {
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$download = new WC_Product_Download();
			$download->set_id( $key );
			$download->set_name( $key );
			$download->set_file( $path );
			$downloads[] = $download;
		}
		$this->product(
			'Guide',
			array(
				'virtual'      => true,
				'downloadable' => true,
				'downloads'    => $downloads,
			)
		);

		$doc = $this->export_doc();
		array_map( 'wp_delete_file', $files );

		$rows = $doc['items'][0]['woo']['downloads'];
		$this->assertSame( array( 'woocommerce_uploads', 'migrateplus', 'wp-content' ), array_column( $rows, 'name' ) );
		$this->assertStringContainsString( 'action=migrateplus_file', $rows[0]['fetch'] );
		$this->assertArrayNotHasKey( 'fetch', $rows[1] );
		$this->assertArrayNotHasKey( 'fetch', $rows[2] );
	}
}
