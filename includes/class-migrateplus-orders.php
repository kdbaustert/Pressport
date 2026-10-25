<?php
/**
 * WooCommerce orders in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports orders through the WooCommerce order API, so the same file reads
 * whether a site keeps orders in posts or in its own tables, and rebuilds them
 * after the records of an import, once the products and customers they name
 * are here.
 *
 * Line items name their product the way related products are named (source id,
 * SKU, slug); customers travel as email and login; refunds and order notes come
 * with their order. Totals are copied, never worked out again, so an imported
 * order reads as it did. Creating an order on a shop sends emails and moves
 * stock: both are switched off while the import runs.
 *
 * An order is matched by the uid it was imported with. Importing the same file
 * again skips or updates it as "Records imported before" says; an update sets
 * the order's status, addresses, totals and dates, not its items. Orders the
 * import creates carry _migrateplus_job_id for rollback.
 */
class MigratePlus_Orders {

	/**
	 * Props copied as they are, in both directions.
	 *
	 * @var string[]
	 */
	const PROPS = array( 'currency', 'prices_include_tax', 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'payment_method', 'payment_method_title', 'transaction_id', 'customer_note', 'created_via', 'order_key' );

	/**
	 * Whether WooCommerce is active with orders.
	 *
	 * @return bool
	 */
	public static function active() {
		return MigratePlus_Woo::active() && function_exists( 'wc_get_orders' ) && function_exists( 'wc_create_order' );
	}

