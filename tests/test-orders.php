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
		$this->assertCount( 1, $again->imported_orders() + $job->imported_orders() );
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
}
