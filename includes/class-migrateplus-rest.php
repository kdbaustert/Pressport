<?php
/**
 * REST routes another site's Migrate Plus pulls an export through, authenticated
 * with an application password or this site's pull key. The admin-ajax export
 * endpoints only work with a logged-in browser session, so a site-to-site pull
 * gets routes of its own.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

class MigratePlus_Rest {

	const ROUTE_NAMESPACE = 'migrateplus/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'site' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'export_start' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/export/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'export_run' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/export/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export_file' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Same capability as the admin screens. MigratePlus_Admin only loads on admin
	 * requests, so the filter is applied here rather than through its helper.
	 * A request carrying the pull key from Settings → Access runs as the
	 * administrator who generated it, like an application password of theirs would.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			$key    = (string) $request->get_header( 'X-MigratePlus-Key' );
			$stored = (string) MigratePlus_Settings::get( 'pull_key' );
			$user   = (int) MigratePlus_Settings::get( 'pull_key_user' );
			if ( '' !== $key && '' !== $stored && $user && hash_equals( $stored, $key ) && get_userdata( $user ) ) {
				wp_set_current_user( $user );
			}
		}
		$capability = (string) apply_filters( 'migrateplus_capability', MigratePlus_Settings::get( 'capability' ) );
		if ( current_user_can( $capability ) ) {
			return true;
		}
		return new WP_Error(
			'migrateplus_rest_forbidden',
			__( 'This needs a user allowed to run Migrate Plus. Connect with an application password of such an account, or with the old site\'s pull key.', 'migrateplus' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * What the pulling site shows after connecting: who this site is and what it
	 * offers, each exportable type the connected user may edit with its count.
	 *
	 * @return array
	 */
	public static function site() {
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $object ) {
			if ( MigratePlus_Exporter::exportable_type( $object->name ) && current_user_can( $object->cap->edit_posts ) ) {
				$types[] = $object->name;
			}
		}
		$counts = array();
		if ( $types ) {
			$args   = MigratePlus_Exporter::sanitize_args( array( 'post_types' => $types ) );
			$counts = is_wp_error( $args ) ? array() : MigratePlus_Exporter::counts( $args );
		}
		$offered = array();
		// In the order counts() walked them: the order the export file will hold.
		foreach ( $counts as $type => $count ) {
			$offered[] = array(
				'type'  => $type,
				'label' => MigratePlus_Exporter::type_label( $type ),
				'count' => (int) $count,
			);
		}
		return array(
			'name'    => get_bloginfo( 'name' ),
			'url'     => home_url(),
			'version' => MIGRATEPLUS_VERSION,
			'types'   => $offered,
		);
	}

	/**
	 * Start an export, as the Export screen would with the same args.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function export_start( WP_REST_Request $request ) {
		$raw  = $request->get_param( 'args' );
		$args = MigratePlus_Exporter::args_for_user( is_array( $raw ) ? $raw : array() );
		if ( is_wp_error( $args ) ) {
			return self::bad_request( $args );
		}
		$state = MigratePlus_Exporter::start( $args );
		if ( is_wp_error( $state ) ) {
			return self::bad_request( $state );
		}
		// With menus, terms or settings included, a file of those alone is still worth having.
		if ( 0 === (int) $state['total'] && ! array_sum( MigratePlus_Exporter::side_counts( $args ) ) ) {
			MigratePlus_Exporter::forget( $state['id'] );
			return self::bad_request( new WP_Error( 'migrateplus_export_empty', __( 'Nothing matches these filters. Widen the status or date range.', 'migrateplus' ) ) );
		}
		return array(
			'id'    => $state['id'],
			'total' => (int) $state['total'],
		);
	}

	/**
	 * Export the next batch and report progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function export_run( WP_REST_Request $request ) {
		$state = self::request_export( $request );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		MigratePlus_Exporter::run_batch( $state, 50 );
		$out = array(
			'id'       => $state['id'],
			'done'     => (int) $state['done'],
			'total'    => (int) $state['total'],
			'complete' => ! empty( $state['complete'] ),
		);
		if ( ! empty( $state['error'] ) ) {
			$out['error'] = $state['error'];
		} elseif ( $out['complete'] ) {
			$out['filename'] = $state['filename'];
			$out['size']     = (int) $state['size'];
		}
		return $out;
	}

	/**
	 * Stream a finished export's file, like the admin download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error On failure; on success the file is sent and the request ends.
	 */
	public static function export_file( WP_REST_Request $request ) {
		$state = self::request_export( $request );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( empty( $state['complete'] ) || ! is_readable( $state['file'] ) ) {
			return new WP_Error( 'migrateplus_export_unready', __( 'This export is not ready or has expired. Run it again.', 'migrateplus' ), array( 'status' => 404 ) );
		}
		nocache_headers();
		$gzip = '.gz' === substr( $state['filename'], -3 );
		header( $gzip ? 'Content-Type: application/gzip' : 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Length: ' . filesize( $state['file'] ) );
		readfile( $state['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The export the request names, when the connected user may use it: its owner,
	 * or an administrator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected static function request_export( WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'export' ) );
		$state = $id ? MigratePlus_Exporter::load_state( $id ) : null;
		if ( ! $state || ( get_current_user_id() !== (int) $state['user'] && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'migrateplus_export_missing', __( 'Export not found. It may have expired; run it again.', 'migrateplus' ), array( 'status' => 404 ) );
		}
		return $state;
	}

	/**
	 * A WP_Error from the exporter, carrying the HTTP status REST needs.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	protected static function bad_request( WP_Error $error ) {
		$error->add_data( array( 'status' => 400 ) );
		return $error;
	}
}