	/**
	 * How many orders an export would carry.
	 *
	 * @param array $args Export args (after, before).
	 * @return int
	 */
	public static function count( array $args ) {
		if ( ! self::active() || ! self::user_can() ) {
			return 0;
		}
		$result = wc_get_orders( self::query( $args, 1, 1 ) + array( 'paginate' => true ) );
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * One page of orders as portable records, oldest first.
	 *
	 * @param array $args   Export args.
	 * @param mixed $cursor Page number from the last batch, or null for the first.
	 * @param int   $limit  Orders per page.
	 * @return array { items: array[], next: int|null }
	 */
	public static function export_batch( array $args, $cursor, $limit ) {
		if ( ! self::active() || ! self::user_can() ) {
			return array(
				'items' => array(),
				'next'  => null,
			);
		}
		$page   = max( 1, (int) $cursor );
		$orders = wc_get_orders( self::query( $args, $page, $limit ) );
		$items  = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof WC_Order ) {
				$items[] = self::export_order( $order, $args );
			}
		}
		return array(
			'items' => $items,
			'next'  => count( $items ) < $limit ? null : $page + 1,
		);
	}

	/**
	 * The wc_get_orders() args for a page of the export: every status, within the
	 * export's date range when it has one.
	 *
	 * @param array $args  Export args.
	 * @param int   $page  Page.
	 * @param int   $limit Page size.
	 * @return array
	 */
	protected static function query( array $args, $page, $limit ) {
		$q = array(
			'type'    => 'shop_order',
			'status'  => array_keys( wc_get_order_statuses() ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'limit'   => $limit,
			'paged'   => $page,
			'return'  => 'objects',
		);
		if ( ! empty( $args['after'] ) || ! empty( $args['before'] ) ) {
			$q['date_created'] = ( ! empty( $args['after'] ) ? $args['after'] : '1970-01-01' ) . '...' . ( ! empty( $args['before'] ) ? $args['before'] : gmdate( 'Y-m-d' ) );
		}
		return $q;
	}

	/**
	 * One order as a portable record.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $args  Export args.
	 * @return array
	 */
	protected static function export_order( WC_Order $order, array $args ) {
		$customer = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : false;
		$item     = array(
			'id'             => (int) $order->get_id(),
			'number'         => (string) $order->get_order_number(),
			'status'         => $order->get_status(),
			'date_created'   => self::date( $order->get_date_created() ),
			'date_paid'      => self::date( $order->get_date_paid() ),
			'date_completed' => self::date( $order->get_date_completed() ),
			'customer'       => $customer ? array(
				'email' => $customer->user_email,
				'login' => $customer->user_login,
			) : null,
			'billing'        => $order->get_address( 'billing' ),
			'shipping'       => $order->get_address( 'shipping' ),
			'items'          => array(),
			'shipping_lines' => array(),
			'fee_lines'      => array(),
			'coupon_lines'   => array(),
			'tax_lines'      => array(),
			'refunds'        => array(),
			'notes'          => array(),
			'meta'           => array(),
		);
		foreach ( self::PROPS as $prop ) {
			$item[ $prop ] = $order->{'get_' . $prop}( 'edit' );
		}
		$item['total_tax'] = $order->get_total_tax( 'edit' );
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$product_id      = (int) $line->get_product_id();
			$variation_id    = (int) $line->get_variation_id();
			$item['items'][] = array(
				'name'      => $line->get_name(),
				'product'   => $product_id ? self::product_ref( $product_id ) : null,
				'variation' => $variation_id ? self::product_ref( $variation_id ) : null,
				'quantity'  => $line->get_quantity(),
				'tax_class' => $line->get_tax_class(),
				'subtotal'  => $line->get_subtotal(),
				'total'     => $line->get_total(),
				'taxes'     => $line->get_taxes(),
				'meta'      => self::item_meta( $line ),
			);
		}
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$item['shipping_lines'][] = array(
				'method_title' => $line->get_method_title(),
				'method_id'    => $line->get_method_id(),
				'instance_id'  => $line->get_instance_id(),
				'total'        => $line->get_total(),
				'taxes'        => $line->get_taxes(),
				'meta'         => self::item_meta( $line ),
			);
		}
		foreach ( $order->get_items( 'fee' ) as $line ) {
			$item['fee_lines'][] = array(
				'name'       => $line->get_name(),
				'tax_class'  => $line->get_tax_class(),
				'tax_status' => $line->get_tax_status(),
				'amount'     => $line->get_amount(),
				'total'      => $line->get_total(),
				'taxes'      => $line->get_taxes(),
			);
		}
		foreach ( $order->get_items( 'coupon' ) as $line ) {
			$item['coupon_lines'][] = array(
				'code'         => $line->get_code(),
				'discount'     => $line->get_discount(),
				'discount_tax' => $line->get_discount_tax(),
			);
		}
		foreach ( $order->get_items( 'tax' ) as $line ) {
			$item['tax_lines'][] = array(
				'rate_id'            => (int) $line->get_rate_id(),
				'rate_code'          => $line->get_rate_code(),
				'label'              => $line->get_label(),
				'compound'           => (bool) $line->get_compound(),
				'tax_total'          => $line->get_tax_total(),
				'shipping_tax_total' => $line->get_shipping_tax_total(),
				'rate_percent'       => $line->get_rate_percent(),
			);
		}
		foreach ( $order->get_refunds() as $refund ) {
			$by                = $refund->get_refunded_by() ? get_userdata( $refund->get_refunded_by() ) : false;
			$item['refunds'][] = array(
				'id'          => (int) $refund->get_id(),
				'amount'      => $refund->get_amount(),
				'reason'      => $refund->get_reason(),
				'date'        => self::date( $refund->get_date_created() ),
				'refunded_by' => $by ? $by->user_email : '',
			);
		}
		foreach ( wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'orderby'  => 'date_created',
				'order'    => 'ASC',
			)
		) as $note ) {
			$item['notes'][] = array(
				'content'       => $note->content,
				'customer_note' => (bool) $note->customer_note,
				'added_by'      => $note->added_by,
				'date'          => $note->date_created instanceof DateTime ? $note->date_created->format( 'c' ) : '',
			);
		}
		foreach ( $order->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( '' === $key || '_' === $key[0] || ( ! empty( $args['exclude_meta'] ) && MigratePlus_Exporter::meta_excluded( $key, $args['exclude_meta'] ) ) ) {
				continue;
			}
			$item['meta'][ $key ] = $meta->value;
		}
		return apply_filters( 'migrateplus_export_order', $item, $order, $args );
	}

	/**
	 * A product as a line item names it: by id on the old site, SKU and slug.
	 *
	 * @param int $product_id Product or variation id.
	 * @return array|null
	 */
	protected static function product_ref( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'id' => (int) $product_id );
		}
		return array(
			'id'   => (int) $product_id,
			'sku'  => (string) $product->get_sku( 'edit' ),
			'slug' => (string) $product->get_slug( 'edit' ),
			'name' => (string) $product->get_name( 'edit' ),
		);
	}

	/**
	 * An item's custom fields (variation attributes, extensions' data).
	 *
	 * @param WC_Order_Item $line Item.
	 * @return array[] { key, value }
	 */
	protected static function item_meta( WC_Order_Item $line ) {
		$out = array();
		foreach ( $line->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( '' !== $key && '_' !== $key[0] ) {
				$out[] = array(
					'key'   => $key,
					'value' => $meta->value,
				);
			}
		}
		return $out;
	}

	/**
	 * @param WC_DateTime|null $date Date.
	 * @return string ISO 8601 in UTC, or ''.
	 */
	protected static function date( $date ) {
		return $date instanceof DateTime ? gmdate( 'c', $date->getTimestamp() ) : '';
	}

	/**
	 * Whether a job still has orders to import. They come after its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['orders_file'] ) && ! empty( $job->data['options']['import_orders'] );
	}

	/**
	 * The orders a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['orders_file'] ) ? (string) $job->data['settings']['orders_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_orders_cache', __( 'The cached orders are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * Import the next batch of the job's orders, resuming at stage_pos.
	 *
	 * @param MigratePlus_Job $job Job (stage_pos and created_total are updated in place).
	 * @return array { done: bool, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$orders = self::load( $job );
		if ( is_wp_error( $orders ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', 0, $orders->get_error_message() ) ),
			);
		}
		if ( ! self::active() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', 0, __( 'Orders were not imported: WooCommerce is not active on this site.', 'migrateplus' ), true ) ),
			);
		}
		if ( ! self::user_can() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', 0, __( 'Orders were not imported: you are not allowed to manage WooCommerce on this site.', 'migrateplus' ), true ) ),
			);
		}
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$limit   = max( 1, min( 100, (int) $opts['batch_size'] ) );
		$pos     = (int) $job->data['stage_pos'];
		$results = array();
		self::quiet( true );
		foreach ( array_slice( $orders, $pos, $limit ) as $record ) {
			if ( is_array( $record ) ) {
				try {
					$results[] = self::import_order( $record, $job, $opts );
				} catch ( Exception $e ) {
					/* translators: 1: order number, 2: error */
					$results[] = self::result( 'failed', 0, sprintf( __( 'Order #%1$s could not be imported: %2$s', 'migrateplus' ), isset( $record['number'] ) ? MigratePlus_Util::stringify( $record['number'] ) : '', $e->getMessage() ) );
				}
			}
			++$pos;
		}
		self::quiet( false );
		$job->data['stage_pos'] = $pos;
		return array(
			'done'    => $pos >= count( $orders ),
			'results' => $results,
		);
	}

	/**
	 * Keep WooCommerce from acting on imported orders as on new ones: no emails
	 * to customers or the shop, no stock moved.
	 *
	 * @param bool $on Switch on or off.
	 */
	protected static function quiet( $on ) {
		$fn = $on ? 'add_filter' : 'remove_filter';
		$fn( 'woocommerce_can_reduce_order_stock', '__return_false' );
		$fn( 'woocommerce_can_restore_order_stock', '__return_false' );
		// Not deferred, so each email asks whether it is enabled now, and is told no.
		$fn( 'woocommerce_defer_transactional_emails', '__return_false' );
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				$fn( 'woocommerce_email_enabled_' . $email->id, '__return_false' );
			}
		}
	}

	/**
	 * Create or update one order from its exported record.
	 *
	 * @param array           $record Order as exported.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import_order( array $record, MigratePlus_Job $job, array $opts ) {
		$dry    = ! empty( $opts['dry_run'] );
		$read   = static function ( $key ) use ( $record ) {
			return isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? MigratePlus_Util::stringify( $record[ $key ] ) : '';
		};
		$number = '' !== $read( 'number' ) ? $read( 'number' ) : $read( 'id' );
		if ( '' === $read( 'id' ) ) {
			return self::result( 'failed', 0, __( 'Order without an id was not imported.', 'migrateplus' ) );
		}
		$uid      = MigratePlus_Importer::uid( $job, 'order:' . $read( 'id' ) );
		$existing = self::find_order( $uid );
		if ( $existing && 'skip' === $opts['duplicate'] ) {
			/* translators: 1: order number on the old site, 2: order id here */
			return self::result( 'skipped', $existing, sprintf( __( 'Already imported: order #%1$s (#%2$d).', 'migrateplus' ), $number, $existing ) );
		}
		$updating = $existing && 'update' === $opts['duplicate'];
		$warnings = array();
		$status   = sanitize_key( str_replace( 'wc-', '', $read( 'status' ) ) );
		if ( '' === $status || ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			/* translators: %s: status */
			$warnings[] = sprintf( __( 'Status "%s" does not exist on this site, so the order is on hold.', 'migrateplus' ), $status );
			$status     = 'on-hold';
		}
		$customer    = isset( $record['customer'] ) && is_array( $record['customer'] ) ? $record['customer'] : array();
		$customer_id = 0;
		if ( $customer ) {
			$user = ! empty( $customer['email'] ) && is_email( $customer['email'] ) ? get_user_by( 'email', $customer['email'] ) : false;
			$user = $user ? $user : ( ! empty( $customer['login'] ) ? get_user_by( 'login', MigratePlus_Util::stringify( $customer['login'] ) ) : false );
			if ( $user ) {
				$customer_id = (int) $user->ID;
			} else {
				/* translators: %s: customer email */
				$warnings[] = sprintf( __( 'Customer "%s" is not on this site, so the order is a guest order.', 'migrateplus' ), isset( $customer['email'] ) ? MigratePlus_Util::stringify( $customer['email'] ) : '' );
			}
		}

		if ( $dry ) {
			/* translators: 1: Would create/Would update, 2: order number */
			return self::result( $updating ? 'updated' : 'created', $existing, self::with_warnings( sprintf( __( '%1$s order #%2$s.', 'migrateplus' ), $updating ? __( 'Would update', 'migrateplus' ) : __( 'Would create', 'migrateplus' ), $number ), $warnings ), ! empty( $warnings ) );
		}

		if ( $updating ) {
			$order = wc_get_order( $existing );
		} else {
			$order = wc_create_order(
				array(
					'status'      => 'pending',
					'customer_id' => $customer_id,
				)
			);
		}
		if ( ! $order instanceof WC_Order ) {
			/* translators: %s: order number */
			return self::result( 'failed', 0, sprintf( __( 'Order #%s could not be created.', 'migrateplus' ), $number ) );
		}

		$props = array( 'customer_id' => $customer_id );
		foreach ( self::PROPS as $prop ) {
			if ( array_key_exists( $prop, $record ) && is_scalar( $record[ $prop ] ) ) {
				$props[ $prop ] = 'prices_include_tax' === $prop ? (bool) $record[ $prop ] : MigratePlus_Util::stringify( $record[ $prop ] );
			}
		}
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			foreach ( isset( $record[ $kind ] ) && is_array( $record[ $kind ] ) ? $record[ $kind ] : array() as $field => $value ) {
				$field = sanitize_key( $field );
				if ( '' !== $field && is_scalar( $value ) && is_callable( array( $order, 'set_' . $kind . '_' . $field ) ) ) {
					$props[ $kind . '_' . $field ] = MigratePlus_Util::stringify( $value );
				}
			}
		}
		foreach ( array( 'date_created', 'date_paid', 'date_completed' ) as $prop ) {
			$date = MigratePlus_Importer::parse_date( $read( $prop ) );
			if ( $date ) {
				$props[ $prop ] = $date->getTimestamp();
			}
		}
		$order->set_props( $props );

		if ( ! $updating ) {
			self::add_items( $order, $record, $job, $warnings );
		}
		foreach ( isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array() as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' !== $key && '_' !== $key[0] ) {
				$order->update_meta_data( $key, $value );
			}
		}
		if ( ! $updating ) {
			$order->update_meta_data( '_migrateplus_job_id', $job->data['id'] );
			$order->update_meta_data( '_migrateplus_source_uid', $uid );
		}
		// The status last: the change fires WooCommerce's order hooks, with emails and stock switched off.
		$order->set_status( $status );
		$order->save();

		if ( ! $updating ) {
			++$job->data['created_total'];
			self::add_notes( $order, isset( $record['notes'] ) && is_array( $record['notes'] ) ? $record['notes'] : array() );
			self::add_refunds( $order, isset( $record['refunds'] ) && is_array( $record['refunds'] ) ? $record['refunds'] : array(), $warnings );
		}

		/* translators: 1: Created/Updated, 2: order number on the old site, 3: order id here */
		$message = sprintf( __( '%1$s order #%2$s (#%3$d).', 'migrateplus' ), $updating ? __( 'Updated', 'migrateplus' ) : __( 'Created', 'migrateplus' ), $number, $order->get_id() );
		return self::result( $updating ? 'updated' : 'created', $order->get_id(), self::with_warnings( $message, $warnings ), ! empty( $warnings ) );
	}

	/**
	 * Add an exported order's lines to a new order.
	 *
	 * @param WC_Order        $order    Order (not yet saved).
	 * @param array           $record   Order as exported.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 */
	protected static function add_items( WC_Order $order, array $record, MigratePlus_Job $job, array &$warnings ) {
		foreach ( isset( $record['items'] ) && is_array( $record['items'] ) ? $record['items'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item       = new WC_Order_Item_Product();
			$product_id = self::resolve_product( isset( $line['product'] ) ? $line['product'] : null, $job );
			$variation  = self::resolve_product( isset( $line['variation'] ) ? $line['variation'] : null, $job );
			if ( ! $product_id && ! empty( $line['product'] ) ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( 'Product "%s" is not on this site; its line keeps the name and price but links to no product.', 'migrateplus' ), isset( $line['name'] ) ? MigratePlus_Util::stringify( $line['name'] ) : '' );
			}
			$item->set_props(
				array(
					'name'         => isset( $line['name'] ) ? MigratePlus_Util::stringify( $line['name'] ) : '',
					'product_id'   => $product_id,
					'variation_id' => $variation,
					'quantity'     => isset( $line['quantity'] ) ? (int) $line['quantity'] : 1,
					'tax_class'    => isset( $line['tax_class'] ) ? MigratePlus_Util::stringify( $line['tax_class'] ) : '',
					'subtotal'     => isset( $line['subtotal'] ) ? MigratePlus_Util::stringify( $line['subtotal'] ) : '0',
					'total'        => isset( $line['total'] ) ? MigratePlus_Util::stringify( $line['total'] ) : '0',
				)
			);
			self::set_taxes( $item, $line );
			self::set_item_meta( $item, $line );
			$order->add_item( $item );
		}
		foreach ( isset( $record['shipping_lines'] ) && is_array( $record['shipping_lines'] ) ? $record['shipping_lines'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item = new WC_Order_Item_Shipping();
			$item->set_props(
				array(
					'method_title' => isset( $line['method_title'] ) ? MigratePlus_Util::stringify( $line['method_title'] ) : '',
					'method_id'    => isset( $line['method_id'] ) ? MigratePlus_Util::stringify( $line['method_id'] ) : '',
					'instance_id'  => isset( $line['instance_id'] ) ? MigratePlus_Util::stringify( $line['instance_id'] ) : '',
					'total'        => isset( $line['total'] ) ? MigratePlus_Util::stringify( $line['total'] ) : '0',
				)
			);
			self::set_taxes( $item, $line );
			self::set_item_meta( $item, $line );
			$order->add_item( $item );
		}
		foreach ( isset( $record['fee_lines'] ) && is_array( $record['fee_lines'] ) ? $record['fee_lines'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item = new WC_Order_Item_Fee();
			$item->set_props(
				array(
					'name'       => isset( $line['name'] ) ? MigratePlus_Util::stringify( $line['name'] ) : '',
					'tax_class'  => isset( $line['tax_class'] ) ? MigratePlus_Util::stringify( $line['tax_class'] ) : '',
					'tax_status' => isset( $line['tax_status'] ) && in_array( $line['tax_status'], array( 'taxable', 'none' ), true ) ? $line['tax_status'] : 'taxable',
					'amount'     => isset( $line['amount'] ) ? MigratePlus_Util::stringify( $line['amount'] ) : '0',
					'total'      => isset( $line['total'] ) ? MigratePlus_Util::stringify( $line['total'] ) : '0',
				)
			);
			self::set_taxes( $item, $line );
			$order->add_item( $item );
		}
		foreach ( isset( $record['coupon_lines'] ) && is_array( $record['coupon_lines'] ) ? $record['coupon_lines'] : array() as $line ) {
			if ( ! is_array( $line ) || empty( $line['code'] ) ) {
				continue;
			}
			$item = new WC_Order_Item_Coupon();
			$item->set_props(
				array(
					'code'         => MigratePlus_Util::stringify( $line['code'] ),
					'discount'     => isset( $line['discount'] ) ? MigratePlus_Util::stringify( $line['discount'] ) : '0',
					'discount_tax' => isset( $line['discount_tax'] ) ? MigratePlus_Util::stringify( $line['discount_tax'] ) : '0',
				)
			);
			$order->add_item( $item );
		}
		foreach ( isset( $record['tax_lines'] ) && is_array( $record['tax_lines'] ) ? $record['tax_lines'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$item = new WC_Order_Item_Tax();
			// The rate id is the old site's: it keys the items' taxes above, so the totals still add up here.
			$item->set_props(
				array(
					'rate_id'            => isset( $line['rate_id'] ) ? (int) $line['rate_id'] : 0,
					'rate_code'          => isset( $line['rate_code'] ) ? MigratePlus_Util::stringify( $line['rate_code'] ) : '',
					'label'              => isset( $line['label'] ) ? MigratePlus_Util::stringify( $line['label'] ) : '',
					'compound'           => ! empty( $line['compound'] ),
					'tax_total'          => isset( $line['tax_total'] ) ? MigratePlus_Util::stringify( $line['tax_total'] ) : '0',
					'shipping_tax_total' => isset( $line['shipping_tax_total'] ) ? MigratePlus_Util::stringify( $line['shipping_tax_total'] ) : '0',
					'rate_percent'       => isset( $line['rate_percent'] ) ? (float) $line['rate_percent'] : 0,
				)
			);
			$order->add_item( $item );
		}
	}

	/**
	 * This site's product for a line's reference: imported from the same id,
	 * else the same SKU, else the same slug.
	 *
	 * @param mixed           $ref { id, sku, slug, name } or null.
	 * @param MigratePlus_Job $job Job.
	 * @return int 0 when it is not here.
	 */
	protected static function resolve_product( $ref, MigratePlus_Job $job ) {
		if ( ! is_array( $ref ) ) {
			return 0;
		}
		$types = array( 'product', 'product_variation' );
		$id    = isset( $ref['id'] ) ? MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, MigratePlus_Util::stringify( $ref['id'] ) ), $types, $job->data['id'] ) : 0;
		if ( ! $id && ! empty( $ref['sku'] ) ) {
			$id = (int) wc_get_product_id_by_sku( MigratePlus_Util::stringify( $ref['sku'] ) );
		}
		if ( ! $id && ! empty( $ref['slug'] ) ) {
			$hit = get_posts(
				array(
					'name'             => sanitize_title( MigratePlus_Util::stringify( $ref['slug'] ) ),
					'post_type'        => $types,
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			$id  = $hit ? (int) $hit[0] : 0;
		}
		return (int) $id;
	}

	/**
	 * @param WC_Order_Item $item Item.
	 * @param array         $line Exported line.
	 */
	protected static function set_taxes( WC_Order_Item $item, array $line ) {
		if ( ! empty( $line['taxes'] ) && is_array( $line['taxes'] ) && method_exists( $item, 'set_taxes' ) ) {
			$item->set_taxes( $line['taxes'] );
		}
	}

	/**
	 * @param WC_Order_Item $item Item.
	 * @param array         $line Exported line.
	 */
	protected static function set_item_meta( WC_Order_Item $item, array $line ) {
		foreach ( isset( $line['meta'] ) && is_array( $line['meta'] ) ? $line['meta'] : array() as $meta ) {
			if ( is_array( $meta ) && isset( $meta['key'] ) && '' !== (string) $meta['key'] && '_' !== (string) $meta['key'][0] ) {
				$item->add_meta_data( (string) $meta['key'], isset( $meta['value'] ) ? $meta['value'] : '' );
			}
		}
	}

	/**
	 * @param WC_Order $order Saved order.
	 * @param array[]  $notes Exported notes.
	 */
	protected static function add_notes( WC_Order $order, array $notes ) {
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) || empty( $note['content'] ) ) {
				continue;
			}
			$id = $order->add_order_note( MigratePlus_Util::stringify( $note['content'] ), ! empty( $note['customer_note'] ) ? 1 : 0, false );
			if ( ! $id ) {
				continue;
			}
			$edit = array( 'comment_ID' => $id );
			if ( ! empty( $note['added_by'] ) ) {
				$edit['comment_author'] = sanitize_text_field( MigratePlus_Util::stringify( $note['added_by'] ) );
			}
			$date = isset( $note['date'] ) ? MigratePlus_Importer::parse_date( MigratePlus_Util::stringify( $note['date'] ) ) : null;
			if ( $date ) {
				$edit['comment_date']     = $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
				$edit['comment_date_gmt'] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			if ( count( $edit ) > 1 ) {
				wp_update_comment( wp_slash( $edit ) );
			}
		}
	}

	/**
	 * @param WC_Order $order    Saved order.
	 * @param array[]  $refunds  Exported refunds.
	 * @param string[] $warnings Warnings (by reference).
	 */
	protected static function add_refunds( WC_Order $order, array $refunds, array &$warnings ) {
		foreach ( $refunds as $refund ) {
			if ( ! is_array( $refund ) || ! isset( $refund['amount'] ) ) {
				continue;
			}
			$by  = ! empty( $refund['refunded_by'] ) && is_email( $refund['refunded_by'] ) ? get_user_by( 'email', $refund['refunded_by'] ) : false;
			$new = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => MigratePlus_Util::stringify( $refund['amount'] ),
					'reason'         => isset( $refund['reason'] ) ? MigratePlus_Util::stringify( $refund['reason'] ) : '',
					'refunded_by'    => $by ? (int) $by->ID : 0,
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
			if ( is_wp_error( $new ) ) {
				/* translators: %s: error */
				$warnings[] = sprintf( __( 'A refund could not be added: %s', 'migrateplus' ), $new->get_error_message() );
				continue;
			}
			$date = isset( $refund['date'] ) ? MigratePlus_Importer::parse_date( MigratePlus_Util::stringify( $refund['date'] ) ) : null;
			if ( $date ) {
				$new->set_date_created( $date->getTimestamp() );
				$new->save();
			}
		}
	}

	/**
	 * The order imported with a uid.
	 *
	 * @param string $uid From MigratePlus_Importer::uid().
	 * @return int 0 when none.
	 */
	protected static function find_order( $uid ) {
		$ids = self::ids_by_meta( '_migrateplus_source_uid', $uid, 1 );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * IDs of the orders a job created, for rollback.
	 *
	 * @param string $job_id Job id.
	 * @return int[]
	 */
	public static function imported( $job_id ) {
		return self::active() ? self::ids_by_meta( '_migrateplus_job_id', (string) $job_id, -1 ) : array();
	}

	/**
	 * Orders with a meta value. WooCommerce's own order tables take a meta query;
	 * orders kept as posts are asked through the posts table, since that store
	 * refuses one.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @param int    $limit How many (-1 for all).
	 * @return int[]
	 */
	protected static function ids_by_meta( $key, $value, $limit ) {
		$hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$ids = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'status'     => array_keys( wc_get_order_statuses() ),
					'limit'      => $limit,
					'return'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => $key,
							'value' => $value,
						),
					),
				)
			);
			return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'shop_order',
				'post_status'      => 'any',
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_key'         => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $value, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Delete an order the import created, with its refunds.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public static function delete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		self::quiet( true );
		foreach ( $order->get_refunds() as $refund ) {
			$refund->delete( true );
		}
		$ok = (bool) $order->delete( true );
		self::quiet( false );
		return $ok;
	}

	/**
	 * @param string   $message  Message.
	 * @param string[] $warnings Warnings.
	 * @return string
	 */
	protected static function with_warnings( $message, array $warnings ) {
		return $warnings ? $message . ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', array_unique( $warnings ) ) : $message;
	}

	/**
	 * Whether the person may see and create orders. WP-CLI without --user runs
	 * with the shell account's access.
	 *
	 * @return bool
	 */
	protected static function user_can() {
		return ! get_current_user_id() || current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's.
	}

	/**
	 * @param string $action   created|updated|skipped|failed.
	 * @param int    $order_id Order id.
	 * @param string $message  Message.
	 * @param bool   $warn     Warning.
	 * @return array
	 */
	protected static function result( $action, $order_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $order_id,
			'message' => $message,
			'warning' => $warn,
			'section' => 'orders',
		);
	}
}
