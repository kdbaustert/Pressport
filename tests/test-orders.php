<?php
/**
 * WooCommerce orders through an export and an import: items find their
 * products, customers their accounts, notes and refunds come along, nothing is
 * emailed or restocked, and rollback removes what was created.
 *
 * @package MigratePlus
 */
class Test_Orders extends WP_UnitTestCase {

	/**
	 * Emails WooCommerce tried to send.
	 *
	 * @var string[]
	 */
	protected $mails = array();

	public function set_up() {
		parent::set_up();
		if ( ! MigratePlus_Orders::active() ) {
			$this->markTestSkipped( 'WooCommerce is not installed; composer install fetches it.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ) );
		parent::tear_down();
	}

	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts['subject'];
		return true;
	}

	protected function json_file( array $doc ) {
		$file = wp_tempnam( 'migrateplus-test.json' );
		file_put_contents( $file, wp_json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	protected function import_file( $file, array $options = array() ) {
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		$this->assertIsArray( $preview );
		$job                  = $preview['job'];
		$job->data['options'] = array_merge( $job->data['options'], $options );
		$job->save();
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			$this->assertIsArray( $result );
		} while ( ! $result['done'] );
		return $job;
	}

	protected function export_doc( array $args ) {
		$state = MigratePlus_Exporter::start( MigratePlus_Exporter::sanitize_args( $args ) );
		$this->assertIsArray( $state );
		while ( empty( $state['complete'] ) ) {
			MigratePlus_Exporter::run_batch( $state, 10 );
		}
		$doc = json_decode( (string) file_get_contents( $state['file'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MigratePlus_Exporter::forget( $state['id'] );
		return $doc;
	}

	protected function order_with_everything() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tent' );
		$product->set_sku( 'TENT-1' );
		$product->set_regular_price( '100' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
		$customer = self::factory()->user->create(
			array(
				'user_login' => 'cathy',
				'user_email' => 'cathy@old.test',
				'role'       => 'customer',
			)
		);
		$order    = wc_create_order( array( 'customer_id' => $customer ) );
		$order->add_product( $product, 2 );
		$order->set_billing_first_name( 'Cathy' );
		$order->set_billing_city( 'Dundee' );
		$order->set_shipping_city( 'Perth' );
		$order->set_payment_method_title( 'Card' );
		$order->set_customer_note( 'Leave at the door' );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Courier' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( '5' );
		$order->add_item( $shipping );
		$order->set_shipping_total( '5' );
		$order->set_total( '205' );
		$order->set_date_created( '2024-03-04 10:00:00' );
		$order->set_status( 'completed' );
		$order->save();
		$order->add_order_note( 'Packed by Sam', 0 );
		wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '20',
				'reason'         => 'Dented pole',
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		return array( $product, $customer, wc_get_order( $order->get_id() ) );
	}

	public function test_orders_round_trip_without_emails_or_stock_changes_and_roll_back() {
		list( $product, $customer, $order ) = $this->order_with_everything();
		$this->mails                        = array();

		$doc = $this->export_doc(
			array(
				'post_types'     => array( 'product' ),
				'include_orders' => 1,
			)
		);
		$this->assertCount( 1, $doc['orders'] );
		$exported = $doc['orders'][0];
		$this->assertSame( 'completed', $exported['status'] );
		$this->assertSame( 'cathy@old.test', $exported['customer']['email'] );
		$this->assertSame( 'TENT-1', $exported['items'][0]['product']['sku'] );
		$this->assertSame( 2, $exported['items'][0]['quantity'] );
		$this->assertSame( 'Courier', $exported['shipping_lines'][0]['method_title'] );
		$this->assertContains( 'Packed by Sam', array_column( $exported['notes'], 'content' ) ); // Beside WooCommerce's own stock note.
		$this->assertSame( 20.0, (float) $exported['refunds'][0]['amount'] );
		$this->assertSame( 205.0, (float) $exported['total'] );
		// The totals log says what was written.
		$this->assertSame(
			1,
			MigratePlus_Orders::count(
				MigratePlus_Exporter::sanitize_args(
					array(
						'post_types'     => array(),
						'include_orders' => 1,
					)
				)
			)
		);

		$doc['migrateplus']['site'] = 'http://old.test';
		$file                       = $this->json_file( $doc );
		$order->delete( true );
		$stock = wc_get_product( $product->get_id() )->get_stock_quantity(); // 8: the original order took two, and deleting it gives none back.

		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$last = end( $job->data['sections'] );
		$this->assertSame( 'orders', $last['type'] );
		$ids = $job->imported_orders();
		$this->assertCount( 1, $ids );
		$new = wc_get_order( $ids[0] );
		$this->assertSame( 'completed', $new->get_status() );
		$this->assertSame( $customer, $new->get_customer_id() );
		$this->assertSame( 'Dundee', $new->get_billing_city() );
		$this->assertSame( 'Perth', $new->get_shipping_city() );
		$this->assertSame( 'Leave at the door', $new->get_customer_note() );
		$this->assertSame( 205.0, (float) $new->get_total() );
		$this->assertSame( '2024-03-04 10:00:00', $new->get_date_created()->date( 'Y-m-d H:i:s' ) );
		$items = array_values( $new->get_items( 'line_item' ) );
		// The file carried the product too, so the line points at the copy the import made of it.
		$copy = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertCount( 1, $copy );
		$this->assertSame( (int) $copy[0], $items[0]->get_product_id() );
		$this->assertNotSame( $product->get_id(), $items[0]->get_product_id() );
		$this->assertSame( 2, $items[0]->get_quantity() );
		$this->assertSame( 'Courier', array_values( $new->get_items( 'shipping' ) )[0]->get_method_title() );
		$notes = wc_get_order_notes( array( 'order_id' => $new->get_id() ) );
		$this->assertContains( 'Packed by Sam', array_column( array_map( 'get_object_vars', $notes ), 'content' ) );
		$this->assertCount( 1, $new->get_refunds() );
		$this->assertSame( 20.0, (float) $new->get_refunds()[0]->get_amount() );
		$this->assertSame( $stock, wc_get_product( $product->get_id() )->get_stock_quantity() );
		$this->assertSame( array(), $this->mails );

		// Importing again skips it; "update" refreshes the order, not its items.
		$file  = $this->json_file( $doc );
		$again = $this->import_file( $file );
		$this->assertSame( array(), $again->imported_orders() );
		$this->assertCount( 1, array_merge( $again->imported_orders(), $job->imported_orders() ) );
		$this->assertStringContainsString( 'Already imported: order #', implode( "\n", array_column( $again->data['log'], 'm' ) ) );
		$again->delete();
		wp_delete_file( $file );

		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( wc_get_order( $new->get_id() ) );
		$job->delete();
	}

	public function test_orders_need_manage_woocommerce() {
		$this->order_with_everything();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$args = MigratePlus_Exporter::sanitize_args(
			array(
				'post_types'     => array(),
				'include_orders' => 1,
			)
		);
		$this->assertSame( 0, MigratePlus_Orders::count( $args ) );
		$this->assertSame( array(), MigratePlus_Orders::export_batch( $args, null, 50 )['items'] );
	}

	protected function orders_doc() {
		$doc                        = $this->export_doc(
			array(
				'post_types'     => array(),
				'include_orders' => 1,
			)
		);
		$doc['migrateplus']['site'] = 'http://old.test';
		return $doc;
	}

	protected function log_of( MigratePlus_Job $job ) {
		return implode( "\n", array_column( $job->data['log'], 'm' ) );
	}

	protected function rollback( MigratePlus_Job $job ) {
		do {
			$result = MigratePlus_Runner::rollback_batch( $job );
		} while ( ! $result['done'] );
		$this->assertSame( '', $result['error'] );
		$job->delete();
	}

	public function test_imported_orders_leave_total_sales_as_they_were() {
		list( $product ) = $this->order_with_everything();
		$sold            = (int) get_post_meta( $product->get_id(), 'total_sales', true );
		$this->assertSame( 2, $sold );
		// Orders only: the line finds the same product by SKU, whose count already holds the original order.
		$file = $this->json_file( $this->orders_doc() );
		$job  = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertCount( 1, $job->imported_orders() );
		$this->assertSame( $sold, (int) get_post_meta( $product->get_id(), 'total_sales', true ) );
		$this->rollback( $job );
		$this->assertSame( $sold, (int) get_post_meta( $product->get_id(), 'total_sales', true ) );
	}

	public function test_a_refunded_order_keeps_its_partial_refund() {
		list( , , $order )           = $this->order_with_everything();
		$doc                         = $this->orders_doc();
		$doc['orders'][0]['status']  = 'refunded';
		$doc['orders'][0]['refunds'] = array_slice( $doc['orders'][0]['refunds'], 0, 1 );
		$file                        = $this->json_file( $doc );
		$order->delete( true );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new = wc_get_order( $job->imported_orders()[0] );
		$this->assertSame( 'refunded', $new->get_status() );
		$this->assertSame(
			array( 20.0 ),
			array_map(
				static function ( $refund ) {
					return (float) $refund->get_amount();
				},
				$new->get_refunds()
			)
		);
		$this->assertStringNotContainsString( 'refund could not be added', $this->log_of( $job ) );
		$this->rollback( $job );
	}

	public function test_refunds_keep_who_made_them_when_and_for_which_line() {
		list( , , $order ) = $this->order_with_everything();
		$refunder          = get_current_user_id();
		$line              = array_values( $order->get_items( 'line_item' ) )[0];
		wc_create_refund(
			array(
				'order_id'     => $order->get_id(),
				'amount'       => '100',
				'reason'       => 'One tent back',
				'date_created' => '2024-03-05 09:00:00',
				'line_items'   => array(
					$line->get_id() => array(
						'qty'          => 1,
						'refund_total' => 100,
					),
				),
			)
		);
		$file = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new  = wc_get_order( $job->imported_orders()[0] );
		$back = null;
		foreach ( $new->get_refunds() as $refund ) {
			if ( 'One tent back' === $refund->get_reason() ) {
				$back = $refund;
			}
		}
		$this->assertInstanceOf( 'WC_Order_Refund', $back );
		$this->assertSame( $refunder, $back->get_refunded_by() );
		$this->assertSame( '2024-03-05 09:00:00', $back->get_date_created()->date( 'Y-m-d H:i:s' ) );
		$new_line = array_values( $new->get_items( 'line_item' ) )[0];
		$this->assertSame( -1, $new->get_qty_refunded_for_item( $new_line->get_id() ) );
		$this->assertSame( 100.0, (float) $new->get_total_refunded_for_item( $new_line->get_id() ) );
		$this->rollback( $job );
	}

	public function test_an_order_that_fails_part_way_is_marked_for_rollback_and_the_rest_import() {
		list( , , $order )                    = $this->order_with_everything();
		$doc                                  = $this->orders_doc();
		$second                               = $doc['orders'][0];
		$second['id']                         = 9999;
		$second['number']                     = '9999';
		$doc['orders'][0]['items'][0]['name'] = 'Explodes';
		$doc['orders'][]                      = $second;
		$file                                 = $this->json_file( $doc );
		$order->delete( true );
		$boom = static function ( $item_id, $item ) {
			if ( 'Explodes' === $item->get_name() ) {
				throw new Error( 'Out of memory' ); // An Error, which WooCommerce's own catch lets through.
			}
		};
		add_action( 'woocommerce_new_order_item', $boom, 10, 2 );
		$job = $this->import_file( $file );
		remove_action( 'woocommerce_new_order_item', $boom, 10 );
		wp_delete_file( $file );
		$this->assertSame( 'complete', $job->data['status'] );
		$this->assertStringContainsString( 'could not be imported: Out of memory', $this->log_of( $job ) );
		$this->assertStringContainsString( 'Created order #9999', $this->log_of( $job ) );
		$this->assertCount( 2, $job->imported_orders() );
		$this->rollback( $job );
	}

	public function test_a_duplicate_gets_its_own_order_key() {
		list( , , $order ) = $this->order_with_everything();
		$key               = $order->get_order_key();
		$file              = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		$first = $this->import_file( $file );
		$copy  = $this->import_file( $file, array( 'duplicate' => 'create' ) );
		wp_delete_file( $file );
		$this->assertSame( $key, wc_get_order( $first->imported_orders()[0] )->get_order_key() );
		$copy_key = wc_get_order( $copy->imported_orders()[0] )->get_order_key();
		$this->assertNotSame( $key, $copy_key );
		$this->assertStringStartsWith( 'wc_order_', $copy_key );
		$this->rollback( $copy );
		$this->rollback( $first );
	}

	public function test_trashed_imported_orders_are_still_found() {
		list( , , $order ) = $this->order_with_everything();
		$file              = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		$job = $this->import_file( $file );
		$ids = $job->imported_orders();
		wc_get_order( $ids[0] )->delete( false );
		$this->assertSame( 'trash', wc_get_order( $ids[0] )->get_status() );
		$this->assertSame( $ids, $job->imported_orders() );
		$again = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertStringContainsString( 'Already imported: order #', $this->log_of( $again ) );
		$again->delete();
		$this->rollback( $job );
		$this->assertFalse( wc_get_order( $ids[0] ) );
	}

	public function test_order_details_woocommerce_turns_down_are_reported() {
		list( , , $order )                         = $this->order_with_everything();
		$doc                                       = $this->orders_doc();
		$doc['orders'][0]['billing']['email']      = 'not-an-email';
		$doc['orders'][0]['billing']['first_name'] = 'Cath';
		$file                                      = $this->json_file( $doc );
		$order->delete( true );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$this->assertStringContainsString( 'Some of the order\'s details were not set: Invalid billing email address', $this->log_of( $job ) );
		$this->assertSame( 'Cath', wc_get_order( $job->imported_orders()[0] )->get_billing_first_name() );
		$this->rollback( $job );
	}

	public function test_the_old_order_number_and_gateway_references_travel() {
		list( , , $order ) = $this->order_with_everything();
		$order->update_meta_data( '_stripe_intent_id', 'pi_123' );
		$order->update_meta_data( '_some_plugin_cache', 'stale' );
		$order->save();
		$number = $order->get_order_number();
		$doc    = $this->orders_doc();
		$this->assertSame( 'pi_123', $doc['orders'][0]['meta']['_stripe_intent_id'] );
		$this->assertArrayNotHasKey( '_some_plugin_cache', $doc['orders'][0]['meta'] );
		// A line saying it already took stock must not arrive: the import moves none.
		$doc['orders'][0]['items'][0]['meta'][] = array(
			'key'   => '_reduced_stock',
			'value' => '2',
		);
		$file                                   = $this->json_file( $doc );
		$order->delete( true );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new = wc_get_order( $job->imported_orders()[0] );
		$this->assertSame( 'pi_123', $new->get_meta( '_stripe_intent_id' ) );
		$this->assertSame( $number, $new->get_meta( '_migrateplus_source_order_number' ) );
		$this->assertSame( '', array_values( $new->get_items( 'line_item' ) )[0]->get_meta( '_reduced_stock' ) );
		$notes = array_column( array_map( 'get_object_vars', wc_get_order_notes( array( 'order_id' => $new->get_id() ) ) ), 'content' );
		$this->assertContains( 'Imported from order #' . $number . ' on the old site.', $notes );
		$this->rollback( $job );
	}

	public function test_webhooks_are_not_delivered_for_imported_orders() {
		list( , , $order ) = $this->order_with_everything();
		$file              = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		$asked  = array();
		$listen = static function () use ( &$asked ) {
			$asked[] = apply_filters( 'woocommerce_webhook_should_deliver', true, null, 0 );
		};
		add_action( 'woocommerce_new_order', $listen );
		$job = $this->import_file( $file );
		remove_action( 'woocommerce_new_order', $listen );
		wp_delete_file( $file );
		$this->assertSame( array( false ), $asked );
		$this->assertTrue( apply_filters( 'woocommerce_webhook_should_deliver', true, null, 0 ) );
		$this->rollback( $job );
	}

	public function test_a_customer_is_matched_by_email_only_and_otherwise_the_order_is_a_guest_order() {
		list( , $customer, $order ) = $this->order_with_everything();
		$file                       = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		wp_delete_user( $customer );
		// The new site's "cathy" is another person.
		self::factory()->user->create(
			array(
				'user_login' => 'cathy',
				'user_email' => 'cathy@agency.test',
			)
		);
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new = wc_get_order( $job->imported_orders()[0] );
		$this->assertSame( 0, $new->get_customer_id() );
		$this->assertSame( 'Cathy', $new->get_billing_first_name() );
		$this->assertSame( 'Perth', $new->get_shipping_city() );
		$this->assertStringContainsString( 'Customer "cathy@old.test" was not found on this site, so the order is a guest order with the billing and shipping details from the file.', $this->log_of( $job ) );
		$this->rollback( $job );
	}

	public function test_the_date_range_matches_the_posts_range() {
		$late = wc_create_order();
		$late->set_date_created( '2024-03-04 22:00:00' );
		$late->save();
		$future = wc_create_order();
		$future->set_date_created( '2999-01-01 10:00:00' );
		$future->save();
		$count = static function ( array $range ) {
			$args = array(
				'post_types'     => array(),
				'include_orders' => 1,
			);
			return MigratePlus_Orders::count( MigratePlus_Exporter::sanitize_args( array_merge( $args, $range ) ) );
		};

		// The last day is included in full, and a blank end means no end.
		$this->assertSame(
			1,
			$count(
				array(
					'after'  => '2024-03-01',
					'before' => '2024-03-04',
				)
			)
		);
		$this->assertSame( 1, $count( array( 'before' => '2024-03-04' ) ) );
		$this->assertSame( 1, $count( array( 'after' => '2024-03-05' ) ) );
	}

	public function test_an_order_left_half_built_is_built_again_on_resume() {
		list( , , $order ) = $this->order_with_everything();
		$file              = $this->json_file( $this->orders_doc() );
		$order->delete( true );
		$preview = MigratePlus_Runner::preview( 'json', array( 'file' => $file ), 5 );
		wp_delete_file( $file );
		$job = $preview['job'];
		$this->assertSame( 'skip', $job->data['options']['duplicate'] );
		$die = static function () {
			throw new Error( 'Maximum execution time exceeded' );
		};
		add_action( 'woocommerce_new_order_item', $die );
		MigratePlus_Orders::import_batch( $job );
		remove_action( 'woocommerce_new_order_item', $die );
		$half = $job->imported_orders();
		$this->assertCount( 1, $half );
		$this->assertSame( 'pending', wc_get_order( $half[0] )->get_status() );
		$this->assertCount( 0, wc_get_order( $half[0] )->get_refunds() );

		// The request died before it saved its place, so the order comes round again.
		$job->data['stage_pos'] = 0;
		$result                 = MigratePlus_Orders::import_batch( $job );

		$this->assertSame( 'created', $result['results'][0]['action'] );
		$this->assertFalse( wc_get_order( $half[0] ) );
		$built = $job->imported_orders();
		$this->assertCount( 1, $built );
		$new = wc_get_order( $built[0] );
		$this->assertSame( 'completed', $new->get_status() );
		$this->assertSame( 2, array_values( $new->get_items() )[0]->get_quantity() );
		$this->assertCount( 1, $new->get_refunds() );
		$this->assertSame( '', $new->get_meta( MigratePlus_Importer::INCOMPLETE_META ) );
		$this->rollback( $job );
	}

	public function test_a_fractional_quantity_is_kept_where_the_shop_allows_one() {
		list( , , $order )                        = $this->order_with_everything();
		$doc                                      = $this->orders_doc();
		$doc['orders'][0]['items'][0]['quantity'] = 1.5;
		$file                                     = $this->json_file( $doc );
		$order->delete( true );
		// What decimal quantity plugins do.
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
		$job = $this->import_file( $file );
		wp_delete_file( $file );
		$new = wc_get_order( $job->imported_orders()[0] );
		$this->assertSame( 1.5, array_values( $new->get_items() )[0]->get_quantity() );
		remove_filter( 'woocommerce_stock_amount', 'floatval' );
		add_filter( 'woocommerce_stock_amount', 'intval' );
		$this->rollback( $job );
	}
}
