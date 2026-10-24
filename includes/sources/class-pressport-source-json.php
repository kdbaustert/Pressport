<?php
/**
 * JSON file source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports an array of objects from an uploaded JSON file, normally a PressPort export.
 * The records are cached locally so batches are consistent.
 */
class PressPort_Source_JSON extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'json';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'JSON file', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'file',
				'label'    => __( 'JSON file', 'pressport' ),
				'type'     => 'file',
				'accept'   => '.json,application/json',
				'remote'   => false,
				'required' => true,
			),
		);
	}

	/**
	 * Locate the records list inside a decoded document.
	 *
	 * @param mixed $doc Decoded JSON.
	 * @return array|WP_Error
	 */
	public static function find_records( $doc ) {
		if ( is_array( $doc ) && isset( $doc[0] ) && is_array( $doc[0] ) && array_keys( $doc ) === range( 0, count( $doc ) - 1 ) ) {
			return $doc; // Top-level list of objects.
		}
		if ( is_array( $doc ) ) {
			foreach ( array( 'items', 'data', 'posts', 'results', 'records', 'entries', 'articles', 'nodes', 'rows' ) as $key ) {
				if ( isset( $doc[ $key ] ) && is_array( $doc[ $key ] ) && ! empty( $doc[ $key ] ) && isset( $doc[ $key ][0] ) && is_array( $doc[ $key ][0] ) ) {
					return $doc[ $key ];
				}
			}
			// Fallback: first list of objects anywhere at depth 1-2.
			foreach ( $doc as $value ) {
				if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
					return array_values( $value );
				}
				if ( is_array( $value ) ) {
					foreach ( $value as $inner ) {
						if ( is_array( $inner ) && isset( $inner[0] ) && is_array( $inner[0] ) ) {
							return array_values( $inner );
						}
					}
				}
			}
		}
		return new WP_Error( 'pressport_json_records', __( 'Could not find a list of records in this JSON file.', 'pressport' ) );
	}

	/** @inheritDoc */
	public function prepare() {
		$file = (string) $this->get( 'file' );
		if ( '' === $file || ! is_readable( $file ) ) {
			return new WP_Error( 'pressport_json_input', __( 'Upload a JSON file.', 'pressport' ) );
		}
		$doc = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( null === $doc ) {
			return new WP_Error( 'pressport_json', __( 'Uploaded file is not valid JSON: ', 'pressport' ) . json_last_error_msg() );
		}

		// A PressPort export: remember its mapping and use its item list.
		if ( is_array( $doc ) && isset( $doc['pressport']['mapping'], $doc['items'] ) && is_array( $doc['items'] ) ) {
			// Its site is the base for relative media URLs: only an http(s) address, never javascript: or the like.
			$this->settings['pressport_mapping']   = $doc['pressport']['mapping'];
			$this->settings['pressport_site']      = isset( $doc['pressport']['site'] ) ? esc_url_raw( (string) $doc['pressport']['site'], array( 'http', 'https' ) ) : '';
			$this->settings['pressport_post_type'] = isset( $doc['pressport']['post_type'] ) ? sanitize_key( $doc['pressport']['post_type'] ) : '';
		}

		$records = self::find_records( $doc );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$records = array_values( array_filter( $records, 'is_array' ) );

		$cache = PressPort_Util::ensure_storage_dir() . 'json-' . wp_generate_password( 12, false ) . '.json';
		$json  = wp_json_encode( $records );
		if ( false === $json ) {
			// Values PHP cannot write back out, such as a number too large to hold (1e400).
			return new WP_Error( 'pressport_json', __( 'This JSON file holds values that cannot be read: ', 'pressport' ) . json_last_error_msg() );
		}
		if ( false === file_put_contents( $cache, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'pressport_json_cache', __( 'Could not write the import cache to the uploads folder.', 'pressport' ) );
		}

		$this->settings['cache_file'] = $cache;
		$this->settings['total']      = count( $records );
		// Collect field names from the first few records; optional fields may be missing from the first one.
		// A PressPort export's mapping was built from every item, so check them all or later-only fields drop out of it.
		$fields = array();
		foreach ( isset( $this->settings['pressport_mapping'] ) ? $records : array_slice( $records, 0, 25 ) as $record ) {
			$fields += array_flip( array_keys( PressPort_Util::flatten( $record ) ) );
		}
		$this->settings['fields'] = array_keys( $fields );
		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$cache = (string) $this->get( 'cache_file' );
		if ( ! $cache || ! is_readable( $cache ) ) {
			return new WP_Error( 'pressport_json_cache', __( 'Cached feed is missing. Start a new import.', 'pressport' ) );
		}
		// Decoded once per request: duplicate checks call this every 200 records.
		static $decoded = array();
		$key            = $cache . ':' . filemtime( $cache );
		if ( ! isset( $decoded[ $key ] ) ) {
			$decoded = array( $key => json_decode( (string) file_get_contents( $cache ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$all = $decoded[ $key ];
		if ( ! is_array( $all ) ) {
			return new WP_Error( 'pressport_json_cache', __( 'Cached feed is unreadable. Start a new import.', 'pressport' ) );
		}
		$offset = null === $cursor ? 0 : (int) $cursor;
		$slice  = array_slice( $all, $offset, $limit );
		$next   = $offset + count( $slice );

		return array(
			'records' => array_map( array( 'PressPort_Util', 'flatten' ), $slice ),
			'next'    => $next < count( $all ) ? $next : null,
			'total'   => count( $all ),
		);
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return PressPort_Sources::guess_mapping( (array) $this->get( 'fields', array() ) );
	}
}
